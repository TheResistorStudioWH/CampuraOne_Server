<?php

declare(strict_types=1);

/**
 * Core login logic (shared by student/shop/school auth modules)
 *
 * This file MUST NOT handle HTTP requests or JSON responses.
 * It only provides reusable authentication logic.
 */

function coreLogin(PDO $pdo, string $userName, string $password): array
{
    $sql = "
        SELECT
            userID,
            userName,
            userImg,
            passwordHash,
            shopIDs,
            studentID,
            createdAt,
            updatedAt
        FROM app_users
        WHERE userName = :userName
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['userName' => $userName]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('User not found');
    }

    if (!password_verify($password, $user['passwordHash'])) {
        throw new Exception('Invalid password');
    }

    // Decode shopIDs safely
    $shopIDs = [];
    if (!empty($user['shopIDs'])) {
        $decoded = json_decode((string)$user['shopIDs'], true);
        if (is_array($decoded)) {
            $shopIDs = array_map('intval', $decoded);
        }
    }

    return [
        'userID' => (int)$user['userID'],
        'userName' => $user['userName'],
        'userImg' => $user['userImg'],
        'shopIDs' => $shopIDs,
        'studentID' => $user['studentID'] !== null ? (int)$user['studentID'] : null,
        'createdAt' => $user['createdAt'],
        'updatedAt' => $user['updatedAt'],
    ];
}