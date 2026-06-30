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
            areaID,
            areaName,
            areaAddress,
            schoolID,
            DATE_FORMAT(createdAt, '%Y-%m-%dT%H:%i:%sZ') AS createdAt,
            DATE_FORMAT(updatedAt, '%Y-%m-%dT%H:%i:%sZ') AS updatedAt
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

    echo json_encode([
        "code" => 200,
        "message" => "success",
        "data" => $campuses
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        "code" => 500,
        "message" => "database query failed",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}