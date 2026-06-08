<?php
declare(strict_types=1);

final class AccountService
{
    public function __construct(private CartService $cartService)
    {
    }

    public function handle(string $action, array $data, ?array $user): array
    {
        if (!$user || empty($user['id'])) {
            return ['ok' => false, 'message' => 'Потрібно увійти в особистий кабінет.'];
        }

        return match ($action) {
            'account_update_profile' => $this->updateProfile($data, $user),
            'account_create_address' => $this->createAddress($data, $user),
            'account_delete_address' => $this->deleteAddress($data, $user),
            'account_save_notifications' => $this->saveNotifications($data, $user),
            'wishlist_toggle' => $this->toggleWishlist((int)($data['product_id'] ?? 0), $user),
            'wishlist_remove' => $this->removeWishlist((int)($data['product_id'] ?? 0), $user),
            'comparison_toggle' => $this->toggleComparison((int)($data['product_id'] ?? 0), $user),
            'comparison_remove' => $this->removeComparison((int)($data['product_id'] ?? 0), $user),
            'repeat_order' => $this->repeatOrder((int)($data['order_id'] ?? 0), $user),
            default => ['ok' => false, 'message' => 'Невідома дія особистого кабінету.'],
        };
    }

    public function dashboardData(array $user): array
    {
        $userId = (int)($user['id'] ?? 0);

        $profile = $this->profile($userId);
        $orderIdentity = array_merge($user, [
            'phone' => $profile['phone'] ?? ($user['phone'] ?? ''),
            'email' => $profile['email'] ?? ($user['email'] ?? ''),
        ]);
        $this->attachOrdersToUser($userId, $orderIdentity);

        return [
            'profile' => $profile,
            'orders' => $this->orders($orderIdentity),
            'wishlist' => $this->wishlist($userId),
            'comparison' => $this->comparison($userId),
            'addresses' => $this->addresses($userId),
            'notifications' => $this->notificationSettings($userId),
            'status_labels' => $this->statusLabels(),
        ];
    }

    public function wishlistCount(?array $user): int
    {
        if (!$user || empty($user['id'])) {
            return 0;
        }

        $row = Database::fetchOne(
            'SELECT COUNT(*) AS cnt FROM rc_wishlist WHERE user_id = :user_id',
            ['user_id' => (int)$user['id']]
        );

        return (int)($row['CNT'] ?? 0);
    }

    public function comparisonCount(?array $user): int
    {
        if (!$user || empty($user['id'])) {
            return 0;
        }

        $row = Database::fetchOne(
            'SELECT COUNT(*) AS cnt FROM rc_comparison_items WHERE user_id = :user_id',
            ['user_id' => (int)$user['id']]
        );

        return (int)($row['CNT'] ?? 0);
    }

    private function updateProfile(array $data, array $user): array
    {
        $userId = (int)$user['id'];
        $fullName = trim((string)($data['full_name'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $password = trim((string)($data['password'] ?? ''));

        if ($fullName === '' || $phone === '') {
            return ['ok' => false, 'message' => 'Вкажіть ПІБ і телефон.'];
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Email має неправильний формат.'];
        }

        if ($password !== '' && mb_strlen($password) < 6) {
            return ['ok' => false, 'message' => 'Новий пароль має містити щонайменше 6 символів.'];
        }

        $phoneDigits = $this->normalizePhone($phone);
        $duplicate = Database::fetchOne(
            "SELECT id
             FROM rc_users
             WHERE id <> :id
               AND (REGEXP_REPLACE(phone, '[^0-9]', '') = :phone_digits
                    OR (:email_value IS NOT NULL AND LOWER(email) = LOWER(:email_value)))
             FETCH FIRST 1 ROWS ONLY",
            ['id' => $userId, 'phone_digits' => $phoneDigits, 'email_value' => $email !== '' ? $email : null]
        );

        if ($duplicate) {
            return ['ok' => false, 'message' => 'Телефон або email уже використовується іншим користувачем.'];
        }

        $sql = "UPDATE rc_users
                SET full_name = :full_name,
                    phone = :phone,
                    email = :email";
        $params = [
            'full_name' => $fullName,
            'phone' => $phone,
            'email' => $email !== '' ? $email : null,
            'id' => $userId,
        ];

        if ($password !== '') {
            $sql .= ', password_hash = :password_hash';
            $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        $sql .= ' WHERE id = :id';

        $ok = Database::execute($sql, $params);
        if (!$ok) {
            return ['ok' => false, 'message' => 'Профіль не оновлено: ' . (Database::lastError() ?? 'невідома помилка')];
        }

        $_SESSION['user']['name'] = $fullName;
        $_SESSION['user']['phone'] = $phone;
        $_SESSION['user']['email'] = $email;

        return ['ok' => true, 'message' => 'Профіль оновлено.'];
    }

    private function createAddress(array $data, array $user): array
    {
        $userId = (int)$user['id'];
        $label = trim((string)($data['label'] ?? ''));
        $recipientName = trim((string)($data['recipient_name'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $city = trim((string)($data['city'] ?? ''));
        $address = trim((string)($data['address'] ?? ''));
        $deliveryType = trim((string)($data['delivery_type'] ?? 'courier'));
        $isDefault = !empty($data['is_default']) ? 1 : 0;

        if ($recipientName === '' || $phone === '' || $city === '' || $address === '') {
            return ['ok' => false, 'message' => 'Заповніть отримувача, телефон, місто та адресу.'];
        }

        if ($isDefault === 1) {
            Database::execute('UPDATE rc_user_addresses SET is_default = 0 WHERE user_id = :user_id', ['user_id' => $userId]);
        }

        $ok = Database::execute(
            "INSERT INTO rc_user_addresses (user_id, label, recipient_name, phone, city, address, delivery_type, is_default)
             VALUES (:user_id, :label, :recipient_name, :phone, :city, :address, :delivery_type, :is_default)",
            [
                'user_id' => $userId,
                'label' => $label !== '' ? $label : 'Адреса',
                'recipient_name' => $recipientName,
                'phone' => $phone,
                'city' => $city,
                'address' => $address,
                'delivery_type' => $deliveryType !== '' ? $deliveryType : 'courier',
                'is_default' => $isDefault,
            ]
        );

        return [
            'ok' => $ok,
            'message' => $ok ? 'Адресу доставки додано.' : 'Адресу не додано: ' . (Database::lastError() ?? 'невідома помилка'),
        ];
    }

    private function deleteAddress(array $data, array $user): array
    {
        $ok = Database::execute(
            'DELETE FROM rc_user_addresses WHERE id = :id AND user_id = :user_id',
            ['id' => (int)($data['address_id'] ?? 0), 'user_id' => (int)$user['id']]
        );

        return [
            'ok' => $ok,
            'message' => $ok ? 'Адресу видалено.' : 'Адресу не видалено: ' . (Database::lastError() ?? 'невідома помилка'),
        ];
    }

    private function saveNotifications(array $data, array $user): array
    {
        $userId = (int)$user['id'];
        $params = [
            'user_id' => $userId,
            'email_notifications' => !empty($data['email_notifications']) ? 1 : 0,
            'sms_notifications' => !empty($data['sms_notifications']) ? 1 : 0,
            'telegram_notifications' => !empty($data['telegram_notifications']) ? 1 : 0,
            'order_status_notifications' => !empty($data['order_status_notifications']) ? 1 : 0,
            'promo_notifications' => !empty($data['promo_notifications']) ? 1 : 0,
        ];

        $ok = Database::execute(
            "MERGE INTO rc_user_notification_settings s
             USING (
                SELECT :user_id AS user_id,
                       :email_notifications AS email_notifications,
                       :sms_notifications AS sms_notifications,
                       :telegram_notifications AS telegram_notifications,
                       :order_status_notifications AS order_status_notifications,
                       :promo_notifications AS promo_notifications
                FROM dual
             ) v
             ON (s.user_id = v.user_id)
             WHEN MATCHED THEN UPDATE SET
                s.email_notifications = v.email_notifications,
                s.sms_notifications = v.sms_notifications,
                s.telegram_notifications = v.telegram_notifications,
                s.order_status_notifications = v.order_status_notifications,
                s.promo_notifications = v.promo_notifications,
                s.updated_at = CURRENT_TIMESTAMP
             WHEN NOT MATCHED THEN INSERT (
                user_id, email_notifications, sms_notifications, telegram_notifications, order_status_notifications, promo_notifications
             ) VALUES (
                v.user_id, v.email_notifications, v.sms_notifications, v.telegram_notifications, v.order_status_notifications, v.promo_notifications
             )",
            $params
        );

        return [
            'ok' => $ok,
            'message' => $ok ? 'Налаштування сповіщень збережено.' : 'Сповіщення не збережено: ' . (Database::lastError() ?? 'невідома помилка'),
        ];
    }

    private function toggleWishlist(int $productId, array $user): array
    {
        if ($productId <= 0) {
            return ['ok' => false, 'message' => 'Товар не знайдено.'];
        }

        $userId = (int)$user['id'];
        $existing = Database::fetchOne(
            'SELECT id FROM rc_wishlist WHERE user_id = :user_id AND product_id = :product_id',
            ['user_id' => $userId, 'product_id' => $productId]
        );

        if ($existing) {
            Database::execute('DELETE FROM rc_wishlist WHERE id = :id', ['id' => (int)$existing['ID']]);
            return ['ok' => true, 'message' => 'Товар прибрано з бажаного.'];
        }

        $product = Database::fetchOne('SELECT id FROM rc_products WHERE id = :id AND is_active = 1', ['id' => $productId]);
        if (!$product) {
            return ['ok' => false, 'message' => 'Товар не знайдено або він неактивний.'];
        }

        $ok = Database::execute(
            'INSERT INTO rc_wishlist (user_id, product_id) VALUES (:user_id, :product_id)',
            ['user_id' => $userId, 'product_id' => $productId]
        );

        return [
            'ok' => $ok,
            'message' => $ok ? 'Товар додано в бажане.' : 'Не вдалося додати в бажане: ' . (Database::lastError() ?? 'невідома помилка'),
        ];
    }

    private function removeWishlist(int $productId, array $user): array
    {
        $ok = Database::execute(
            'DELETE FROM rc_wishlist WHERE user_id = :user_id AND product_id = :product_id',
            ['user_id' => (int)$user['id'], 'product_id' => $productId]
        );

        return ['ok' => $ok, 'message' => $ok ? 'Товар прибрано з бажаного.' : 'Не вдалося видалити товар.'];
    }

    private function toggleComparison(int $productId, array $user): array
    {
        if ($productId <= 0) {
            return ['ok' => false, 'message' => 'Товар не знайдено.'];
        }

        $userId = (int)$user['id'];
        $existing = Database::fetchOne(
            'SELECT id FROM rc_comparison_items WHERE user_id = :user_id AND product_id = :product_id',
            ['user_id' => $userId, 'product_id' => $productId]
        );

        if ($existing) {
            Database::execute('DELETE FROM rc_comparison_items WHERE id = :id', ['id' => (int)$existing['ID']]);
            return ['ok' => true, 'message' => 'Товар прибрано з порівняння.'];
        }

        
        

        $product = Database::fetchOne('SELECT id FROM rc_products WHERE id = :id AND is_active = 1', ['id' => $productId]);
        if (!$product) {
            return ['ok' => false, 'message' => 'Товар не знайдено або він неактивний.'];
        }

        $ok = Database::execute(
            'INSERT INTO rc_comparison_items (user_id, session_id, product_id) VALUES (:user_id, :session_id, :product_id)',
            ['user_id' => $userId, 'session_id' => session_id(), 'product_id' => $productId]
        );

        return [
            'ok' => $ok,
            'message' => $ok ? 'Товар додано до порівняння.' : 'Не вдалося додати до порівняння: ' . (Database::lastError() ?? 'невідома помилка'),
        ];
    }

    private function removeComparison(int $productId, array $user): array
    {
        $ok = Database::execute(
            'DELETE FROM rc_comparison_items WHERE user_id = :user_id AND product_id = :product_id',
            ['user_id' => (int)$user['id'], 'product_id' => $productId]
        );

        return ['ok' => $ok, 'message' => $ok ? 'Товар прибрано з порівняння.' : 'Не вдалося видалити товар.'];
    }

    private function repeatOrder(int $orderId, array $user): array
    {
        if ($orderId <= 0) {
            return ['ok' => false, 'message' => 'Замовлення не знайдено.'];
        }

        $order = $this->orderForUser($orderId, $user);
        if (!$order) {
            return ['ok' => false, 'message' => 'Немає доступу до цього замовлення.'];
        }

        $items = Database::fetchAll(
            'SELECT product_id, quantity FROM rc_order_items WHERE order_id = :order_id AND product_id IS NOT NULL',
            ['order_id' => $orderId]
        );

        if (!$items) {
            return ['ok' => false, 'message' => 'У замовленні немає товарів для повторення.'];
        }

        $added = 0;
        foreach ($items as $item) {
            $result = $this->cartService->addProduct((int)$item['PRODUCT_ID'], (float)$item['QUANTITY'], $user);
            if (!empty($result['ok'])) {
                $added++;
            }
        }

        if ($added === 0) {
            return ['ok' => false, 'message' => 'Не вдалося повторити замовлення. Перевірте наявність товарів.'];
        }

        return ['ok' => true, 'message' => 'Товари з замовлення додано в кошик.'];
    }

    private function profile(int $userId): array
    {
        $row = Database::fetchOne(
            "SELECT u.id, u.full_name, u.phone, u.email, r.code AS role_code, r.name AS role_name
             FROM rc_users u
             JOIN rc_roles r ON r.id = u.role_id
             WHERE u.id = :id",
            ['id' => $userId]
        );

        if (!$row) {
            return [];
        }

        return [
            'id' => (int)$row['ID'],
            'full_name' => (string)$row['FULL_NAME'],
            'phone' => (string)($row['PHONE'] ?? ''),
            'email' => (string)($row['EMAIL'] ?? ''),
            'role_code' => (string)$row['ROLE_CODE'],
            'role_name' => (string)$row['ROLE_NAME'],
        ];
    }


    private function attachOrdersToUser(int $userId, array $identity): void
    {
        if ($userId <= 0) {
            return;
        }
        $phone = $this->normalizePhone((string)($identity['phone'] ?? ''));
        $email = strtolower(trim((string)($identity['email'] ?? '')));
        if ($phone === '' && $email === '') {
            return;
        }
        try {
            Database::execute(
                "UPDATE rc_orders
                 SET user_id = :user_id
                 WHERE (user_id IS NULL OR user_id = 0)
                   AND ((:phone_digits IS NOT NULL AND REGEXP_REPLACE(customer_phone, '[^0-9]', '') = :phone_digits)
                        OR (:email_value IS NOT NULL AND LOWER(TRIM(customer_email)) = :email_value))",
                [
                    'user_id' => $userId,
                    'phone_digits' => $phone !== '' ? $phone : null,
                    'email_value' => $email !== '' ? $email : null,
                ]
            );
        } catch (Throwable) {
            
        }
    }

    private function orders(array $user): array
    {
        $userId = (int)($user['id'] ?? 0);
        $phone = $this->normalizePhone((string)($user['phone'] ?? ''));
        $email = strtolower(trim((string)($user['email'] ?? '')));

        $orders = Database::fetchAll(
            "SELECT id, customer_name, customer_phone, customer_email, delivery_type, delivery_address,
                    payment_type, status, total_amount, comment_text, created_at, updated_at,
                    TO_CHAR(created_at, 'DD.MM.YYYY HH24:MI') AS created_at_fmt,
                    TO_CHAR(updated_at, 'DD.MM.YYYY HH24:MI') AS updated_at_fmt
             FROM rc_orders
             WHERE user_id = :user_id
                OR (:phone_digits IS NOT NULL AND REGEXP_REPLACE(customer_phone, '[^0-9]', '') = :phone_digits)
                OR (:email_value IS NOT NULL AND LOWER(TRIM(customer_email)) = :email_value)
             ORDER BY created_at DESC, id DESC",
            ['user_id' => $userId, 'phone_digits' => $phone !== '' ? $phone : null, 'email_value' => $email !== '' ? $email : null]
        );

        $result = [];
        foreach ($orders as $order) {
            $orderId = (int)$order['ID'];
            $items = Database::fetchAll(
                'SELECT id, product_id, product_name, sku, quantity, unit, price, line_total FROM rc_order_items WHERE order_id = :order_id ORDER BY id',
                ['order_id' => $orderId]
            );

            $history = [];
            if ($this->tableExists('RC_ORDER_STATUS_HISTORY')) {
                $history = Database::fetchAll(
                    "SELECT status, comment_text, TO_CHAR(created_at, 'DD.MM.YYYY HH24:MI') AS created_at FROM rc_order_status_history WHERE order_id = :order_id ORDER BY created_at ASC, id ASC",
                    ['order_id' => $orderId]
                );
            }

            $result[] = [
                'id' => $orderId,
                'customer_name' => (string)$order['CUSTOMER_NAME'],
                'customer_phone' => (string)$order['CUSTOMER_PHONE'],
                'customer_email' => (string)($order['CUSTOMER_EMAIL'] ?? ''),
                'delivery_type' => (string)($order['DELIVERY_TYPE'] ?? ''),
                'delivery_address' => (string)($order['DELIVERY_ADDRESS'] ?? ''),
                'payment_type' => (string)($order['PAYMENT_TYPE'] ?? ''),
                'status' => (string)$order['STATUS'],
                'total_amount' => (float)$order['TOTAL_AMOUNT'],
                'comment_text' => $this->dbValueToString($order['COMMENT_TEXT'] ?? ''),
                'created_at' => (string)($order['CREATED_AT_FMT'] ?? $order['CREATED_AT']),
                'updated_at' => (string)($order['UPDATED_AT_FMT'] ?? $order['UPDATED_AT'] ?? ''),
                'items' => $items,
                'history' => $history,
            ];
        }

        return $result;
    }

    private function orderForUser(int $orderId, array $user): ?array
    {
        $phone = $this->normalizePhone((string)($user['phone'] ?? ''));
        $email = strtolower(trim((string)($user['email'] ?? '')));
        return Database::fetchOne(
            "SELECT id
             FROM rc_orders
             WHERE id = :id
               AND (user_id = :user_id
                    OR (:phone_digits IS NOT NULL AND REGEXP_REPLACE(customer_phone, '[^0-9]', '') = :phone_digits)
                    OR (:email_value IS NOT NULL AND LOWER(TRIM(customer_email)) = :email_value))",
            ['id' => $orderId, 'user_id' => (int)$user['id'], 'phone_digits' => $phone !== '' ? $phone : null, 'email_value' => $email !== '' ? $email : null]
        );
    }

    private function wishlist(int $userId): array
    {
        $rows = Database::fetchAll(
            "SELECT p.id, p.sku, p.name, p.brand, p.unit, p.price, p.old_price, p.stock_qty, p.status, p.image,
                    c.slug AS category_slug, c.name AS category_name,
                    (SELECT i.image_path FROM rc_product_images i WHERE i.product_id = p.id ORDER BY i.is_main DESC, i.sort_order, i.id FETCH FIRST 1 ROWS ONLY) AS main_image
             FROM rc_wishlist w
             JOIN rc_products p ON p.id = w.product_id
             JOIN rc_categories c ON c.id = p.category_id
             WHERE w.user_id = :user_id AND p.is_active = 1
             ORDER BY w.created_at DESC, w.id DESC",
            ['user_id' => $userId]
        );

        return array_map(fn(array $row): array => $this->productFromRow($row), $rows);
    }

    private function comparison(int $userId): array
    {
        $rows = Database::fetchAll(
            "SELECT p.id, p.sku, p.name, p.brand, p.unit, p.price, p.old_price, p.stock_qty, p.status, p.image,
                    c.slug AS category_slug, c.name AS category_name,
                    (SELECT i.image_path FROM rc_product_images i WHERE i.product_id = p.id ORDER BY i.is_main DESC, i.sort_order, i.id FETCH FIRST 1 ROWS ONLY) AS main_image
             FROM rc_comparison_items ci
             JOIN rc_products p ON p.id = ci.product_id
             JOIN rc_categories c ON c.id = p.category_id
             WHERE ci.user_id = :user_id AND p.is_active = 1
             ORDER BY ci.created_at DESC, ci.id DESC",
            ['user_id' => $userId]
        );

        $products = array_map(fn(array $row): array => $this->productFromRow($row), $rows);

        foreach ($products as &$product) {
            $attrs = Database::fetchAll(
                'SELECT attr_name, attr_value FROM rc_product_attributes WHERE product_id = :id ORDER BY sort_order, id',
                ['id' => (int)$product['id']]
            );
            $product['attrs'] = [];
            foreach ($attrs as $attr) {
                $product['attrs'][(string)$attr['ATTR_NAME']] = (string)$attr['ATTR_VALUE'];
            }
        }
        unset($product);

        return $products;
    }

    private function addresses(int $userId): array
    {
        return Database::fetchAll(
            'SELECT id, label, recipient_name, phone, city, address, delivery_type, is_default, created_at
             FROM rc_user_addresses
             WHERE user_id = :user_id
             ORDER BY is_default DESC, created_at DESC, id DESC',
            ['user_id' => $userId]
        );
    }

    private function notificationSettings(int $userId): array
    {
        $row = Database::fetchOne(
            'SELECT email_notifications, sms_notifications, telegram_notifications, order_status_notifications, promo_notifications
             FROM rc_user_notification_settings
             WHERE user_id = :user_id',
            ['user_id' => $userId]
        );

        if (!$row) {
            return [
                'email_notifications' => 1,
                'sms_notifications' => 1,
                'telegram_notifications' => 0,
                'order_status_notifications' => 1,
                'promo_notifications' => 0,
            ];
        }

        return [
            'email_notifications' => (int)$row['EMAIL_NOTIFICATIONS'],
            'sms_notifications' => (int)$row['SMS_NOTIFICATIONS'],
            'telegram_notifications' => (int)$row['TELEGRAM_NOTIFICATIONS'],
            'order_status_notifications' => (int)$row['ORDER_STATUS_NOTIFICATIONS'],
            'promo_notifications' => (int)$row['PROMO_NOTIFICATIONS'],
        ];
    }

    private function productFromRow(array $row): array
    {
        return [
            'id' => (int)$row['ID'],
            'sku' => (string)$row['SKU'],
            'name' => (string)$row['NAME'],
            'brand' => (string)($row['BRAND'] ?? ''),
            'unit' => (string)($row['UNIT'] ?? 'шт.'),
            'price' => (float)$row['PRICE'],
            'old_price' => $row['OLD_PRICE'] !== null ? (float)$row['OLD_PRICE'] : null,
            'stock_qty' => (float)$row['STOCK_QTY'],
            'status' => (string)$row['STATUS'],
            'image' => (string)($row['MAIN_IMAGE'] ?? $row['IMAGE'] ?? 'cement.svg'),
            'category' => (string)$row['CATEGORY_SLUG'],
            'category_name' => (string)$row['CATEGORY_NAME'],
        ];
    }

    private function statusLabels(): array
    {
        return [
            'created' => 'створено',
            'waiting_confirmation' => 'очікує підтвердження',
            'confirmed' => 'підтверджено',
            'waiting_payment' => 'очікує оплати',
            'paid' => 'оплачено',
            'packing' => 'комплектується',
            'packed' => 'зібрано',
            'sent' => 'передано в доставку',
            'delivering' => 'доставляється',
            'delivered' => 'доставлено',
            'cancelled' => 'скасовано',
            'returned' => 'повернення',
        ];
    }

    private function tableExists(string $tableName): bool
    {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS cnt FROM user_tables WHERE table_name = :table_name',
            ['table_name' => strtoupper($tableName)]
        );

        return (int)($row['CNT'] ?? 0) > 0;
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone) ?? '';
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
