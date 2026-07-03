<?php

declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/request.php';

requireMethod('GET');

$schoolID = isset($_GET['schoolID']) ? intval($_GET['schoolID']) : 0;

if ($schoolID <= 0) {
    respondJson(400, 'missing or invalid schoolID', null, 400);
}

try {
    $sql = "
        SELECT
            schoolID,
            schoolName,
            schoolLogo,
            schoolAddress,
            schoolCalendar,
            areaIDs,
            compoundIDs,
            createdAt,
            updatedAt
        FROM schools
        WHERE schoolID = :schoolID
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':schoolID', $schoolID, PDO::PARAM_INT);
    $stmt->execute();

    $school = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$school) {
        respondJson(404, 'school not found', null, 404);
    }

    $data = [
        "schoolID" => intval($school["schoolID"]),
        "schoolName" => $school["schoolName"],
        "schoolLogo" => $school["schoolLogo"],
        "schoolAddress" => json_decode($school["schoolAddress"], true),
        "schoolCalendar" => $school["schoolCalendar"],
        "areaIDs" => json_decode($school["areaIDs"], true),
        "compoundIDs" => json_decode($school["compoundIDs"], true),
        "createdAt" => $school["createdAt"],
        "updatedAt" => $school["updatedAt"]
    ];

    respondJson(200, 'success', $data);

} catch (Throwable $e) {
    respondJson(
        500,
        'server error',
        ['error' => $e->getMessage()],
        500
    );
}