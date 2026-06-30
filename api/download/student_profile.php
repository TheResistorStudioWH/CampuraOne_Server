<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

$studentID = $_GET['studentID'] ?? null;

function send_json($httpCode, $code, $message, $data = null) {
    http_response_code($httpCode);

    echo json_encode([
        "code" => $code,
        "message" => $message,
        "data" => $data
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    exit;
}

if ($studentID === null || $studentID === '') {
    send_json(400, 400, "missing studentID");
}

try {
    $sql = "
        SELECT
            studentID,
            schoolID,
            studentName,
            gender,
            compoundID,
            departmentID,
            classID,
            DATE_FORMAT(createdAt, '%Y-%m-%dT%H:%i:%sZ') AS createdAt,
            DATE_FORMAT(updatedAt, '%Y-%m-%dT%H:%i:%sZ') AS updatedAt
        FROM students
        WHERE studentID = ?
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        intval($studentID)
    ]);

    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        send_json(404, 404, "student not found");
    }

    send_json(200, 200, "success", [
        "studentID" => intval($student["studentID"]),
        "schoolID" => intval($student["schoolID"]),
        "studentName" => $student["studentName"],
        "gender" => $student["gender"],
        "compoundID" => intval($student["compoundID"]),
        "departmentID" => intval($student["departmentID"]),
        "classID" => intval($student["classID"]),
        "createdAt" => $student["createdAt"],
        "updatedAt" => $student["updatedAt"]
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