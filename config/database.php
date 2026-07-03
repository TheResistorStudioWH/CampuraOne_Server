<?php

$host = "127.0.0.1";
$dbname = "campura_one";
$username = "campura_one";
$password = "070817Linux";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "code" => 500,
        "message" => "database connection failed"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}