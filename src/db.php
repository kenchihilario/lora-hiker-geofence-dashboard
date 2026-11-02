<?php
function config(): array {
    static $c = null;
    if ($c === null) {
        $file = __DIR__ . '/../config.php';
        if (!is_file($file)) throw new RuntimeException('Missing config.php. Copy config.example.php and configure MariaDB.');
        $c = require $file;
    }
    return $c;
}
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = config();
        $pdo = new PDO("mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4", $c['db_user'], $c['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
