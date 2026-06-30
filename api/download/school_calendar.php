<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

$schoolID = $_GET['schoolID'] ?? null;

if ($schoolID === null || $schoolID === '') {
    http_response_code(400);
    echo json_encode([
        "code" => 400,
        "message" => "missing schoolID",
        "data" => null
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

try {
    $sql = "
        SELECT
            schoolID,
            schoolCalendar,
            DATE_FORMAT(updatedAt, '%Y-%m-%dT%H:%i:%sZ') AS updatedAt
        FROM schools
        WHERE schoolID = ?
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$schoolID]);

    $school = $stmt->fetch();

    if (!$school) {
        http_response_code(404);
        echo json_encode([
            "code" => 404,
            "message" => "school not found",
            "data" => null
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    echo json_encode([
        "code" => 200,
        "message" => "success",
        "data" => [
            "schoolID" => intval($school["schoolID"]),
            "content" => $school["schoolCalendar"],
            "updatedAt" => $school["updatedAt"]
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        "code" => 500,
        "message" => "database query failed",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}