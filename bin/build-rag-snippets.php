#!/usr/bin/env php
<?php
// bin/build-rag-snippets.php
declare(strict_types=1);

/**
 * Build RAG snippets JSONL from config/business-rules.php and config/field-guide.php
 * Output: corpus/snippets.jsonl
 *
 * Types used:
 * - rule          : privacy, defaults, scope limits, intent rules, validation, formatting, contextual rules
 * - syn           : terminology mapping (one phrase per snippet)
 * - threshold     : clinical thresholds
 * - test_type     : per test type defaults (table, key columns, groupings)
 * - column        : column semantics (table + column)
 * - exemplar      : query patterns/examples (pattern + example SQL)
 * - validation    : required joins / recommended filters
 */

chdir(__DIR__ . '/..');

$business = require __DIR__ . '/../config/business-rules.php';
$field    = require __DIR__ . '/../config/field-guide.php';

$appCfg  = require __DIR__ . '/../config/app.php';
$schemaPath = $appCfg['schema_path'] ?? (__DIR__ . '/../var/schema.json');
$schema = is_file($schemaPath) ? json_decode(file_get_contents($schemaPath), true) : [];


$outDir = __DIR__ . '/../corpus';
@mkdir($outDir, 0775, true);
$outFile = $outDir . '/snippets.jsonl';
$fp = fopen($outFile, 'w');
if (!$fp) {
    fwrite(STDERR, "Failed to open $outFile for writing\n");
    exit(1);
}

$forbiddenCols = array_map('strtolower', $business['global_rules']['privacy']['forbidden_columns'] ?? []);

$emitCount = 0;
$emit = function (array $row) use ($fp, &$emitCount) {
    fwrite($fp, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    $emitCount++;
};

$slug = function ($s): string {
    $s = (string)$s;
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
    return trim($s, '-');
};
$hid = function (string $s): string {
    return substr(sha1($s), 0, 10);
};

// ---------- BUSINESS RULES → rule / validation ----------
if (!empty($business['global_rules'])) {
    foreach ($business['global_rules'] as $sectionKey => $section) {
        $rules = $section['rules'] ?? [];
        $desc  = $section['description'] ?? $sectionKey;
        foreach ($rules as $i => $text) {
            $id = "rule:global.$sectionKey#" . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT);
            $emit([
                'id'   => $id,
                'type' => 'rule',
                'text' => $text,
                'meta' => ['section' => "global.$sectionKey", 'description' => $desc],
                'tags' => [$sectionKey, 'global']
            ]);
        }
        // privacy forbidden columns (one consolidated rule)
        if ($sectionKey === 'privacy') {
            $id = "rule:privacy.forbidden_columns#p01";
            $emit([
                'id'   => $id,
                'type' => 'rule',
                'text' => 'Forbidden columns (never return): ' . implode(', ', $section['forbidden_columns'] ?? []),
                'meta' => ['columns' => $section['forbidden_columns'] ?? [], 'priority' => 1.3],
                'tags' => ['privacy', 'forbidden']
            ]);
        }
    }
}

if (!empty($business['intent_rules'])) {
    foreach ($business['intent_rules'] as $intent => $block) {
        foreach (($block['rules'] ?? []) as $i => $text) {
            $id = "rule:intent.$intent#" . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT);
            $emit([
                'id'   => $id,
                'type' => 'rule',
                'text' => $text,
                'meta' => ['intent' => $intent, 'description' => $block['description'] ?? ''],
                'tags' => ['intent', $intent]
            ]);
        }
        // defaults inside intent
        if (!empty($block['default_behavior'])) {
            foreach ($block['default_behavior'] as $k => $v) {
                // If the key is numeric, the value *is* the behavior text
                $text = is_int($k) ? (string)$v : "$k = $v";
                $id   = "rule:intent.$intent.default#" . $hid($intent . '|' . $text);

                $emit([
                    'id'   => $id,
                    'type' => 'rule',
                    'text' => "Default behavior: $text",
                    'meta' => ['intent' => $intent, 'key' => $k, 'value' => $v],
                    'tags' => ['intent', $intent, 'default']
                ]);
            }
        }
    }
}

if (!empty($business['validation_rules'])) {
    $vr = $business['validation_rules'];
    foreach (($vr['reject_patterns'] ?? []) as $i => $rx) {
        $emit([
            'id'   => "validation:reject_pattern#" . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT),
            'type' => 'validation',
            'text' => "Reject pattern: $rx",
            'meta' => ['pattern' => $rx],
            'tags' => ['validation', 'reject']
        ]);
    }
    foreach (($vr['reject_intents'] ?? []) as $i => $txt) {
        $emit([
            'id'   => "validation:reject_intent#" . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT),
            'type' => 'validation',
            'text' => "Reject intent: $txt",
            'meta' => ['intent_text' => $txt],
            'tags' => ['validation', 'reject']
        ]);
    }
    if (!empty($vr['scope_limits'])) {
        foreach ($vr['scope_limits'] as $k => $v) {
            $emit([
                'id'   => "validation:scope_limit#" . $slug((string)$k),
                'type' => 'validation',
                'text' => "Scope limit: $k = " . (is_scalar($v) ? $v : json_encode($v)),
                'meta' => ['key' => $k, 'value' => $v],
                'tags' => ['validation', 'scope']
            ]);
        }
    }

    $emit([
        'id'   => "validation:date_default#tested",
        'type' => 'validation',
        'text' => "Default temporal field is sample_tested_datetime unless the user explicitly asks for collection date.",
        'meta' => ['priority' => 1.15],  // ← add
        'tags' => ['validation', 'temporal']
    ]);

    if (!empty($field['generic_patterns'])) {
        echo "Processing " . count($field['generic_patterns']) . " generic patterns...\n";
        foreach ($field['generic_patterns'] as $userPhrase => $config) {
            echo "  - Processing pattern: $userPhrase\n";
        }
    } else {
        echo "No generic_patterns found in field guide!\n";
        echo "Available field guide keys: " . implode(', ', array_keys($field)) . "\n";
    }

    // $emit([
    //     'id'   => "validation:group_by_lab#v1",
    //     'type' => 'validation',
    //     'text' => "When user says 'by lab' or 'by testing lab', group on fv.lab_id and JOIN facility_details fd ON fv.lab_id = fd.facility_id; select fd.facility_name for display.",
    //     'meta' => ['priority' => 1.2],
    //     'tags' => ['validation', 'lab']
    // ]);

    $emit([
        'id'   => "validation:group_by_lab#generic",
        'type' => 'validation',
        'text' => "When user says 'by lab' or 'by testing lab', group on {table_alias}.lab_id and JOIN facility_details fd ON {table_alias}.lab_id = fd.facility_id; select fd.facility_name for display.",
        'meta' => ['priority' => 1.2, 'pattern' => 'lab_grouping'],
        'tags' => ['validation', 'lab', 'generic']
    ]);
}

if (!empty($business['response_formatting'])) {
    foreach ($business['response_formatting'] as $secKey => $block) {
        foreach (($block['rules'] ?? []) as $i => $txt) {
            $emit([
                'id'   => "rule:formatting.$secKey#" . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT),
                'type' => 'rule',
                'text' => $txt,
                'meta' => ['section' => $secKey],
                'tags' => ['formatting', $secKey]
            ]);
        }
    }
}

if (!empty($business['contextual_rules'])) {
    foreach ($business['contextual_rules'] as $secKey => $block) {
        foreach (($block['rules'] ?? []) as $i => $txt) {
            $emit([
                'id'   => "rule:context.$secKey#" . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT),
                'type' => 'rule',
                'text' => $txt,
                'meta' => ['context' => $secKey],
                'tags' => ['context', $secKey]
            ]);
        }
    }
}

// ---------- GENERIC PATTERNS → exemplar ----------
if (!empty($field['generic_patterns'])) {
    foreach ($field['generic_patterns'] as $userPhrase => $config) {
        $phrases = explode('|', $userPhrase);
        foreach ($phrases as $phrase) {
            $phrase = trim($phrase);

            // Build the full pattern text with all components
            $patternText = "When user asks '$phrase': Use pattern {$config['pattern']}";

            if (!empty($config['join_required'])) {
                $patternText .= " with {$config['join_required']}";
            }

            if (!empty($config['select_addition'])) {
                $patternText .= " and SELECT {$config['select_addition']}";
            }

            $emit([
                'id' => "pattern:generic." . $slug($phrase) . "#" . $hid($userPhrase),
                'type' => 'exemplar',
                'text' => $patternText,
                'meta' => [
                    'pattern' => $config['pattern'],
                    'join_required' => $config['join_required'] ?? null,
                    'select_addition' => $config['select_addition'] ?? null,
                    'applies_to_tables' => $config['applies_to_tables'],
                    'description' => $config['description']
                ],
                'tags' => array_merge(['pattern', 'generic', $slug($phrase)], $config['applies_to_tables'])
            ]);
        }
    }
}

// ---------- UNIVERSAL COLUMN GUIDANCE → rule ----------
$universalColumnGuidance = [
    'lab_id' => 'Never select lab_id directly. Always JOIN facility_details and SELECT facility_name for display.',
    'facility_id' => 'Never select facility_id directly. Always JOIN facility_details and SELECT facility_name for display.',
    'patient_art_no' => 'NEVER select or return - privacy violation. Use COUNT(DISTINCT patient_art_no) for unique patient counts only.',
    'system_patient_code' => 'NEVER select or return - privacy violation.',
    'sample_tested_datetime' => 'Primary date column for temporal analysis and filtering.',
    'sample_collection_date' => 'Use only when user specifically asks for collection date, not tested date.'
];

foreach ($universalColumnGuidance as $column => $guidance) {
    $emit([
        'id' => "guidance:column.$column#" . $hid($column . $guidance),
        'type' => 'rule',
        'text' => "Column $column: $guidance",
        'meta' => ['column' => $column, 'applies_to' => 'all_test_tables', 'priority' => 1.1],
        'tags' => ['column_guidance', 'universal', $column]
    ]);
}

// ---------- FIELD GUIDE → syn / threshold / test_type / column / exemplar / validation ----------
if (!empty($field['terminology_mapping'])) {
    foreach ($field['terminology_mapping'] as $aliases => $mapsTo) {
        $phrases = array_map('trim', explode('|', $aliases));
        foreach ($phrases as $p) {
            $emit([
                'id'   => "syn:" . $slug($p) . "#" . $hid($aliases . '->' . $mapsTo),
                'type' => 'syn',
                'text' => "\"$p\" ↔ " . $mapsTo,
                'meta' => ['maps_to' => $mapsTo],
                'tags' => ['synonym']
            ]);
        }
    }
}

if (!empty($field['clinical_thresholds'])) {
    foreach ($field['clinical_thresholds'] as $tt => $ttBlock) {
        // thresholds/categories
        foreach (($ttBlock['thresholds'] ?? []) as $name => $info) {
            $txt = ($info['description'] ?? $name) . ' // ' . ($info['condition'] ?? '');
            $emit([
                'id'   => "threshold:$tt.$name#" . $hid($txt),
                'type' => 'threshold',
                'text' => $txt,
                'meta' => ['test_type' => $tt, 'condition' => $info['condition'] ?? null, 'meaning' => $info['clinical_meaning'] ?? null],
                'tags' => ['threshold', $tt]
            ]);
        }
        foreach (($ttBlock['categories'] ?? []) as $name => $cond) {
            $txt = "$name // $cond";
            $emit([
                'id'   => "threshold:$tt.$name#" . $hid($txt),
                'type' => 'threshold',
                'text' => $txt,
                'meta' => ['test_type' => $tt, 'condition' => $cond],
                'tags' => ['threshold', $tt]
            ]);
        }
    }
}

if (!empty($field['test_type_logic'])) {
    foreach ($field['test_type_logic'] as $tt => $cfg) {
        $emit([
            'id'   => "test_type:$tt#" . $hid(json_encode($cfg)),
            'type' => 'test_type',
            'text' => ($cfg['default_description'] ?? $tt) . " (table: {$cfg['table']})",
            'meta' => $cfg,
            'tags' => ['test_type', $tt, $cfg['table'] ?? '']
        ]);
    }
}

if (!empty($field['column_semantics'])) {
    foreach ($field['column_semantics'] as $table => $cols) {
        foreach ($cols as $col => $desc) {
            $sensitive = in_array(strtolower($col), $forbiddenCols, true);
            $emit([
                'id'   => "col:$table.$col#" . $hid($desc),
                'type' => 'column',
                'text' => "$table.$col: $desc",
                'meta' => ['table' => $table, 'name' => $col, 'sensitive' => $sensitive],
                'tags' => array_merge(['column', $table, $col], $sensitive ? ['sensitive'] : []),
            ]);
        }
    }
}

if (!empty($field['query_patterns'])) {
    foreach ($field['query_patterns'] as $key => $pat) {
        $text = ($pat['description'] ?? $key) . ' // ' . ($pat['pattern'] ?? '');
        $emit([
            'id'   => "exemplar:$key#" . $hid($text . '|' . ($pat['example'] ?? '')),
            'type' => 'exemplar',
            'text' => $text,
            'meta' => ['pattern' => $pat['pattern'] ?? null, 'example' => $pat['example'] ?? null],
            'tags' => ['exemplar', $key]
        ]);
    }
}

if (!empty($field['field_validation'])) {
    $fv = $field['field_validation'];
    foreach (($fv['required_joins'] ?? []) as $k => $v) {
        $emit([
            'id'   => "validation:required_join#" . $slug($k),
            'type' => 'validation',
            'text' => "Required join for $k: $v",
            'meta' => ['key' => $k, 'rule' => $v],
            'tags' => ['validation', 'join']
        ]);
    }
    foreach (($fv['recommended_filters'] ?? []) as $k => $sub) {
        foreach ($sub as $rKey => $expr) {
            $emit([
                'id'   => "validation:recommended_filter#" . $slug("$k-$rKey"),
                'type' => 'validation',
                'text' => "Recommended filter ($k.$rKey): $expr",
                'meta' => ['category' => $k, 'key' => $rKey, 'expr' => $expr],
                'tags' => ['validation', 'filter', $k]
            ]);
        }
    }
}


// ---------- SCHEMA → table / column / relationship ----------
if (!empty($schema) && !empty($schema['tables']) && is_array($schema['tables'])) {

    // Tables + Columns (v2 format)
    foreach ($schema['tables'] as $tableName => $tinfo) {
        // Table
        $emit([
            'id'   => "table:$tableName#" . $hid($tableName),
            'type' => 'table',
            'text' => "$tableName (" . ($tinfo['type'] ?? 'base table') . ")",
            'meta' => ['table' => $tableName, 'type' => ($tinfo['type'] ?? 'base table')],
            'tags' => ['table', $tableName]
        ]);

        // Columns
        foreach (($tinfo['columns'] ?? []) as $col) {
            $colName  = $col['name'] ?? '';
            if ($colName === '') continue;

            $sqlType  = $col['type'] ?? '';
            $nullable = (bool)($col['nullable'] ?? false);
            $key      = (string)($col['key'] ?? '');
            $sensitive = in_array(strtolower($colName), $forbiddenCols, true);

            $emit([
                'id'   => "col:$tableName.$colName#" . $hid("$tableName.$colName"),
                'type' => 'column',
                'text' => "$tableName.$colName ($sqlType, " . ($nullable ? 'NULL' : 'NOT NULL') . ($key ? ", $key" : "") . ")",
                'meta' => [
                    'table' => $tableName,
                    'name'  => $colName,
                    'sql_type' => $sqlType,
                    'nullable' => $nullable,
                    'key' => $key,
                    'sensitive' => $sensitive
                ],
                'tags' => array_values(array_filter([
                    'column',
                    $tableName,
                    $colName,
                    $key ?: null,
                    $sensitive ? 'sensitive' : null
                ]))
            ]);
        }
    }

    // Relationships
    foreach (($schema['relationships'] ?? []) as $rel) {
        $fromT = $rel['from_table']   ?? '';
        $fromC = $rel['from_column']  ?? '';
        $toT   = $rel['to_table']     ?? '';
        $toC   = $rel['to_column']    ?? '';
        if (!$fromT || !$fromC || !$toT || !$toC) continue;

        $emit([
            'id'   => "relationship:$fromT.$fromC->$toT.$toC#" . $hid("$fromT.$fromC->$toT.$toC"),
            'type' => 'relationship',
            'text' => "$fromT.$fromC -> $toT.$toC",
            'meta' => [
                'from_table' => $fromT,
                'from_column' => $fromC,
                'to_table'   => $toT,
                'to_column'   => $toC
            ],
            'tags' => ['relationship', $fromT, $toT]
        ]);
    }
}


fclose($fp);
echo "Wrote $emitCount snippets to $outFile\n";
