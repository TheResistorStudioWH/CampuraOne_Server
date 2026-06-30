<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

$schoolID = $_GET['schoolID'] ?? null;

if ($schoolID === null) {
    echo json_encode([
        "code" => 400,
        "message" => "missing schoolID",
        "data" => null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM main_shops WHERE schoolID = ?");
$stmt->execute([$schoolID]);
$shops = $stmt->fetchAll();

foreach ($shops as &$shop) {
    $shop["shopID"] = intval($shop["shopID"]);
    $shop["schoolID"] = intval($shop["schoolID"]);
    $shop["areaID"] = $shop["areaID"] === null ? null : intval($shop["areaID"]);
    $shop["accountID"] = intval($shop["accountID"]);

    $shop["shopAddress"] = json_decode($shop["shopAddress"], true);
    $shop["subShopsList"] = json_decode($shop["subShopsList"], true) ?? [];
    $shop["productsList"] = json_decode($shop["productsList"], true) ?? [];
    $shop["adIDs"] = json_decode($shop["adIDs"], true) ?? [];
    $shop["saleIDs"] = json_decode($shop["saleIDs"], true) ?? [];
}

echo json_encode([
    "code" => 200,
    "message" => "success",
    "data" => $shops
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);