<?php

declare(strict_types=1);

function requiredPositiveInt(string $name, array $source): int
{
    if (!isset($source[$name]) || $source[$name] === '') {
        respondJson(
            400,
            "missing {$name}",
            null,
            400
        );
    }

    $value = filter_var(
        $source[$name],
        FILTER_VALIDATE_INT
    );

    if ($value === false || $value <= 0) {
        respondJson(
            400,
            "invalid {$name}",
            null,
            400
        );
    }

    return $value;
}

function optionalPositiveInt(
    string $name,
    array $source
): ?int {
    if (!isset($source[$name]) || $source[$name] === '') {
        return null;
    }

    $value = filter_var(
        $source[$name],
        FILTER_VALIDATE_INT
    );

    if ($value === false || $value <= 0) {
        respondJson(
            400,
            "invalid {$name}",
            null,
            400
        );
    }

    return $value;
}

function requiredString(string $name, array $source): string
{
    $value = trim((string) ($source[$name] ?? ''));

    if ($value === '') {
        respondJson(
            400,
            "missing {$name}",
            null,
            400
        );
    }

    return $value;
}

function optionalBooleanFlag(
    string $name,
    array $source
): bool {
    if (!isset($source[$name]) || $source[$name] === '') {
        return false;
    }

    $value = filter_var(
        $source[$name],
        FILTER_VALIDATE_INT
    );

    if ($value === false || !in_array($value, [0, 1], true)) {
        respondJson(
            400,
            "invalid {$name}, expected 0 or 1",
            null,
            400
        );
    }

    return $value === 1;
}
