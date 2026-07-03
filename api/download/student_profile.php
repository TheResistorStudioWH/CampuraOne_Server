<?php

declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/response.php';

$studentID = $_GET['studentID'] ?? null;

if ($studentID === null || $studentID === '') {
    respondJson(400, 'missing studentID', null, 400);
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
        respondJson(404, 'student not found', null, 404);
    }

    respondJson(200, 'success', [
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
    respondJson(
        500,
        'database query failed',
        ['error' => $e->getMessage()],
        500
    );
}