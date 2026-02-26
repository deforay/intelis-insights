<?php

declare(strict_types=1);

/**
 * Asset versioning helper — appends file modification time for cache busting.
 * Usage: <?= asset('/assets/css/app.css') ?>
 */
if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $publicDir = defined('PUBLIC_DIR') ? PUBLIC_DIR : __DIR__ . '/..';
        $fullPath = $publicDir . $path;
        $version = file_exists($fullPath) ? filemtime($fullPath) : time();
        return $path . '?v=' . $version;
    }
}
