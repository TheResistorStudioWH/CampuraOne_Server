<?php

declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/request.php';

requireMethod('GET');

$userID = $_GET['userID'] ?? null;

function decodeShopIDs(?string $rawValue): array {
    if ($rawValue === null || $rawValue === '') {
        return [];
    }

    $decoded = json_decode($rawValue, true);

    if (is_array($decoded)) {
        return array_values(array_map('intval', $decoded));
    }

    return [];
}

if ($userID === null || $userID === '') {
    respondJson(400, 'missing userID', null, 400);
}

try {
    $sql = "
        SELECT
            userID,
            userName,
            userImg,
            shopIDs,
            studentID,
            DATE_FORMAT(createdAt, '%Y-%m-%d %H:%i:%s') AS createdAt,
            DATE_FORMAT(updatedAt, '%Y-%m-%d %H:%i:%s') AS updatedAt
        FROM app_users
        WHERE userID = ?
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        intval($userID)
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        respondJson(404, 'user not found', null, 404);
    }

    respondJson(200, 'success', [
        "userID" => intval($user["userID"]),
        "userName" => $user["userName"],
        "userImg" => $user["userImg"],
        "shopIDs" => decodeShopIDs($user["shopIDs"]),
        "studentID" => $user["studentID"] === null ? null : intval($user["studentID"]),
        "createdAt" => $user["createdAt"],
        "updatedAt" => $user["updatedAt"]
    ]);

} catch (PDOException $e) {
    respondJson(
        500,
        'database query failed',
        ['error' => $e->getMessage()],
        500
    );
}