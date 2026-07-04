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
            areaID,
            areaName,
            areaAddress,
            schoolID,
            DATE_FORMAT(createdAt, '%Y-%m-%d %H:%i:%s') AS createdAt,
            DATE_FORMAT(updatedAt, '%Y-%m-%d %H:%i:%s') AS updatedAt
        FROM campus
        WHERE schoolID = ?
        ORDER BY areaID ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$schoolID]);

    $campuses = $stmt->fetchAll();

    foreach ($campuses as &$item) {
        $item["areaID"] = intval($item["areaID"]);
        $item["schoolID"] = intval($item["schoolID"]);

        if (isset($item["areaAddress"]) && $item["areaAddress"] !== null && $item["areaAddress"] !== '') {
            $decodedAddress = json_decode($item["areaAddress"], true);
            $item["areaAddress"] = is_array($decodedAddress) ? $decodedAddress : [$item["areaAddress"]];
        } else {
            $item["areaAddress"] = null;
        }
    }

    respondJson(200, 'success', $campuses);

} catch (PDOException $e) {
    respondJson(
        500,
        'database query failed',
        ['error' => $e->getMessage()],
        500
    );
}