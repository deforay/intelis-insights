#!/usr/bin/env php
<?php

declare(strict_types=1);

// Load .env if present
if (is_file(__DIR__ . '/../.env')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}
$app = require __DIR__ . '/../config/app.php';
$db  = require __DIR__ . '/../config/db.php';
// Use the query DB (vlsm) for schema export
$qdb = $db['query'];
$pdo = new PDO($qdb['dsn'], $qdb['user'], $qdb['password'], $qdb['options']);
$dbName = $app['db_name'] ?? 'vlsm';

// Configuration for reference tables
$explicitReferenceTables = [
    // Add specific table names you want to include data for
    'facility_details',
    'facility_type',
    's_available_country_forms',
    'instruments',
    'instrument_machines',
    'geographical_divisions',
    // Add more as needed
];

// Get all tables/views
$tablesStmt = $pdo->prepare("SELECT TABLE_NAME, TABLE_TYPE
                                        FROM INFORMATION_SCHEMA.TABLES
                                        WHERE TABLE_SCHEMA = :db
                                        AND TABLE_TYPE IN ('BASE TABLE','VIEW')
                                        ORDER BY TABLE_NAME");
$tablesStmt->execute([':db' => $dbName]);
$allTables = $tablesStmt->fetchAll(PDO::FETCH_ASSOC);

// Build schema structure
$tables = [];
$relationships = [];
$referenceData = [];

// Column query with more metadata
$colsStmt = $pdo->prepare("SELECT
    c.COLUMN_NAME,
    c.DATA_TYPE,
    c.IS_NULLABLE,
    c.COLUMN_KEY,
    c.COLUMN_DEFAULT,
    c.CHARACTER_MAXIMUM_LENGTH,
    c.NUMERIC_PRECISION,
    c.NUMERIC_SCALE,
    c.COLUMN_COMMENT,
    k.REFERENCED_TABLE_NAME,
    k.REFERENCED_COLUMN_NAME,
    k.CONSTRAINT_NAME
    FROM INFORMATION_SCHEMA.COLUMNS c
    LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
    ON c.TABLE_SCHEMA = k.TABLE_SCHEMA
    AND c.TABLE_NAME = k.TABLE_NAME
    AND c.COLUMN_NAME = k.COLUMN_NAME
    AND k.REFERENCED_TABLE_NAME IS NOT NULL
    WHERE c.TABLE_SCHEMA = :db AND c.TABLE_NAME = :t
    ORDER BY c.ORDINAL_POSITION
");

foreach ($allTables as $table) {
    $tableName = $table['TABLE_NAME'];
    $tableType = $table['TABLE_TYPE'];

    echo "Processing table: {$tableName}\n";

    $colsStmt->execute([':db' => $dbName, ':t' => $tableName]);
    $columns = [];

    while ($r = $colsStmt->fetch(PDO::FETCH_ASSOC)) {
        $columnInfo = [
            'name' => $r['COLUMN_NAME'],
            'type' => $r['DATA_TYPE'],
            'nullable' => $r['IS_NULLABLE'] === 'YES',
            'key' => $r['COLUMN_KEY'],
        ];

        // Add optional metadata
        if ($r['COLUMN_DEFAULT'] !== null) {
            $columnInfo['default'] = $r['COLUMN_DEFAULT'];
        }
        if ($r['CHARACTER_MAXIMUM_LENGTH']) {
            $columnInfo['max_length'] = (int)$r['CHARACTER_MAXIMUM_LENGTH'];
        }
        if ($r['NUMERIC_PRECISION']) {
            $columnInfo['precision'] = (int)$r['NUMERIC_PRECISION'];
        }
        if ($r['NUMERIC_SCALE']) {
            $columnInfo['scale'] = (int)$r['NUMERIC_SCALE'];
        }
        if ($r['COLUMN_COMMENT']) {
            $columnInfo['comment'] = $r['COLUMN_COMMENT'];
        }

        $columns[] = $columnInfo;

        // Capture foreign key relationships
        if ($r['REFERENCED_TABLE_NAME']) {
            $relationships[] = [
                'from_table' => $tableName,
                'from_column' => $r['COLUMN_NAME'],
                'to_table' => $r['REFERENCED_TABLE_NAME'],
                'to_column' => $r['REFERENCED_COLUMN_NAME'],
                'constraint_name' => $r['CONSTRAINT_NAME']
            ];
        }
    }

    $tables[$tableName] = [
        'type' => strtolower($tableType),
        'columns' => $columns
    ];

    // Check if this is a reference table and should include data
    $isReferenceTable = in_array($tableName, $explicitReferenceTables) ||
        str_starts_with($tableName, 'r_');

    if ($isReferenceTable) {
        echo "  -> Including reference data for {$tableName}\n";
        $referenceData[$tableName] = getReferenceTableData($pdo, $tableName);
    }
}

// Build the schema
$schema = [
    'version' => '2.0',
    'generated_at' => date('Y-m-d H:i:s'),
    'database' => $dbName,
    'tables' => $tables,
    'relationships' => $relationships,
    'reference_data' => $referenceData
];

@mkdir(dirname($app['schema_path']), 0777, true);
file_put_contents($app['schema_path'], json_encode($schema, JSON_PRETTY_PRINT));
echo "Wrote schema to {$app['schema_path']}\n";
echo "Tables processed: " . count($tables) . "\n";
echo "Relationships found: " . count($relationships) . "\n";
echo "Reference tables with data: " . count($referenceData) . "\n";

/**
 * Get reference table data with intelligent limiting
 */
function getReferenceTableData(PDO $pdo, string $tableName): array
{
    try {
        // First, check the table size
        $countStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM `{$tableName}`");
        $countStmt->execute();
        $count = (int)$countStmt->fetchColumn();

        // Limit reference data to reasonable size (adjust as needed)
        $limit = $count > 1000 ? 100 : ($count > 100 ? 50 : $count);

        // Get sample data with intelligent column selection
        $dataStmt = $pdo->prepare("SELECT * FROM `{$tableName}` LIMIT {$limit}");
        $dataStmt->execute();
        $data = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'total_rows' => $count,
            'sample_rows' => $limit,
            'data' => $data
        ];
    } catch (Exception $e) {
        echo "  -> Warning: Could not fetch data for {$tableName}: " . $e->getMessage() . "\n";
        return [
            'total_rows' => 0,
            'sample_rows' => 0,
            'data' => [],
            'error' => $e->getMessage()
        ];
    }
}
