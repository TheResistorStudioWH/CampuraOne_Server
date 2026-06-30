<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

$saleID = $_GET['saleID'] ?? null;
$type = $_GET['type'] ?? null;
$onlyActive = $_GET['onlyActive'] ?? null;

function send_json($httpCode, $code, $message, $data = null) {
    http_response_code($httpCode);

    echo json_encode([
        "code" => $code,
        "message" => $message,
        "data" => $data
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    exit;
}

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
            send_json(400, 400, "invalid type, only L or S is allowed");
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

    send_json(200, 200, "success", $advertisements);

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