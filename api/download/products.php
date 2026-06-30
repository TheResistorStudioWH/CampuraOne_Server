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
        | JSON_PRESERVE_ZERO_FRACTION
    );

    exit;
}


/**
 * 读取可选的正整数参数。
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

    /*
     * 保证 PDO 会抛出异常，方便定位数据库问题。
     */
    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    $productID = optionalPositiveInt('productID');
    $mainShopID = optionalPositiveInt('mainShopID');
    $subShopID = optionalPositiveInt('subShopID');
    $categoryID = optionalPositiveInt('categoryID');

    $scope = isset($_GET['scope'])
        ? trim((string) $_GET['scope'])
        : null;

    if (
        $scope !== null
        && $scope !== ''
        && $scope !== 'direct'
    ) {
        respond(
            400,
            'invalid scope',
            null,
            400
        );
    }

    $conditions = [];
    $parameters = [];

    /*
     * 单个商品。
     */
    if ($productID !== null) {
        $conditions[] = 'p.productID = :productID';
        $parameters[':productID'] = $productID;
    }

    /*
     * 主商户下的商品。
     */
    if ($mainShopID !== null) {
        $conditions[] = 'p.mainShopID = :mainShopID';
        $parameters[':mainShopID'] = $mainShopID;
    }

    /*
     * 附属商户下的商品。
     */
    if ($subShopID !== null) {
        $conditions[] = 'p.subShopID = :subShopID';
        $parameters[':subShopID'] = $subShopID;
    }

    /*
     * 分类筛选。
     */
    if ($categoryID !== null) {
        $conditions[] = 'p.categoryID = :categoryID';
        $parameters[':categoryID'] = $categoryID;
    }

    /*
     * 主商户直营商品：
     * subShopID 为空。
     */
    if ($scope === 'direct') {
        $conditions[] = 'p.subShopID IS NULL';
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
            p.productID,
            p.productName,
            p.price,
            p.productImg,
            p.productIntro,
            p.mainShopID,
            p.subShopID,
            p.categoryID,
            p.createdAt,
            p.updatedAt,

            pc.categoryName,
            pc.categoryIcon,
            pc.categoryColor

        FROM products AS p

        LEFT JOIN product_categories AS pc
            ON pc.categoryID = p.categoryID

        {$whereSQL}

        ORDER BY
            p.updatedAt DESC,
            p.productID DESC
    ";

    /*
     * productID 查询只需要一条。
     */
    if ($productID !== null) {
        $sql .= ' LIMIT 1';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    /*
     * 查询单个商品时 data 返回对象。
     */
    if ($productID !== null) {
        $product = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$product) {
            respond(
                404,
                'product not found',
                null,
                404
            );
        }

        /*
         * PDO 可能把 DECIMAL 返回为字符串。
         * Swift 的 SwiftyJSON.doubleValue 仍可解析，
         * 这里额外转成数字，让 JSON 更标准。
         */
        if (isset($product['price'])) {
            $product['price'] = (float) $product['price'];
        }

        if (isset($product['productID'])) {
            $product['productID'] = (int) $product['productID'];
        }

        if (isset($product['mainShopID'])) {
            $product['mainShopID'] = (int) $product['mainShopID'];
        }

        if ($product['subShopID'] !== null) {
            $product['subShopID'] = (int) $product['subShopID'];
        }

        if ($product['categoryID'] !== null) {
            $product['categoryID'] = (int) $product['categoryID'];
        }

        respond(
            200,
            'success',
            $product
        );
    }

    /*
     * 列表查询。
     */
    $products = $statement->fetchAll(
        PDO::FETCH_ASSOC
    );

    foreach ($products as &$product) {
        if (isset($product['price'])) {
            $product['price'] = (float) $product['price'];
        }

        if (isset($product['productID'])) {
            $product['productID'] = (int) $product['productID'];
        }

        if (isset($product['mainShopID'])) {
            $product['mainShopID'] = (int) $product['mainShopID'];
        }

        if ($product['subShopID'] !== null) {
            $product['subShopID'] = (int) $product['subShopID'];
        }

        if ($product['categoryID'] !== null) {
            $product['categoryID'] = (int) $product['categoryID'];
        }
    }

    unset($product);

    respond(
        200,
        'success',
        $products
    );

} catch (PDOException $error) {
    error_log(
        'products.php database error: '
        . $error->getMessage()
    );

    /*
     * 调试期间可暂时把下面 message 改成：
     *
     * $error->getMessage()
     *
     * 正式使用时不要向客户端暴露数据库结构。
     */
    respond(
        500,
        'database error',
        null,
        500
    );

} catch (Throwable $error) {
    error_log(
        'products.php server error: '
        . $error->getMessage()
    );

    respond(
        500,
        'server error',
        null,
        500
    );
}