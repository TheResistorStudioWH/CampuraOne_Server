<?php

declare(strict_types=1);

/**
 * Respond with JSON data
 *
 * @param int $code
 * @param string $message
 * @param mixed $data
 * @param int $httpStatus
 * @return never
 */
function respondJson(
    int $code,
    string $message,
    mixed $data = null,
    int $httpStatus = 200
): never {
    http_response_code($httpStatus);

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        [
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ],
        JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
    );

    exit;
}
