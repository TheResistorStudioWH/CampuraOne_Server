<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

function response_json($code, $message, $data = null) {
    echo json_encode([
        "code" => $code,
        "message" => $message,
        "data" => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response_json(405, "method not allowed");
}

$schoolID = isset($_GET['schoolID']) ? intval($_GET['schoolID']) : 0;

if ($schoolID <= 0) {
    response_json(400, "missing or invalid schoolID");
}

try {
    /*
        这里假设你的 database.php 里已经创建了 $pdo
        如果你的变量名不是 $pdo，把下面的 $pdo 改成你实际的连接变量名。
    */

    $sql = "
        SELECT
            schoolID,
            schoolName,
            schoolLogo,
            schoolAddress,
            schoolCalendar,
            areaIDs,
            compoundIDs,
            createdAt,
            updatedAt
        FROM schools
        WHERE schoolID = :schoolID
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':schoolID', $schoolID, PDO::PARAM_INT);
    $stmt->execute();

    $school = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$school) {
        response_json(404, "school not found");
    }

    $data = [
        "schoolID" => intval($school["schoolID"]),
        "schoolName" => $school["schoolName"],
        "schoolLogo" => $school["schoolLogo"],
        "schoolAddress" => json_decode($school["schoolAddress"], true),
        "schoolCalendar" => $school["schoolCalendar"],
        "areaIDs" => json_decode($school["areaIDs"], true),
        "compoundIDs" => json_decode($school["compoundIDs"], true),
        "createdAt" => $school["createdAt"],
        "updatedAt" => $school["updatedAt"]
    ];

    response_json(200, "success", $data);

} catch (Throwable $e) {
    response_json(500, "server error", [
        "error" => $e->getMessage()
    ]);
}