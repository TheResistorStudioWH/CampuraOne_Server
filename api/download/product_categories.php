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
     * categoryID 是可选参数。
     *
     * 不传：
     * 返回全部分类。
     *
     * 传入：
     * 返回指定分类。
     */
    $categoryID = optionalPositiveInt('categoryID', $_GET);

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
                DATE_FORMAT(createdAt, \'%Y-%m-%d %H:%i:%s\') AS createdAt,
                DATE_FORMAT(updatedAt, \'%Y-%m-%d %H:%i:%s\') AS updatedAt
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
            respondJson(
                404,
                'product category not found',
                null,
                404
            );
        }

        $category['categoryID'] = (int) $category['categoryID'];

        respondJson(
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
            DATE_FORMAT(createdAt, \'%Y-%m-%d %H:%i:%s\') AS createdAt,
            DATE_FORMAT(updatedAt, \'%Y-%m-%d %H:%i:%s\') AS updatedAt
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

    respondJson(
        200,
        'success',
        $categories
    );

} catch (PDOException $error) {
    error_log(
        'product_categories.php database error: '
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
        'product_categories.php server error: '
        . $error->getMessage()
    );

    respondJson(
        500,
        'server error',
        null,
        500
    );
}