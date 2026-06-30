<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

$schoolID = $_GET['schoolID'] ?? null;
$compoundID = $_GET['compoundID'] ?? null;
$departmentID = $_GET['departmentID'] ?? null;
$classID = $_GET['classID'] ?? null;

function send_json($httpCode, $code, $message, $data = null) {
    http_response_code($httpCode);

    echo json_encode([
        "code" => $code,
        "message" => $message,
        "data" => $data
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    exit;
}

if ($schoolID === null || $schoolID === '') {
    send_json(400, 400, "missing schoolID");
}

if ($compoundID === null || $compoundID === '') {
    send_json(400, 400, "missing compoundID");
}

if ($departmentID === null || $departmentID === '') {
    send_json(400, 400, "missing departmentID");
}

if ($classID === null || $classID === '') {
    send_json(400, 400, "missing classID");
}

try {
    $sql = "
        SELECT
            tableID,
            schoolID,
            compoundID,
            departmentID,
            classID,
            content,
            DATE_FORMAT(createdAt, '%Y-%m-%dT%H:%i:%sZ') AS createdAt,
            DATE_FORMAT(updatedAt, '%Y-%m-%dT%H:%i:%sZ') AS updatedAt
        FROM course_tables
        WHERE schoolID = ?
          AND compoundID = ?
          AND departmentID = ?
          AND classID = ?
        ORDER BY updatedAt DESC, tableID DESC
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        intval($schoolID),
        intval($compoundID),
        intval($departmentID),
        intval($classID)
    ]);

    $courseTable = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$courseTable) {
        send_json(404, 404, "course table not found");
    }

    send_json(200, 200, "success", [
        "tableID" => intval($courseTable["tableID"]),
        "schoolID" => intval($courseTable["schoolID"]),
        "compoundID" => intval($courseTable["compoundID"]),
        "departmentID" => intval($courseTable["departmentID"]),
        "classID" => intval($courseTable["classID"]),
        "content" => $courseTable["content"],
        "createdAt" => $courseTable["createdAt"],
        "updatedAt" => $courseTable["updatedAt"]
    ]);

} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        "code" => 500,
        "message" => "database query failed",
        "data" => null,
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    exit;
}