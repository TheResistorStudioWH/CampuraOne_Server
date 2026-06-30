<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config/database.php';


function respond(
    int $code,
    string $message,
    mixed $data = null,
    int $httpStatus = 200
): never {
    http_response_code($httpStatus);

    echo json_encode(
        [
            'code' => $code,
            'message' => $message,
            'data' => $data
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}


/**
 * 获取可选正整数参数。
 *
 * 未传参数时返回 null；
 * 参数非法时返回 400。
 */
function optionalPositiveInt(string $name): ?int {
    if (!isset($_GET[$name]) || $_GET[$name] === '') {
        return null;
    }

    $value = filter_var(
        $_GET[$name],
        FILTER_VALIDATE_INT
    );

    if ($value === false || $value <= 0) {
        respond(
            400,
            "invalid {$name}",
            null,
            400
        );
    }

    return $value;
}


/**
 * 获取可选的 0 / 1 参数。
 *
 * 未传或传 0：false
 * 传 1：true
 */
function optionalBooleanFlag(string $name): bool {
    if (!isset($_GET[$name]) || $_GET[$name] === '') {
        return false;
    }

    $value = filter_var(
        $_GET[$name],
        FILTER_VALIDATE_INT
    );

    if ($value === false || !in_array($value, [0, 1], true)) {
        respond(
            400,
            "invalid {$name}, expected 0 or 1",
            null,
            400
        );
    }

    return $value === 1;
}


try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        respond(
            405,
            'method not allowed',
            null,
            405
        );
    }

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException(
            'PDO connection variable $pdo is unavailable'
        );
    }

    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    /*
     * 两个参数都是可选：
     *
     * productID：
     * 指定商品时只返回该商品的促销。
     *
     * onlyActive：
     * 传 1 时只返回当前有效促销。
     */
    $productID = optionalPositiveInt('productID');
    $onlyActive = optionalBooleanFlag('onlyActive');

    $conditions = [];
    $parameters = [];

    if ($productID !== null) {
        $conditions[] = 'pe.productID = :productID';
        $parameters[':productID'] = $productID;
    }

    if ($onlyActive) {
        $conditions[] = 'pe.startTime <= NOW()';
        $conditions[] = '
            (
                pe.endTime IS NULL
                OR pe.endTime >= NOW()
            )
        ';
    }

    $whereSQL = '';

    if (!empty($conditions)) {
        $whereSQL = 'WHERE ' . implode(
            ' AND ',
            $conditions
        );
    }

    $sql = "
        SELECT
            pe.saleID,
            pe.productID,
            pe.saleRule,
            pe.startTime,
            pe.endTime,
            pe.createdAt,
            pe.updatedAt
        FROM promotion_events AS pe
        {$whereSQL}
        ORDER BY
            pe.startTime DESC,
            pe.saleID DESC
    ";

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    $promotionEvents = $statement->fetchAll(
        PDO::FETCH_ASSOC
    );

    foreach ($promotionEvents as &$promotionEvent) {
        $promotionEvent['saleID'] = (int) $promotionEvent['saleID'];

        if ($promotionEvent['productID'] !== null) {
            $promotionEvent['productID'] =
                (int) $promotionEvent['productID'];
        }
    }

    unset($promotionEvent);

    respond(
        200,
        'success',
        $promotionEvents
    );

} catch (PDOException $error) {
    error_log(
        'promotions.php database error: '
        . $error->getMessage()
    );

    respond(
        500,
        'database error',
        null,
        500
    );

} catch (Throwable $error) {
    error_log(
        'promotions.php server error: '
        . $error->getMessage()
    );

    respond(
        500,
        'server error',
        null,
        500
    );
}