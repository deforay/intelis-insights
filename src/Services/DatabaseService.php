<?php
// src/Services/DatabaseService.php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

class DatabaseService
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

        // Set timezone and execution limits
        $this->pdo->exec("SET SESSION time_zone = '+05:30'");
        $this->pdo->exec("SET SESSION MAX_EXECUTION_TIME = 2000");
    }

    public function executeQuery(string $sql): array
    {
        $startTime = microtime(true);

        try {
            $statement = $this->pdo->query($sql);
            $rows = $statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];

            return [
                'rows' => $rows,
                'count' => count($rows),
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000)
            ];
        } catch (\Throwable $e) {
            throw new RuntimeException("Database error: " . $e->getMessage());
        }
    }

    public function testConnection(): bool
    {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
