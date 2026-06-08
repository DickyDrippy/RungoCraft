<?php
declare(strict_types=1);
require_once __DIR__ . '/Phone.php';
final class CartService
{
    public function cart(?array $user = null): array
    {
        $cartId = $this->getOrCreateCartId($user);
        return $this->cartById($cartId);
    }

    public function addProduct(int $productId, float $quantity, ?array $user = null): array
    {
        if ($this->isStaffUser($user)) {
            return ['ok' => false, 'message' => 'Працівники не оформлюють клієнтські замовлення зі службового профілю.'];
        }
        $quantity = max(1, $quantity);

        $product = Database::fetchOne(
            "SELECT id, name, price, stock_qty, status, is_active
             FROM rc_products
             WHERE id = :id",
            ['id' => $productId]
        );

        if (!$product || (int)($product['IS_ACTIVE'] ?? 0) !== 1) {
            return ['ok' => false, 'message' => 'Товар не знайдено або він недоступний.'];
        }

        $status = (string)($product['STATUS'] ?? 'in_stock');
        if (in_array($status, ['out_of_stock', 'expected', 'archived'], true)) {
            return ['ok' => false, 'message' => 'Товар зараз недоступний для покупки: ' . product_status_label($status) . '.'];
        }

        $stock = (float)($product['STOCK_QTY'] ?? 0);
        $isPreorder = $status === 'preorder';
        if (!$isPreorder && $stock <= 0) {
            return ['ok' => false, 'message' => 'Товару немає в наявності.'];
        }

        $cartId = $this->getOrCreateCartId($user);
        $existing = Database::fetchOne(
            "SELECT id, quantity
             FROM rc_cart_items
             WHERE cart_id = :cart_id AND product_id = :product_id",
            ['cart_id' => $cartId, 'product_id' => $productId]
        );

        $newQuantity = $quantity;
        if ($existing) {
            $newQuantity += (float)$existing['QUANTITY'];
        }
        if (!$isPreorder) {
            $newQuantity = min($newQuantity, $stock);
        }

        if ($existing) {
            $ok = Database::execute(
                "UPDATE rc_cart_items
                 SET quantity = :quantity,
                     price_snapshot = :price,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id",
                [
                    'quantity' => $newQuantity,
                    'price' => (float)$product['PRICE'],
                    'id' => (int)$existing['ID'],
                ]
            );
        } else {
            $ok = Database::execute(
                "INSERT INTO rc_cart_items (cart_id, product_id, quantity, price_snapshot)
                 VALUES (:cart_id, :product_id, :quantity, :price)",
                [
                    'cart_id' => $cartId,
                    'product_id' => $productId,
                    'quantity' => $newQuantity,
                    'price' => (float)$product['PRICE'],
                ]
            );
        }

        if (!$ok) {
            return ['ok' => false, 'message' => 'Не вдалося оновити кошик: ' . (Database::lastError() ?? 'невідома помилка')];
        }

        Database::execute(
            "UPDATE rc_carts SET updated_at = CURRENT_TIMESTAMP WHERE id = :id",
            ['id' => $cartId]
        );

        return ['ok' => true, 'message' => 'Товар додано в кошик.'];
    }

    public function updateItem(int $itemId, float $quantity, ?array $user = null): array
    {
        $cartId = $this->getOrCreateCartId($user);
        $item = Database::fetchOne(
            "SELECT ci.id, ci.product_id, p.stock_qty, p.status
             FROM rc_cart_items ci
             JOIN rc_products p ON p.id = ci.product_id
             WHERE ci.id = :id AND ci.cart_id = :cart_id",
            ['id' => $itemId, 'cart_id' => $cartId]
        );

        if (!$item) {
            return ['ok' => false, 'message' => 'Позицію кошика не знайдено.'];
        }

        if ($quantity <= 0) {
            return $this->removeItem($itemId, $user);
        }

        $status = (string)($item['STATUS'] ?? 'in_stock');
        if (in_array($status, ['out_of_stock', 'expected', 'archived'], true)) {
            return ['ok' => false, 'message' => 'Цю позицію зараз не можна замовити: ' . product_status_label($status) . '.'];
        }

        if ($status !== 'preorder') {
            $quantity = min($quantity, max(0, (float)$item['STOCK_QTY']));
            if ($quantity <= 0) {
                return $this->removeItem($itemId, $user);
            }
        } else {
            $quantity = max(1, $quantity);
        }

        $ok = Database::execute(
            "UPDATE rc_cart_items
             SET quantity = :quantity,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND cart_id = :cart_id",
            ['quantity' => $quantity, 'id' => $itemId, 'cart_id' => $cartId]
        );

        if (!$ok) {
            return ['ok' => false, 'message' => 'Кількість не оновлено: ' . (Database::lastError() ?? 'невідома помилка')];
        }

        Database::execute("UPDATE rc_carts SET updated_at = CURRENT_TIMESTAMP WHERE id = :id", ['id' => $cartId]);
        return ['ok' => true, 'message' => 'Кошик оновлено.'];
    }

    public function removeItem(int $itemId, ?array $user = null): array
    {
        $cartId = $this->getOrCreateCartId($user);
        $ok = Database::execute(
            "DELETE FROM rc_cart_items WHERE id = :id AND cart_id = :cart_id",
            ['id' => $itemId, 'cart_id' => $cartId]
        );

        if (!$ok) {
            return ['ok' => false, 'message' => 'Позицію не видалено: ' . (Database::lastError() ?? 'невідома помилка')];
        }

        Database::execute("UPDATE rc_carts SET updated_at = CURRENT_TIMESTAMP WHERE id = :id", ['id' => $cartId]);
        return ['ok' => true, 'message' => 'Товар видалено з кошика.'];
    }

    public function clear(?array $user = null): array
    {
        $cartId = $this->getOrCreateCartId($user);
        $ok = Database::execute("DELETE FROM rc_cart_items WHERE cart_id = :cart_id", ['cart_id' => $cartId]);

        return [
            'ok' => $ok,
            'message' => $ok ? 'Кошик очищено.' : 'Кошик не очищено: ' . (Database::lastError() ?? 'невідома помилка'),
        ];
    }

    public function createOrder(array $data, ?array $user = null): array
    {
        if (!$user || empty($user['id'])) {
            return ['ok' => false, 'message' => 'Оформлення замовлення з кошика доступне тільки після входу або реєстрації. Увійдіть у кабінет, потім поверніться до кошика.'];
        }
        if ($this->isStaffUser($user)) {
            return ['ok' => false, 'message' => 'Працівник не може оформити клієнтське замовлення зі службового профілю.'];
        }
        $this->attachSessionCartToUser((int)$user['id']);
        $cart = $this->cart($user);
        if (empty($cart['items'])) {
            return ['ok' => false, 'message' => 'Кошик порожній. Додайте товари перед оформленням.'];
        }

        $customerName = trim((string)($data['customer_name'] ?? ''));
        $customerPhoneRaw = trim((string)($data['customer_phone'] ?? ''));
        $customerPhoneCheck = Phone::normalizeUa($customerPhoneRaw);
        $customerPhone = $customerPhoneCheck['phone'];
        $customerEmail = trim((string)($data['customer_email'] ?? ''));
        $deliveryType = trim((string)($data['delivery_type'] ?? 'pickup'));
        $deliveryAddress = trim((string)($data['delivery_address'] ?? ''));
        $deliveryCarrier = trim((string)($data['delivery_carrier'] ?? $deliveryType));
        $deliveryCity = trim((string)($data['delivery_city'] ?? ''));
        if ($deliveryCity === '') {
            $deliveryCity = trim((string)($data['delivery_city_address'] ?? ''));
        }
        $deliveryWarehouse = trim((string)($data['delivery_warehouse'] ?? ''));
        $deliveryCityRef = trim((string)($data['delivery_city_ref'] ?? ''));
        $deliveryWarehouseRef = trim((string)($data['delivery_warehouse_ref'] ?? ''));
        $deliveryRecipientRaw = trim((string)($data['delivery_recipient'] ?? ''));
        $deliveryRecipientPhoneRaw = trim((string)($data['delivery_recipient_phone'] ?? ''));
        $deliveryRecipient = $deliveryRecipientRaw !== '' ? $deliveryRecipientRaw : $customerName;
        $deliveryRecipientPhone = $customerPhone;
        $paymentType = trim((string)($data['payment_type'] ?? 'cash'));
        $companyInfo = trim((string)($data['company_info'] ?? ''));
        $comment = trim((string)($data['comment_text'] ?? ''));
        if ($customerName === '') {
            return ['ok' => false, 'message' => 'Вкажіть ПІБ для підтвердження замовлення.'];
        }
        if (!$this->validCustomerName($customerName)) {
            return ['ok' => false, 'message' => 'Вкажіть реальне ПІБ: мінімум імʼя та прізвище, тільки літери, пробіли, апостроф або дефіс.'];
        }
        if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Вкажіть коректний email, наприклад name@example.com.'];
        }

        if (!$customerPhoneCheck['ok']) {
            return ['ok' => false, 'message' => $customerPhoneCheck['message']];
        }

        if ($deliveryRecipientRaw !== '' || $deliveryRecipientPhoneRaw !== '') {
            if (!$this->validCustomerName($deliveryRecipientRaw)) {
                return ['ok' => false, 'message' => 'Вкажіть реальне ПІБ іншого отримувача: мінімум імʼя та прізвище, тільки літери, пробіли, апостроф або дефіс.'];
            }
            $recipientPhoneCheck = Phone::normalizeUa($deliveryRecipientPhoneRaw);
            if (!$recipientPhoneCheck['ok']) {
                return ['ok' => false, 'message' => 'Вкажіть коректний телефон іншого отримувача для формування ТТН.'];
            }
            $deliveryRecipientPhone = $recipientPhoneCheck['phone'];
        }

        if ($deliveryType === 'kyiv_courier') {
            $deliveryCity = 'Київ';
        }

        if (in_array($deliveryType, ['nova_poshta_branch', 'nova_poshta_courier', 'delivery_auto_branch', 'delivery_auto_courier'], true) && $deliveryCity === '') {
            return ['ok' => false, 'message' => 'Оберіть місто доставки.'];
        }

        if (in_array($deliveryType, ['nova_poshta_branch', 'delivery_auto_branch'], true) && $deliveryWarehouse === '') {
            return ['ok' => false, 'message' => 'Оберіть відділення служби доставки.'];
        }

        if (in_array($deliveryType, ['kyiv_courier', 'construction_site', 'nova_poshta_courier', 'delivery_auto_courier'], true) && $deliveryAddress === '') {
            return ['ok' => false, 'message' => 'Вкажіть адресу доставки.'];
        }

        $stockCheck = $this->validateStock($cart['items']);
        if (!$stockCheck['ok']) {
            return $stockCheck;
        }

        if ($companyInfo !== '') {
            $comment = trim($comment . "\nРеквізити/компанія: " . $companyInfo);
        }

        $orderColumns = $this->columns('RC_ORDERS');
        $fields = ['customer_name', 'customer_phone', 'customer_email', 'delivery_type', 'payment_type', 'status', 'total_amount'];
        $values = [':customer_name', ':customer_phone', ':customer_email', ':delivery_type', ':payment_type', ':status', ':total_amount'];
        $params = [
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'customer_email' => $customerEmail !== '' ? $customerEmail : null,
            'delivery_type' => $deliveryType,
            'payment_type' => $paymentType,
            'status' => $this->initialOrderStatus($paymentType),
            'total_amount' => (float)$cart['total'],
        ];

        if (!empty($user['id'])) {
            $fields[] = 'user_id';
            $values[] = ':user_id';
            $params['user_id'] = (int)$user['id'];
        }

        if (isset($orderColumns['DELIVERY_ADDRESS'])) {
            $fields[] = 'delivery_address';
            $values[] = ':delivery_address';
            $params['delivery_address'] = $deliveryAddress !== '' ? $deliveryAddress : null;
        }

        if (isset($orderColumns['DELIVERY_CARRIER'])) {
            $fields[] = 'delivery_carrier';
            $values[] = ':delivery_carrier';
            $params['delivery_carrier'] = $deliveryCarrier !== '' ? $deliveryCarrier : $deliveryType;
        }

        if (isset($orderColumns['DELIVERY_CITY'])) {
            $fields[] = 'delivery_city';
            $values[] = ':delivery_city';
            $params['delivery_city'] = $deliveryCity !== '' ? $deliveryCity : null;
        }

        if (isset($orderColumns['DELIVERY_WAREHOUSE'])) {
            $fields[] = 'delivery_warehouse';
            $values[] = ':delivery_warehouse';
            $params['delivery_warehouse'] = $deliveryWarehouse !== '' ? $deliveryWarehouse : null;
        }

        if (isset($orderColumns['DELIVERY_CITY_REF'])) {
            $fields[] = 'delivery_city_ref';
            $values[] = ':delivery_city_ref';
            $params['delivery_city_ref'] = $deliveryCityRef !== '' ? $deliveryCityRef : null;
        }

        if (isset($orderColumns['DELIVERY_WAREHOUSE_REF'])) {
            $fields[] = 'delivery_warehouse_ref';
            $values[] = ':delivery_warehouse_ref';
            $params['delivery_warehouse_ref'] = $deliveryWarehouseRef !== '' ? $deliveryWarehouseRef : null;
        }

        if (isset($orderColumns['DELIVERY_STATUS'])) {
            $fields[] = 'delivery_status';
            $values[] = ':delivery_status';
            $params['delivery_status'] = 'pending';
        }

        if (isset($orderColumns['COMMENT_TEXT'])) {
            $fields[] = 'comment_text';
            $values[] = ':comment_text';
            $params['comment_text'] = $comment !== '' ? $comment : null;
        }

        $sql = 'INSERT INTO rc_orders (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ') RETURNING id INTO :new_id';
        $orderId = $this->executeReturningId($sql, $params);

        if ($orderId <= 0) {
            return ['ok' => false, 'message' => 'Замовлення не створено: ' . (Database::lastError() ?? 'невідома помилка')];
        }

        $this->createDeliveryShipment($orderId, [
            'delivery_type' => $deliveryType,
            'delivery_carrier' => $deliveryCarrier,
            'delivery_city' => $deliveryCity,
            'delivery_warehouse' => $deliveryWarehouse,
            'delivery_city_ref' => $deliveryCityRef,
            'delivery_warehouse_ref' => $deliveryWarehouseRef,
            'delivery_address' => $deliveryAddress,
            'delivery_recipient' => $deliveryRecipient,
            'delivery_recipient_phone' => $deliveryRecipientPhone,
            'customer_phone' => $customerPhone,
            'comment_text' => $comment,
        ]);

        foreach ($cart['items'] as $item) {
            $this->insertOrderItem($orderId, $item);
        }

        $this->reserveOrderItemsFromDefaultWarehouse($orderId, $cart['items'], (int)$user['id']);

        Database::execute(
            "UPDATE rc_carts
             SET status = 'ordered', updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            ['id' => (int)$cart['id']]
        );

        $this->logStatus($orderId, $this->initialOrderStatus($paymentType), $user);
        $_SESSION['last_order_id'] = $orderId;

        return ['ok' => true, 'message' => 'Замовлення створено. Менеджер звʼяжеться для підтвердження.', 'order_id' => $orderId];
    }



    private function reserveOrderItemsFromDefaultWarehouse(int $orderId, array $items, int $userId): void
    {
        if ($orderId <= 0 || $items === []) {
            return;
        }

        $warehouseId = $this->defaultWarehouseId();
        if ($warehouseId <= 0) {
            return;
        }

        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $qty = (float)($item['quantity'] ?? 0);
            $status = (string)($item['status'] ?? 'in_stock');
            if ($productId <= 0 || $qty <= 0 || $status === 'preorder') {
                continue;
            }

            $this->ensureStockRow($warehouseId, $productId);
            $stock = Database::fetchOne(
                "SELECT qty_total, qty_reserved, (qty_total - qty_reserved) AS qty_available
                 FROM rc_stock
                 WHERE warehouse_id = :warehouse_id AND product_id = :product_id",
                ['warehouse_id' => $warehouseId, 'product_id' => $productId]
            );
            if (!$stock || (float)($stock['QTY_AVAILABLE'] ?? 0) < $qty) {
                continue;
            }

            $exists = Database::fetchOne(
                "SELECT id FROM rc_order_reservations
                 WHERE order_id = :order_id AND product_id = :product_id AND status = 'reserved'
                 FETCH FIRST 1 ROWS ONLY",
                ['order_id' => $orderId, 'product_id' => $productId]
            );
            if ($exists) {
                $this->refreshProductStock($productId);
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
                ['order_id' => $orderId, 'warehouse_id' => $warehouseId, 'product_id' => $productId, 'quantity' => $qty, 'created_by' => $userId]
            );
            $this->refreshProductStock($productId);
        }
    }

    private function defaultWarehouseId(): int
    {
        Database::execute(
            "MERGE INTO rc_warehouses w
             USING (SELECT 'kyiv-main' code, 'Склад-магазин Київ' name, 'м. Київ, вул. Куренівська, 15' address_text FROM dual) v
             ON (w.code = v.code)
             WHEN MATCHED THEN UPDATE SET w.name = v.name, w.address_text = v.address_text, w.is_active = 1
             WHEN NOT MATCHED THEN INSERT (code, name, address_text) VALUES (v.code, v.name, v.address_text)"
        );
        $row = Database::fetchOne("SELECT id FROM rc_warehouses WHERE code = 'kyiv-main' FETCH FIRST 1 ROWS ONLY");
        return (int)($row['ID'] ?? 0);
    }

    private function ensureStockRow(int $warehouseId, int $productId): void
    {
        if ($warehouseId <= 0 || $productId <= 0) {
            return;
        }
        $product = Database::fetchOne('SELECT stock_qty FROM rc_products WHERE id = :id', ['id' => $productId]);
        $qty = max(0, (float)($product['STOCK_QTY'] ?? 0));
        Database::execute(
            "MERGE INTO rc_stock s
             USING (SELECT :warehouse_id warehouse_id, :product_id product_id, :qty_total qty_total FROM dual) v
             ON (s.warehouse_id = v.warehouse_id AND s.product_id = v.product_id)
             WHEN NOT MATCHED THEN INSERT (warehouse_id, product_id, qty_total, qty_reserved)
             VALUES (v.warehouse_id, v.product_id, v.qty_total, 0)",
            ['warehouse_id' => $warehouseId, 'product_id' => $productId, 'qty_total' => $qty]
        );
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

    private function isStaffUser(?array $user): bool
    {
        return in_array((string)($user['role'] ?? ''), ['admin', 'manager', 'warehouse'], true);
    }

    private function validCustomerName(string $name): bool
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if (mb_strlen($name, 'UTF-8') < 5) {
            return false;
        }
        $parts = array_values(array_filter(explode(' ', $name), static fn(string $part): bool => mb_strlen($part, 'UTF-8') >= 2));
        if (count($parts) < 2) {
            return false;
        }
        return (bool)preg_match("/^[\p{L}][\p{L}'’`\- ]+$/u", $name);
    }

    private function attachSessionCartToUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $sessionId = session_id();
        if ($sessionId === '') {
            return;
        }
        $sessionCart = Database::fetchOne(
            "SELECT id FROM rc_carts WHERE session_id = :session_id AND status = 'active' ORDER BY updated_at DESC FETCH FIRST 1 ROWS ONLY",
            ['session_id' => $sessionId]
        );
        if (!$sessionCart) {
            return;
        }
        $sessionCartId = (int)$sessionCart['ID'];
        $userCart = Database::fetchOne(
            "SELECT id FROM rc_carts WHERE user_id = :user_id AND status = 'active' ORDER BY updated_at DESC FETCH FIRST 1 ROWS ONLY",
            ['user_id' => $userId]
        );
        if (!$userCart) {
            Database::execute(
                "UPDATE rc_carts SET user_id = :user_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
                ['user_id' => $userId, 'id' => $sessionCartId]
            );
            return;
        }
        $userCartId = (int)$userCart['ID'];
        if ($userCartId === $sessionCartId) {
            return;
        }
        $items = Database::fetchAll("SELECT product_id, quantity, price_snapshot FROM rc_cart_items WHERE cart_id = :cart_id", ['cart_id' => $sessionCartId]);
        foreach ($items as $item) {
            $existing = Database::fetchOne(
                "SELECT id, quantity FROM rc_cart_items WHERE cart_id = :cart_id AND product_id = :product_id",
                ['cart_id' => $userCartId, 'product_id' => (int)$item['PRODUCT_ID']]
            );
            if ($existing) {
                Database::execute(
                    "UPDATE rc_cart_items SET quantity = quantity + :quantity, price_snapshot = :price, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
                    ['quantity' => (float)$item['QUANTITY'], 'price' => (float)$item['PRICE_SNAPSHOT'], 'id' => (int)$existing['ID']]
                );
            } else {
                Database::execute(
                    "INSERT INTO rc_cart_items (cart_id, product_id, quantity, price_snapshot) VALUES (:cart_id, :product_id, :quantity, :price)",
                    ['cart_id' => $userCartId, 'product_id' => (int)$item['PRODUCT_ID'], 'quantity' => (float)$item['QUANTITY'], 'price' => (float)$item['PRICE_SNAPSHOT']]
                );
            }
        }
        Database::execute("UPDATE rc_carts SET status = 'merged', updated_at = CURRENT_TIMESTAMP WHERE id = :id", ['id' => $sessionCartId]);
    }

    private function initialOrderStatus(string $paymentType): string
    {
        $paymentType = strtolower(trim($paymentType));
        if (in_array($paymentType, ['online_card', 'liqpay', 'wayforpay'], true)) {
            return 'waiting_payment';
        }
        if (in_array($paymentType, ['manager_confirm', 'invoice'], true)) {
            return 'waiting_confirmation';
        }
        return 'confirmed';
    }

    public function order(int $id, ?array $user = null): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $order = Database::fetchOne(
            "SELECT *
             FROM rc_orders
             WHERE id = :id",
            ['id' => $id]
        );

        if (!$order) {
            return null;
        }

        $items = Database::fetchAll(
            "SELECT * FROM rc_order_items WHERE order_id = :id ORDER BY id",
            ['id' => $id]
        );

        $shipment = null;
        if ($this->tableExists('RC_DELIVERY_SHIPMENTS')) {
            $shipment = Database::fetchOne(
                "SELECT * FROM rc_delivery_shipments WHERE order_id = :order_id ORDER BY id DESC FETCH FIRST 1 ROWS ONLY",
                ['order_id' => $id]
            );
        }

        return ['order' => $order, 'items' => $items, 'shipment' => $shipment];
    }

    public function safeReturn(string $path, string $fallback = '/cart'): string
    {
        $path = trim($path);
        if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $fallback;
        }

        return str_starts_with($path, '/') ? $path : $fallback;
    }

    private function getOrCreateCartId(?array $user = null): int
    {
        $sessionId = session_id();
        $userId = !empty($user['id']) ? (int)$user['id'] : null;

        if ($userId) {
            $this->attachSessionCartToUser($userId);
            $cart = Database::fetchOne(
                "SELECT id FROM rc_carts WHERE user_id = :user_id AND status = 'active' ORDER BY updated_at DESC FETCH FIRST 1 ROWS ONLY",
                ['user_id' => $userId]
            );
        } else {
            $cart = Database::fetchOne(
                "SELECT id FROM rc_carts WHERE session_id = :session_id AND status = 'active' ORDER BY updated_at DESC FETCH FIRST 1 ROWS ONLY",
                ['session_id' => $sessionId]
            );
        }

        if ($cart) {
            return (int)$cart['ID'];
        }

        if ($userId) {
            Database::execute(
                "INSERT INTO rc_carts (user_id, session_id, status) VALUES (:user_id, :session_id, 'active')",
                ['user_id' => $userId, 'session_id' => $sessionId]
            );
            $cart = Database::fetchOne(
                "SELECT id FROM rc_carts WHERE user_id = :user_id AND status = 'active' ORDER BY id DESC FETCH FIRST 1 ROWS ONLY",
                ['user_id' => $userId]
            );
        } else {
            Database::execute(
                "INSERT INTO rc_carts (session_id, status) VALUES (:session_id, 'active')",
                ['session_id' => $sessionId]
            );
            $cart = Database::fetchOne(
                "SELECT id FROM rc_carts WHERE session_id = :session_id AND status = 'active' ORDER BY id DESC FETCH FIRST 1 ROWS ONLY",
                ['session_id' => $sessionId]
            );
        }

        return (int)($cart['ID'] ?? 0);
    }

    private function cartById(int $cartId): array
    {
        if ($cartId <= 0) {
            return ['id' => 0, 'items' => [], 'count' => 0, 'total' => 0.0];
        }

        $rows = Database::fetchAll(
            "SELECT
                ci.id,
                ci.cart_id,
                ci.product_id,
                ci.quantity,
                ci.price_snapshot,
                p.name,
                p.sku,
                p.brand,
                p.unit,
                p.stock_qty,
                p.status,
                p.image,
                (
                    SELECT i.image_path
                    FROM rc_product_images i
                    WHERE i.product_id = p.id
                    ORDER BY i.is_main DESC, i.sort_order ASC, i.id ASC
                    FETCH FIRST 1 ROWS ONLY
                ) AS main_image
             FROM rc_cart_items ci
             JOIN rc_products p ON p.id = ci.product_id
             WHERE ci.cart_id = :cart_id
             ORDER BY ci.id",
            ['cart_id' => $cartId]
        );

        $items = [];
        $total = 0.0;
        $count = 0.0;

        foreach ($rows as $row) {
            $quantity = (float)$row['QUANTITY'];
            $price = (float)$row['PRICE_SNAPSHOT'];
            $lineTotal = $quantity * $price;
            $total += $lineTotal;
            $count += $quantity;

            $items[] = [
                'id' => (int)$row['ID'],
                'cart_id' => (int)$row['CART_ID'],
                'product_id' => (int)$row['PRODUCT_ID'],
                'quantity' => $quantity,
                'price' => $price,
                'line_total' => $lineTotal,
                'name' => (string)$row['NAME'],
                'sku' => (string)$row['SKU'],
                'brand' => (string)($row['BRAND'] ?? ''),
                'unit' => (string)($row['UNIT'] ?? 'шт.'),
                'stock_qty' => (float)$row['STOCK_QTY'],
                'stock' => (float)$row['STOCK_QTY'],
                'status' => (string)($row['STATUS'] ?? 'in_stock'),
                'image' => (string)($row['MAIN_IMAGE'] ?? $row['IMAGE'] ?? 'cement.svg'),
            ];
        }

        return ['id' => $cartId, 'items' => $items, 'count' => $count, 'total' => $total];
    }

    private function validateStock(array $items): array
    {
        foreach ($items as $item) {
            $product = Database::fetchOne(
                "SELECT name, stock_qty, status FROM rc_products WHERE id = :id AND is_active = 1",
                ['id' => (int)$item['product_id']]
            );

            if (!$product) {
                return ['ok' => false, 'message' => 'Один із товарів більше недоступний. Оновіть кошик.'];
            }

            $status = (string)($product['STATUS'] ?? 'in_stock');
            if (in_array($status, ['out_of_stock', 'expected', 'archived'], true)) {
                return ['ok' => false, 'message' => 'Товар більше недоступний для покупки: ' . (string)$product['NAME']];
            }

            if ($status !== 'preorder' && (float)$product['STOCK_QTY'] < (float)$item['quantity']) {
                return ['ok' => false, 'message' => 'Недостатньо залишку для товару: ' . (string)$product['NAME']];
            }
        }

        return ['ok' => true, 'message' => ''];
    }

    private function insertOrderItem(int $orderId, array $item): void
    {
        $columns = $this->columns('RC_ORDER_ITEMS');
        $fields = ['order_id', 'product_id', 'price'];
        $values = [':order_id', ':product_id', ':price'];
        $params = [
            'order_id' => $orderId,
            'product_id' => (int)$item['product_id'],
            'price' => (float)$item['price'],
        ];

        if (isset($columns['PRODUCT_NAME'])) {
            $fields[] = 'product_name';
            $values[] = ':product_name';
            $params['product_name'] = (string)$item['name'];
        }

        if (isset($columns['SKU'])) {
            $fields[] = 'sku';
            $values[] = ':sku';
            $params['sku'] = (string)$item['sku'];
        }

        if (isset($columns['UNIT'])) {
            $fields[] = 'unit';
            $values[] = ':unit';
            $params['unit'] = (string)$item['unit'];
        }

        if (isset($columns['QUANTITY'])) {
            $fields[] = 'quantity';
            $values[] = ':quantity';
            $params['quantity'] = (float)$item['quantity'];
        }

        if (isset($columns['QTY'])) {
            $fields[] = 'qty';
            $values[] = ':qty';
            $params['qty'] = (float)$item['quantity'];
        }

        if (isset($columns['LINE_TOTAL'])) {
            $fields[] = 'line_total';
            $values[] = ':line_total';
            $params['line_total'] = (float)$item['line_total'];
        }

        Database::execute(
            'INSERT INTO rc_order_items (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')',
            $params
        );
    }

    private function createDeliveryShipment(int $orderId, array $delivery): void
    {
        if (!$this->tableExists('RC_DELIVERY_SHIPMENTS')) {
            return;
        }

        $type = (string)($delivery['delivery_type'] ?? 'pickup');
        $carrier = (string)($delivery['delivery_carrier'] ?? $type);
        if ($carrier === '') {
            $carrier = $type;
        }

        $columns = $this->columns('RC_DELIVERY_SHIPMENTS');

        $fields = [
            'order_id',
            'carrier_code',
            'delivery_type',
            'city',
            'warehouse',
            'address_text',
            'recipient_name',
            'recipient_phone',
            'delivery_status',
            'manager_comment',
        ];
        $values = [
            ':order_id',
            ':carrier_code',
            ':delivery_type',
            ':city',
            ':warehouse',
            ':address_text',
            ':recipient_name',
            ':recipient_phone',
            ':delivery_status',
            ':manager_comment',
        ];
        $params = [
            'order_id' => $orderId,
            'carrier_code' => $carrier,
            'delivery_type' => $type,
            'city' => trim((string)($delivery['delivery_city'] ?? '')) ?: null,
            'warehouse' => trim((string)($delivery['delivery_warehouse'] ?? '')) ?: null,
            'address_text' => trim((string)($delivery['delivery_address'] ?? '')) ?: null,
            'recipient_name' => trim((string)($delivery['delivery_recipient'] ?? '')) ?: null,
            'recipient_phone' => trim((string)($delivery['delivery_recipient_phone'] ?? $delivery['customer_phone'] ?? '')) ?: null,
            'delivery_status' => 'pending',
            'manager_comment' => trim((string)($delivery['comment_text'] ?? '')) ?: null,
        ];

        if (isset($columns['CITY_REF'])) {
            $fields[] = 'city_ref';
            $values[] = ':city_ref';
            $params['city_ref'] = trim((string)($delivery['delivery_city_ref'] ?? '')) ?: null;
        }

        if (isset($columns['WAREHOUSE_REF'])) {
            $fields[] = 'warehouse_ref';
            $values[] = ':warehouse_ref';
            $params['warehouse_ref'] = trim((string)($delivery['delivery_warehouse_ref'] ?? '')) ?: null;
        }

        Database::execute(
            'INSERT INTO rc_delivery_shipments (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')',
            $params
        );
    }

    private function tableExists(string $table): bool
    {
        $row = Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM user_tables WHERE table_name = :table_name",
            ['table_name' => strtoupper($table)]
        );

        return $row && (int)$row['CNT'] > 0;
    }

    private function executeReturningId(string $sql, array $params): int
    {
        $conn = Database::connect();
        if (!$conn) {
            return 0;
        }

        $stmt = oci_parse($conn, $sql);
        if (!$stmt) {
            $error = oci_error($conn);
            return 0;
        }

        $binds = [];
        foreach ($params as $name => $value) {
            $key = ':' . ltrim((string)$name, ':');
            $binds[$key] = $value;
            oci_bind_by_name($stmt, $key, $binds[$key]);
        }

        $newId = 0;
        oci_bind_by_name($stmt, ':new_id', $newId, 32, SQLT_INT);
        $ok = @oci_execute($stmt, OCI_COMMIT_ON_SUCCESS);
        if (!$ok) {
            $error = oci_error($stmt);
        }
        oci_free_statement($stmt);

        return (int)$newId;
    }

    private function columns(string $table): array
    {
        $rows = Database::fetchAll(
            "SELECT column_name FROM user_tab_columns WHERE table_name = :table_name",
            ['table_name' => strtoupper($table)]
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[(string)$row['COLUMN_NAME']] = true;
        }

        return $columns;
    }

    private function logStatus(int $orderId, string $status, ?array $user): void
    {
        $table = Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM user_tables WHERE table_name = 'RC_ORDER_STATUS_HISTORY'"
        );

        if (!$table || (int)$table['CNT'] === 0) {
            return;
        }

        Database::execute(
            "INSERT INTO rc_order_status_history (order_id, status, user_id, comment_text)
             VALUES (:order_id, :status, :user_id, :comment_text)",
            [
                'order_id' => $orderId,
                'status' => $status,
                'user_id' => !empty($user['id']) ? (int)$user['id'] : null,
                'comment_text' => 'Замовлення створено через checkout сайту.',
            ]
        );
    }
}
