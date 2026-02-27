<?php

declare(strict_types=1);

namespace App\Bootstrap;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;

/**
 * Boots Eloquent ORM for standalone use outside Laravel.
 *
 * Usage:
 *   Database::boot($dbConfig);
 *   $rows = Capsule::table('vl_agg_volume_status')->where('period_type', 'month')->get();
 */
final class Database
{
    private static bool $booted = false;

    /**
     * Boot Eloquent with one or two DB connections.
     *
     * @param array $cfg      Default (app) connection — intelis_insights.
     * @param array $queryCfg Optional second connection — vlsm (LLM-generated SQL).
     */
    public static function boot(array $cfg = [], array $queryCfg = []): Capsule
    {
        if (self::$booted) {
            return Capsule::getInstance();
        }

        $capsule = new Capsule();

        // Default connection — intelis_insights (reports, chat sessions, models)
        $capsule->addConnection([
            'driver'    => 'mysql',
            'host'      => $cfg['host'] ?? (getenv('DB_HOST') ?: '127.0.0.1'),
            'port'      => $cfg['port'] ?? (getenv('DB_PORT') ?: '3306'),
            'database'  => $cfg['database'] ?? (getenv('DB_NAME') ?: 'intelis_insights'),
            'username'  => $cfg['username'] ?? (getenv('DB_USER') ?: 'root'),
            'password'  => $cfg['password'] ?? (getenv('DB_PASSWORD') ?: ''),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
        ]);

        // Query connection — vlsm (LLM-generated SQL execution)
        if (!empty($queryCfg)) {
            $capsule->addConnection([
                'driver'    => 'mysql',
                'host'      => $queryCfg['host'] ?? (getenv('QUERY_DB_HOST') ?: ($cfg['host'] ?? (getenv('DB_HOST') ?: '127.0.0.1'))),
                'port'      => $queryCfg['port'] ?? $cfg['port'] ?? (getenv('DB_PORT') ?: '3306'),
                'database'  => $queryCfg['database'] ?? (getenv('QUERY_DB_NAME') ?: 'vlsm'),
                'username'  => $queryCfg['username'] ?? $cfg['username'] ?? (getenv('DB_USER') ?: 'root'),
                'password'  => $queryCfg['password'] ?? $cfg['password'] ?? (getenv('DB_PASSWORD') ?: ''),
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => '',
            ], 'query');
        }

        $capsule->setEventDispatcher(new Dispatcher());
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$booted = true;
        return $capsule;
    }
}
