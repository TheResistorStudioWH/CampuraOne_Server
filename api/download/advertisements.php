<?php

declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/response.php';

$saleID = $_GET['saleID'] ?? null;
$type = $_GET['type'] ?? null;
$onlyActive = $_GET['onlyActive'] ?? null;

try {
    $sql = "
        SELECT
            adID,
            saleID,
            type,
            img,
            DATE_FORMAT(startTime, '%Y-%m-%dT%H:%i:%sZ') AS startTime,
            DATE_FORMAT(endTime, '%Y-%m-%dT%H:%i:%sZ') AS endTime,
            DATE_FORMAT(createdAt, '%Y-%m-%dT%H:%i:%sZ') AS createdAt,
            DATE_FORMAT(updatedAt, '%Y-%m-%dT%H:%i:%sZ') AS updatedAt
        FROM advertisements
        WHERE 1 = 1
    ";

    $params = [];

    if ($saleID !== null && $saleID !== '') {
        $sql .= " AND saleID = ? ";
        $params[] = intval($saleID);
    }

    if ($type !== null && $type !== '') {
        $type = strtoupper(trim($type));

        if ($type !== 'L' && $type !== 'S') {
            respondJson(400, 'invalid type, only L or S is allowed', null, 400);
        }

        $sql .= " AND type = ? ";
        $params[] = $type;
    }

    if ($onlyActive === '1') {
        $sql .= "
            AND startTime <= NOW()
            AND (endTime IS NULL OR endTime >= NOW())
        ";
    }

    $sql .= "
        ORDER BY startTime DESC, adID DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $advertisements = [];

    foreach ($rows as $row) {
        $advertisements[] = [
            "adID" => intval($row["adID"]),
            "saleID" => intval($row["saleID"]),
            "type" => $row["type"],
            "img" => $row["img"],
            "startTime" => $row["startTime"],
            "endTime" => $row["endTime"],
            "createdAt" => $row["createdAt"],
            "updatedAt" => $row["updatedAt"]
        ];
    }

    respondJson(200, 'success', $advertisements);
} catch (PDOException $e) {
    respondJson(
        500,
        'database query failed',
        ['error' => $e->getMessage()],
        500
    );
}
