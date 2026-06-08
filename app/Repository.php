<?php
declare(strict_types=1);

final class Repository
{
    private const COMPACT_CATALOG_SLUGS = ['budivelni-materialy','santehnika','elektryka','instrumenty','pokrivlya-fasad','ozdoblennya','derevyna-plyty','kriplennya-metal'];

    public function __construct(private array $fallback = [])
    {
    }

    public function company(): array
    {
        $rows = Database::fetchAll(
            "SELECT setting_key, setting_value
             FROM rc_site_settings"
        );

        $settings = [];

        foreach ($rows as $row) {
            $settings[(string)$row['SETTING_KEY']] = (string)$row['SETTING_VALUE'];
        }

        return [
            'name' => $settings['name'] ?? 'RungoCraft',
            'tagline' => $settings['tagline'] ?? 'будматеріали та інструменти',
            'phone' => $settings['phone'] ?? '+380937278561',
            'phone_label' => $settings['phone_label'] ?? '+38 (093) 727-85-61',
            'email' => $settings['email'] ?? 'fatoha359@gmail.com',
            'address' => $settings['address'] ?? 'м. Київ, вул. Куренівська, 15',
            'worktime' => $settings['worktime'] ?? '8:00 - 20:00, без вихідних',
            'telegram_url' => $settings['telegram_url'] ?? 'https://t.me/rungocraft',
            'youtube_url' => $settings['youtube_url'] ?? '#',
            'google_maps_url' => $settings['google_maps_url'] ?? '#',
        ];
    }

    public function roles(): array
    {
        $rows = Database::fetchAll(
            "SELECT code, name
             FROM rc_roles
             ORDER BY id"
        );

        $roles = [];

        foreach ($rows as $row) {
            $roles[(string)$row['CODE']] = (string)$row['NAME'];
        }

        return $roles;
    }

    public function demoUsers(): array
    {
        return [];
    }

    public function categories(): array
    {
        $rows = Database::fetchAll(
            "SELECT id, parent_id, slug, name, icon, description, sort_order
             FROM rc_categories
             WHERE NVL(is_active, 1) = 1
             ORDER BY sort_order, name, id"
        );

        return $this->buildCategoryTree($rows);
    }

    public function popularCategories(int $limit = 20): array
    {
        $limit = max(1, min($limit, 40));

        $rows = Database::fetchAll(
            "SELECT id, slug, name, icon, description, sort_order
             FROM rc_categories
             WHERE NVL(is_active, 1) = 1
               AND parent_id IS NULL
             ORDER BY sort_order, name
             FETCH FIRST {$limit} ROWS ONLY"
        );

        $categories = [];

        foreach ($rows as $row) {
            $categories[] = [
                'id' => (int)$row['ID'],
                'slug' => (string)$row['SLUG'],
                'name' => (string)$row['NAME'],
                'icon' => (string)($row['ICON'] ?? '▦'),
                'description' => $this->dbValueToString($row['DESCRIPTION'] ?? ''),
            ];
        }

        return $categories;
    }

    public function catalogGroups(): array
    {
        try {
            $table = Database::fetchOne(
                "SELECT COUNT(*) AS cnt
                 FROM user_tables
                 WHERE table_name = 'RC_CATALOG_TERMS'"
            );

            if (!$table || (int)$table['CNT'] === 0) {
                return [];
            }

            $rows = Database::fetchAll(
                "SELECT group_code, group_title, term_name, term_slug, sort_order
                 FROM rc_catalog_terms
                 WHERE is_active = 1
                 ORDER BY group_sort_order, sort_order, term_name"
            );
        } catch (Throwable) {
            return [];
        }

        $groups = [];

        foreach ($rows as $row) {
            $code = (string)$row['GROUP_CODE'];

            if (!isset($groups[$code])) {
                $groups[$code] = [
                    'code' => $code,
                    'title' => (string)$row['GROUP_TITLE'],
                    'items' => [],
                ];
            }

            $groups[$code]['items'][] = [
                'name' => (string)$row['TERM_NAME'],
                'slug' => (string)$row['TERM_SLUG'],
            ];
        }

        return array_values($groups);
    }

    public function catalogFilterOptions(string $categorySlug = ''): array
    {
        $categorySlug = trim($categorySlug);
        if ($categorySlug === '') {
            return [];
        }

        try {
            $params = [];
            $where = ['p.is_active = 1', 'pa.attr_value IS NOT NULL'];
            $this->appendCategoryFilter($where, $params, $categorySlug, 'c');

            $rows = Database::fetchAll(
                "SELECT pa.attr_name, pa.attr_value, COUNT(*) AS cnt
                 FROM rc_product_attributes pa
                 JOIN rc_products p ON p.id = pa.product_id
                 JOIN rc_categories c ON c.id = p.category_id
                 WHERE " . implode(' AND ', $where) . "
                 GROUP BY pa.attr_name, pa.attr_value
                 ORDER BY pa.attr_name, cnt DESC, pa.attr_value
                 FETCH FIRST 240 ROWS ONLY",
                $params
            );
        } catch (Throwable) {
            return [];
        }

        $optionCounts = [];
        foreach ($rows as $row) {
            $name = trim((string)($row['ATTR_NAME'] ?? ''));
            $rawValue = trim((string)($row['ATTR_VALUE'] ?? ''));
            $count = (int)($row['CNT'] ?? 0);
            if ($name === '' || $rawValue === '') {
                continue;
            }
            if (!isset($optionCounts[$name])) {
                $optionCounts[$name] = [];
            }
            foreach ($this->splitAttributeFilterValues($rawValue) as $value) {
                $optionCounts[$name][$value] = ($optionCounts[$name][$value] ?? 0) + max(1, $count);
            }
        }

        $options = [];
        foreach ($optionCounts as $name => $values) {
            uasort($values, static function (int $left, int $right): int {
                return $right <=> $left;
            });

            $options[$name] = [];
            foreach ($values as $value => $count) {
                if (count($options[$name]) >= 16) {
                    break;
                }
                $options[$name][] = [
                    'value' => (string)$value,
                    'count' => (int)$count,
                ];
            }
        }

        return $options;
    }

    public function products(array $filters = []): array
    {
        $params = [];
        $where = ['p.is_active = 1', "NVL(p.status, 'in_stock') <> 'archived'", 'NVL(c.is_active, 1) = 1'];

        if (!empty($filters['category'])) {
            $this->appendCategoryFilter($where, $params, (string)$filters['category'], 'c');
        }

        if (!empty($filters['q'])) {
            $q = trim(preg_replace('/\s+/u', ' ', (string)$filters['q']) ?? (string)$filters['q']);
            $len = mb_strlen($q, 'UTF-8');

            if ($len <= 1) {
                $letter = mb_strtoupper(mb_substr($q, 0, 1, 'UTF-8'), 'UTF-8');
                $where[] = "(
                    UPPER(SUBSTR(p.name, 1, 1)) = :q_letter
                    OR UPPER(SUBSTR(NVL(p.brand, ''), 1, 1)) = :q_letter
                )";
                $params['q_letter'] = $letter;
            } elseif (preg_match('/^\d+(?:[.,]\d+)?$/u', $q)) {
                
                
                $where[] = "(
                    LOWER(p.name) LIKE LOWER(:q_any)
                    OR LOWER(p.sku) = LOWER(:q_exact)
                    OR LOWER(p.sku) LIKE LOWER(:q_prefix)
                )";
                $params['q_any'] = '%' . $q . '%';
                $params['q_exact'] = $q;
                $params['q_prefix'] = $q . '%';
            } else {
                $terms = preg_split('/\s+/u', mb_strtolower($q, 'UTF-8')) ?: [];
                $termIndex = 0;
                foreach ($terms as $term) {
                    $term = trim($term);
                    if ($term === '' || mb_strlen($term, 'UTF-8') < 2) {
                        continue;
                    }
                    $where[] = "(
                        LOWER(p.name) LIKE LOWER(:q_term_{$termIndex})
                        OR LOWER(p.sku) LIKE LOWER(:q_term_{$termIndex})
                        OR LOWER(NVL(p.brand, '')) LIKE LOWER(:q_term_{$termIndex})
                        OR LOWER(c.name) LIKE LOWER(:q_term_{$termIndex})
                    )";
                    $params['q_term_' . $termIndex] = '%' . $term . '%';
                    $termIndex++;
                }
            }
        }

        if (!empty($filters['letter'])) {
            $letter = mb_strtoupper(mb_substr(trim((string)$filters['letter']), 0, 1, 'UTF-8'), 'UTF-8');

            if ($letter !== '') {
                $where[] = "UPPER(SUBSTR(p.name, 1, 1)) = :letter";
                $params['letter'] = $letter;
            }
        }

        $priceMin = trim((string)($filters['price_min'] ?? ''));
        if ($priceMin !== '') {
            $where[] = 'p.price >= :price_min';
            $params['price_min'] = (float)str_replace(',', '.', $priceMin);
        }

        $priceMax = trim((string)($filters['price_max'] ?? ''));
        if ($priceMax !== '') {
            $where[] = 'p.price <= :price_max';
            $params['price_max'] = (float)str_replace(',', '.', $priceMax);
        }

        if (!empty($filters['in_stock'])) {
            $where[] = "p.stock_qty > 0 AND p.status NOT IN ('out_of_stock', 'archive', 'archived')";
        }

        $attrFilters = is_array($filters['attrs'] ?? null) ? $filters['attrs'] : [];
        $attrIndex = 0;
        foreach ($attrFilters as $attrName => $attrValues) {
            $attrName = trim((string)$attrName);
            $values = is_array($attrValues) ? $attrValues : [$attrValues];
            $values = array_values(array_unique(array_filter(array_map(static function (mixed $value): string {
                return trim((string)$value);
            }, $values), static fn (string $value): bool => $value !== '')));

            if ($attrName === '' || $values === []) {
                continue;
            }

            $valueConditions = [];
            foreach ($values as $valueIndex => $attrValue) {
                $exactParam = "attr_value_{$attrIndex}_{$valueIndex}";
                $regexParam = "attr_value_regex_{$attrIndex}_{$valueIndex}";
                $valueConditions[] = "(
                    LOWER(paf{$attrIndex}.attr_value) = :{$exactParam}
                    OR REGEXP_LIKE(LOWER(paf{$attrIndex}.attr_value), :{$regexParam})
                )";
                $params[$exactParam] = mb_strtolower($attrValue, 'UTF-8');
                $params[$regexParam] = '(^|[[:space:]]*[/,;|][[:space:]]*)' . $this->escapeRegexValue(mb_strtolower($attrValue, 'UTF-8')) . '([[:space:]]*[/,;|][[:space:]]*|$)';
            }

            $where[] = "EXISTS (
                SELECT 1
                FROM rc_product_attributes paf{$attrIndex}
                WHERE paf{$attrIndex}.product_id = p.id
                  AND LOWER(paf{$attrIndex}.attr_name) = LOWER(:attr_name_{$attrIndex})
                  AND (" . implode(' OR ', $valueConditions) . ")
            )";
            $params['attr_name_' . $attrIndex] = $attrName;
            $attrIndex++;
        }

        $sortSql = 'p.id DESC';

        if (!empty($filters['sort'])) {
            $sort = (string)$filters['sort'];

            if ($sort === 'price_asc') {
                $sortSql = 'p.price ASC, p.id DESC';
            } elseif ($sort === 'price_desc') {
                $sortSql = 'p.price DESC, p.id DESC';
            } elseif ($sort === 'stock') {
                $sortSql = 'p.stock_qty DESC, p.id DESC';
            } elseif ($sort === 'name') {
                $sortSql = 'p.name ASC';
            }
        }

        $rows = Database::fetchAll(
            "SELECT
                p.id,
                p.category_id,
                p.sku,
                p.name,
                p.brand,
                p.unit,
                p.price,
                p.old_price,
                p.stock_qty,
                p.status,
                p.image,
                p.description,
                c.slug AS category_slug,
                c.name AS category_name,
                (
                    SELECT i.image_path
                    FROM rc_product_images i
                    WHERE i.product_id = p.id
                    ORDER BY i.is_main DESC, i.sort_order ASC, i.id ASC
                    FETCH FIRST 1 ROWS ONLY
                ) AS main_image
             FROM rc_products p
             JOIN rc_categories c ON c.id = p.category_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY {$sortSql}",
            $params
        );

        $products = [];

        foreach ($rows as $row) {
            $products[] = $this->mapProductRow($row, false);
        }

        return $products;
    }

    public function product(int $id): ?array
    {
        $row = Database::fetchOne(
            "SELECT
                p.id,
                p.category_id,
                p.sku,
                p.name,
                p.brand,
                p.unit,
                p.price,
                p.old_price,
                p.stock_qty,
                p.status,
                p.image,
                p.description,
                c.slug AS category_slug,
                c.name AS category_name,
                (
                    SELECT i.image_path
                    FROM rc_product_images i
                    WHERE i.product_id = p.id
                    ORDER BY i.is_main DESC, i.sort_order ASC, i.id ASC
                    FETCH FIRST 1 ROWS ONLY
                ) AS main_image
             FROM rc_products p
             JOIN rc_categories c ON c.id = p.category_id
             WHERE p.is_active = 1
               AND NVL(p.status, 'in_stock') <> 'archived'
               AND p.id = :id",
            ['id' => $id]
        );

        if (!$row) {
            return null;
        }

        return $this->mapProductRow($row, true);
    }

    public function productBySku(string $sku): ?array
    {
        $row = Database::fetchOne(
            "SELECT
                p.id,
                p.category_id,
                p.sku,
                p.name,
                p.brand,
                p.unit,
                p.price,
                p.old_price,
                p.stock_qty,
                p.status,
                p.image,
                p.description,
                c.slug AS category_slug,
                c.name AS category_name,
                (
                    SELECT i.image_path
                    FROM rc_product_images i
                    WHERE i.product_id = p.id
                    ORDER BY i.is_main DESC, i.sort_order ASC, i.id ASC
                    FETCH FIRST 1 ROWS ONLY
                ) AS main_image
             FROM rc_products p
             JOIN rc_categories c ON c.id = p.category_id
             WHERE p.is_active = 1
               AND NVL(p.status, 'in_stock') <> 'archived'
               AND p.sku = :sku",
            ['sku' => $sku]
        );

        if (!$row) {
            return null;
        }

        return $this->mapProductRow($row, true);
    }

    public function featuredProducts(int $limit = 8): array
    {
        $rows = Database::fetchAll(
            "SELECT
                p.id,
                p.category_id,
                p.sku,
                p.name,
                p.brand,
                p.unit,
                p.price,
                p.old_price,
                p.stock_qty,
                p.status,
                p.image,
                p.description,
                c.slug AS category_slug,
                c.name AS category_name,
                (
                    SELECT i.image_path
                    FROM rc_product_images i
                    WHERE i.product_id = p.id
                    ORDER BY i.is_main DESC, i.sort_order ASC, i.id ASC
                    FETCH FIRST 1 ROWS ONLY
                ) AS main_image
             FROM rc_products p
             JOIN rc_categories c ON c.id = p.category_id
             WHERE p.is_active = 1
               AND NVL(p.status, 'in_stock') <> 'archived'
             ORDER BY p.created_at DESC, p.id DESC
             FETCH FIRST {$limit} ROWS ONLY"
        );

        $products = [];

        foreach ($rows as $row) {
            $products[] = $this->mapProductRow($row, false);
        }

        return $products;
    }
public function reviews(int $limit = 6): array
{
    try {
        $table = Database::fetchOne(
            "SELECT COUNT(*) AS cnt
             FROM user_tables
             WHERE table_name = 'RC_REVIEWS'"
        );

        if (!$table || (int)$table['CNT'] === 0) {
            return [];
        }

        $limit = max(1, min($limit, 20));

        $rows = Database::fetchAll(
            "SELECT
                r.id,
                COALESCE(r.customer_name, r.author_name, 'Клієнт RungoCraft') AS customer_name,
                r.rating,
                r.review_text,
                r.created_at,
                r.product_id,
                p.name AS product_name,
                CASE WHEN r.product_id IS NULL THEN 'site' ELSE 'product' END AS review_type
             FROM rc_reviews r
             LEFT JOIN rc_products p ON p.id = r.product_id
             WHERE NVL(r.is_active, r.is_approved) = 1
             ORDER BY r.created_at DESC, r.id DESC
             FETCH FIRST {$limit} ROWS ONLY"
        );

        $reviews = [];

        foreach ($rows as $row) {
            $reviews[] = [
                'id' => (int)$row['ID'],
                'name' => (string)$row['CUSTOMER_NAME'],
                'author' => (string)$row['CUSTOMER_NAME'],
                'rating' => (int)$row['RATING'],
                'text' => $this->dbValueToString($row['REVIEW_TEXT'] ?? ''),
                'created_at' => (string)$row['CREATED_AT'],
                'type' => (string)($row['REVIEW_TYPE'] ?? 'site'),
                'product_id' => isset($row['PRODUCT_ID']) ? (int)$row['PRODUCT_ID'] : null,
                'product_name' => (string)($row['PRODUCT_NAME'] ?? ''),
            ];
        }

        return $reviews;
    } catch (Throwable) {
        return [];
    }
}
    private function mapProductRow(array $row, bool $withDetails): array
    {
        $id = (int)$row['ID'];

        $attrs = [];
        $images = [];

        if ($withDetails) {
            $attrRows = Database::fetchAll(
                "SELECT attr_name, attr_value
                 FROM rc_product_attributes
                 WHERE product_id = :id
                 ORDER BY sort_order, id",
                ['id' => $id]
            );

            foreach ($attrRows as $attrRow) {
                $attrs[(string)$attrRow['ATTR_NAME']] = (string)$attrRow['ATTR_VALUE'];
            }

            $imageRows = Database::fetchAll(
                "SELECT image_path, alt_text, is_main
                 FROM rc_product_images
                 WHERE product_id = :id
                 ORDER BY is_main DESC, sort_order, id",
                ['id' => $id]
            );

            foreach ($imageRows as $imageRow) {
                $images[] = [
                    'path' => (string)$imageRow['IMAGE_PATH'],
                    'alt' => (string)($imageRow['ALT_TEXT'] ?? $row['NAME']),
                    'is_main' => (int)$imageRow['IS_MAIN'] === 1,
                ];
            }
        } else {
            $attrRows = Database::fetchAll(
                "SELECT attr_name, attr_value
                 FROM rc_product_attributes
                 WHERE product_id = :id
                 ORDER BY sort_order, id
                 FETCH FIRST 3 ROWS ONLY",
                ['id' => $id]
            );
            foreach ($attrRows as $attrRow) {
                $attrs[(string)$attrRow['ATTR_NAME']] = (string)$attrRow['ATTR_VALUE'];
            }
        }

        $mainImage = trim((string)($row['MAIN_IMAGE'] ?? $row['IMAGE'] ?? 'cement.svg'));
        if ($mainImage === '') {
            $mainImage = 'cement.svg';
        }
        if ($withDetails && $images === [] && $mainImage !== 'cement.svg') {
            $images[] = [
                'path' => $mainImage,
                'alt' => (string)$row['NAME'],
                'is_main' => true,
            ];
        }
        $reviewStats = $this->productReviewStats($id);
        $userProductState = $this->userProductState($id);

        return [
            'id' => $id,
            'category_id' => (int)$row['CATEGORY_ID'],
            'category' => (string)$row['CATEGORY_SLUG'],
            'category_name' => (string)$row['CATEGORY_NAME'],
            'sku' => (string)$row['SKU'],
            'name' => (string)$row['NAME'],
            'brand' => (string)($row['BRAND'] ?? ''),
            'unit' => (string)($row['UNIT'] ?? 'шт.'),
            'price' => (float)$row['PRICE'],
            'old_price' => $row['OLD_PRICE'] !== null ? (float)$row['OLD_PRICE'] : null,
            'stock' => (float)$row['STOCK_QTY'],
            'stock_qty' => (float)$row['STOCK_QTY'],
            'status' => (string)($row['STATUS'] ?? 'in_stock'),
            'rating' => $reviewStats['rating'],
            'review_count' => $reviewStats['count'],
            'image' => $mainImage,
            'images' => $images,
            'is_wished' => $userProductState['wished'],
            'is_compared' => $userProductState['compared'],
            'description' => $this->dbValueToString($row['DESCRIPTION'] ?? ''),
            'attrs' => $attrs,
        ];
    }

    private function buildCategoryTree(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $id = (int)($row['ID'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $items[$id] = [
                'id' => $id,
                'parent_id' => $row['PARENT_ID'] !== null ? (int)$row['PARENT_ID'] : null,
                'slug' => (string)$row['SLUG'],
                'name' => (string)$row['NAME'],
                'icon' => (string)($row['ICON'] ?? '▦'),
                'description' => $this->dbValueToString($row['DESCRIPTION'] ?? ''),
                'sort_order' => (int)($row['SORT_ORDER'] ?? 100),
                'children' => [],
            ];
        }

        $childrenByParent = [];
        foreach ($items as $id => $item) {
            $parentId = $item['parent_id'];
            if ($parentId === null || !isset($items[$parentId])) {
                $parentId = 0;
            }
            $childrenByParent[$parentId][] = $id;
        }

        $build = function (int $parentId) use (&$build, &$childrenByParent, &$items): array {
            $ids = $childrenByParent[$parentId] ?? [];
            usort($ids, static function (int $left, int $right) use (&$items): int {
                $bySort = ($items[$left]['sort_order'] ?? 100) <=> ($items[$right]['sort_order'] ?? 100);
                if ($bySort !== 0) {
                    return $bySort;
                }
                return strnatcasecmp((string)$items[$left]['name'], (string)$items[$right]['name']);
            });

            $result = [];
            foreach ($ids as $id) {
                $item = $items[$id];
                $item['children'] = $build($id);
                $result[] = $item;
            }
            return $result;
        };

        return $build(0);
    }

    private function appendCategoryFilter(array &$where, array &$params, string $categorySlug, string $categoryAlias = 'c'): void
    {
        $categorySlug = trim($categorySlug);
        if ($categorySlug === '') {
            return;
        }

        $paramBase = 'category_' . count($params);
        $where[] = "{$categoryAlias}.id IN (
            SELECT id
            FROM rc_categories
            WHERE NVL(is_active, 1) = 1
            START WITH slug = :{$paramBase}
            CONNECT BY PRIOR id = parent_id
        )";
        $params[$paramBase] = $categorySlug;
    }


    private function splitAttributeFilterValues(string $value): array
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:\/|,|;|\|)\s*/u', $value) ?: [$value];
        $result = [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part === '') {
                continue;
            }
            $result[$part] = true;
        }

        return array_keys($result);
    }

    private function escapeRegexValue(string $value): string
    {
        return preg_quote($value, '/');
    }

    private function userProductState(int $productId): array
    {
        $user = function_exists('current_user') ? current_user() : null;
        $userId = (int)($user['id'] ?? 0);
        if ($productId <= 0 || $userId <= 0) {
            return ['wished' => false, 'compared' => false];
        }

        try {
            $wish = Database::fetchOne(
                'SELECT 1 AS ok FROM rc_wishlist WHERE user_id = :user_id AND product_id = :product_id FETCH FIRST 1 ROWS ONLY',
                ['user_id' => $userId, 'product_id' => $productId]
            );
            $compare = Database::fetchOne(
                'SELECT 1 AS ok FROM rc_comparison_items WHERE user_id = :user_id AND product_id = :product_id FETCH FIRST 1 ROWS ONLY',
                ['user_id' => $userId, 'product_id' => $productId]
            );
            return ['wished' => (bool)$wish, 'compared' => (bool)$compare];
        } catch (Throwable) {
            return ['wished' => false, 'compared' => false];
        }
    }

    private function productReviewStats(int $productId): array
    {
        try {
            $row = Database::fetchOne(
                "SELECT ROUND(AVG(rating), 1) AS avg_rating, COUNT(*) AS cnt
                 FROM rc_reviews
                 WHERE product_id = :product_id
                   AND NVL(is_active, is_approved) = 1",
                ['product_id' => $productId]
            );
            $count = (int)($row['CNT'] ?? 0);
            return [
                'rating' => $count > 0 ? (float)$row['AVG_RATING'] : 0.0,
                'count' => $count,
            ];
        } catch (Throwable) {
            return ['rating' => 0.0, 'count' => 0];
        }
    }

    private function dbValueToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_object($value) && method_exists($value, 'load')) {
            $loaded = $value->load();
            return is_string($loaded) ? $loaded : '';
        }

        return (string)$value;
    }
}