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

    public function addQuery(string $query, array $queryResult, array $dbResult = null): void
    {
        $conversationHistory = $this->getConversationHistory();

        $contextEntry = [
            'timestamp' => time(),
            'original_query' => $query,
            'sql' => $queryResult['sql'],
            'intent' => $queryResult['intent'],
            'intent_details' => $queryResult['intent_details'] ?? [],
            'tables_used' => $queryResult['tables_used'],
            'filters_applied' => $this->extractFilters($queryResult['sql']),
            'summary' => $this->generateQuerySummary($query, $queryResult)
        ];

        // Add database results/output if provided
        if ($dbResult !== null) {
            $contextEntry['output'] = [
                'count' => $dbResult['count'],
                'execution_time_ms' => $dbResult['execution_time_ms'] ?? 0,
                'has_data' => $dbResult['count'] > 0,
                'sample_rows' => $this->extractSampleData($dbResult['rows'] ?? []),
                'result_summary' => $this->generateResultSummary($dbResult, $queryResult['intent'])
            ];
        }

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

        if (empty($conversationHistory)) {
            return [];
        }

        // Check if new query seems to reference previous context
        $seemsToReference = $this->seemsToReferencePrevious($newQuery);

        if (!$seemsToReference) {
            return [];
        }

        $recentQueries = array_slice($conversationHistory, -3); // Last 3 queries

        return [
            'has_context' => true,
            'recent_queries' => $recentQueries,
            'context_summary' => $this->buildContextSummary($recentQueries),
            'suggested_filters' => $this->suggestContinuationFilters($recentQueries),
            'common_tables' => $this->getCommonTables($recentQueries),
            'previous_outputs' => $this->buildPreviousOutputsContext($recentQueries)
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

    private function extractSampleData(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        // Take first 3 rows and limit columns to avoid memory bloat
        $sampleRows = array_slice($rows, 0, 3);
        $limitedSample = [];

        foreach ($sampleRows as $row) {
            $limitedRow = [];
            $colCount = 0;
            foreach ($row as $col => $value) {
                if ($colCount >= 5) break; // Limit to 5 columns per row
                $limitedRow[$col] = $value;
                $colCount++;
            }
            $limitedSample[] = $limitedRow;
        }

        return $limitedSample;
    }

    private function generateResultSummary(array $dbResult, string $intent): string
    {
        $count = $dbResult['count'];
        $rows = $dbResult['rows'] ?? [];

        if ($count === 0) {
            return "No results found";
        }

        switch ($intent) {
            case 'count':
                return "Found {$count} records";
                
            case 'list':
                return "Retrieved {$count} records" . ($count > 10 ? " (showing sample)" : "");
                
            case 'aggregate':
                // Try to extract aggregate values from first row
                if (!empty($rows)) {
                    $firstRow = $rows[0];
                    $values = [];
                    foreach ($firstRow as $col => $val) {
                        if (is_numeric($val)) {
                            $values[] = "{$col}: {$val}";
                        }
                    }
                    return "Computed: " . implode(', ', array_slice($values, 0, 3));
                }
                return "Computed {$count} aggregate values";
                
            default:
                return "Returned {$count} results";
        }
    }

    private function buildPreviousOutputsContext(array $recentQueries): array
    {
        $outputsContext = [];

        foreach ($recentQueries as $i => $queryEntry) {
            $output = [
                'query_number' => $i + 1,
                'query' => $queryEntry['original_query'],
                'intent' => $queryEntry['intent'],
                'intent_details' => $queryEntry['intent_details'] ?? [],
                'generated_sql' => $queryEntry['generated_sql'] ?? null
            ];

            if (isset($queryEntry['output'])) {
                $output['result_count'] = $queryEntry['output']['count'];
                $output['has_data'] = $queryEntry['output']['has_data'];
                $output['summary'] = $queryEntry['output']['result_summary'];
                $output['sample_data'] = $queryEntry['output']['sample_rows'];
                $output['full_data'] = $queryEntry['output']['full_rows'] ?? [];
                $output['columns_returned'] = $queryEntry['output']['columns_returned'] ?? [];
                $output['data_types'] = $queryEntry['output']['data_types'] ?? [];
                $output['execution_time_ms'] = $queryEntry['output']['execution_time_ms'] ?? 0;
            } else {
                $output['result_count'] = 'unknown';
                $output['has_data'] = false;
                $output['summary'] = 'No output data stored';
            }

            $outputsContext[] = $output;
        }

        return $outputsContext;
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
            'furthermore',
            'from those',
            'among them',
            'of those',
            'break down',
            'filter those',
            'what percentage'
        ];

        $queryLower = strtolower($query);

        foreach ($referenceWords as $word) {
            if (strpos($queryLower, $word) !== false) {
                return true;
            }
        }

        return false;
    }

    private function extractFilters(string $sql): array
    {
        $filters = [];

        // Extract WHERE conditions
        if (preg_match('/WHERE\s+(.+?)(?:\s+GROUP BY|\s+ORDER BY|\s+LIMIT|$)/i', $sql, $matches)) {
            $whereClause = $matches[1];

            // Date filter extraction patterns
            if (preg_match('/sample_tested_datetime\s*>=\s*DATE_SUB\s*\(\s*NOW\s*\(\s*\)\s*,\s*INTERVAL\s+(\d+)\s+(\w+)\s*\)/i', $whereClause, $dateMatch)) {
                $filters['time_period'] = $dateMatch[1] . ' ' . strtoupper($dateMatch[2]);
                $filters['time_sql'] = "sample_tested_datetime >= DATE_SUB(NOW(), INTERVAL {$dateMatch[1]} {$dateMatch[2]})";
            }

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
        $summary = "Previous queries and their outputs:\n";

        foreach ($recentQueries as $i => $queryEntry) {
            $num = $i + 1;
            $summary .= "Q{$num}: {$queryEntry['original_query']}\n";
            $summary .= "   Intent: {$queryEntry['intent']}\n";

            // Add output summary if available
            if (isset($queryEntry['output']['result_summary'])) {
                $summary .= "   Output: {$queryEntry['output']['result_summary']}\n";
            }

            if (!empty($queryEntry['filters_applied'])) {
                $filters = [];
                foreach ($queryEntry['filters_applied'] as $key => $value) {
                    // Skip the _sql keys for display
                    if (!str_ends_with($key, '_sql')) {
                        $filters[] = "{$key}: {$value}";
                    }
                }
                if (!empty($filters)) {
                    $summary .= "   Filters: " . implode(', ', $filters) . "\n";
                }
            }
            $summary .= "\n";
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