<?php

declare(strict_types=1);

/**
 * Campura One JWT Utility (HS256)
 * Lightweight implementation without external dependencies
 */

const JWT_SECRET = 'campura_one_dev_secret_change_me';
const JWT_ALG = 'HS256';

function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string
{
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

function generateJWT(array $payload, int $expSeconds = 604800): string
{
    $header = [
        'alg' => JWT_ALG,
        'typ' => 'JWT'
    ];

    $now = time();

    $payload['iat'] = $now;
    $payload['exp'] = $now + $expSeconds;

    $headerEncoded = base64UrlEncode(json_encode($header, JSON_UNESCAPED_UNICODE));
    $payloadEncoded = base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE));

    $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, JWT_SECRET, true);
    $signatureEncoded = base64UrlEncode($signature);

    return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
}

function verifyJWT(string $jwt): array
{
    $parts = explode('.', $jwt);

    if (count($parts) !== 3) {
        throw new Exception('Invalid token structure');
    }

    [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

    $expectedSignature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, JWT_SECRET, true);
    $expectedSignatureEncoded = base64UrlEncode($expectedSignature);

    if (!hash_equals($expectedSignatureEncoded, $signatureEncoded)) {
        throw new Exception('Invalid token signature');
    }

    $payload = json_decode(base64UrlDecode($payloadEncoded), true);

    if (!$payload) {
        throw new Exception('Invalid token payload');
    }

    if (isset($payload['exp']) && time() > $payload['exp']) {
        throw new Exception('Token expired');
    }

    return $payload;
}