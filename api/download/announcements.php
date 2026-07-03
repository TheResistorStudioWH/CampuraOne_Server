<?php

declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/response.php';

$schoolID = $_GET['schoolID'] ?? null;
$type = $_GET['type'] ?? null;

$areaID = $_GET['areaID'] ?? null;
$departmentID = $_GET['departmentID'] ?? null;
$classID = $_GET['classID'] ?? null;
$studentID = $_GET['studentID'] ?? null;

if ($schoolID === null || $schoolID === '') {
    respondJson(400, 'missing schoolID', null, 400);
}

try {
    $sql = "
        SELECT
            a.announceID,
            a.schoolID,
            a.content,
            a.type,
            DATE_FORMAT(a.startTime, '%Y-%m-%dT%H:%i:%sZ') AS startTime,
            DATE_FORMAT(a.endTime, '%Y-%m-%dT%H:%i:%sZ') AS endTime,
            DATE_FORMAT(a.createdAt, '%Y-%m-%dT%H:%i:%sZ') AS createdAt,
            DATE_FORMAT(a.updatedAt, '%Y-%m-%dT%H:%i:%sZ') AS updatedAt,
            t.targetJSON
        FROM announcements a
        LEFT JOIN announcement_targets t
            ON a.announceID = t.announceID
        WHERE a.schoolID = ?
          AND (a.startTime IS NULL OR a.startTime <= NOW())
          AND (a.endTime IS NULL OR a.endTime >= NOW())
    ";

    $params = [$schoolID];

    if ($type !== null && $type !== '') {
        $sql .= " AND a.type = ?";
        $params[] = $type;
    }

    $sql .= "
        ORDER BY
            CASE
                WHEN a.type = 2 THEN 0
                WHEN a.type = 1 THEN 1
                ELSE 2
            END,
            a.createdAt DESC,
            a.announceID DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $announcements = $stmt->fetchAll();

    $visibleAnnouncements = [];

    foreach ($announcements as &$item) {
        $item["announceID"] = intval($item["announceID"]);
        $item["schoolID"] = intval($item["schoolID"]);
        $item["type"] = intval($item["type"]);

        if (
            isset($item["targetJSON"]) &&
            $item["targetJSON"] !== null &&
            $item["targetJSON"] !== ''
        ) {
            $decodedTarget = json_decode($item["targetJSON"], true);

            $item["target"] = is_array($decodedTarget)
                ? normalizeAnnouncementTarget($decodedTarget)
                : null;
        } else {
            $item["target"] = null;
        }

        unset($item["targetJSON"]);

        if (isAnnouncementVisible(
            $item["target"],
            $areaID,
            $departmentID,
            $classID,
            $studentID
        )) {
            $visibleAnnouncements[] = $item;
        }
    }

    respondJson(200, 'success', $visibleAnnouncements);

} catch (PDOException $e) {
    respondJson(
        500,
        'database query failed',
        ['error' => $e->getMessage()],
        500
    );
}

function normalizeAnnouncementTarget(array $target): array
{
    return [
        "allSchool" => isset($target["allSchool"]) ? boolval($target["allSchool"]) : false,
        "areaIDs" => isset($target["areaIDs"]) && is_array($target["areaIDs"]) ? array_map('intval', $target["areaIDs"]) : [],
        "departmentIDs" => isset($target["departmentIDs"]) && is_array($target["departmentIDs"]) ? array_map('intval', $target["departmentIDs"]) : [],
        "classIDs" => isset($target["classIDs"]) && is_array($target["classIDs"]) ? array_map('intval', $target["classIDs"]) : [],
        "studentIDs" => isset($target["studentIDs"]) && is_array($target["studentIDs"]) ? array_map('intval', $target["studentIDs"]) : []
    ];
}

function isAnnouncementVisible(
    ?array $target,
    $areaID,
    $departmentID,
    $classID,
    $studentID
): bool {
    // 如果没有 target，暂时认为可见。
    // 这样可以兼容旧数据，避免旧通知突然全部消失。
    if ($target === null) {
        return true;
    }

    if ($target["allSchool"] === true) {
        return true;
    }

    if ($areaID !== null && $areaID !== '' && in_array(intval($areaID), $target["areaIDs"], true)) {
        return true;
    }

    if ($departmentID !== null && $departmentID !== '' && in_array(intval($departmentID), $target["departmentIDs"], true)) {
        return true;
    }

    if ($classID !== null && $classID !== '' && in_array(intval($classID), $target["classIDs"], true)) {
        return true;
    }

    if ($studentID !== null && $studentID !== '' && in_array(intval($studentID), $target["studentIDs"], true)) {
        return true;
    }

    return false;
}