<?php
// src/Services/ConversationContextService.php
declare(strict_types=1);

namespace App\Services;

class ConversationContextService
{
    private int $maxHistory = 10;

    public function __construct()
    {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    private function getConversationHistory(): array
    {
        return $_SESSION['conversation_history'] ?? [];
    }

    private function setConversationHistory(array $history): void
    {
        $_SESSION['conversation_history'] = $history;
    }

    public function addQuery(string $query, array $queryResult): void
    {
        $conversationHistory = $this->getConversationHistory();

        $contextEntry = [
            'timestamp' => time(),
            'original_query' => $query,
            'sql' => $queryResult['sql'],
            'intent' => $queryResult['intent'],
            'tables_used' => $queryResult['tables_used'],
            'filters_applied' => $this->extractFilters($queryResult['sql']),
            'summary' => $this->generateQuerySummary($query, $queryResult)
        ];

        $conversationHistory[] = $contextEntry;

        // Keep only recent history
        if (count($conversationHistory) > $this->maxHistory) {
            array_shift($conversationHistory);
        }

        $this->setConversationHistory($conversationHistory);
    }

    public function getContextForNewQuery(string $newQuery): array
    {
        $conversationHistory = $this->getConversationHistory();

        // error_log("getContextForNewQuery called with: '$newQuery'");
        // error_log("History count: " . count($conversationHistory));

        if (empty($conversationHistory)) {
            //error_log("No conversation history");
            return [];
        }

        // Check if new query seems to reference previous context
        $seemsToReference = $this->seemsToReferencePrevious($newQuery);
        //error_log("Seems to reference previous: " . ($seemsToReference ? 'YES' : 'NO'));

        if (!$seemsToReference) {
            //error_log("No reference detected, returning empty context");
            return [];
        }

        $recentQueries = array_slice($conversationHistory, -3); // Last 3 queries

        return [
            'has_context' => true,
            'recent_queries' => $recentQueries,
            'context_summary' => $this->buildContextSummary($recentQueries),
            'suggested_filters' => $this->suggestContinuationFilters($recentQueries),
            'common_tables' => $this->getCommonTables($recentQueries)
        ];
    }

    public function clearHistory(): void
    {
        unset($_SESSION['conversation_history']);
    }

    public function getHistory(): array
    {
        return $this->getConversationHistory();
    }



    private function seemsToReferencePrevious(string $query): bool
    {
        $referenceWords = [
            'these',
            'those',
            'them',
            'they',
            'it',
            'same',
            'above',
            'previous',
            'earlier',
            'how many of',
            'what about',
            'and',
            'also',
            'additionally',
            'furthermore'
        ];

        $queryLower = strtolower($query);

        // DEBUG: Log the query and detection
        // error_log("Context Detection - Query: '$query'");
        // error_log("Context Detection - Query Lower: '$queryLower'");

        foreach ($referenceWords as $word) {
            if (strpos($queryLower, $word) !== false) {
                //error_log("Context Detection - Found reference word: '$word'");
                return true;
            }
        }

        //error_log("Context Detection - No reference words found");
        return false;
    }

    private function extractFilters(string $sql): array
    {
        $filters = [];

        // Extract WHERE conditions
        if (preg_match('/WHERE\s+(.+?)(?:\s+GROUP BY|\s+ORDER BY|\s+LIMIT|$)/i', $sql, $matches)) {
            $whereClause = $matches[1];

            // FIXED: Better date filter extraction patterns
            // Pattern 1: DATE_SUB with INTERVAL
            if (preg_match('/sample_tested_datetime\s*>=\s*DATE_SUB\s*\(\s*NOW\s*\(\s*\)\s*,\s*INTERVAL\s+(\d+)\s+(\w+)\s*\)/i', $whereClause, $dateMatch)) {
                $filters['time_period'] = $dateMatch[1] . ' ' . strtoupper($dateMatch[2]);
                $filters['time_sql'] = "sample_tested_datetime >= DATE_SUB(NOW(), INTERVAL {$dateMatch[1]} {$dateMatch[2]})";
            }

            // Pattern 2: Other date patterns if needed
            if (preg_match('/sample_tested_datetime\s*>=\s*[\'"]([^\'"]+)[\'"]/i', $whereClause, $dateMatch2)) {
                $filters['time_period'] = 'specific_date';
                $filters['time_sql'] = "sample_tested_datetime >= '{$dateMatch2[1]}'";
            }

            // Facility filters
            if (preg_match('/facility_name\s*=\s*[\'"]([^\'"]+)[\'"]/i', $whereClause, $facilityMatch)) {
                $filters['facility'] = $facilityMatch[1];
                $filters['facility_sql'] = "facility_name = '{$facilityMatch[1]}'";
            }

            // State filters
            if (preg_match('/facility_state\s*=\s*[\'"]([^\'"]+)[\'"]/i', $whereClause, $stateMatch)) {
                $filters['state'] = $stateMatch[1];
                $filters['state_sql'] = "facility_state = '{$stateMatch[1]}'";
            }

            // Machine/analyzer filters
            if (preg_match('/machine_used\s*=\s*[\'"]([^\'"]+)[\'"]/i', $whereClause, $machineMatch)) {
                $filters['analyzer'] = $machineMatch[1];
                $filters['analyzer_sql'] = "machine_used = '{$machineMatch[1]}'";
            }

            // VL category filters
            if (preg_match('/vl_result_category\s*=\s*[\'"]([^\'"]+)[\'"]/i', $whereClause, $vlMatch)) {
                $filters['vl_category'] = $vlMatch[1];
                $filters['vl_category_sql'] = "vl_result_category = '{$vlMatch[1]}'";
            }

            // Numeric VL thresholds
            if (preg_match('/result_value_absolute\s*([><=]+)\s*(\d+)/i', $whereClause, $numericMatch)) {
                $filters['vl_threshold'] = $numericMatch[1] . ' ' . $numericMatch[2];
                $filters['vl_threshold_sql'] = "result_value_absolute {$numericMatch[1]} {$numericMatch[2]}";
            }
        }

        return $filters;
    }

    private function generateQuerySummary(string $query, array $result): string
    {
        $intent = $result['intent'];
        $tables = implode(', ', $result['tables_used']);

        return "Asked about {$intent} from {$tables}: \"{$query}\"";
    }

    private function buildContextSummary(array $recentQueries): string
    {
        $summary = "Recent conversation context:\n";

        foreach ($recentQueries as $i => $query) {
            $num = $i + 1;
            $summary .= "Q{$num}: {$query['original_query']}\n";

            if (!empty($query['filters_applied'])) {
                $filters = [];
                foreach ($query['filters_applied'] as $key => $value) {
                    // Skip the _sql keys for display
                    if (!str_ends_with($key, '_sql')) {
                        $filters[] = "{$key}: {$value}";
                    }
                }
                if (!empty($filters)) {
                    $summary .= "   Filters: " . implode(', ', $filters) . "\n";
                }
            }
        }

        return $summary;
    }

    private function suggestContinuationFilters(array $recentQueries): array
    {
        $allFilters = [];

        foreach ($recentQueries as $query) {
            $allFilters = array_merge($allFilters, $query['filters_applied']);
        }

        // Find SQL fragments that should be carried forward
        $suggestions = [];

        if (isset($allFilters['time_sql'])) {
            $suggestions[] = $allFilters['time_sql'];
        }

        if (isset($allFilters['facility_sql'])) {
            $suggestions[] = $allFilters['facility_sql'];
        }

        if (isset($allFilters['state_sql'])) {
            $suggestions[] = $allFilters['state_sql'];
        }

        if (isset($allFilters['analyzer_sql'])) {
            $suggestions[] = $allFilters['analyzer_sql'];
        }

        return $suggestions;
    }

    private function getCommonTables(array $recentQueries): array
    {
        $tableCounts = [];

        foreach ($recentQueries as $query) {
            foreach ($query['tables_used'] as $table) {
                $tableCounts[$table] = ($tableCounts[$table] ?? 0) + 1;
            }
        }

        // Return tables used in multiple queries
        return array_keys(array_filter($tableCounts, fn($count) => $count > 1));
    }
}
