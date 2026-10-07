<?php

$host = getenv('CAMPURA_DB_HOST') ?: "127.0.0.1";
$dbname = getenv('CAMPURA_DB_NAME') ?: "campura_one";
$username = getenv('CAMPURA_DB_USER') ?: "campura_one";
$password = getenv('CAMPURA_DB_PASSWORD');
// Existing deployment must supply credentials through its private environment.
if ($password === false) {
    throw new RuntimeException('CAMPURA_DB_PASSWORD is required');
}
$port = (int)(getenv('CAMPURA_DB_PORT') ?: 3306);

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    $pdo->exec("SET time_zone = '+08:00'");
    return $pdo;
} catch (PDOException $e) {
    if (PHP_SAPI === 'cli') {
        throw new RuntimeException('database connection failed', 0, $e);
    }
    http_response_code(500);
    echo json_encode([
        "code" => 500,
        "message" => "database connection failed",
        "data" => null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
