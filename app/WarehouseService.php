<?php
declare(strict_types=1);

final class WarehouseService
{
    public function handle(string $action, array $data, ?array $user): array
    {
        if (!$user || !in_array((string)($user['role'] ?? ''), ['admin', 'manager', 'warehouse'], true)) {
            return ['ok' => false, 'message' => 'Недостатньо прав для складської операції.'];
        }

        return match ($action) {
            'warehouse_create' => $this->createWarehouse($data, $user),
            'warehouse_sync_products' => $this->syncProductsToWarehouse((int)($data['warehouse_id'] ?? 0), $user),
            'warehouse_stock_adjust' => $this->adjustStock($data, $user),
            'warehouse_reserve_order' => $this->reserveOrder((int)($data['order_id'] ?? 0), (int)($data['warehouse_id'] ?? 0), $user),
            'warehouse_release_order' => $this->releaseOrder((int)($data['order_id'] ?? 0), $user),
            'warehouse_update_order_status' => $this->updateOrderStatus((int)($data['order_id'] ?? 0), (string)($data['status'] ?? ''), $user),
            default => ['ok' => false, 'message' => 'Невідома складська дія.'],
        };
    }

    public function adminData(?array $user = null): array
    {
        $this->ensureDefaultWarehouse();

        $warehouses = Database::fetchAll(
            "SELECT id, code, name, address_text, is_active, created_at
             FROM rc_warehouses
             ORDER BY is_active DESC, id"
        );

        $stockRows = Database::fetchAll(
            "SELECT
                s.id,
                s.warehouse_id,
                w.name AS warehouse_name,
                p.id AS product_id,
                p.sku,
                p.name AS product_name,
                p.unit,
                p.status AS product_status,
                s.qty_total,
                s.qty_reserved,
                (s.qty_total - s.qty_reserved) AS qty_available,
                s.updated_at
             FROM rc_stock s
             JOIN rc_warehouses w ON w.id = s.warehouse_id
             JOIN rc_products p ON p.id = s.product_id
             ORDER BY w.id, p.name"
        );

        $products = Database::fetchAll(
            "SELECT id, sku, name, stock_qty, status, unit
             FROM rc_products
             WHERE is_active = 1
             ORDER BY name"
        );

        $orders = Database::fetchAll(
            "SELECT
                o.id,
                o.customer_name,
                o.customer_phone,
                o.status,
                o.total_amount,
                o.created_at,
                NVL(SUM(r.quantity), 0) AS reserved_qty,
                COUNT(r.id) AS reservation_rows
             FROM rc_orders o
             LEFT JOIN rc_order_reservations r ON r.order_id = o.id AND r.status = 'reserved'
             WHERE o.status IN ('confirmed', 'paid', 'processing', 'picking', 'packed', 'ready_for_delivery')
             GROUP BY o.id, o.customer_name, o.customer_phone, o.status, o.total_amount, o.created_at
             ORDER BY o.id DESC
             FETCH FIRST 80 ROWS ONLY"
        );

        $movements = Database::fetchAll(
            "SELECT
                m.id,
                m.created_at,
                m.movement_type,
                m.quantity,
                m.comment_text,
                w.name AS warehouse_name,
                p.sku,
                p.name AS product_name,
                u.full_name AS user_name
             FROM rc_stock_movements m
             JOIN rc_warehouses w ON w.id = m.warehouse_id
             JOIN rc_products p ON p.id = m.product_id
             LEFT JOIN rc_users u ON u.id = m.user_id
             ORDER BY m.id DESC
             FETCH FIRST 60 ROWS ONLY"
        );

        $reservations = Database::fetchAll(
            "SELECT
                r.id,
                r.order_id,
                r.quantity,
                r.status,
                r.created_at,
                w.name AS warehouse_name,
                p.sku,
                p.name AS product_name
             FROM rc_order_reservations r
             JOIN rc_warehouses w ON w.id = r.warehouse_id
             JOIN rc_products p ON p.id = r.product_id
             WHERE r.status = 'reserved'
             ORDER BY r.id DESC
             FETCH FIRST 60 ROWS ONLY"
        );

        $stats = [
            'warehouses' => count($warehouses),
            'stock_positions' => count($stockRows),
            'low_stock' => 0,
            'reserved' => 0,
        ];

        foreach ($stockRows as $row) {
            if ((float)($row['QTY_AVAILABLE'] ?? 0) <= 2) {
                $stats['low_stock']++;
            }
            if ((float)($row['QTY_RESERVED'] ?? 0) > 0) {
                $stats['reserved']++;
            }
        }

        return [
            'stats' => $stats,
            'warehouses' => $warehouses,
            'stock' => $stockRows,
            'products' => $products,
            'orders' => $orders,
            'movements' => $movements,
            'reservations' => $reservations,
        ];
    }

    private function ensureDefaultWarehouse(): void
    {
        Database::execute(
            "MERGE INTO rc_warehouses w
             USING (SELECT 'kyiv-main' code, 'Склад-магазин Київ' name, 'м. Київ, вул. Куренівська, 15' address_text FROM dual) v
             ON (w.code = v.code)
             WHEN MATCHED THEN UPDATE SET w.name = v.name, w.address_text = v.address_text, w.is_active = 1
             WHEN NOT MATCHED THEN INSERT (code, name, address_text) VALUES (v.code, v.name, v.address_text)"
        );
    }

    private function createWarehouse(array $data, array $user): array
    {
        $code = $this->slug((string)($data['code'] ?? ''));
        $name = trim((string)($data['name'] ?? ''));
        $address = trim((string)($data['address_text'] ?? ''));

        if ($code === '' || $name === '') {
            return ['ok' => false, 'message' => 'Вкажіть код і назву складу.'];
        }

        $ok = Database::execute(
            "MERGE INTO rc_warehouses w
             USING (SELECT :code code, :name name, :address_text address_text FROM dual) v
             ON (w.code = v.code)
             WHEN MATCHED THEN UPDATE SET w.name = v.name, w.address_text = v.address_text, w.is_active = 1
             WHEN NOT MATCHED THEN INSERT (code, name, address_text) VALUES (v.code, v.name, v.address_text)",
            ['code' => $code, 'name' => $name, 'address_text' => $address]
        );

        $this->log($user, 'warehouse_create', 'warehouse', null, $name);
        return ['ok' => $ok, 'message' => $ok ? 'Склад збережено.' : 'Не вдалося зберегти склад.'];
    }

    private function syncProductsToWarehouse(int $warehouseId, array $user): array
    {
        if ($warehouseId <= 0) {
            $warehouse = Database::fetchOne("SELECT id FROM rc_warehouses WHERE code = 'kyiv-main'");
            $warehouseId = (int)($warehouse['ID'] ?? 0);
        }

        if ($warehouseId <= 0) {
            return ['ok' => false, 'message' => 'Склад не знайдено.'];
        }

        $ok = Database::execute(
            "INSERT INTO rc_stock (warehouse_id, product_id, qty_total, qty_reserved)
             SELECT :warehouse_id, p.id, p.stock_qty, 0
             FROM rc_products p
             WHERE p.is_active = 1
               AND NOT EXISTS (
                   SELECT 1 FROM rc_stock s
                   WHERE s.warehouse_id = :warehouse_id AND s.product_id = p.id
               )",
            ['warehouse_id' => $warehouseId]
        );

        $productIds = Database::fetchAll('SELECT DISTINCT product_id FROM rc_stock WHERE warehouse_id = :warehouse_id', ['warehouse_id' => $warehouseId]);
        foreach ($productIds as $productRow) {
            $this->refreshProductStock((int)($productRow['PRODUCT_ID'] ?? 0));
        }

        $this->log($user, 'warehouse_sync_products', 'warehouse', $warehouseId, 'Синхронізація товарів зі складом');
        return ['ok' => $ok, 'message' => $ok ? 'Товари синхронізовано зі складом і картками товарів.' : 'Не вдалося синхронізувати товари.'];
    }

    private function adjustStock(array $data, array $user): array
    {
        $warehouseId = (int)($data['warehouse_id'] ?? 0);
        $productId = (int)($data['product_id'] ?? 0);
        $quantity = (float)($data['quantity'] ?? 0);
        $type = (string)($data['movement_type'] ?? 'set');
        $comment = trim((string)($data['comment_text'] ?? ''));

        if ($warehouseId <= 0 || $productId <= 0) {
            return ['ok' => false, 'message' => 'Оберіть склад і товар.'];
        }

        $existing = Database::fetchOne(
            "SELECT id, qty_total, qty_reserved
             FROM rc_stock
             WHERE warehouse_id = :warehouse_id AND product_id = :product_id",
            ['warehouse_id' => $warehouseId, 'product_id' => $productId]
        );

        if (!$existing) {
            Database::execute(
                "INSERT INTO rc_stock (warehouse_id, product_id, qty_total, qty_reserved)
                 VALUES (:warehouse_id, :product_id, 0, 0)",
                ['warehouse_id' => $warehouseId, 'product_id' => $productId]
            );
            $currentTotal = 0.0;
        } else {
            $currentTotal = (float)$existing['QTY_TOTAL'];
        }

        $newTotal = $type === 'increase' ? $currentTotal + $quantity : ($type === 'decrease' ? max(0, $currentTotal - $quantity) : max(0, $quantity));
        $movementQty = $type === 'decrease' ? -abs($quantity) : ($type === 'set' ? $newTotal - $currentTotal : abs($quantity));

        $ok = Database::execute(
            "UPDATE rc_stock
             SET qty_total = :qty_total, updated_at = CURRENT_TIMESTAMP
             WHERE warehouse_id = :warehouse_id AND product_id = :product_id",
            ['qty_total' => $newTotal, 'warehouse_id' => $warehouseId, 'product_id' => $productId]
        );

        $this->refreshProductStock($productId);

        Database::execute(
            "INSERT INTO rc_stock_movements (warehouse_id, product_id, user_id, movement_type, quantity, comment_text)
             VALUES (:warehouse_id, :product_id, :user_id, :movement_type, :quantity, :comment_text)",
            [
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'user_id' => (int)($user['id'] ?? 0),
                'movement_type' => $type,
                'quantity' => $movementQty,
                'comment_text' => $comment,
            ]
        );

        $this->log($user, 'warehouse_stock_adjust', 'product', $productId, 'Оновлено залишок: ' . $newTotal);
        return ['ok' => $ok, 'message' => $ok ? 'Залишок оновлено.' : 'Не вдалося оновити залишок.'];
    }

    private function reserveOrder(int $orderId, int $warehouseId, array $user): array
    {
        if ($orderId <= 0 || $warehouseId <= 0) {
            return ['ok' => false, 'message' => 'Оберіть замовлення і склад.'];
        }

        $order = Database::fetchOne('SELECT status, payment_type, payment_status FROM rc_orders WHERE id = :id', ['id' => $orderId]);
        $orderStatus = (string)($order['STATUS'] ?? '');
        $paymentType = (string)($order['PAYMENT_TYPE'] ?? '');
        $paymentStatus = (string)($order['PAYMENT_STATUS'] ?? '');
        $canGoWarehouse = in_array($orderStatus, ['confirmed','paid','processing','picking'], true)
            || ($paymentType === 'cash' && in_array($orderStatus, ['confirmed','processing','picking'], true));
        if (!$canGoWarehouse) {
            return ['ok' => false, 'message' => 'Замовлення ще не готове для складу. Спочатку потрібна онлайн-оплата або підтвердження менеджера.'];
        }

        $items = Database::fetchAll(
            "SELECT product_id, quantity
             FROM rc_order_items
             WHERE order_id = :order_id AND product_id IS NOT NULL",
            ['order_id' => $orderId]
        );

        if (!$items) {
            return ['ok' => false, 'message' => 'У замовленні немає товарів для резерву.'];
        }

        foreach ($items as $item) {
            $productId = (int)$item['PRODUCT_ID'];
            $qty = (float)$item['QUANTITY'];
            $stock = Database::fetchOne(
                "SELECT qty_total, qty_reserved, (qty_total - qty_reserved) AS qty_available
                 FROM rc_stock
                 WHERE warehouse_id = :warehouse_id AND product_id = :product_id",
                ['warehouse_id' => $warehouseId, 'product_id' => $productId]
            );
            if (!$stock || (float)$stock['QTY_AVAILABLE'] < $qty) {
                return ['ok' => false, 'message' => 'Недостатньо залишку для товару ID ' . $productId . '.'];
            }
        }

        foreach ($items as $item) {
            $productId = (int)$item['PRODUCT_ID'];
            $qty = (float)$item['QUANTITY'];
            $exists = Database::fetchOne(
                "SELECT id FROM rc_order_reservations
                 WHERE order_id = :order_id AND product_id = :product_id AND status = 'reserved'",
                ['order_id' => $orderId, 'product_id' => $productId]
            );
            if ($exists) {
                continue;
            }
            Database::execute(
                "UPDATE rc_stock
                 SET qty_reserved = qty_reserved + :qty, updated_at = CURRENT_TIMESTAMP
                 WHERE warehouse_id = :warehouse_id AND product_id = :product_id",
                ['qty' => $qty, 'warehouse_id' => $warehouseId, 'product_id' => $productId]
            );
            Database::execute(
                "INSERT INTO rc_order_reservations (order_id, warehouse_id, product_id, quantity, status, created_by)
                 VALUES (:order_id, :warehouse_id, :product_id, :quantity, 'reserved', :created_by)",
                ['order_id' => $orderId, 'warehouse_id' => $warehouseId, 'product_id' => $productId, 'quantity' => $qty, 'created_by' => (int)($user['id'] ?? 0)]
            );
            $this->refreshProductStock($productId);
        }

        Database::execute(
            "UPDATE rc_orders SET status = 'picking', updated_at = CURRENT_TIMESTAMP WHERE id = :order_id",
            ['order_id' => $orderId]
        );

        $this->log($user, 'warehouse_reserve_order', 'order', $orderId, 'Резерв товарів під замовлення');
        return ['ok' => true, 'message' => 'Товари зарезервовано, замовлення передано на комплектацію.'];
    }

    private function releaseOrder(int $orderId, array $user): array
    {
        if ($orderId <= 0) {
            return ['ok' => false, 'message' => 'Замовлення не знайдено.'];
        }

        $reservations = Database::fetchAll(
            "SELECT id, warehouse_id, product_id, quantity
             FROM rc_order_reservations
             WHERE order_id = :order_id AND status = 'reserved'",
            ['order_id' => $orderId]
        );

        foreach ($reservations as $reservation) {
            Database::execute(
                "UPDATE rc_stock
                 SET qty_reserved = GREATEST(0, qty_reserved - :qty), updated_at = CURRENT_TIMESTAMP
                 WHERE warehouse_id = :warehouse_id AND product_id = :product_id",
                [
                    'qty' => (float)$reservation['QUANTITY'],
                    'warehouse_id' => (int)$reservation['WAREHOUSE_ID'],
                    'product_id' => (int)$reservation['PRODUCT_ID'],
                ]
            );
            Database::execute(
                "UPDATE rc_order_reservations
                 SET status = 'released', released_at = CURRENT_TIMESTAMP
                 WHERE id = :id",
                ['id' => (int)$reservation['ID']]
            );
            $this->refreshProductStock((int)$reservation['PRODUCT_ID']);
        }

        $this->log($user, 'warehouse_release_order', 'order', $orderId, 'Резерв замовлення знято');
        return ['ok' => true, 'message' => 'Резерв замовлення знято.'];
    }

    private function updateOrderStatus(int $orderId, string $status, array $user): array
    {
        $allowed = ['created', 'confirmed', 'paid', 'processing', 'picking', 'packed', 'ready_for_delivery', 'delivered', 'completed', 'cancelled'];
        if ($orderId <= 0 || !in_array($status, $allowed, true)) {
            return ['ok' => false, 'message' => 'Некоректний статус замовлення.'];
        }

        $currentOrder = Database::fetchOne('SELECT status FROM rc_orders WHERE id = :order_id', ['order_id' => $orderId]);
        $currentStatus = (string)($currentOrder['STATUS'] ?? '');
        $reserved = Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM rc_order_reservations WHERE order_id = :order_id AND status = 'reserved'",
            ['order_id' => $orderId]
        );
        $reservedCount = (int)($reserved['CNT'] ?? 0);

        if ($status === 'packed' && $reservedCount <= 0) {
            return ['ok' => false, 'message' => 'Спочатку зарезервуйте товари. Без резерву не можна пакувати замовлення.'];
        }

        if (in_array($status, ['ready_for_delivery','delivered'], true)
            && $reservedCount <= 0
            && !in_array($currentStatus, ['packed','ready_for_delivery','sent','delivering'], true)) {
            return ['ok' => false, 'message' => 'Перед передачею в доставку замовлення має бути зарезервоване і зібране на складі.'];
        }

        $ok = Database::execute(
            "UPDATE rc_orders SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :order_id",
            ['status' => $status, 'order_id' => $orderId]
        );

        if (in_array($status, ['packed', 'delivered', 'completed'], true)) {
            $this->consumeReservedStock($orderId, $user);
        } elseif ($status === 'cancelled') {
            $this->releaseOrder($orderId, $user);
        }

        $this->log($user, 'warehouse_update_order_status', 'order', $orderId, 'Статус складу: ' . $status);
        return ['ok' => $ok, 'message' => $ok ? 'Статус замовлення оновлено.' : 'Не вдалося оновити статус.'];
    }

    private function consumeReservedStock(int $orderId, array $user): void
    {
        if ($orderId <= 0) {
            return;
        }
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

    private function log(array $user, string $action, string $entityType, ?int $entityId, string $details): void
    {
        Database::execute(
            "INSERT INTO rc_admin_logs (user_id, action, entity_type, entity_id, details, ip_address)
             VALUES (:user_id, :action, :entity_type, :entity_id, :details, :ip_address)",
            [
                'user_id' => (int)($user['id'] ?? 0),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => $details,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]
        );
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\-]+/', '-', $value) ?: '';
        return trim($value, '-');
    }
}
