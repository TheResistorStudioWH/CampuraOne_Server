<?php

declare(strict_types=1);

function requireMethod(string $method): void
{
    $actualMethod = $_SERVER['REQUEST_METHOD'] ?? '';

    if (strtoupper($actualMethod) !== strtoupper($method)) {
        respondJson(
            405,
            'method not allowed',
            ['expected' => strtoupper($method)],
            405
        );
    }
}

function getQueryString(string $name, ?string $default = null): ?string
{
    if (!isset($_GET[$name])) {
        return $default;
    }

    $value = trim((string) $_GET[$name]);

    return $value === '' ? $default : $value;
}

function getPostString(string $name, ?string $default = null): ?string
{
    if (!isset($_POST[$name])) {
        return $default;
    }

    $value = trim((string) $_POST[$name]);

    return $value === '' ? $default : $value;
}
