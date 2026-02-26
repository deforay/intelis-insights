<?php

declare(strict_types=1);

namespace App\Services;

/**
 * ConversationContextService
 *
 * Manages conversational context for chained natural-language queries against
 * the LIMS / lab-data warehouse.  Each "turn" stores the user question, the
 * generated SQL, the result set metadata, and extracted filters so that
 * follow-up questions ("of those …", "break down by province", etc.) can be
 * resolved correctly by the LLM.
 *
 * Storage: PHP session ($_SESSION).  Max 10 turns retained; the last 3 are
 * surfaced to the LLM prompt.
 */
class ConversationContextService
{
    /** Maximum number of turns kept in session history. */
    private int $maxHistory = 10;

    /** Number of recent turns included in the LLM prompt context. */
    private int $llmContextWindow = 3;

    /** Maximum sample rows stored per turn (to save memory). */
    private int $maxSampleRows = 5;

    /** Maximum columns per sample row stored (to save memory). */
    private int $maxSampleCols = 6;

    /**
     * Known table/test-type keywords used to decide whether a short question
     * is implicitly referencing a previous result set.
     */
    private array $tableKeywords = [
        'vl', 'viral load', 'eid', 'early infant', 'dbs',
        'covid', 'tb', 'tuberculosis', 'hiv', 'hepatitis',
        'form_vl', 'form_eid', 'form_covid', 'form_tb',
        'recency', 'form_recency',
        'generic_tests', 'form_generic',
        'cd4', 'form_cd4',
    ];

    // -----------------------------------------------------------------------
    // Constructor / session bootstrap
    // -----------------------------------------------------------------------

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    // -----------------------------------------------------------------------
    // Session read / write helpers
    // -----------------------------------------------------------------------

    private function getConversationHistory(): array
    {
        return $_SESSION['conversation_history'] ?? [];
    }

    private function setConversationHistory(array $history): void
    {
        $_SESSION['conversation_history'] = $history;
    }

    // -----------------------------------------------------------------------
    // Public — history management
    // -----------------------------------------------------------------------

    /**
     * Clear all conversation history from the session.
     */
    public function clearHistory(): void
    {
        unset($_SESSION['conversation_history']);
    }

    /**
     * Return the full conversation history array.
     */
    public function getHistory(): array
    {
        return $this->getConversationHistory();
    }

    /**
     * Return a single history entry by zero-based index.
     * Returns null when the index is out of range.
     */
    public function getHistoryItem(int $index): ?array
    {
        $history = $this->getConversationHistory();
        return $history[$index] ?? null;
    }

    /**
     * Truncate history to keep only entries up to (and including) $index.
     * Useful for a "go back" / undo feature.
     */
    public function rewindTo(int $index): void
    {
        $history = $this->getConversationHistory();

        if ($index < 0) {
            $this->clearHistory();
            return;
        }

        $this->setConversationHistory(
            array_slice($history, 0, $index + 1)
        );
    }

    // -----------------------------------------------------------------------
    // Public — add a turn
    // -----------------------------------------------------------------------

    /**
     * Record a completed query turn in the conversation history.
     *
     * @param string     $query       The original natural-language question.
     * @param array      $queryResult Metadata from the SQL generation step.
     *                                Expected keys: sql, intent, intent_details,
     *                                tables_used.
     * @param array|null $dbResult    Database execution result.
     *                                Expected keys: rows (array), count (int),
     *                                execution_time_ms (int), columns (array).
     */
    public function addQuery(string $query, array $queryResult, ?array $dbResult = null): void
    {
        $history = $this->getConversationHistory();

        $generatedSql = $queryResult['sql'] ?? ($queryResult['generated_sql'] ?? '');

        $entry = [
            'timestamp'        => time(),
            'original_query'   => $query,
            'generated_sql'    => $generatedSql,
            'intent'           => $queryResult['intent'] ?? 'unknown',
            'intent_details'   => $queryResult['intent_details'] ?? [],
            'tables_used'      => $queryResult['tables_used'] ?? [],
            'filters_applied'  => $this->extractFilters($generatedSql),
            'columns_returned' => [],
            'row_count'        => 0,
            'sample_rows'      => [],
            'result_summary'   => '',
            'summary'          => $this->generateQuerySummary($query, $queryResult),
        ];

        // Enrich with database result data when available.
        if ($dbResult !== null) {
            $rows    = $dbResult['rows'] ?? [];
            $count   = $dbResult['count'] ?? count($rows);
            $columns = $dbResult['columns']
                ?? (!empty($rows) ? array_keys((array) $rows[0]) : []);

            $entry['columns_returned'] = $columns;
            $entry['row_count']        = $count;
            $entry['sample_rows']      = $this->extractSampleData($rows);
            $entry['result_summary']   = $this->generateResultSummary($dbResult, $entry['intent']);

            // Legacy "output" block kept for backward compatibility with any
            // consumers that read the old shape.
            $entry['output'] = [
                'count'            => $count,
                'execution_time_ms'=> $dbResult['execution_time_ms'] ?? 0,
                'has_data'         => $count > 0,
                'sample_rows'      => $entry['sample_rows'],
                'columns_returned' => $columns,
                'result_summary'   => $entry['result_summary'],
            ];
        }

        $history[] = $entry;

        // Trim to max history size.
        if (count($history) > $this->maxHistory) {
            $history = array_slice($history, -$this->maxHistory);
        }

        $this->setConversationHistory(array_values($history));
    }

    // -----------------------------------------------------------------------
    // Public — context retrieval for a new query
    // -----------------------------------------------------------------------

    /**
     * Build the context payload to accompany a new user query.
     *
     * Returns an empty array when the query does not appear to reference
     * any previous turn.  Otherwise returns rich context including recent
     * queries, filters, and LLM-ready text.
     */
    public function getContextForNewQuery(string $newQuery): array
    {
        $history = $this->getConversationHistory();

        if (empty($history)) {
            return [];
        }

        if (!$this->seemsToReferencePrevious($newQuery)) {
            return [];
        }

        $recentQueries = array_slice($history, -$this->llmContextWindow);

        return [
            'has_context'       => true,
            'recent_queries'    => $recentQueries,
            'context_summary'   => $this->buildContextSummary($recentQueries),
            'llm_context_block' => $this->buildContextForLlm($recentQueries),
            'suggested_filters' => $this->suggestContinuationFilters($recentQueries),
            'common_tables'     => $this->getCommonTables($recentQueries),
            'previous_outputs'  => $this->buildPreviousOutputsContext($recentQueries),
        ];
    }

    // -----------------------------------------------------------------------
    // Public — LLM context block builder
    // -----------------------------------------------------------------------

    /**
     * Build a rich, human-readable context block designed to be injected into
     * the LLM system/user prompt so that it can resolve pronouns, carry
     * forward filters, and handle drill-down / refinement questions.
     *
     * Called internally by getContextForNewQuery() but also available publicly
     * so callers can generate the block independently.
     *
     * @param array|null $recentQueries  If null, the last N turns are used.
     */
    public function buildContextForLlm(?array $recentQueries = null): string
    {
        if ($recentQueries === null) {
            $history = $this->getConversationHistory();
            if (empty($history)) {
                return '';
            }
            $recentQueries = array_slice($history, -$this->llmContextWindow);
        }

        if (empty($recentQueries)) {
            return '';
        }

        $block  = "CONVERSATION CONTEXT (use to resolve references like \"those\", \"these\", etc.):\n\n";

        foreach ($recentQueries as $i => $entry) {
            $num = $i + 1;
            $block .= "Q{$num}: \"{$entry['original_query']}\"\n";

            if (!empty($entry['generated_sql'])) {
                // Include a condensed version of the SQL (first 300 chars) to
                // keep the prompt manageable while still giving the LLM the
                // query structure.
                $sql = $entry['generated_sql'];
                if (strlen($sql) > 300) {
                    $sql = substr($sql, 0, 300) . '...';
                }
                $block .= "SQL: {$sql}\n";
            }

            // Result summary
            $resultText = $entry['result_summary']
                ?? ($entry['output']['result_summary'] ?? '');
            if ($resultText !== '') {
                $block .= "Result: {$resultText}\n";
            }

            // Filters — human-readable
            $filterParts = [];
            foreach ($entry['filters_applied'] ?? [] as $key => $value) {
                if (!str_ends_with($key, '_sql')) {
                    $filterParts[] = "{$key}={$value}";
                }
            }
            $tables = implode(', ', $entry['tables_used'] ?? []);
            if ($tables !== '') {
                $filterParts[] = "table={$tables}";
            }
            if (!empty($filterParts)) {
                $block .= "Filters: " . implode(', ', $filterParts) . "\n";
            }

            $block .= "\n";
        }

        // Instruction footer for the LLM.
        $block .= "If the user says \"these\", \"those\", \"of those\" etc., they mean the results from the most recent query above.\n";
        $block .= "CARRY FORWARD all filters from the previous query and ADD the new conditions.\n";

        return $block;
    }

    // -----------------------------------------------------------------------
    // Reference detection
    // -----------------------------------------------------------------------

    /**
     * Determine whether $query likely references a previous conversation turn.
     *
     * Detection categories:
     *  - Pronouns: these, those, them, it, they
     *  - Continuations: of those, among them, from those, filter those
     *  - Drill-downs: break down, by province, by facility (when no explicit
     *    table/test keyword is present)
     *  - Refinements: but only, just the, narrow to, in Littoral, in Kigali
     *  - Follow-ups: what about, how about, and also, what percentage,
     *    furthermore
     *  - Implicit: short questions (< 6 words) without a table/test keyword
     */
    public function seemsToReferencePrevious(string $query): bool
    {
        $lower = strtolower(trim($query));

        // ------------------------------------------------------------------
        // 1. Pronoun references
        // ------------------------------------------------------------------
        $pronouns = [
            'these', 'those', 'them', 'they', 'it',
            'same', 'above', 'previous', 'earlier',
        ];
        foreach ($pronouns as $word) {
            // Word-boundary check so "item" doesn't match "it".
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/', $lower)) {
                return true;
            }
        }

        // ------------------------------------------------------------------
        // 2. Continuation phrases
        // ------------------------------------------------------------------
        $continuations = [
            'of those',
            'among them',
            'from those',
            'filter those',
            'from the above',
            'from the previous',
            'of the above',
            'out of those',
            'within those',
            'from that',
            'of that',
        ];
        foreach ($continuations as $phrase) {
            if (str_contains($lower, $phrase)) {
                return true;
            }
        }

        // ------------------------------------------------------------------
        // 3. Drill-down patterns — only when no explicit table keyword
        // ------------------------------------------------------------------
        $drillDowns = [
            'break down',
            'breakdown',
            'by province',
            'by facility',
            'by region',
            'by state',
            'by district',
            'by month',
            'by year',
            'by quarter',
            'by age',
            'by sex',
            'by gender',
            'group by',
            'per facility',
            'per province',
            'per region',
            'per state',
            'per month',
        ];
        $hasTableKeyword = $this->containsTableKeyword($lower);
        foreach ($drillDowns as $phrase) {
            if (str_contains($lower, $phrase) && !$hasTableKeyword) {
                return true;
            }
        }

        // ------------------------------------------------------------------
        // 4. Refinement phrases
        // ------------------------------------------------------------------
        $refinements = [
            'but only',
            'just the',
            'narrow to',
            'narrow down',
            'limit to',
            'restrict to',
            'only the',
            'only for',
            'only in',
            'only from',
            'exclude',
            'except',
            'in littoral',
            'in kigali',
            'in centre',
            'in south',
            'in north',
            'in east',
            'in west',
            'in adamawa',
            'in far north',
        ];
        foreach ($refinements as $phrase) {
            if (str_contains($lower, $phrase)) {
                return true;
            }
        }

        // ------------------------------------------------------------------
        // 5. Follow-up phrases
        // ------------------------------------------------------------------
        $followUps = [
            'what about',
            'how about',
            'and also',
            'what percentage',
            'what percent',
            'what proportion',
            'furthermore',
            'additionally',
            'how many of',
            'what fraction',
            'also show',
            'also include',
            'can you also',
            'now show',
            'now give',
            'now list',
            'compare with',
            'compare to',
        ];
        foreach ($followUps as $phrase) {
            if (str_contains($lower, $phrase)) {
                return true;
            }
        }

        // ------------------------------------------------------------------
        // 6. Implicit reference — short query without a table/test keyword
        //    e.g. "by province?" or "suppressed ones?"
        // ------------------------------------------------------------------
        $wordCount = str_word_count($lower);
        if ($wordCount < 6 && !$hasTableKeyword) {
            // Only if there is existing history
            $history = $this->getConversationHistory();
            if (!empty($history)) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Filter extraction from SQL
    // -----------------------------------------------------------------------

    /**
     * Parse a SQL string and extract known filter types as key/value pairs.
     *
     * Returns both human-readable keys (e.g. "time_period") and their SQL
     * fragments (e.g. "time_sql") so they can be carried forward.
     */
    public function extractFilters(string $sql): array
    {
        $filters = [];

        // Extract WHERE clause content.
        if (!preg_match('/WHERE\s+(.+?)(?:\s+GROUP\s+BY|\s+ORDER\s+BY|\s+LIMIT|\s+HAVING|$)/is', $sql, $matches)) {
            return $filters;
        }

        $where = $matches[1];

        // --- Date / time filters ---

        // DATE_SUB pattern
        if (preg_match('/sample_tested_datetime\s*>=\s*DATE_SUB\s*\(\s*NOW\s*\(\s*\)\s*,\s*INTERVAL\s+(\d+)\s+(\w+)\s*\)/i', $where, $m)) {
            $filters['time_period']  = $m[1] . ' ' . strtoupper($m[2]);
            $filters['time_sql']     = "sample_tested_datetime >= DATE_SUB(NOW(), INTERVAL {$m[1]} {$m[2]})";
        }

        // Literal date >=
        if (preg_match('/sample_tested_datetime\s*>=\s*[\'"]([^\'"]+)[\'"]/i', $where, $m)) {
            $filters['time_period']  = 'specific_date';
            $filters['time_start']   = $m[1];
            $filters['time_sql']     = "sample_tested_datetime >= '{$m[1]}'";
        }

        // Literal date <=  (range end)
        if (preg_match('/sample_tested_datetime\s*<=\s*[\'"]([^\'"]+)[\'"]/i', $where, $m)) {
            $filters['time_end']     = $m[1];
            $filters['time_end_sql'] = "sample_tested_datetime <= '{$m[1]}'";
        }

        // BETWEEN pattern
        if (preg_match('/sample_tested_datetime\s+BETWEEN\s+[\'"]([^\'"]+)[\'"]\s+AND\s+[\'"]([^\'"]+)[\'"]/i', $where, $m)) {
            $filters['time_period']  = 'range';
            $filters['time_start']   = $m[1];
            $filters['time_end']     = $m[2];
            $filters['time_sql']     = "sample_tested_datetime BETWEEN '{$m[1]}' AND '{$m[2]}'";
        }

        // YEAR() pattern
        if (preg_match('/YEAR\s*\(\s*sample_tested_datetime\s*\)\s*=\s*(\d{4})/i', $where, $m)) {
            $filters['time_period']  = 'year';
            $filters['time_year']    = (int) $m[1];
            $filters['time_sql']     = "YEAR(sample_tested_datetime) = {$m[1]}";
        }

        // --- Facility filter ---
        if (preg_match('/facility_name\s*=\s*[\'"]([^\'"]+)[\'"]/i', $where, $m)) {
            $filters['facility']     = $m[1];
            $filters['facility_sql'] = "facility_name = '{$m[1]}'";
        }

        // --- Province / state filter ---
        if (preg_match('/facility_state\s*=\s*[\'"]([^\'"]+)[\'"]/i', $where, $m)) {
            $filters['state']        = $m[1];
            $filters['state_sql']    = "facility_state = '{$m[1]}'";
        }

        // --- Province (alternate column name) ---
        if (preg_match('/province\s*=\s*[\'"]([^\'"]+)[\'"]/i', $where, $m)) {
            $filters['province']     = $m[1];
            $filters['province_sql'] = "province = '{$m[1]}'";
        }

        // --- District filter ---
        if (preg_match('/district\s*=\s*[\'"]([^\'"]+)[\'"]/i', $where, $m)) {
            $filters['district']     = $m[1];
            $filters['district_sql'] = "district = '{$m[1]}'";
        }

        // --- Machine / analyzer filter ---
        if (preg_match('/machine_used\s*=\s*[\'"]([^\'"]+)[\'"]/i', $where, $m)) {
            $filters['analyzer']     = $m[1];
            $filters['analyzer_sql'] = "machine_used = '{$m[1]}'";
        }

        // --- VL result category ---
        if (preg_match('/vl_result_category\s*=\s*[\'"]([^\'"]+)[\'"]/i', $where, $m)) {
            $filters['vl_category']     = $m[1];
            $filters['vl_category_sql'] = "vl_result_category = '{$m[1]}'";
        }

        // --- Numeric VL threshold ---
        if (preg_match('/result_value_absolute\s*([><=!]+)\s*(\d+)/i', $where, $m)) {
            $filters['vl_threshold']     = $m[1] . ' ' . $m[2];
            $filters['vl_threshold_sql'] = "result_value_absolute {$m[1]} {$m[2]}";
        }

        // --- Gender / sex filter ---
        if (preg_match('/(?:gender|sex)\s*=\s*[\'"]([^\'"]+)[\'"]/i', $where, $m)) {
            $filters['gender']     = $m[1];
            $filters['gender_sql'] = "gender = '{$m[1]}'";
        }

        // --- Age filter ---
        if (preg_match('/age\s*([><=!]+)\s*(\d+)/i', $where, $m)) {
            $filters['age']     = $m[1] . ' ' . $m[2];
            $filters['age_sql'] = "age {$m[1]} {$m[2]}";
        }

        // --- Patient status / treatment status ---
        if (preg_match('/(?:patient_status|treatment_status)\s*=\s*[\'"]([^\'"]+)[\'"]/i', $where, $m)) {
            $filters['patient_status']     = $m[1];
            $filters['patient_status_sql'] = "patient_status = '{$m[1]}'";
        }

        return $filters;
    }

    // -----------------------------------------------------------------------
    // Continuation filter suggestions
    // -----------------------------------------------------------------------

    /**
     * Collect SQL filter fragments from recent queries that should be carried
     * forward into a continuation query.
     *
     * @return string[] Array of SQL condition fragments.
     */
    public function suggestContinuationFilters(array $recentQueries): array
    {
        // Merge all filters; later entries overwrite earlier ones for the same
        // key, which is the desired behaviour (most recent filter wins).
        $allFilters = [];
        foreach ($recentQueries as $q) {
            foreach (($q['filters_applied'] ?? []) as $key => $value) {
                $allFilters[$key] = $value;
            }
        }

        $suggestions = [];

        $sqlKeys = [
            'time_sql',
            'time_end_sql',
            'facility_sql',
            'state_sql',
            'province_sql',
            'district_sql',
            'analyzer_sql',
            'vl_category_sql',
            'vl_threshold_sql',
            'gender_sql',
            'age_sql',
            'patient_status_sql',
        ];

        foreach ($sqlKeys as $key) {
            if (isset($allFilters[$key])) {
                $suggestions[] = $allFilters[$key];
            }
        }

        return $suggestions;
    }

    // -----------------------------------------------------------------------
    // Context summary builder (enhanced)
    // -----------------------------------------------------------------------

    /**
     * Build a human-readable context summary string including SQL and result
     * details for each recent turn.
     */
    public function buildContextSummary(array $recentQueries): string
    {
        $summary = "Previous queries, SQL, and their outputs:\n";

        foreach ($recentQueries as $i => $entry) {
            $num = $i + 1;
            $summary .= "Q{$num}: {$entry['original_query']}\n";
            $summary .= "   Intent: {$entry['intent']}\n";

            // Include generated SQL (truncated for readability).
            if (!empty($entry['generated_sql'])) {
                $sql = $entry['generated_sql'];
                if (strlen($sql) > 200) {
                    $sql = substr($sql, 0, 200) . '...';
                }
                $summary .= "   SQL: {$sql}\n";
            }

            // Tables used
            if (!empty($entry['tables_used'])) {
                $summary .= "   Tables: " . implode(', ', $entry['tables_used']) . "\n";
            }

            // Result summary
            $resultSummary = $entry['result_summary']
                ?? ($entry['output']['result_summary'] ?? '');
            if ($resultSummary !== '') {
                $summary .= "   Result: {$resultSummary}\n";
            }

            // Row count
            $rowCount = $entry['row_count'] ?? ($entry['output']['count'] ?? null);
            if ($rowCount !== null) {
                $summary .= "   Row count: {$rowCount}\n";
            }

            // Columns returned
            if (!empty($entry['columns_returned'])) {
                $summary .= "   Columns: " . implode(', ', $entry['columns_returned']) . "\n";
            }

            // Filters
            if (!empty($entry['filters_applied'])) {
                $display = [];
                foreach ($entry['filters_applied'] as $key => $value) {
                    if (!str_ends_with($key, '_sql')) {
                        $display[] = "{$key}: {$value}";
                    }
                }
                if (!empty($display)) {
                    $summary .= "   Filters: " . implode(', ', $display) . "\n";
                }
            }

            $summary .= "\n";
        }

        return $summary;
    }

    // -----------------------------------------------------------------------
    // Previous outputs context (enhanced)
    // -----------------------------------------------------------------------

    /**
     * Build a structured array of previous outputs suitable for programmatic
     * consumption (e.g. charting, table rendering).
     */
    public function buildPreviousOutputsContext(array $recentQueries): array
    {
        $outputs = [];

        foreach ($recentQueries as $i => $entry) {
            $output = [
                'query_number'     => $i + 1,
                'query'            => $entry['original_query'],
                'intent'           => $entry['intent'],
                'intent_details'   => $entry['intent_details'] ?? [],
                'generated_sql'    => $entry['generated_sql'] ?? null,
                'tables_used'      => $entry['tables_used'] ?? [],
                'filters_applied'  => $entry['filters_applied'] ?? [],
                'columns_returned' => $entry['columns_returned'] ?? [],
            ];

            if (isset($entry['output'])) {
                $output['result_count']      = $entry['output']['count'] ?? $entry['row_count'] ?? 0;
                $output['has_data']          = ($output['result_count'] > 0);
                $output['summary']           = $entry['output']['result_summary'] ?? $entry['result_summary'] ?? '';
                $output['sample_data']       = $entry['output']['sample_rows'] ?? $entry['sample_rows'] ?? [];
                $output['execution_time_ms'] = $entry['output']['execution_time_ms'] ?? 0;
            } elseif (isset($entry['row_count'])) {
                // New-shape entry without legacy "output" sub-key.
                $output['result_count']      = $entry['row_count'];
                $output['has_data']          = ($entry['row_count'] > 0);
                $output['summary']           = $entry['result_summary'] ?? '';
                $output['sample_data']       = $entry['sample_rows'] ?? [];
                $output['execution_time_ms'] = 0;
            } else {
                $output['result_count']      = 'unknown';
                $output['has_data']          = false;
                $output['summary']           = 'No output data stored';
                $output['sample_data']       = [];
                $output['execution_time_ms'] = 0;
            }

            $outputs[] = $output;
        }

        return $outputs;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Extract sample rows from a result set for storage.
     * Limits to $maxSampleRows rows and $maxSampleCols columns per row.
     */
    private function extractSampleData(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $sample = array_slice($rows, 0, $this->maxSampleRows);
        $limited = [];

        foreach ($sample as $row) {
            $limitedRow = [];
            $colCount = 0;
            foreach ((array) $row as $col => $value) {
                if ($colCount >= $this->maxSampleCols) {
                    break;
                }
                $limitedRow[$col] = $value;
                $colCount++;
            }
            $limited[] = $limitedRow;
        }

        return $limited;
    }

    /**
     * Generate a human-readable summary of database results.
     */
    private function generateResultSummary(array $dbResult, string $intent): string
    {
        $count = $dbResult['count'] ?? count($dbResult['rows'] ?? []);
        $rows  = $dbResult['rows'] ?? [];

        if ($count === 0) {
            return 'No results found';
        }

        switch ($intent) {
            case 'count':
                // Try to extract the count value from the first row.
                if (!empty($rows)) {
                    $firstRow = (array) $rows[0];
                    foreach ($firstRow as $col => $val) {
                        if (is_numeric($val) && $val > 0) {
                            $formatted = number_format((float) $val);
                            return "Found {$formatted} records ({$col})";
                        }
                    }
                }
                return "Found {$count} records";

            case 'list':
                return "Retrieved {$count} records" . ($count > 10 ? ' (showing sample)' : '');

            case 'aggregate':
                if (!empty($rows)) {
                    $firstRow = (array) $rows[0];
                    $parts = [];
                    foreach ($firstRow as $col => $val) {
                        if (is_numeric($val)) {
                            $parts[] = "{$col}: " . number_format((float) $val, 2);
                        }
                    }
                    if (!empty($parts)) {
                        return 'Computed: ' . implode(', ', array_slice($parts, 0, 4));
                    }
                }
                return "Computed {$count} aggregate values";

            case 'trend':
                return "Retrieved {$count} data points for trend analysis";

            case 'comparison':
                return "Retrieved {$count} rows for comparison";

            default:
                return "Returned {$count} results";
        }
    }

    /**
     * Generate a short summary sentence for a query turn.
     */
    private function generateQuerySummary(string $query, array $result): string
    {
        $intent = $result['intent'] ?? 'query';
        $tables = implode(', ', $result['tables_used'] ?? []);

        return "Asked about {$intent} from {$tables}: \"{$query}\"";
    }

    /**
     * Check whether a lowercased query string contains any known
     * table / test-type keyword.
     */
    private function containsTableKeyword(string $lowerQuery): bool
    {
        foreach ($this->tableKeywords as $keyword) {
            if (str_contains($lowerQuery, $keyword)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Identify tables used across multiple recent queries.
     *
     * @return string[] Table names appearing in more than one query.
     */
    private function getCommonTables(array $recentQueries): array
    {
        $counts = [];

        foreach ($recentQueries as $q) {
            foreach ($q['tables_used'] ?? [] as $table) {
                $counts[$table] = ($counts[$table] ?? 0) + 1;
            }
        }

        return array_keys(array_filter($counts, fn(int $c) => $c > 1));
    }
}
