<?php

$dbHost = getenv('LYAIDEU_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('LYAIDEU_DB_NAME') ?: 'lyaideudb';
$dbUser = getenv('LYAIDEU_DB_USER') ?: 'root';
$dbPass = getenv('LYAIDEU_DB_PASS');
if ($dbPass === false) {
    $dbPass = '';
}

$dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    if (defined('LYAIDEU_DB_THROW')) {
        throw $e;
    }

    http_response_code(500);
    exit('Database connection failed. Check db.php credentials and make sure lyaideudb exists.');
}
