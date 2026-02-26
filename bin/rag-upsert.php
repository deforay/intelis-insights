#!/usr/bin/env php
<?php
declare(strict_types=1);

use GuzzleHttp\Client;

require __DIR__ . '/../vendor/autoload.php';

$ragBase = getenv('RAG_BASE_URL') ?: 'http://127.0.0.1:8089';
$file    = $argv[1] ?? __DIR__ . '/../corpus/snippets.jsonl';
$batchSz = (int)($argv[2] ?? 500);

if (!is_file($file)) {
    fwrite(STDERR, "File not found: $file\n");
    exit(1);
}

$http = new Client(['base_uri' => $ragBase, 'timeout' => 30]);
$batch = [];
$total = 0;

$fh = fopen($file, 'r');
if (!$fh) {
    fwrite(STDERR, "Cannot open $file\n");
    exit(1);
}

while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '') continue;
    $obj = json_decode($line, true);
    if (!is_array($obj) || empty($obj['id']) || empty($obj['text'])) continue;
    // Normalize fields
    $obj['type'] = $obj['type'] ?? 'misc';
    $obj['meta'] = $obj['meta'] ?? [];
    $obj['tags'] = $obj['tags'] ?? [];
    $batch[] = $obj;

    if (count($batch) >= $batchSz) {
        $resp = $http->post('/v1/upsert', ['json' => ['items' => $batch]]);
        echo "Upserted batch, status=" . $resp->getStatusCode() . "\n";
        $total += count($batch);
        $batch = [];
    }
}
fclose($fh);

if ($batch) {
    $resp = $http->post('/v1/upsert', ['json' => ['items' => $batch]]);
    echo "Upserted final batch, status=" . $resp->getStatusCode() . "\n";
    $total += count($batch);
}

echo "Done. Upserted $total snippets to $ragBase\n";
