<?php

declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/validation.php';


try {
    requireMethod('GET');

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
    $productID = optionalPositiveInt('productID', $_GET);
    $onlyActive = optionalBooleanFlag('onlyActive', $_GET);

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
            DATE_FORMAT(pe.startTime, '%Y-%m-%d %H:%i:%s') AS startTime,
            DATE_FORMAT(pe.endTime, '%Y-%m-%d %H:%i:%s') AS endTime,
            DATE_FORMAT(pe.createdAt, '%Y-%m-%d %H:%i:%s') AS createdAt,
            DATE_FORMAT(pe.updatedAt, '%Y-%m-%d %H:%i:%s') AS updatedAt
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

    respondJson(
        200,
        'success',
        $promotionEvents
    );

} catch (PDOException $error) {
    error_log(
        'promotions.php database error: '
        . $error->getMessage()
    );

    respondJson(
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

    respondJson(
        500,
        'server error',
        null,
        500
    );
}