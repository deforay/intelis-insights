<?php
return [
    'dsn'      => 'mysql:host=127.0.0.1;dbname=vlsm;charset=utf8mb4',
    'user'     => '',
    'password' => '',
    // sensible defaults
    'options'  => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ],
];
