<?php

declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/response.php';

$schoolID = $_GET['schoolID'] ?? null;

if ($schoolID === null || $schoolID === '') {
    respondJson(400, 'missing schoolID', null, 400);
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
        respondJson(404, 'school not found', null, 404);
    }

    respondJson(200, 'success', [
        "schoolID" => intval($school["schoolID"]),
        "content" => $school["schoolCalendar"],
        "updatedAt" => $school["updatedAt"]
    ]);

} catch (PDOException $e) {
    respondJson(
        500,
        'database query failed',
        ['error' => $e->getMessage()],
        500
    );
}