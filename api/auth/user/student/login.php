<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../includes/response.php';
require_once __DIR__ . '/../../../../includes/auth/core_login.php';
require_once __DIR__ . '/../../../../includes/auth/jwt.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(405, 'Method Not Allowed', null, 405);
}

$pdo = require __DIR__ . '/../../../../config/database.php';

$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput ?: '', true);

$userName = trim((string) ($jsonInput['userName'] ?? $_POST['userName'] ?? ''));
$password = (string) ($jsonInput['password'] ?? $_POST['password'] ?? '');

if ($userName === '' || $password === '') {
    respondJson(400, 'userName and password are required', null, 400);
}

try {
    $data = coreLogin($pdo, $userName, $password);

    // Student-only guard
    if (empty($data['studentID'])) {
        respondJson(403, 'Current account is not a student account', null, 403);
    }

    $payload = [
        'userID' => $data['userID'],
        'studentID' => $data['studentID'],
        'userName' => $data['userName']
    ];

    $token = generateJWT($payload);

    respondJson(200, 'success', [
        'token' => $token,
        'user' => $data
    ]);

} catch (Exception $e) {
    // Authentication failure
    if ($e->getMessage() === 'User not found' || $e->getMessage() === 'Invalid password') {
        respondJson(401, 'Invalid username or password', null, 401);
    }

    respondJson(500, 'Login failed: ' . $e->getMessage(), null, 500);
}