<?php
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    "code" => 200,
    "message" => "success",
    "data" => [
        "title" => "Campura One API 已启动",
        "status" => "ok"
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);