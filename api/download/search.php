<?php

declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/validation.php';

const SEARCH_DEFAULT_LIMIT = 20;
const SEARCH_MAX_LIMIT = 100;
const SEARCH_SUGGESTION_LIMIT = 8;

/*
|--------------------------------------------------------------------------
| mbstring 兼容
|--------------------------------------------------------------------------
|
| 当前服务器没有启用 mbstring。
| 中文搜索本身仍可通过普通字符串函数完成；
| 这里只为相关度排序提供兼容实现，避免接口直接 500。
|
*/

if (!function_exists('mb_strtolower')) {
    function mb_strtolower(
        string $string,
        ?string $encoding = null
    ): string {
        return strtolower($string);
    }
}

if (!function_exists('mb_strpos')) {
    function mb_strpos(
        string $haystack,
        string $needle,
        int $offset = 0,
        ?string $encoding = null
    ) {
        return strpos(
            $haystack,
            $needle,
            $offset
        );
    }
}

if (!function_exists('mb_strlen')) {
    function mb_strlen(
        string $string,
        ?string $encoding = null
    ): int {
        return strlen($string);
    }
}

if (!function_exists('mb_substr_count')) {
    function mb_substr_count(
        string $haystack,
        string $needle,
        ?string $encoding = null
    ): int {
        return substr_count(
            $haystack,
            $needle
        );
    }
}

/*
|--------------------------------------------------------------------------
| Campura One 统一搜索接口
|--------------------------------------------------------------------------
|
| mode:
| - all      普通全局：促销 > 商品 > 商户
| - smart    智能全局：促销、商品、商户混合相关度排序
| - product  仅商品
| - shop     仅商户
|
*/

/*
|--------------------------------------------------------------------------
| 搜索能力配置
|--------------------------------------------------------------------------
|
| 每种模式只描述“查什么”和“怎么排”，避免以后继续在主流程里堆 switch。
| 后续接入 DeepSeek 时，可以新增 ai 模式，或在 enhancer 中对 smart 结果重排。
|
*/

function searchModeConfig(): array
{
    return [
        'all' => [
            'searchPromotions' => true,
            'searchProducts' => true,
            'searchShops' => true,
            'sortStrategy' => 'grouped',
            'buildSuggestions' => false,
        ],
        'smart' => [
            'searchPromotions' => true,
            'searchProducts' => true,
            'searchShops' => true,
            'sortStrategy' => 'relevance',
            'buildSuggestions' => true,
        ],
        'product' => [
            'searchPromotions' => false,
            'searchProducts' => true,
            'searchShops' => false,
            'sortStrategy' => 'relevance',
            'buildSuggestions' => false,
        ],
        'shop' => [
            'searchPromotions' => false,
            'searchProducts' => false,
            'searchShops' => true,
            'sortStrategy' => 'relevance',
            'buildSuggestions' => false,
        ],
    ];
}

try {
    requireMethod('GET');

    $query = trim((string)($_GET['q'] ?? ''));
    $mode = strtolower(trim((string)($_GET['mode'] ?? 'all')));

    $schoolID = isset($_GET['schoolID'])
        && $_GET['schoolID'] !== ''
        ? (int)$_GET['schoolID']
        : null;

    $limit = isset($_GET['limit'])
        ? max(1, min((int)$_GET['limit'], SEARCH_MAX_LIMIT))
        : SEARCH_DEFAULT_LIMIT;

    $offset = isset($_GET['offset'])
        ? max(0, (int)$_GET['offset'])
        : 0;

    $modeConfigMap = searchModeConfig();

    if (!isset($modeConfigMap[$mode])) {
        respondJson(400, '不支持的搜索模式', [
            'supportedModes' => array_keys($modeConfigMap),
        ], 400);
    }

    $modeConfig = $modeConfigMap[$mode];

    /*
    |--------------------------------------------------------------------------
    | 空关键词不执行 SQL
    |--------------------------------------------------------------------------
    */

    if ($query === '') {
        respondJson(200, 'success', [
            'query' => '',
            'mode' => $mode,
            'matchedCategories' => [],
            'suggestions' => [],
            'results' => [],
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'total' => 0,
                'hasMore' => false
            ],
            'searchMeta' => buildSearchMeta(
                $mode,
                $schoolID,
                0,
                false
            )
        ]);
    }

    $like = '%' . $query . '%';

    $promotionRows = [];
    $productRows = [];
    $shopRows = [];

    /*
    |--------------------------------------------------------------------------
    | 按模式查询
    |--------------------------------------------------------------------------
    */

    if ($modeConfig['searchPromotions']) {
        $promotionRows = searchPromotions(
            $pdo,
            $like,
            $schoolID
        );
    }

    if ($modeConfig['searchProducts']) {
        $productRows = searchProducts(
            $pdo,
            $like,
            $schoolID
        );
    }

    if ($modeConfig['searchShops']) {
        $shopRows = searchShops(
            $pdo,
            $like,
            $schoolID
        );
    }

    $rows = array_merge(
        $promotionRows,
        $productRows,
        $shopRows
    );

    /*
    |--------------------------------------------------------------------------
    | 计算相关度分数
    |--------------------------------------------------------------------------
    */

    foreach ($rows as &$row) {
        $row['_score'] = calculateSearchScore(
            $row,
            $query
        );
    }
    unset($row);

    /*
    |--------------------------------------------------------------------------
    | 排序
    |--------------------------------------------------------------------------
    */

    if ($modeConfig['sortStrategy'] === 'grouped') {
        /*
         * 普通全局固定分组顺序：
         *
         * 促销 > 商品 > 商户
         *
         * 同一组内部再按相关度排序。
         */
        usort(
            $rows,
            static function (
                array $left,
                array $right
            ): int {
                $leftRank = ordinaryTypeRank(
                    (string)$left['type']
                );

                $rightRank = ordinaryTypeRank(
                    (string)$right['type']
                );

                if ($leftRank !== $rightRank) {
                    return $leftRank <=> $rightRank;
                }

                if ($left['_score'] !== $right['_score']) {
                    return $right['_score']
                        <=> $left['_score'];
                }

                return strcmp(
                    (string)$left['title'],
                    (string)$right['title']
                );
            }
        );
    } else {
        /*
         * 智能全局以及单独类型模式：
         * 主要按照相关度混合排序。
         */
        usort(
            $rows,
            static function (
                array $left,
                array $right
            ): int {
                if ($left['_score'] !== $right['_score']) {
                    return $right['_score']
                        <=> $left['_score'];
                }

                $leftRank = smartTypeRank(
                    (string)$left['type']
                );

                $rightRank = smartTypeRank(
                    (string)$right['type']
                );

                if ($leftRank !== $rightRank) {
                    return $leftRank <=> $rightRank;
                }

                return strcmp(
                    (string)$left['title'],
                    (string)$right['title']
                );
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 智能搜索顶部建议
    |--------------------------------------------------------------------------
    |
    | 返回完整 SearchResultItem 结构。
    |
    */

    $suggestions = [];

    if ($modeConfig['buildSuggestions']) {
        $suggestionRows = array_slice(
            $rows,
            0,
            SEARCH_SUGGESTION_LIMIT
        );

        $suggestions = array_map(
            'removeInternalFields',
            $suggestionRows
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 正式结果分页
    |--------------------------------------------------------------------------
    */

    $total = count($rows);

    $pagedRows = array_slice(
        $rows,
        $offset,
        $limit
    );

    $results = array_map(
        'removeInternalFields',
        $pagedRows
    );

    respondJson(200, 'success', [
        'query' => $query,
        'mode' => $mode,
        'matchedCategories' => collectMatchedCategories(
            $rows
        ),
        'suggestions' => $suggestions,
        'results' => $results,
        'pagination' => [
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
            'hasMore' => (
                $offset + count($results)
            ) < $total
        ],
        'searchMeta' => buildSearchMeta(
            $mode,
            $schoolID,
            $total,
            false
        )
    ]);
} catch (PDOException $error) {
    respondJson(
        500,
        '数据库搜索失败',
        ['error' => $error->getMessage()],
        500
    );
} catch (Throwable $error) {
    respondJson(
        500,
        '搜索失败',
        ['error' => $error->getMessage()],
        500
    );
}
/*
|--------------------------------------------------------------------------
| 搜索元数据与未来 AI 增强入口
|--------------------------------------------------------------------------
*/

function buildSearchMeta(
    string $mode,
    ?int $schoolID,
    int $total,
    bool $aiEnhanced
): array {
    return [
        'engine' => $aiEnhanced ? 'deepseek' : 'local',
        'mode' => $mode,
        'schoolID' => $schoolID,
        'total' => $total,
        'aiEnhanced' => $aiEnhanced,
        'aiReady' => true,
    ];
}

/**
 * 后续接入 DeepSeek 时，把本地结果和用户关键词交给该函数进行意图识别、
 * 查询改写或二次重排。当前阶段保持原样返回，避免影响现有搜索结果。
 */
function enhanceSearchResults(
    array $rows,
    string $query,
    string $mode
): array {
    return $rows;
}

/*
|--------------------------------------------------------------------------
| 搜索当前有效促销
|--------------------------------------------------------------------------
*/

function searchPromotions(
    PDO $pdo,
    string $like,
    ?int $schoolID
): array {
    $sql = <<<SQL
        SELECT
            'promotion' AS resultType,

            CONCAT(
                'promotion-',
                sale.saleID
            ) AS resultKey,

            sale.saleID AS targetID,

            CASE
                WHEN NULLIF(
                    TRIM(sale.saleRule),
                    ''
                ) IS NOT NULL
                    THEN sale.saleRule

                WHEN NULLIF(
                    TRIM(product.productName),
                    ''
                ) IS NOT NULL
                    THEN CONCAT(
                        product.productName,
                        '促销活动'
                    )

                ELSE '促销活动'
            END AS resultTitle,

            CONCAT_WS(
                ' · ',

                NULLIF(
                    TRIM(product.productName),
                    ''
                ),

                NULLIF(
                    TRIM(
                        COALESCE(
                            subShop.shopName,
                            directShop.shopName
                        )
                    ),
                    ''
                ),

                CONCAT(
                    DATE_FORMAT(
                        sale.startTime,
                        '%Y-%m-%d'
                    ),
                    ' 至 ',
                    COALESCE(
                        DATE_FORMAT(
                            sale.endTime,
                            '%Y-%m-%d'
                        ),
                        '长期'
                    )
                )
            ) AS resultSubtitle,

            product.productImg AS imageURL,

            NULL AS categoryID,
            '促销' AS categoryName,
            'tag.fill' AS categoryIcon,
            '#FF9500' AS categoryColor

        FROM promotion_events AS sale

        LEFT JOIN products AS product
            ON product.productID = sale.productID

        LEFT JOIN main_shops AS directShop
            ON directShop.shopID = product.mainShopID

        LEFT JOIN sub_shops AS subShop
            ON subShop.shopID = product.subShopID

        LEFT JOIN main_shops AS parentShop
            ON parentShop.shopID = subShop.mainShopID

        WHERE
            sale.startTime <= NOW()

            AND (
                sale.endTime IS NULL
                OR sale.endTime >= NOW()
            )

            AND (
                sale.saleRule LIKE :saleRuleLike
                OR product.productName LIKE :saleProductLike
                OR product.productIntro LIKE :saleIntroLike
                OR directShop.shopName LIKE :saleDirectShopLike
                OR subShop.shopName LIKE :saleSubShopLike
                OR parentShop.shopName LIKE :saleParentShopLike
            )
    SQL;

    $parameters = [
        ':saleRuleLike' => $like,
        ':saleProductLike' => $like,
        ':saleIntroLike' => $like,
        ':saleDirectShopLike' => $like,
        ':saleSubShopLike' => $like,
        ':saleParentShopLike' => $like
    ];

    if ($schoolID !== null) {
        $sql .= <<<SQL

            AND COALESCE(
                directShop.schoolID,
                parentShop.schoolID
            ) = :saleSchoolID
        SQL;

        $parameters[':saleSchoolID'] = $schoolID;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    return normalizeRows(
        $statement->fetchAll(PDO::FETCH_ASSOC)
    );
}

/*
|--------------------------------------------------------------------------
| 搜索商品
|--------------------------------------------------------------------------
*/

function searchProducts(
    PDO $pdo,
    string $like,
    ?int $schoolID
): array {
    $sql = <<<SQL
        SELECT
            'product' AS resultType,

            CONCAT(
                'product-',
                product.productID
            ) AS resultKey,

            product.productID AS targetID,
            product.productName AS resultTitle,

            CONCAT_WS(
                ' · ',

                NULLIF(
                    TRIM(product.productIntro),
                    ''
                ),

                NULLIF(
                    TRIM(
                        COALESCE(
                            subShop.shopName,
                            directShop.shopName
                        )
                    ),
                    ''
                )
            ) AS resultSubtitle,

            product.productImg AS imageURL,

            category.categoryID AS categoryID,
            category.categoryName AS categoryName,
            category.categoryIcon AS categoryIcon,
            category.categoryColor AS categoryColor

        FROM products AS product

        LEFT JOIN product_categories AS category
            ON category.categoryID = product.categoryID

        LEFT JOIN main_shops AS directShop
            ON directShop.shopID = product.mainShopID

        LEFT JOIN sub_shops AS subShop
            ON subShop.shopID = product.subShopID

        LEFT JOIN main_shops AS parentShop
            ON parentShop.shopID = subShop.mainShopID

        WHERE (
            product.productName LIKE :productNameLike
            OR product.productIntro LIKE :productIntroLike
            OR category.categoryName LIKE :productCategoryLike
            OR directShop.shopName LIKE :productDirectShopLike
            OR subShop.shopName LIKE :productSubShopLike
            OR parentShop.shopName LIKE :productParentShopLike
        )
    SQL;

    $parameters = [
        ':productNameLike' => $like,
        ':productIntroLike' => $like,
        ':productCategoryLike' => $like,
        ':productDirectShopLike' => $like,
        ':productSubShopLike' => $like,
        ':productParentShopLike' => $like
    ];

    if ($schoolID !== null) {
        $sql .= <<<SQL

            AND COALESCE(
                directShop.schoolID,
                parentShop.schoolID
            ) = :productSchoolID
        SQL;

        $parameters[':productSchoolID'] = $schoolID;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    return normalizeRows(
        $statement->fetchAll(PDO::FETCH_ASSOC)
    );
}

/*
|--------------------------------------------------------------------------
| 搜索商户
|--------------------------------------------------------------------------
*/

function searchShops(
    PDO $pdo,
    string $like,
    ?int $schoolID
): array {
    /*
    |--------------------------------------------------------------------------
    | 主商户
    |--------------------------------------------------------------------------
    */

    $mainShopSQL = <<<SQL
        SELECT
            'shop' AS resultType,

            CONCAT(
                'main-shop-',
                mainShop.shopID
            ) AS resultKey,

            mainShop.shopID AS targetID,
            mainShop.shopName AS resultTitle,

            COALESCE(
                NULLIF(
                    TRIM(mainShop.shopSlogan),
                    ''
                ),
                NULLIF(
                    TRIM(
                        CAST(
                            mainShop.shopAddress
                            AS CHAR
                        )
                    ),
                    ''
                )
            ) AS resultSubtitle,

            COALESCE(
                mainShop.shopImg,
                mainShop.shopLogo
            ) AS imageURL,

            NULL AS categoryID,
            NULL AS categoryName,
            NULL AS categoryIcon,
            NULL AS categoryColor

        FROM main_shops AS mainShop

        WHERE (
            mainShop.shopName LIKE :mainShopNameLike
            OR mainShop.shopSlogan LIKE :mainShopSloganLike
            OR CAST(
                mainShop.shopAddress
                AS CHAR
            ) LIKE :mainShopAddressLike
        )
    SQL;

    $mainShopParameters = [
        ':mainShopNameLike' => $like,
        ':mainShopSloganLike' => $like,
        ':mainShopAddressLike' => $like
    ];

    if ($schoolID !== null) {
        $mainShopSQL .= <<<SQL

            AND mainShop.schoolID = :mainShopSchoolID
        SQL;

        $mainShopParameters[':mainShopSchoolID'] = $schoolID;
    }

    $mainStatement = $pdo->prepare(
        $mainShopSQL
    );

    $mainStatement->execute(
        $mainShopParameters
    );

    $mainRows = $mainStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

    /*
    |--------------------------------------------------------------------------
    | 附属商户
    |--------------------------------------------------------------------------
    */

    $subShopSQL = <<<SQL
        SELECT
            'shop' AS resultType,

            CONCAT(
                'sub-shop-',
                subShop.shopID
            ) AS resultKey,

            subShop.shopID AS targetID,
            subShop.shopName AS resultTitle,

            CONCAT_WS(
                ' · ',

                NULLIF(
                    TRIM(parentShop.shopName),
                    ''
                ),

                NULLIF(
                    TRIM(
                        CAST(
                            subShop.shopAddress
                            AS CHAR
                        )
                    ),
                    ''
                )
            ) AS resultSubtitle,

            COALESCE(
                subShop.shopImg,
                parentShop.shopImg,
                parentShop.shopLogo
            ) AS imageURL,

            NULL AS categoryID,
            NULL AS categoryName,
            NULL AS categoryIcon,
            NULL AS categoryColor

        FROM sub_shops AS subShop

        INNER JOIN main_shops AS parentShop
            ON parentShop.shopID = subShop.mainShopID

        WHERE (
            subShop.shopName LIKE :subShopNameLike
            OR parentShop.shopName LIKE :subParentNameLike
            OR CAST(
                subShop.shopAddress
                AS CHAR
            ) LIKE :subShopAddressLike
        )
    SQL;

    $subShopParameters = [
        ':subShopNameLike' => $like,
        ':subParentNameLike' => $like,
        ':subShopAddressLike' => $like
    ];

    if ($schoolID !== null) {
        $subShopSQL .= <<<SQL

            AND parentShop.schoolID = :subShopSchoolID
        SQL;

        $subShopParameters[':subShopSchoolID'] = $schoolID;
    }

    $subStatement = $pdo->prepare(
        $subShopSQL
    );

    $subStatement->execute(
        $subShopParameters
    );

    $subRows = $subStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

    return normalizeRows(
        array_merge(
            $mainRows,
            $subRows
        )
    );
}

/*
|--------------------------------------------------------------------------
| 将数据库结果统一为客户端 SearchResultItem
|--------------------------------------------------------------------------
*/

function normalizeRows(array $rows): array {
    return array_map(
        static function (array $row): array {
            $type = (string)$row['resultType'];
            $targetID = (int)$row['targetID'];

            return [
                'id' => (
                    isset($row['resultKey'])
                    && trim((string)$row['resultKey']) !== ''
                )
                    ? (string)$row['resultKey']
                    : $type . '-' . $targetID,

                'type' => $type,
                'targetID' => $targetID,

                'title' => trim(
                    (string)(
                        $row['resultTitle']
                        ?? ''
                    )
                ),

                'subtitle' => nullableTrimmedString(
                    $row['resultSubtitle']
                    ?? null
                ),

                'imageURL' => nullableTrimmedString(
                    $row['imageURL']
                    ?? null
                ),

                'categoryID' => (
                    isset($row['categoryID'])
                    && $row['categoryID'] !== null
                )
                    ? (int)$row['categoryID']
                    : null,

                'categoryName' => nullableTrimmedString(
                    $row['categoryName']
                    ?? null
                ),

                'categoryIcon' => nullableTrimmedString(
                    $row['categoryIcon']
                    ?? null
                ),

                'categoryColor' => nullableTrimmedString(
                    $row['categoryColor']
                    ?? null
                )
            ];
        },
        $rows
    );
}

/*
|--------------------------------------------------------------------------
| 智能搜索相关度评分
|--------------------------------------------------------------------------
*/

function calculateSearchScore(
    array $row,
    string $query
): int {
    $normalizedQuery = normalizeSearchText(
        $query
    );

    $title = normalizeSearchText(
        (string)(
            $row['title']
            ?? ''
        )
    );

    $subtitle = normalizeSearchText(
        (string)(
            $row['subtitle']
            ?? ''
        )
    );

    $categoryName = normalizeSearchText(
        (string)(
            $row['categoryName']
            ?? ''
        )
    );

    if ($normalizedQuery === '') {
        return 0;
    }

    $score = 0;

    /*
     * 标题完全匹配。
     */
    if ($title === $normalizedQuery) {
        $score += 12000;
    }

    /*
     * 标题以前缀形式匹配。
     */
    if (
        $title !== ''
        && mb_strpos(
            $title,
            $normalizedQuery
        ) === 0
    ) {
        $score += 8000;
    }

    /*
     * 标题中包含关键词。
     */
    if (
        $title !== ''
        && mb_strpos(
            $title,
            $normalizedQuery
        ) !== false
    ) {
        $score += 5000;
    }

    /*
     * LIKE 命中次数越多，分数越高。
     */
    $score += countOccurrences(
        $title,
        $normalizedQuery
    ) * 1000;

    if (
        $subtitle !== ''
        && mb_strpos(
            $subtitle,
            $normalizedQuery
        ) !== false
    ) {
        $score += 2000;
    }

    $score += countOccurrences(
        $subtitle,
        $normalizedQuery
    ) * 400;

    if (
        $categoryName !== ''
        && mb_strpos(
            $categoryName,
            $normalizedQuery
        ) !== false
    ) {
        $score += 1500;
    }

    /*
     * 当前阶段的促销权重：
     *
     * 促销标题文字越完整，获得越高的附加分。
     * 最大只增加 1200，防止纯长文本完全压过精准匹配。
     */
    if (
        ($row['type'] ?? '')
        === 'promotion'
    ) {
        $promotionLength = mb_strlen(
            $title,
            'UTF-8'
        );

        $score += min(
            $promotionLength * 12,
            1200
        );
    }

    return $score;
}

/*
|--------------------------------------------------------------------------
| 普通全局固定类型顺序
|--------------------------------------------------------------------------
*/

function ordinaryTypeRank(string $type): int {
    return match ($type) {
        'promotion' => 0,
        'product' => 1,
        'shop' => 2,
        default => 99
    };
}

/*
|--------------------------------------------------------------------------
| 智能排序分数相同情况下的类型顺序
|--------------------------------------------------------------------------
*/

function smartTypeRank(string $type): int {
    return match ($type) {
        'promotion' => 0,
        'product' => 1,
        'shop' => 2,
        default => 99
    };
}

/*
|--------------------------------------------------------------------------
| 收集命中的商品分类
|--------------------------------------------------------------------------
*/

function collectMatchedCategories(
    array $rows
): array {
    $categoryMap = [];

    foreach ($rows as $row) {
        if (
            ($row['type'] ?? '')
            !== 'product'
        ) {
            continue;
        }

        if (
            !isset($row['categoryID'])
            || $row['categoryID'] === null
        ) {
            continue;
        }

        $categoryID = (int)$row['categoryID'];

        $categoryMap[$categoryID] = [
            'categoryID' => $categoryID,
            'categoryName' => (
                $row['categoryName']
                ?? null
            ),
            'categoryIcon' => (
                $row['categoryIcon']
                ?? null
            ),
            'categoryColor' => (
                $row['categoryColor']
                ?? null
            )
        ];
    }

    return array_values(
        $categoryMap
    );
}

/*
|--------------------------------------------------------------------------
| 清除仅供服务端排序使用的字段
|--------------------------------------------------------------------------
*/

function removeInternalFields(
    array $row
): array {
    unset($row['_score']);

    return $row;
}

/*
|--------------------------------------------------------------------------
| 文本处理
|--------------------------------------------------------------------------
*/

function normalizeSearchText(
    string $value
): string {
    return mb_strtolower(
        trim($value),
        'UTF-8'
    );
}

function countOccurrences(
    string $text,
    string $needle
): int {
    if (
        $text === ''
        || $needle === ''
    ) {
        return 0;
    }

    return mb_substr_count(
        $text,
        $needle,
        'UTF-8'
    );
}

function nullableTrimmedString(
    mixed $value
): ?string {
    if ($value === null) {
        return null;
    }

    $trimmed = trim(
        (string)$value
    );

    return $trimmed === ''
        ? null
        : $trimmed;
}
