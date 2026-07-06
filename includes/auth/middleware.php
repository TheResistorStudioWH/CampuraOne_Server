<?php

declare(strict_types=1);

require_once __DIR__ . '/../response.php';
require_once __DIR__ . '/jwt.php';

/**
 * Campura One Auth Middleware
 * Validates JWT from Authorization header and returns payload
 */
function authMiddleware(): array
{
    $headers = getallheaders();

    if (!isset($headers['Authorization'])) {
        respondJson(401, 'Missing Authorization header', null, 401);
    }

    $authHeader = $headers['Authorization'];

    if (!str_starts_with($authHeader, 'Bearer ')) {
        respondJson(401, 'Invalid Authorization format', null, 401);
    }

    $token = substr($authHeader, 7);

    if ($token === '') {
        respondJson(401, 'Empty token', null, 401);
    }

    try {
        $payload = verifyJWT($token);
        return $payload;
    } catch (Exception $e) {
        respondJson(401, 'Invalid or expired token: ' . $e->getMessage(), null, 401);
    }
}
