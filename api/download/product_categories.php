<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config/database.php';


/**
 * 统一返回 JSON。
 */
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
 * 读取可选的正整数参数。
 *
 * 不传参数时返回 null，
 * 参数格式错误时直接返回 400。
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
     * categoryID 是可选参数。
     *
     * 不传：
     * 返回全部分类。
     *
     * 传入：
     * 返回指定分类。
     */
    $categoryID = optionalPositiveInt('categoryID');

    /*
     * 查询单个分类。
     */
    if ($categoryID !== null) {
        $statement = $pdo->prepare(
            '
            SELECT
                categoryID,
                categoryName,
                categoryIcon,
                categoryColor,
                createdAt,
                updatedAt
            FROM product_categories
            WHERE categoryID = :categoryID
            LIMIT 1
            '
        );

        $statement->execute([
            ':categoryID' => $categoryID
        ]);

        $category = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$category) {
            respond(
                404,
                'product category not found',
                null,
                404
            );
        }

        $category['categoryID'] = (int) $category['categoryID'];

        respond(
            200,
            'success',
            $category
        );
    }

    /*
     * 没有传 categoryID：
     * 返回全部商品分类。
     */
    $statement = $pdo->query(
        '
        SELECT
            categoryID,
            categoryName,
            categoryIcon,
            categoryColor,
            createdAt,
            updatedAt
        FROM product_categories
        ORDER BY
            categoryID ASC
        '
    );

    $categories = $statement->fetchAll(
        PDO::FETCH_ASSOC
    );

    foreach ($categories as &$category) {
        $category['categoryID'] = (int) $category['categoryID'];
    }

    unset($category);

    respond(
        200,
        'success',
        $categories
    );

} catch (PDOException $error) {
    error_log(
        'product_categories.php database error: '
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
        'product_categories.php server error: '
        . $error->getMessage()
    );

    respond(
        500,
        'server error',
        null,
        500
    );
}