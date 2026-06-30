<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

$mainShopID = $_GET['mainShopID'] ?? null;

if ($mainShopID === null || $mainShopID === '') {
    http_response_code(400);
    echo json_encode([
        "code" => 400,
        "message" => "missing mainShopID",
        "data" => null
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT *
        FROM sub_shops
        WHERE mainShopID = ?
        ORDER BY shopID ASC
    ");
    $stmt->execute([$mainShopID]);

    $shops = $stmt->fetchAll();

    foreach ($shops as &$shop) {
        $shop["shopID"] = intval($shop["shopID"]);
        $shop["mainShopID"] = intval($shop["mainShopID"]);
        $shop["accountID"] = intval($shop["accountID"]);

        if (isset($shop["shopAddress"])) {
            $decoded = json_decode($shop["shopAddress"], true);
            $shop["shopAddress"] = is_array($decoded) ? $decoded : [];
        }

        if (isset($shop["productsList"])) {
            $decoded = json_decode($shop["productsList"], true);
            $shop["productsList"] = is_array($decoded) ? $decoded : [];
        }

        if (isset($shop["saleList"])) {
            $decoded = json_decode($shop["saleList"], true);
            $shop["saleList"] = is_array($decoded) ? $decoded : [];
        }
    }

    echo json_encode([
        "code" => 200,
        "message" => "success",
        "data" => $shops
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        "code" => 500,
        "message" => "database query failed",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}