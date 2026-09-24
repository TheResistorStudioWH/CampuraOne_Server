<?php

declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/ics_storage.php';

$schoolID = $_GET['schoolID'] ?? null;
$compoundID = $_GET['compoundID'] ?? null;
$departmentID = $_GET['departmentID'] ?? null;
$classID = $_GET['classID'] ?? null;

if ($schoolID === null || $schoolID === '') {
    respondJson(400, 'missing schoolID', null, 400);
}

if ($compoundID === null || $compoundID === '') {
    respondJson(400, 'missing compoundID', null, 400);
}

if ($departmentID === null || $departmentID === '') {
    respondJson(400, 'missing departmentID', null, 400);
}

if ($classID === null || $classID === '') {
    respondJson(400, 'missing classID', null, 400);
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
           DATE_FORMAT(createdAt, '%Y-%m-%d %H:%i:%s') AS createdAt,
            DATE_FORMAT(updatedAt, '%Y-%m-%d %H:%i:%s') AS updatedAt
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
        respondJson(404, 'course table not found', null, 404);
    }

    $calendar = readCourseICS((string)$courseTable['content']);
    if (($_GET['download'] ?? '') === '1') {
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="course-table-' . intval($courseTable['tableID']) . '.ics"');
        header('X-Content-Type-Options: nosniff');
        echo $calendar['content'];
        exit;
    }
    respondJson(200, 'success', [
        "tableID" => intval($courseTable["tableID"]),
        "schoolID" => intval($courseTable["schoolID"]),
        "compoundID" => intval($courseTable["compoundID"]),
        "departmentID" => intval($courseTable["departmentID"]),
        "classID" => intval($courseTable["classID"]),
        "content" => $calendar['content'],
        "icsPath" => $calendar['icsPath'],
        "createdAt" => $courseTable["createdAt"],
        "updatedAt" => $courseTable["updatedAt"]
    ]);

} catch (Throwable $e) {
    respondJson(
        500,
        'course calendar unavailable',
        null,
        500
    );
}
