<?php

declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/response.php';

$mainShopID = $_GET['mainShopID'] ?? null;

if ($mainShopID === null || $mainShopID === '') {
    respondJson(400, 'missing mainShopID', null, 400);
}

try {
    $stmt = $pdo->prepare("
        SELECT *
        FROM sub_shops
        WHERE mainShopID = ?
        ORDER BY shopID ASC
    ");
    $stmt->execute([$mainShopID]);

    $shops = $stmt->fetchAll(PDO::FETCH_ASSOC);

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