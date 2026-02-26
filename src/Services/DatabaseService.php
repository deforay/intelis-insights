<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class DatabaseService
{
    private PDO $pdo;

    public function __construct(array $dbConfig)
    {
        $this->pdo = new PDO(
            $dbConfig['dsn'],
            $dbConfig['user'],
            $dbConfig['password'],
            $dbConfig['options']
        );
    }

    /**
     * Execute a parameterized query against the aggregate tables.
     *
     * @return array{columns: list<string>, rows: list<array>, count: int, execution_time_ms: float}
     */
    public function execute(string $sql, array $params = []): array
    {
        $start = microtime(true);

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $columns = $rows ? array_keys($rows[0]) : [];

            return [
                'columns' => $columns,
                'rows' => $rows,
                'count' => count($rows),
                'execution_time_ms' => round((microtime(true) - $start) * 1000, 1),
            ];
        } catch (\Throwable $e) {
            throw new RuntimeException('Database error: ' . $e->getMessage());
        }
    }

    /**
     * Insert a row and return the last insert ID.
     */
    public function insert(string $table, array $data): string
    {
        $cols = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', $placeholders)
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        return $this->pdo->lastInsertId();
    }

    public function testConnection(): bool
    {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
