<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

$userID = $_GET['userID'] ?? null;

function send_json($httpCode, $code, $message, $data = null) {
    http_response_code($httpCode);

    echo json_encode([
        "code" => $code,
        "message" => $message,
        "data" => $data
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    exit;
}

function decode_shop_ids($rawValue) {
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
    send_json(400, 400, "missing userID");
}

try {
    $sql = "
        SELECT
            userID,
            userName,
            userImg,
            passwordHash,
            shopIDs,
            studentID,
            DATE_FORMAT(createdAt, '%Y-%m-%dT%H:%i:%sZ') AS createdAt,
            DATE_FORMAT(updatedAt, '%Y-%m-%dT%H:%i:%sZ') AS updatedAt
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
        send_json(404, 404, "user not found");
    }

    send_json(200, 200, "success", [
        "userID" => intval($user["userID"]),
        "userName" => $user["userName"],
        "userImg" => $user["userImg"],
        "passwordHash" => $user["passwordHash"],
        "shopIDs" => decode_shop_ids($user["shopIDs"]),
        "studentID" => $user["studentID"] === null ? null : intval($user["studentID"]),
        "createdAt" => $user["createdAt"],
        "updatedAt" => $user["updatedAt"]
    ]);

} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        "code" => 500,
        "message" => "database query failed",
        "data" => null,
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    exit;
}