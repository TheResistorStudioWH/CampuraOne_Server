<?php

declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/response.php';

$schoolID = $_GET['schoolID'] ?? null;

if ($schoolID === null || $schoolID === '') {
    respondJson(400, 'missing schoolID', null, 400);
}

try {
    $stmt = $pdo->prepare("SELECT * FROM main_shops WHERE schoolID = ?");
    $stmt->execute([$schoolID]);
    $shops = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($shops as &$shop) {
        $shop["shopID"] = intval($shop["shopID"]);
        $shop["schoolID"] = intval($shop["schoolID"]);
        $shop["areaID"] = $shop["areaID"] === null ? null : intval($shop["areaID"]);
        $shop["accountID"] = intval($shop["accountID"]);

        $shop["shopAddress"] = json_decode($shop["shopAddress"] ?? "{}", true);
        $shop["subShopsList"] = json_decode($shop["subShopsList"], true) ?? [];
        $shop["productsList"] = json_decode($shop["productsList"], true) ?? [];
        $shop["adIDs"] = json_decode($shop["adIDs"], true) ?? [];
        $shop["saleIDs"] = json_decode($shop["saleIDs"], true) ?? [];
    }
    unset($shop);

    respondJson(200, 'success', $shops);
} catch (PDOException $e) {
    respondJson(
        500,
        'database query failed',
        ['error' => $e->getMessage()],
        500
    );
}