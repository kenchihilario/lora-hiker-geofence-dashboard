<?php
function config(): array {
    static $c = null;
    if ($c === null) {
        $file = __DIR__ . '/../config.php';
        if (!file_exists($file)) {
            die('Missing config.php. Copy config.example.php to config.php first.');
        }
        $c = require $file;
    }
    return $c;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = config();
        $pdo = new PDO(
            "mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4",
            $c['db_user'], $c['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}
