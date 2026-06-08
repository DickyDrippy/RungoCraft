<?php
declare(strict_types=1);

final class AdminService
{
    public function handle(string $action, array $data, ?array $user): array
    {
        if (!$user || empty($user['id'])) {
            return ['ok' => false, 'message' => 'Потрібно увійти в кабінет працівника.'];
        }

        return match ($action) {
            'admin_create_category' => $this->createCategory($data, $user),
            'admin_delete_category' => $this->deleteCategory($data, $user),
            'admin_create_product' => $this->createProduct($data, $user),
            'admin_update_stock' => $this->updateStock($data, $user),
            'admin_update_order_status' => $this->updateOrderStatus($data, $user),
            'admin_update_settings' => $this->updateSettings($data, $user),
            'admin_generate_staff_key' => $this->generateStaffKey($data, $user),
            'admin_toggle_staff_key' => $this->toggleStaffKey($data, $user),
            'admin_update_product' => $this->updateProduct($data, $user),
            'admin_delete_product' => $this->deleteProduct($data, $user),
            'admin_delete_product_image' => $this->deleteProductImage($data, $user),
            default => ['ok' => false, 'message' => 'Невідома дія панелі керування.'],
        };
    }

    public function dashboardData(): array
    {
        return [
            'stats' => $this->stats(),
            'categories' => $this->categoriesForAdmin(),
            'products' => $this->productsForAdmin(),
            'orders' => $this->ordersForAdmin(),
            'settings' => $this->settingsForAdmin(),
            'logs' => $this->logsForAdmin(),
            'staff_users' => $this->staffUsersForAdmin(),
            'staff_keys' => $this->staffKeysForAdmin(),
        ];
    }

    private function createCategory(array $data, array $user): array
    {
        if (!$this->hasRole($user, ['admin', 'manager'])) {
            return ['ok' => false, 'message' => 'Недостатньо прав для створення категорії.'];
        }

        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        $icon = trim((string)($data['icon'] ?? '▦'));
        $description = trim((string)($data['description'] ?? ''));
        $sortOrder = (int)($data['sort_order'] ?? 100);
        $parentId = (int)($data['parent_id'] ?? 0);

        if ($name === '') {
            return ['ok' => false, 'message' => 'Вкажіть назву категорії.'];
        }

        if ($slug === '') {
            $slug = $this->slugify($name);
        }

        $ok = Database::execute(
            "MERGE INTO rc_categories c
             USING (
                SELECT
                    :slug AS slug,
                    :name AS name,
                    :icon AS icon,
                    :description AS description,
                    :sort_order AS sort_order,
                    CASE WHEN :parent_id > 0 THEN :parent_id ELSE NULL END AS parent_id
                FROM dual
             ) v
             ON (c.slug = v.slug)
             WHEN MATCHED THEN UPDATE SET
                c.name = v.name,
                c.icon = v.icon,
                c.description = v.description,
                c.sort_order = v.sort_order,
                c.parent_id = v.parent_id,
                c.is_active = 1
             WHEN NOT MATCHED THEN INSERT (slug, name, icon, description, sort_order, parent_id, is_active)
             VALUES (v.slug, v.name, v.icon, v.description, v.sort_order, v.parent_id, 1)",
            [
                'slug' => $slug,
                'name' => $name,
                'icon' => $icon !== '' ? $icon : '▦',
                'description' => $description,
                'sort_order' => $sortOrder,
                'parent_id' => $parentId,
            ]
        );

        if (!$ok) {
            return ['ok' => false, 'message' => 'Категорію не збережено: ' . (Database::lastError() ?? 'невідома помилка')];
        }

        $this->log((int)$user['id'], 'category_save', 'category', null, ['slug' => $slug, 'name' => $name]);
        return ['ok' => true, 'message' => 'Категорію збережено.'];
    }

    private function deleteCategory(array $data, array $user): array
    {
        if (!$this->hasRole($user, ['admin', 'manager'])) {
            return ['ok' => false, 'message' => 'Недостатньо прав для видалення категорії.'];
        }

        $categoryId = (int)($data['category_id'] ?? 0);
        if ($categoryId <= 0) {
            return ['ok' => false, 'message' => 'Категорію не знайдено.'];
        }

        $category = Database::fetchOne(
            'SELECT id, name, parent_id FROM rc_categories WHERE id = :id',
            ['id' => $categoryId]
        );
        if (!$category) {
            return ['ok' => false, 'message' => 'Категорію не знайдено.'];
        }

        $childRows = Database::fetchAll(
            'SELECT id FROM rc_categories WHERE parent_id = :id AND NVL(is_active, 1) = 1',
            ['id' => $categoryId]
        );
        $categoryIds = [$categoryId];
        foreach ($childRows as $child) {
            $categoryIds[] = (int)($child['ID'] ?? 0);
        }
        $categoryIds = array_values(array_filter(array_unique($categoryIds)));

        $placeholders = [];
        $params = [];
        foreach ($categoryIds as $index => $id) {
            $key = 'cid' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $products = Database::fetchOne(
            'SELECT COUNT(*) AS cnt FROM rc_products WHERE category_id IN (' . implode(',', $placeholders) . ') AND NVL(is_active, 1) = 1',
            $params
        );
        if ((int)($products['CNT'] ?? 0) > 0) {
            return ['ok' => false, 'message' => 'Спочатку перенесіть або видаліть товари цієї категорії. Категорію з товарами не видаляю, щоб не зламати каталог.'];
        }

        $ok = Database::execute(
            'UPDATE rc_categories SET is_active = 0 WHERE id IN (' . implode(',', $placeholders) . ')',
            $params
        );

        if (!$ok) {
            return ['ok' => false, 'message' => 'Категорію не видалено: ' . (Database::lastError() ?? 'невідома помилка')];
        }

        $this->log((int)$user['id'], 'category_delete', 'category', $categoryId, ['name' => (string)($category['NAME'] ?? '')]);
        return ['ok' => true, 'message' => count($categoryIds) > 1 ? 'Категорію та її підкатегорії видалено з каталогу.' : 'Категорію видалено з каталогу.', 'return_to' => '/admin#categories'];
    }

    private function createProduct(array $data, array $user): array
    {
        if (!$this->hasRole($user, ['admin', 'manager'])) {
            return ['ok' => false, 'message' => 'Недостатньо прав для створення товару.'];
        }

        $categoryId = (int)($data['category_id'] ?? 0);
        $sku = trim((string)($data['sku'] ?? ''));
        $name = trim((string)($data['name'] ?? ''));
        $brand = trim((string)($data['brand'] ?? ''));
        $unit = trim((string)($data['unit'] ?? 'шт.'));
        $price = (float)str_replace(',', '.', (string)($data['price'] ?? '0'));
        $oldPriceRaw = trim((string)($data['old_price'] ?? ''));
        $oldPrice = $oldPriceRaw !== '' ? (float)str_replace(',', '.', $oldPriceRaw) : null;
        $stockQty = (float)str_replace(',', '.', (string)($data['stock_qty'] ?? '0'));
        $status = $this->normalizeProductStatusForStock($stockQty, (string)($data['status'] ?? 'in_stock'));
        $image = 'cement.svg';
        $uploadedImages = $this->saveProductImages('product_images');
        if ($uploadedImages !== []) {
            $image = $uploadedImages[0];
        }
        $description = trim((string)($data['description'] ?? ''));
        $attrsText = trim((string)($data['attributes'] ?? ''));

        if ($categoryId <= 0 || $sku === '' || $name === '' || $price <= 0) {
            return ['ok' => false, 'message' => 'Заповніть категорію, артикул, назву та ціну товару.'];
        }

        $exists = Database::fetchOne('SELECT id FROM rc_products WHERE sku = :sku', ['sku' => $sku]);
        if ($exists) {
            return ['ok' => false, 'message' => 'Товар з таким артикулом уже існує.'];
        }

        $ok = Database::execute(
            "INSERT INTO rc_products (
                category_id, sku, name, brand, unit, price, old_price, stock_qty, status, image, description, is_active
             ) VALUES (
                :category_id, :sku, :name, :brand, :unit, :price, :old_price, :stock_qty, :status, :image, :description, 1
             )",
            [
                'category_id' => $categoryId,
                'sku' => $sku,
                'name' => $name,
                'brand' => $brand !== '' ? $brand : null,
                'unit' => $unit !== '' ? $unit : 'шт.',
                'price' => $price,
                'old_price' => $oldPrice,
                'stock_qty' => $stockQty,
                'status' => $status,
                'image' => $image !== '' ? $image : 'cement.svg',
                'description' => $description,
            ]
        );

        if (!$ok) {
            return ['ok' => false, 'message' => 'Товар не створено: ' . (Database::lastError() ?? 'невідома помилка')];
        }

        $product = Database::fetchOne('SELECT id FROM rc_products WHERE sku = :sku', ['sku' => $sku]);
        $productId = (int)($product['ID'] ?? 0);

        if ($productId > 0 && $uploadedImages !== []) {
            $imageLines = array_values(array_unique($uploadedImages));
            $sortImage = 10;
            foreach ($imageLines as $idx => $imagePath) {
                Database::execute(
                    "INSERT INTO rc_product_images (product_id, image_path, alt_text, sort_order, is_main)
                     VALUES (:product_id, :image_path, :alt_text, :sort_order, :is_main)",
                    ['product_id' => $productId, 'image_path' => $imagePath, 'alt_text' => $name, 'sort_order' => $sortImage, 'is_main' => $idx === 0 ? 1 : 0]
                );
                $sortImage += 10;
            }
        }

        if ($productId > 0 && $attrsText !== '') {
            $this->saveAttributes($productId, $attrsText);
        }

        $this->log((int)$user['id'], 'product_create', 'product', $productId, ['sku' => $sku, 'name' => $name]);
        return ['ok' => true, 'message' => 'Товар додано в каталог.'];
    }

    private function updateStock(array $data, array $user): array
    {
        if (!$this->hasRole($user, ['admin', 'manager', 'warehouse'])) {
            return ['ok' => false, 'message' => 'Недостатньо прав для оновлення залишків.'];
        }

        $productId = (int)($data['product_id'] ?? 0);
        $stockQty = (float)str_replace(',', '.', (string)($data['stock_qty'] ?? '0'));
        $status = $this->normalizeProductStatusForStock($stockQty, (string)($data['status'] ?? 'in_stock'));
        $priceRaw = trim((string)($data['price'] ?? ''));

        if ($productId <= 0) {
            return ['ok' => false, 'message' => 'Не обрано товар для оновлення.'];
        }

        $sql = "UPDATE rc_products
                SET stock_qty = :stock_qty,
                    status = :status,
                    updated_at = CURRENT_TIMESTAMP";
        $params = ['stock_qty' => $stockQty, 'status' => $status, 'id' => $productId];

        if ($priceRaw !== '') {
            $sql .= ', price = :price';
            $params['price'] = (float)str_replace(',', '.', $priceRaw);
        }

        $sql .= ' WHERE id = :id';

        $ok = Database::execute($sql, $params);
        if (!$ok) {
            return ['ok' => false, 'message' => 'Залишки не оновлено: ' . (Database::lastError() ?? 'невідома помилка')];
        }

        $this->log((int)$user['id'], 'stock_update', 'product', $productId, ['stock_qty' => $stockQty, 'status' => $status]);
        return ['ok' => true, 'message' => 'Залишки товару оновлено.'];
    }

    private function updateOrderStatus(array $data, array $user): array
    {
        if (!$this->hasRole($user, ['admin', 'manager', 'warehouse'])) {
            return ['ok' => false, 'message' => 'Недостатньо прав для зміни статусу замовлення.'];
        }

        $orderId = (int)($data['order_id'] ?? 0);
        $status = trim((string)($data['status'] ?? ''));
        $comment = trim((string)($data['comment_text'] ?? ''));
        $allowed = [
            'created', 'waiting_confirmation', 'confirmed', 'waiting_payment', 'paid',
            'packing', 'packed', 'sent', 'delivering', 'delivered', 'cancelled', 'returned',
            'processing', 'picking', 'ready_for_delivery', 'completed', 'done'
        ];

        if ($orderId <= 0 || !in_array($status, $allowed, true)) {
            return ['ok' => false, 'message' => 'Оберіть коректне замовлення і статус.'];
        }

        $order = Database::fetchOne('SELECT status, payment_type, payment_status, delivery_ttn FROM rc_orders WHERE id = :id', ['id' => $orderId]);
        if (!$order) {
            return ['ok' => false, 'message' => 'Замовлення не знайдено.'];
        }

        $reserved = Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM rc_order_reservations WHERE order_id = :order_id AND status = 'reserved'",
            ['order_id' => $orderId]
        );
        $reservedCount = (int)($reserved['CNT'] ?? 0);
        $currentOrderStatus = (string)($order['STATUS'] ?? '');

        if (in_array($status, ['packing','packed'], true) && $reservedCount <= 0) {
            return ['ok' => false, 'message' => 'Перед комплектацією потрібно зарезервувати товари на складі.'];
        }

        if ($status === 'ready_for_delivery'
            && $reservedCount <= 0
            && !in_array($currentOrderStatus, ['packed','ready_for_delivery','sent','delivering'], true)) {
            return ['ok' => false, 'message' => 'Перед відправкою замовлення має бути зарезервоване і зібране складом.'];
        }

        if (in_array($status, ['sent','delivering','delivered'], true) && trim((string)($order['DELIVERY_TTN'] ?? '')) === '') {
            return ['ok' => false, 'message' => 'Перед статусом доставки потрібно створити або ввести ТТН.'];
        }

        if ($status === 'confirmed') {
            
            $comment = $comment !== '' ? $comment : 'Замовлення підтверджено менеджером і передано до складського алгоритму.';
        }

        $ok = Database::execute(
            "UPDATE rc_orders
             SET status = :status,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            ['status' => $status, 'id' => $orderId]
        );

        if (!$ok) {
            return ['ok' => false, 'message' => 'Статус не оновлено: ' . (Database::lastError() ?? 'невідома помилка')];
        }

        Database::execute(
            "INSERT INTO rc_order_status_history (order_id, status, comment_text, user_id)
             VALUES (:order_id, :status, :comment_text, :created_by)",
            [
                'order_id' => $orderId,
                'status' => $status,
                'comment_text' => $comment !== '' ? $comment : null,
                'created_by' => (int)$user['id'],
            ]
        );

        if (in_array($status, ['packed', 'completed', 'done', 'delivered'], true)) {
            $this->consumeReservedStock($orderId, $user);
        } elseif (in_array($status, ['cancelled', 'returned'], true)) {
            $this->releaseReservedStock($orderId);
        }

        (new NotificationService())->notifyOrderStatus($orderId, $status);
        $this->log((int)$user['id'], 'order_status_update', 'order', $orderId, ['status' => $status, 'comment' => $comment]);

        return ['ok' => true, 'message' => 'Статус замовлення оновлено.'];
    }

    private function consumeReservedStock(int $orderId, array $user): void
    {
        $reservations = Database::fetchAll(
            "SELECT id, warehouse_id, product_id, quantity
             FROM rc_order_reservations
             WHERE order_id = :order_id AND status = 'reserved'",
            ['order_id' => $orderId]
        );
        foreach ($reservations as $reservation) {
            $productId = (int)$reservation['PRODUCT_ID'];
            Database::execute(
                "UPDATE rc_stock
                 SET qty_total = GREATEST(0, qty_total - :qty),
                     qty_reserved = GREATEST(0, qty_reserved - :qty),
                     updated_at = CURRENT_TIMESTAMP
                 WHERE warehouse_id = :warehouse_id AND product_id = :product_id",
                [
                    'qty' => (float)$reservation['QUANTITY'],
                    'warehouse_id' => (int)$reservation['WAREHOUSE_ID'],
                    'product_id' => $productId,
                ]
            );
            Database::execute(
                "UPDATE rc_order_reservations
                 SET status = 'completed', released_at = CURRENT_TIMESTAMP
                 WHERE id = :id",
                ['id' => (int)$reservation['ID']]
            );
            Database::execute(
                "INSERT INTO rc_stock_movements (warehouse_id, product_id, user_id, movement_type, quantity, comment_text, order_id)
                 VALUES (:warehouse_id, :product_id, :user_id, 'order_pack', :quantity, 'Списання під виконане замовлення', :order_id)",
                [
                    'warehouse_id' => (int)$reservation['WAREHOUSE_ID'],
                    'product_id' => $productId,
                    'user_id' => (int)($user['id'] ?? 0),
                    'quantity' => -abs((float)$reservation['QUANTITY']),
                    'order_id' => $orderId,
                ]
            );
            $this->refreshProductStock($productId);
        }
    }

    private function releaseReservedStock(int $orderId): void
    {
        $reservations = Database::fetchAll(
            "SELECT id, warehouse_id, product_id, quantity
             FROM rc_order_reservations
             WHERE order_id = :order_id AND status = 'reserved'",
            ['order_id' => $orderId]
        );
        foreach ($reservations as $reservation) {
            $productId = (int)$reservation['PRODUCT_ID'];
            Database::execute(
                "UPDATE rc_stock
                 SET qty_reserved = GREATEST(0, qty_reserved - :qty), updated_at = CURRENT_TIMESTAMP
                 WHERE warehouse_id = :warehouse_id AND product_id = :product_id",
                [
                    'qty' => (float)$reservation['QUANTITY'],
                    'warehouse_id' => (int)$reservation['WAREHOUSE_ID'],
                    'product_id' => $productId,
                ]
            );
            Database::execute(
                "UPDATE rc_order_reservations SET status = 'released', released_at = CURRENT_TIMESTAMP WHERE id = :id",
                ['id' => (int)$reservation['ID']]
            );
            $this->refreshProductStock($productId);
        }
    }

    private function refreshProductStock(int $productId): void
    {
        if ($productId <= 0) {
            return;
        }
        Database::execute(
            "UPDATE rc_products
             SET stock_qty = (
                 SELECT NVL(SUM(qty_total - qty_reserved), 0)
                 FROM rc_stock
                 WHERE product_id = :product_id
             ),
             status = CASE
                WHEN status IN ('preorder', 'expected', 'archived') THEN status
                WHEN (SELECT NVL(SUM(qty_total - qty_reserved), 0) FROM rc_stock WHERE product_id = :product_id) <= 0 THEN 'out_of_stock'
                WHEN (SELECT NVL(SUM(qty_total - qty_reserved), 0) FROM rc_stock WHERE product_id = :product_id) <= 2 THEN 'low_stock'
                ELSE 'in_stock'
             END,
             updated_at = CURRENT_TIMESTAMP
             WHERE id = :product_id",
            ['product_id' => $productId]
        );
    }

    private function updateSettings(array $data, array $user): array
    {
        if (!$this->hasRole($user, ['admin'])) {
            return ['ok' => false, 'message' => 'Налаштування сайту може змінювати тільки адміністратор.'];
        }

        $allowed = ['phone_label', 'phone', 'email', 'worktime', 'address', 'telegram_url', 'youtube_url', 'google_maps_url'];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            Database::execute(
                "MERGE INTO rc_site_settings s
                 USING (SELECT :setting_key AS setting_key, :setting_value AS setting_value FROM dual) v
                 ON (s.setting_key = v.setting_key)
                 WHEN MATCHED THEN UPDATE SET s.setting_value = v.setting_value, s.updated_at = CURRENT_TIMESTAMP
                 WHEN NOT MATCHED THEN INSERT (setting_key, setting_value) VALUES (v.setting_key, v.setting_value)",
                ['setting_key' => $key, 'setting_value' => trim((string)$data[$key])]
            );
        }

        $this->log((int)$user['id'], 'settings_update', 'settings', null, ['keys' => $allowed]);
        return ['ok' => true, 'message' => 'Налаштування сайту оновлено.'];
    }

    private function categoriesForAdmin(): array
    {
        $rows = Database::fetchAll(
            "SELECT c.id, c.parent_id, c.slug, c.name, c.icon, c.sort_order, c.is_active,
                    p.name AS parent_name
             FROM rc_categories c
             LEFT JOIN rc_categories p ON p.id = c.parent_id
             WHERE NVL(c.is_active, 1) = 1"
        );

        if (!$rows) {
            return [];
        }

        $byId = [];
        foreach ($rows as $row) {
            $id = (int)($row['ID'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $byId[$id] = $row;
        }

        $childrenByParent = [];
        foreach ($byId as $id => $row) {
            $parentId = $row['PARENT_ID'] !== null ? (int)$row['PARENT_ID'] : 0;
            if ($parentId <= 0 || !isset($byId[$parentId])) {
                $parentId = 0;
            }
            $childrenByParent[$parentId][] = $id;
        }

        $sortIds = static function (array &$ids) use (&$byId): void {
            usort($ids, static function (int $left, int $right) use (&$byId): int {
                $leftSort = (int)($byId[$left]['SORT_ORDER'] ?? 100);
                $rightSort = (int)($byId[$right]['SORT_ORDER'] ?? 100);
                if ($leftSort !== $rightSort) {
                    return $leftSort <=> $rightSort;
                }
                return strnatcasecmp((string)($byId[$left]['NAME'] ?? ''), (string)($byId[$right]['NAME'] ?? ''));
            });
        };

        $flat = [];
        $walk = function (int $parentId, int $level, string $parentPath) use (&$walk, &$childrenByParent, &$byId, &$flat, $sortIds): void {
            $ids = $childrenByParent[$parentId] ?? [];
            $sortIds($ids);

            foreach ($ids as $id) {
                $row = $byId[$id];
                $name = (string)($row['NAME'] ?? '');
                $path = $parentPath !== '' ? $parentPath . ' / ' . $name : $name;
                $row['TREE_LEVEL'] = $level;
                $row['TREE_PATH'] = $path;
                $flat[] = $row;
                $walk($id, $level + 1, $path);
            }
        };

        $walk(0, 0, '');
        return $flat;
    }

    private function productsForAdmin(): array
    {
        $rows = Database::fetchAll(
            "SELECT p.id, p.category_id, p.sku, p.name, p.brand, p.unit, p.price, p.old_price,
                    p.stock_qty, p.status, p.image, p.description,
                    c.name AS category_name
             FROM rc_products p
             JOIN rc_categories c ON c.id = p.category_id
             WHERE NVL(p.is_active, 1) = 1
             ORDER BY p.updated_at DESC, p.id DESC
             FETCH FIRST 80 ROWS ONLY"
        );

        if (!$rows) {
            return [];
        }

        $ids = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['ID'] ?? 0), $rows)));
        if ($ids === []) {
            return $rows;
        }

        $imagesByProduct = [];
        $placeholders = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $key = 'pid' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        try {
            $imageRows = Database::fetchAll(
                'SELECT id, product_id, image_path, is_main, sort_order FROM rc_product_images WHERE product_id IN (' . implode(',', $placeholders) . ') ORDER BY product_id, is_main DESC, sort_order, id',
                $params
            );
            foreach ($imageRows as $imageRow) {
                $pid = (int)($imageRow['PRODUCT_ID'] ?? 0);
                $imagesByProduct[$pid][] = $imageRow;
            }
        } catch (Throwable) {
            $imagesByProduct = [];
        }

        foreach ($rows as &$row) {
            $row['PRODUCT_IMAGES'] = $imagesByProduct[(int)($row['ID'] ?? 0)] ?? [];
        }
        unset($row);

        return $rows;
    }

    private function ordersForAdmin(): array
    {
        return Database::fetchAll(
            "SELECT id, customer_name, customer_phone, customer_email, status, payment_type, delivery_type,
                    delivery_status, delivery_ttn, total_amount, created_at, updated_at
             FROM rc_orders
             ORDER BY created_at DESC, id DESC
             FETCH FIRST 50 ROWS ONLY"
        );
    }


    private function staffUsersForAdmin(): array
    {
        return Database::fetchAll(
            "SELECT u.id, u.full_name, u.email, u.phone, r.code AS role_code, u.is_active
             FROM rc_users u
             JOIN rc_roles r ON r.id = u.role_id
             WHERE r.code IN ('admin','manager','warehouse')
             ORDER BY r.code, u.full_name"
        );
    }

    private function staffKeysForAdmin(): array
    {
        $exists = Database::fetchOne("SELECT COUNT(*) AS cnt FROM user_tables WHERE table_name = 'RC_STAFF_FILE_KEYS'");
        if ((int)($exists['CNT'] ?? 0) <= 0) {
            return [];
        }
        $columns = $this->columns('RC_STAFF_FILE_KEYS');
        $hasFileSql = isset($columns['KEY_FILE_CONTENT']) ? "CASE WHEN DBMS_LOB.GETLENGTH(k.key_file_content) > 0 THEN 1 ELSE 0 END" : "0";
        return Database::fetchAll(
            "SELECT k.id, k.user_id, k.label, k.is_active, k.created_at, k.revoked_at, {$hasFileSql} AS has_file, u.full_name, u.email, r.code AS role_code
             FROM rc_staff_file_keys k
             JOIN rc_users u ON u.id = k.user_id
             JOIN rc_roles r ON r.id = u.role_id
             ORDER BY k.created_at DESC, k.id DESC
             FETCH FIRST 50 ROWS ONLY"
        );
    }

    private function generateStaffKey(array $data, array $user): array
    {
        if (!$this->hasRole($user, ['admin'])) {
            return ['ok' => false, 'message' => 'Файлові ключі може створювати тільки адміністратор.'];
        }
        $staffUserId = (int)($data['staff_user_id'] ?? 0);
        $label = trim((string)($data['label'] ?? 'RungoCraft staff key'));
        if ($staffUserId <= 0) {
            return ['ok' => false, 'message' => 'Оберіть працівника для ключа.'];
        }
        $table = Database::fetchOne("SELECT COUNT(*) AS cnt FROM user_tables WHERE table_name = 'RC_STAFF_FILE_KEYS'");
        if ((int)($table['CNT'] ?? 0) <= 0) {
            return ['ok' => false, 'message' => 'Таблиця ключів не створена. Запустіть sql/migrations/033_staff_keys_and_site_reviews.sql.'];
        }
        $staff = Database::fetchOne(
            "SELECT u.id, u.full_name, u.email, r.code AS role_code
             FROM rc_users u JOIN rc_roles r ON r.id = u.role_id
             WHERE u.id = :id AND r.code IN ('admin','manager','warehouse')",
            ['id' => $staffUserId]
        );
        if (!$staff) {
            return ['ok' => false, 'message' => 'Працівника не знайдено або це не службова роль.'];
        }
        
        
        
        $keyBody = implode("\n", [
            'RUNGOCRAFT-STAFF-KEY',
            'user_id=' . $staffUserId,
            'role=' . (string)$staff['ROLE_CODE'],
            'created=' . date('c'),
            'secret=' . bin2hex(random_bytes(32)),
        ]);
        $hash = hash('sha256', $keyBody);
        $columns = $this->columns('RC_STAFF_FILE_KEYS');
        if (isset($columns['KEY_FILE_CONTENT'])) {
            $ok = Database::execute(
                "INSERT INTO rc_staff_file_keys (user_id, key_hash, key_file_content, label, is_active)
                 VALUES (:user_id, :key_hash, :key_file_content, :label, 1)",
                ['user_id' => $staffUserId, 'key_hash' => $hash, 'key_file_content' => $keyBody, 'label' => $label !== '' ? $label : 'Staff key']
            );
        } else {
            $ok = Database::execute(
                "INSERT INTO rc_staff_file_keys (user_id, key_hash, label, is_active)
                 VALUES (:user_id, :key_hash, :label, 1)",
                ['user_id' => $staffUserId, 'key_hash' => $hash, 'label' => $label !== '' ? $label : 'Staff key']
            );
        }
        if (!$ok) {
            return ['ok' => false, 'message' => 'Ключ не створено: ' . (Database::lastError() ?? 'невідома помилка')];
        }
        $_SESSION['staff_key_download'] = [
            'filename' => 'rungocraft_staff_key_user_' . $staffUserId . '_' . date('Ymd_His') . '.txt',
            'content' => $keyBody,
        ];
        $this->log((int)$user['id'], 'staff_key_generate', 'user', $staffUserId, ['label' => $label]);
        return ['ok' => true, 'message' => 'Ключ створено. Завантажте файл і передайте його працівнику.'];
    }

    private function updateProduct(array $data, array $user): array
    {
        if (!$this->hasRole($user, ['admin', 'manager'])) {
            return ['ok' => false, 'message' => 'Недостатньо прав для редагування товару.'];
        }

        $productId = (int)($data['product_id'] ?? 0);
        if ($productId <= 0) {
            return ['ok' => false, 'message' => 'Товар не знайдено.'];
        }

        $name = trim((string)($data['name'] ?? ''));
        $brand = trim((string)($data['brand'] ?? ''));
        $unit = trim((string)($data['unit'] ?? 'шт.'));
        $price = (float)str_replace(',', '.', (string)($data['price'] ?? '0'));
        $oldPriceRaw = trim((string)($data['old_price'] ?? ''));
        $oldPrice = $oldPriceRaw !== '' ? (float)str_replace(',', '.', $oldPriceRaw) : null;
        $stockQty = (float)str_replace(',', '.', (string)($data['stock_qty'] ?? '0'));
        $status = $this->normalizeProductStatusForStock($stockQty, (string)($data['status'] ?? 'in_stock'));
        $image = '';
        $description = trim((string)($data['description'] ?? ''));
        $attrsText = trim((string)($data['attributes'] ?? ''));
        $deleteImageIds = array_map('intval', (array)($data['delete_image_ids'] ?? []));
        $deleteImageIds = array_values(array_filter($deleteImageIds, static fn(int $id): bool => $id > 0));
        if ($deleteImageIds !== []) {
            $this->deleteProductImageRows($productId, $deleteImageIds);
        }
        $uploadedImages = $this->saveProductImages('product_images');
        if ($uploadedImages !== []) {
            $image = $uploadedImages[0];
        }

        if ($name === '' || $price <= 0) {
            return ['ok' => false, 'message' => 'Вкажіть назву і ціну товару.'];
        }

        $affected = Database::executeAffected(
            "UPDATE rc_products
             SET name = :name,
                 brand = :brand,
                 unit = :unit,
                 price = :price,
                 old_price = :old_price,
                 stock_qty = :stock_qty,
                 status = :status,
                 image = CASE WHEN :image_value IS NOT NULL THEN :image_value ELSE image END,
                 description = :description,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            [
                'name' => $name,
                'brand' => $brand !== '' ? $brand : null,
                'unit' => $unit !== '' ? $unit : 'шт.',
                'price' => $price,
                'old_price' => $oldPrice,
                'stock_qty' => $stockQty,
                'status' => $status,
                'image_value' => $image !== '' ? $image : null,
                'description' => $description !== '' ? $description : null,
                'id' => $productId,
            ]
        );

        if ($affected === null) {
            return ['ok' => false, 'message' => 'Товар не оновлено: ' . (Database::lastError() ?? 'невідома помилка')];
        }

        if ($uploadedImages !== []) {
            Database::execute('UPDATE rc_product_images SET is_main = 0 WHERE product_id = :product_id', ['product_id' => $productId]);
            $sort = 10;
            foreach ($uploadedImages as $idx => $imagePath) {
                Database::execute(
                    "INSERT INTO rc_product_images (product_id, image_path, alt_text, sort_order, is_main)
                     VALUES (:product_id, :image_path, :alt_text, :sort_order, :is_main)",
                    ['product_id' => $productId, 'image_path' => $imagePath, 'alt_text' => $name, 'sort_order' => $sort, 'is_main' => $idx === 0 ? 1 : 0]
                );
                $sort += 10;
            }
        }

        if ($attrsText !== '') {
            Database::execute('DELETE FROM rc_product_attributes WHERE product_id = :product_id', ['product_id' => $productId]);
            $this->saveAttributes($productId, $attrsText);
        }

        $this->log((int)$user['id'], 'product_update', 'product', $productId, ['name' => $name]);
        return ['ok' => true, 'message' => 'Картку товару оновлено.', 'return_to' => '/admin#products'];
    }

    private function deleteProduct(array $data, array $user): array
    {
        if (!$this->hasRole($user, ['admin', 'manager'])) {
            return ['ok' => false, 'message' => 'Недостатньо прав для видалення товару.'];
        }

        $productId = (int)($data['product_id'] ?? 0);
        $confirm = (string)($data['confirm_delete'] ?? '');
        if ($productId <= 0) {
            return ['ok' => false, 'message' => 'Товар не знайдено.'];
        }
        if ($confirm !== 'yes') {
            return ['ok' => false, 'message' => 'Підтвердіть видалення товару прапорцем.'];
        }

        $product = Database::fetchOne(
            'SELECT id, sku, name FROM rc_products WHERE id = :id',
            ['id' => $productId]
        );
        if (!$product) {
            return ['ok' => false, 'message' => 'Товар не знайдено.'];
        }

        
        $this->deleteRowsByProductIfTableExists('rc_product_images', $productId);
        $this->deleteRowsByProductIfTableExists('rc_product_attributes', $productId);
        $this->deleteRowsByProductIfTableExists('rc_wishlist', $productId);
        $this->deleteRowsByProductIfTableExists('rc_comparison_items', $productId);
        $this->deleteRowsByProductIfTableExists('rc_cart_items', $productId);
        $this->deleteRowsByProductIfTableExists('rc_availability_requests', $productId);
        $this->deleteRowsByProductIfTableExists('rc_product_questions', $productId);
        $this->deleteRowsByProductIfTableExists('rc_stock', $productId);
        $this->deleteRowsByProductIfTableExists('rc_stock_movements', $productId);
        $this->deleteRowsByProductIfTableExists('rc_order_reservations', $productId);

        
        if ($this->tableExists('RC_REVIEWS')) {
            Database::execute('DELETE FROM rc_reviews WHERE product_id = :product_id', ['product_id' => $productId]);
        }

        
        
        if ($this->tableExists('RC_ORDER_ITEMS')) {
            @Database::execute('UPDATE rc_order_items SET product_id = NULL WHERE product_id = :product_id', ['product_id' => $productId]);
        }

        $deleted = Database::executeAffected('DELETE FROM rc_products WHERE id = :id', ['id' => $productId]);
        if ($deleted === null || $deleted === 0) {
            
            $archived = Database::execute(
                "UPDATE rc_products SET is_active = 0, status = 'archived', updated_at = CURRENT_TIMESTAMP WHERE id = :id",
                ['id' => $productId]
            );
            if (!$archived) {
                return ['ok' => false, 'message' => 'Товар не видалено: ' . (Database::lastError() ?? 'невідома помилка')];
            }
            $this->log((int)$user['id'], 'product_archive_fallback', 'product', $productId, ['sku' => (string)($product['SKU'] ?? '')]);
            return ['ok' => true, 'message' => 'Товар прибрано з каталогу. БД не дозволила фізично видалити запис через історію замовлень, тому картку архівовано.', 'return_to' => '/admin#products'];
        }

        $this->log((int)$user['id'], 'product_delete', 'product', $productId, ['sku' => (string)($product['SKU'] ?? ''), 'name' => (string)($product['NAME'] ?? '')]);
        return ['ok' => true, 'message' => 'Картку товару повністю видалено.', 'return_to' => '/admin#products'];
    }

    private function deleteProductImage(array $data, array $user): array
    {
        if (!$this->hasRole($user, ['admin', 'manager'])) {
            return ['ok' => false, 'message' => 'Недостатньо прав для видалення фото товару.'];
        }
        $productId = (int)($data['product_id'] ?? 0);
        $imageId = (int)($data['image_id'] ?? 0);
        if ($productId <= 0 || $imageId <= 0) {
            return ['ok' => false, 'message' => 'Фото не знайдено.'];
        }
        $this->deleteProductImageRows($productId, [$imageId]);
        $this->rebuildProductMainImage($productId);
        $this->log((int)$user['id'], 'product_image_delete', 'product', $productId, ['image_id' => $imageId]);
        return ['ok' => true, 'message' => 'Фото товару видалено.', 'return_to' => '/admin#products'];
    }

    private function deleteProductImageRows(int $productId, array $imageIds): void
    {
        if ($productId <= 0 || $imageIds === []) {
            return;
        }
        foreach ($imageIds as $imageId) {
            Database::execute(
                'DELETE FROM rc_product_images WHERE product_id = :product_id AND id = :id',
                ['product_id' => $productId, 'id' => (int)$imageId]
            );
        }
        $this->rebuildProductMainImage($productId);
    }

    private function rebuildProductMainImage(int $productId): void
    {
        $main = Database::fetchOne(
            'SELECT image_path FROM rc_product_images WHERE product_id = :product_id ORDER BY is_main DESC, sort_order ASC, id ASC FETCH FIRST 1 ROWS ONLY',
            ['product_id' => $productId]
        );
        $image = trim((string)($main['IMAGE_PATH'] ?? ''));
        if ($image !== '') {
            Database::execute(
                'UPDATE rc_product_images SET is_main = CASE WHEN image_path = :image_path THEN 1 ELSE 0 END WHERE product_id = :product_id',
                ['image_path' => $image, 'product_id' => $productId]
            );
            Database::execute('UPDATE rc_products SET image = :image, updated_at = CURRENT_TIMESTAMP WHERE id = :id', ['image' => $image, 'id' => $productId]);
        }
    }

    private function normalizeProductStatus(string $status): string
    {
        $allowed = ['in_stock', 'low_stock', 'out_of_stock', 'preorder', 'expected', 'archived'];
        $status = trim($status);
        return in_array($status, $allowed, true) ? $status : 'in_stock';
    }

    private function normalizeProductStatusForStock(float $stockQty, string $requestedStatus): string
    {
        $status = $this->normalizeProductStatus($requestedStatus);

        
        
        if (in_array($status, ['preorder', 'expected', 'archived'], true)) {
            return $status;
        }

        
        
        if ($stockQty <= 0) {
            return 'out_of_stock';
        }

        if ($stockQty <= 2) {
            return 'low_stock';
        }

        return 'in_stock';
    }

    private function deleteRowsByProductIfTableExists(string $table, int $productId): void
    {
        $normalized = strtoupper($table);
        if (!$this->tableExists($normalized)) {
            return;
        }
        Database::execute('DELETE FROM ' . $table . ' WHERE product_id = :product_id', ['product_id' => $productId]);
    }

    private function tableExists(string $table): bool
    {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS cnt FROM user_tables WHERE table_name = :table_name',
            ['table_name' => strtoupper($table)]
        );
        return (int)($row['CNT'] ?? 0) > 0;
    }

    private function toggleStaffKey(array $data, array $user): array
    {
        if (!$this->hasRole($user, ['admin'])) {
            return ['ok' => false, 'message' => 'Файлові ключі може змінювати тільки адміністратор.'];
        }
        $keyId = (int)($data['key_id'] ?? 0);
        if ($keyId <= 0) {
            return ['ok' => false, 'message' => 'Ключ не знайдено.'];
        }
        $ok = Database::execute(
            "UPDATE rc_staff_file_keys
             SET is_active = CASE WHEN NVL(is_active, 1) = 1 THEN 0 ELSE 1 END,
                 revoked_at = CASE WHEN NVL(is_active, 1) = 1 THEN CURRENT_TIMESTAMP ELSE NULL END
             WHERE id = :id",
            ['id' => $keyId]
        );
        $this->log((int)$user['id'], 'staff_key_toggle', 'staff_key', $keyId, []);
        return ['ok' => (bool)$ok, 'message' => $ok ? 'Статус ключа оновлено.' : 'Ключ не оновлено: ' . (Database::lastError() ?? 'невідома помилка'), 'return_to' => '/admin#staff_keys'];
    }

    private function settingsForAdmin(): array
    {
        $rows = Database::fetchAll('SELECT setting_key, setting_value FROM rc_site_settings ORDER BY setting_key');
        $settings = [];
        foreach ($rows as $row) {
            $settings[(string)$row['SETTING_KEY']] = (string)$row['SETTING_VALUE'];
        }
        return $settings;
    }

    private function logsForAdmin(): array
    {
        return Database::fetchAll(
            "SELECT l.action, l.entity_type, l.entity_id, l.created_at, u.full_name
             FROM rc_admin_logs l
             LEFT JOIN rc_users u ON u.id = l.user_id
             ORDER BY l.created_at DESC
             FETCH FIRST 20 ROWS ONLY"
        );
    }

    private function stats(): array
    {
        return [
            'products' => (int)(Database::fetchOne('SELECT COUNT(*) AS cnt FROM rc_products WHERE NVL(is_active, 1) = 1')['CNT'] ?? 0),
            'categories' => (int)(Database::fetchOne('SELECT COUNT(*) AS cnt FROM rc_categories WHERE NVL(is_active, 1) = 1')['CNT'] ?? 0),
            'orders' => (int)(Database::fetchOne('SELECT COUNT(*) AS cnt FROM rc_orders')['CNT'] ?? 0),
            'users' => (int)(Database::fetchOne('SELECT COUNT(*) AS cnt FROM rc_users')['CNT'] ?? 0),
        ];
    }

    private function saveAttributes(int $productId, string $attrsText): void
    {
        $lines = preg_split('/\r\n|\r|\n/', $attrsText) ?: [];
        $sort = 10;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/[:=]/', $line, 2);
            if (!$parts || count($parts) < 2) {
                continue;
            }

            $name = trim($parts[0]);
            $value = trim($parts[1]);
            if ($name === '' || $value === '') {
                continue;
            }

            Database::execute(
                "INSERT INTO rc_product_attributes (product_id, attr_name, attr_value, sort_order)
                 VALUES (:product_id, :attr_name, :attr_value, :sort_order)",
                ['product_id' => $productId, 'attr_name' => $name, 'attr_value' => $value, 'sort_order' => $sort]
            );
            $sort += 10;
        }
    }

    private function saveProductImages(string $field): array
    {
        $fileBag = $_FILES[$field] ?? null;
        if (!is_array($fileBag) || empty($fileBag['name'])) {
            return [];
        }

        $names = is_array($fileBag['name']) ? $fileBag['name'] : [$fileBag['name']];
        $tmpNames = is_array($fileBag['tmp_name']) ? $fileBag['tmp_name'] : [$fileBag['tmp_name']];
        $errors = is_array($fileBag['error']) ? $fileBag['error'] : [$fileBag['error']];
        $sizes = is_array($fileBag['size']) ? $fileBag['size'] : [$fileBag['size']];
        $dir = __DIR__ . '/../uploads/products/' . date('Y/m');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $allowed = ['jpg','jpeg','png','webp','gif'];
        $saved = [];
        foreach ($names as $i => $originalName) {
            if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $tmp = (string)($tmpNames[$i] ?? '');
            $size = (int)($sizes[$i] ?? 0);
            if ($tmp === '' || !is_uploaded_file($tmp) || $size <= 0 || $size > 8 * 1024 * 1024) {
                continue;
            }
            $ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true) || !$this->looksLikeSafeImage($tmp, $ext)) {
                continue;
            }
            $base = preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo((string)$originalName, PATHINFO_FILENAME)) ?: 'product';
            $fileName = date('His') . '_' . bin2hex(random_bytes(4)) . '_' . $base . '.' . $ext;
            if (move_uploaded_file($tmp, $dir . '/' . $fileName)) {
                $saved[] = '/uploads/products/' . date('Y/m') . '/' . $fileName;
            }
        }
        return $saved;
    }

    private function looksLikeSafeImage(string $tmp, string $ext): bool
    {
        if (!is_file($tmp)) {
            return false;
        }
        $imageInfo = @getimagesize($tmp);
        if ($imageInfo === false) {
            return false;
        }
        $allowedMimes = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
            'gif' => ['image/gif'],
        ];
        $mime = strtolower((string)($imageInfo['mime'] ?? ''));
        return in_array($mime, $allowedMimes[$ext] ?? [], true);
    }

    private function columns(string $table): array
    {
        $rows = Database::fetchAll('SELECT column_name FROM user_tab_columns WHERE table_name = :table_name', ['table_name' => strtoupper($table)]);
        $columns = [];
        foreach ($rows as $row) {
            $columns[(string)$row['COLUMN_NAME']] = true;
        }
        return $columns;
    }

    private function log(int $userId, string $action, ?string $entityType, ?int $entityId, array $details = []): void
    {
        Database::execute(
            "INSERT INTO rc_admin_logs (user_id, action, entity_type, entity_id, details, ip_address)
             VALUES (:user_id, :action, :entity_type, :entity_id, :details, :ip_address)",
            [
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'local',
            ]
        );
    }

    private function hasRole(array $user, array $roles): bool
    {
        return in_array((string)($user['role'] ?? ''), $roles, true);
    }

    private function slugify(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'h','ґ'=>'g','д'=>'d','е'=>'e','є'=>'ye','ж'=>'zh','з'=>'z','и'=>'y','і'=>'i','ї'=>'yi','й'=>'y',
            'к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'shch','ь'=>'','ю'=>'yu','я'=>'ya',
            'ы'=>'y','э'=>'e','ъ'=>'',
        ];
        $value = strtr($value, $map);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        return $value !== '' ? $value : 'category-' . time();
    }
}
