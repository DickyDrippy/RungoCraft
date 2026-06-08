<?php
declare(strict_types=1);

final class PaymentService
{
    public function handle(string $action, array $data, ?array $user): array
    {
        return match ($action) {
            'payment_start_online' => $this->startOnlinePayment($data, $user),
            'payment_simulate_success' => $this->simulateSuccess($data, $user),
            'payment_admin_update' => $this->adminUpdate($data, $user),
            default => ['ok' => false, 'message' => 'Невідома дія модуля оплати.'],
        };
    }

    public function methods(): array
    {
        if (!$this->tableExists('RC_PAYMENT_METHODS')) {
            return $this->fallbackMethods();
        }

        $rows = Database::fetchAll(
            "SELECT code, name, provider, description, is_online, is_active, sort_order
             FROM rc_payment_methods
             WHERE is_active = 1
             ORDER BY sort_order, id"
        );

        if (!$rows) {
            return $this->fallbackMethods();
        }

        return array_map(static function (array $row): array {
            return [
                'CODE' => (string)$row['CODE'],
                'NAME' => (string)$row['NAME'],
                'PROVIDER' => (string)($row['PROVIDER'] ?? 'manual'),
                'DESCRIPTION' => (string)($row['DESCRIPTION'] ?? ''),
                'IS_ONLINE' => (int)($row['IS_ONLINE'] ?? 0),
                'SORT_ORDER' => (int)($row['SORT_ORDER'] ?? 100),
            ];
        }, $rows);
    }

    public function ensureForOrder(int $orderId, string $methodCode, ?array $user = null): ?array
    {
        if ($orderId <= 0 || !$this->tableExists('RC_PAYMENTS')) {
            return null;
        }

        $order = $this->order($orderId);
        if (!$order) {
            return null;
        }

        $existing = $this->paymentForOrder($orderId);
        if ($existing) {
            return $existing;
        }

        $method = $this->method($methodCode) ?? $this->method('cash') ?? $this->fallbackMethods()[0];
        $code = (string)$method['CODE'];
        $provider = $this->providerForMethod($code, (string)($method['PROVIDER'] ?? 'manual'));
        $amount = (float)($order['TOTAL_AMOUNT'] ?? 0);
        $isOnline = (int)($method['IS_ONLINE'] ?? 0) === 1 || in_array($provider, ['liqpay', 'wayforpay'], true);
        $status = $isOnline ? 'pending' : ($code === 'cash' ? 'pay_on_delivery' : 'waiting_manager');
        $ref = $this->makePaymentReference($orderId, $provider);
        $checkoutUrl = $isOnline ? ('/payment-gateway?provider=' . rawurlencode($provider) . '&payment_id=__PAYMENT_ID__') : null;

        $ok = Database::execute(
            "INSERT INTO rc_payments (
                order_id, method_code, provider, status, amount, currency, transaction_ref, checkout_url, client_note
             ) VALUES (
                :order_id, :method_code, :provider, :status, :amount, 'UAH', :transaction_ref, :checkout_url, :client_note
             )",
            [
                'order_id' => $orderId,
                'method_code' => $code,
                'provider' => $provider,
                'status' => $status,
                'amount' => $amount,
                'transaction_ref' => $ref,
                'checkout_url' => $checkoutUrl,
                'client_note' => $isOnline
                    ? 'Платіж очікує переходу на сторінку платіжного сервісу.'
                    : 'Оплату контролює менеджер.',
            ]
        );

        if (!$ok) {
            return null;
        }

        $payment = $this->paymentForOrder($orderId);
        if ($payment && $isOnline) {
            $paymentId = (int)$payment['ID'];
            $realCheckoutUrl = '/payment-gateway?provider=' . rawurlencode($provider) . '&payment_id=' . $paymentId;
            Database::execute(
                "UPDATE rc_payments SET checkout_url = :url, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
                ['url' => $realCheckoutUrl, 'id' => $paymentId]
            );
            $payment = $this->paymentForOrder($orderId) ?? $payment;
        }

        $this->syncOrderPaymentStatus($orderId, $status, $provider, $ref);
        return $payment;
    }

    public function paymentForOrder(int $orderId): ?array
    {
        if ($orderId <= 0 || !$this->tableExists('RC_PAYMENTS')) {
            return null;
        }

        return Database::fetchOne(
            "SELECT p.*, m.name AS method_name
             FROM rc_payments p
             LEFT JOIN rc_payment_methods m ON m.code = p.method_code
             WHERE p.order_id = :order_id
             ORDER BY p.created_at DESC, p.id DESC
             FETCH FIRST 1 ROWS ONLY",
            ['order_id' => $orderId]
        );
    }


    public function confirmGatewayReturn(int $orderId, int $paymentId = 0, string $provider = ''): ?array
    {
        if ($orderId <= 0 || !$this->tableExists('RC_PAYMENTS')) {
            return null;
        }

        if ($paymentId > 0) {
            $payment = Database::fetchOne('SELECT * FROM rc_payments WHERE id = :id AND order_id = :order_id', ['id' => $paymentId, 'order_id' => $orderId]);
            if (!$payment) {
                $payment = Database::fetchOne('SELECT * FROM rc_payments WHERE id = :id', ['id' => $paymentId]);
                if ($payment && (int)($payment['ORDER_ID'] ?? 0) > 0) {
                    $orderId = (int)$payment['ORDER_ID'];
                }
            }
        } else {
            $payment = $this->paymentForOrder($orderId);
        }

        if (!$payment) {
            return null;
        }

        $currentStatus = strtolower((string)($payment['STATUS'] ?? ''));
        $paymentProvider = trim($provider) !== '' ? trim($provider) : (string)($payment['PROVIDER'] ?? 'online');
        $ref = trim((string)($payment['TRANSACTION_REF'] ?? ''));
        if ($ref === '') {
            $ref = strtoupper($paymentProvider) . '-RETURN-' . date('ymdHis') . '-' . $orderId;
        }

        if ($currentStatus !== 'paid') {
            $columns = $this->columns('RC_PAYMENTS');
            $sets = ['status = :status'];
            $params = ['status' => 'paid', 'id' => (int)$payment['ID']];

            if (isset($columns['PAID_AT'])) {
                $sets[] = 'paid_at = COALESCE(paid_at, CURRENT_TIMESTAMP)';
            }
            if (isset($columns['PROVIDER'])) {
                $sets[] = 'provider = :provider';
                $params['provider'] = $paymentProvider;
            }
            if (isset($columns['TRANSACTION_REF'])) {
                $sets[] = 'transaction_ref = :transaction_ref';
                $params['transaction_ref'] = $ref;
            }
            if (isset($columns['GATEWAY_STATUS'])) {
                $sets[] = 'gateway_status = :gateway_status';
                $params['gateway_status'] = 'success_return';
            }
            if (isset($columns['CLIENT_NOTE'])) {
                $sets[] = 'client_note = :client_note';
                $params['client_note'] = 'Статус підтверджено після повернення з платіжної сторінки.';
            }
            if (isset($columns['UPDATED_AT'])) {
                $sets[] = 'updated_at = CURRENT_TIMESTAMP';
            }

            $ok = Database::execute('UPDATE rc_payments SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
            if ($ok) {
                $this->syncOrderPaymentStatus($orderId, 'paid', $paymentProvider, $ref);
            }
        }

        return $this->paymentForOrder($orderId);
    }

    public function adminData(?array $user): array
    {
        if (!$this->canManage($user) || !$this->tableExists('RC_PAYMENTS')) {
            return ['stats' => [], 'payments' => [], 'methods' => $this->methods()];
        }

        $payments = Database::fetchAll(
            "SELECT
                p.id,
                p.order_id,
                p.method_code,
                p.provider,
                p.status,
                p.amount,
                p.currency,
                p.transaction_ref,
                p.checkout_url,
                p.client_note,
                p.paid_at,
                p.created_at,
                o.customer_name,
                o.customer_phone,
                o.status AS order_status,
                m.name AS method_name
             FROM rc_payments p
             LEFT JOIN rc_orders o ON o.id = p.order_id
             LEFT JOIN rc_payment_methods m ON m.code = p.method_code
             ORDER BY p.created_at DESC, p.id DESC
             FETCH FIRST 80 ROWS ONLY"
        );

        return [
            'stats' => [
                'pending' => $this->countByStatus('pending'),
                'paid' => $this->countByStatus('paid'),
                'waiting_manager' => $this->countByStatus('waiting_manager'),
                'failed' => $this->countByStatus('failed'),
            ],
            'payments' => $payments,
            'methods' => $this->methods(),
        ];
    }

    private function startOnlinePayment(array $data, ?array $user): array
    {
        $orderId = (int)($data['order_id'] ?? 0);
        if ($orderId <= 0) {
            return ['ok' => false, 'message' => 'Замовлення не знайдено для оплати.'];
        }

        $order = $this->order($orderId);
        if (!$order) {
            return ['ok' => false, 'message' => 'Замовлення не знайдено.'];
        }

        if (!$this->canAccessOrder($order, $user)) {
            return ['ok' => false, 'message' => 'Немає доступу до цього замовлення.'];
        }

        $methodCode = (string)($order['PAYMENT_TYPE'] ?? 'online_card');
        $payment = $this->paymentForOrder($orderId) ?? $this->ensureForOrder($orderId, $methodCode, $user);
        if (!$payment) {
            return ['ok' => false, 'message' => 'Не вдалося створити платіж: ' . (Database::lastError() ?? 'невідома помилка')];
        }

        $url = (string)($payment['CHECKOUT_URL'] ?? '');
        if ($url === '') {
            return ['ok' => false, 'message' => 'Для цього способу оплати не потрібен онлайн-перехід.'];
        }

        return ['ok' => true, 'message' => 'Перехід до оплати готовий.', 'redirect' => $url];
    }

    private function simulateSuccess(array $data, ?array $user): array
    {
        $orderId = (int)($data['order_id'] ?? 0);
        $paymentId = (int)($data['payment_id'] ?? 0);
        $payment = null;
        if ($paymentId > 0) {
            $payment = Database::fetchOne('SELECT * FROM rc_payments WHERE id = :id', ['id' => $paymentId]);
            $orderId = (int)($payment['ORDER_ID'] ?? $orderId);
        } elseif ($orderId > 0) {
            $payment = $this->paymentForOrder($orderId);
        }

        if (!$payment) {
            return ['ok' => false, 'message' => 'Платіж не знайдено.'];
        }

        $order = $this->order($orderId);
        if (!$this->canManage($user) && (!$order || !$this->canAccessOrder($order, $user))) {
            return ['ok' => false, 'message' => 'Немає доступу до цього платежу.'];
        }

        $ref = trim((string)($payment['TRANSACTION_REF'] ?? ''));
        if ($ref === '') {
            $ref = strtoupper((string)($payment['PROVIDER'] ?? 'PAY')) . date('ymdHis') . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT);
        }

        $ok = Database::execute(
            "UPDATE rc_payments
             SET status = 'paid',
                 paid_at = CURRENT_TIMESTAMP,
                 transaction_ref = :transaction_ref,
                 client_note = 'Тестову оплату підтверджено через локальний режим.',
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            ['id' => (int)$payment['ID'], 'transaction_ref' => $ref]
        );

        if ($ok) {
            $this->syncOrderPaymentStatus($orderId, 'paid', (string)($payment['PROVIDER'] ?? 'manual'), $ref);
        }

        return ['ok' => $ok, 'message' => $ok ? 'Оплату підтверджено. Замовлення позначено як оплачене.' : 'Не вдалося оновити оплату.'];
    }

    private function adminUpdate(array $data, ?array $user): array
    {
        if (!$this->canManage($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав для керування оплатами.'];
        }

        $paymentId = (int)($data['payment_id'] ?? 0);
        $status = trim((string)($data['status'] ?? 'pending'));
        $transactionRef = trim((string)($data['transaction_ref'] ?? ''));
        $clientNote = trim((string)($data['client_note'] ?? ''));

        if ($paymentId <= 0) {
            return ['ok' => false, 'message' => 'Не обрано платіж.'];
        }

        $allowed = ['pending','waiting_manager','pay_on_delivery','invoice_sent','paid','failed','cancelled','refunded'];
        if (!in_array($status, $allowed, true)) {
            $status = 'pending';
        }

        $payment = Database::fetchOne('SELECT order_id FROM rc_payments WHERE id = :id', ['id' => $paymentId]);
        if (!$payment) {
            return ['ok' => false, 'message' => 'Платіж не знайдено.'];
        }

        $ok = Database::execute(
            "UPDATE rc_payments
             SET status = :status,
                 transaction_ref = :transaction_ref,
                 client_note = :client_note,
                 paid_at = CASE WHEN :status_paid = 'paid' THEN COALESCE(paid_at, CURRENT_TIMESTAMP) ELSE paid_at END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            [
                'status' => $status,
                'transaction_ref' => $transactionRef !== '' ? $transactionRef : null,
                'client_note' => $clientNote !== '' ? $clientNote : null,
                'status_paid' => $status,
                'id' => $paymentId,
            ]
        );

        if ($ok) {
            $this->syncOrderPaymentStatus((int)$payment['ORDER_ID'], $status, '', $transactionRef);
        }

        return ['ok' => $ok, 'message' => $ok ? 'Статус оплати оновлено.' : 'Оплату не оновлено: ' . (Database::lastError() ?? 'невідома помилка')];
    }

    private function method(string $code): ?array
    {
        foreach ($this->methods() as $method) {
            if ((string)$method['CODE'] === $code) {
                return $method;
            }
        }
        return null;
    }

    private function order(int $orderId): ?array
    {
        return Database::fetchOne('SELECT * FROM rc_orders WHERE id = :id', ['id' => $orderId]);
    }

    private function canAccessOrder(array $order, ?array $user): bool
    {
        if ($this->canManage($user)) {
            return true;
        }

        if (!$user || empty($user['id'])) {
            return false;
        }

        return isset($order['USER_ID']) && (int)$order['USER_ID'] === (int)$user['id'];
    }

    private function canManage(?array $user): bool
    {
        $role = (string)($user['role'] ?? '');
        return in_array($role, ['admin', 'manager'], true);
    }

    private function countByStatus(string $status): int
    {
        $row = Database::fetchOne('SELECT COUNT(*) AS cnt FROM rc_payments WHERE status = :status', ['status' => $status]);
        return (int)($row['CNT'] ?? 0);
    }

    private function syncOrderPaymentStatus(int $orderId, string $status, string $provider, string $reference): void
    {
        $columns = $this->columns('RC_ORDERS');
        $sets = [];
        $params = ['id' => $orderId];

        if (isset($columns['PAYMENT_STATUS'])) {
            $sets[] = 'payment_status = :payment_status';
            $params['payment_status'] = $status;
        }

        if ($provider !== '' && isset($columns['PAYMENT_PROVIDER'])) {
            $sets[] = 'payment_provider = :payment_provider';
            $params['payment_provider'] = $provider;
        }

        if ($reference !== '' && isset($columns['PAYMENT_REFERENCE'])) {
            $sets[] = 'payment_reference = :payment_reference';
            $params['payment_reference'] = $reference;
        }

        if ($status === 'paid') {
            $sets[] = "status = CASE WHEN status IN ('created','waiting_payment','waiting_confirmation','confirmed') THEN 'paid' ELSE status END";
        } elseif ($status === 'pay_on_delivery') {
            $sets[] = "status = CASE WHEN status = 'created' THEN 'confirmed' ELSE status END";
        }

        if (isset($columns['UPDATED_AT'])) {
            $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        }

        if (!$sets) {
            return;
        }

        Database::execute('UPDATE rc_orders SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
    }

    private function tableExists(string $table): bool
    {
        $row = Database::fetchOne('SELECT COUNT(*) AS cnt FROM user_tables WHERE table_name = :table_name', ['table_name' => strtoupper($table)]);
        return (int)($row['CNT'] ?? 0) > 0;
    }

    private function columns(string $table): array
    {
        $rows = Database::fetchAll(
            'SELECT column_name FROM user_tab_columns WHERE table_name = :table_name',
            ['table_name' => strtoupper($table)]
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[(string)$row['COLUMN_NAME']] = true;
        }
        return $columns;
    }


    private function providerForMethod(string $methodCode, string $provider): string
    {
        $provider = strtolower(trim($provider));
        $methodCode = strtolower(trim($methodCode));

        if ($methodCode === 'liqpay') {
            return 'liqpay';
        }

        if ($methodCode === 'wayforpay') {
            return 'wayforpay';
        }

        if ($methodCode === 'online_card' || $provider === 'online_card') {
            $cfg = $this->loadIntegrationConfig();
            $default = strtolower((string)($cfg['payments']['default_provider'] ?? 'liqpay'));
            return in_array($default, ['liqpay', 'wayforpay'], true) ? $default : 'liqpay';
        }

        return $provider !== '' ? $provider : 'manual';
    }

    private function loadIntegrationConfig(): array
    {
        $example = __DIR__ . '/../config/integrations.example.php';
        $local = __DIR__ . '/../config/integrations.php';
        $config = file_exists($example) ? require $example : [];
        if (file_exists($local)) {
            $config = array_replace_recursive($config, require $local);
        }
        return is_array($config) ? $config : [];
    }

    private function makePaymentReference(int $orderId, string $provider): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $provider)) ?: 'PAY';
        return $prefix . date('ymdHis') . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT);
    }

    private function fallbackMethods(): array
    {
        return [
            ['CODE' => 'cash', 'NAME' => 'Оплата при отриманні', 'PROVIDER' => 'manual', 'DESCRIPTION' => 'Оплата після підтвердження та отримання товару.', 'IS_ONLINE' => 0, 'SORT_ORDER' => 10],
            ['CODE' => 'liqpay', 'NAME' => 'LiqPay', 'PROVIDER' => 'liqpay', 'DESCRIPTION' => 'Онлайн-оплата карткою через LiqPay.', 'IS_ONLINE' => 1, 'SORT_ORDER' => 20],
            ['CODE' => 'wayforpay', 'NAME' => 'WayForPay', 'PROVIDER' => 'wayforpay', 'DESCRIPTION' => 'Онлайн-оплата карткою через WayForPay.', 'IS_ONLINE' => 1, 'SORT_ORDER' => 30],
            ['CODE' => 'invoice', 'NAME' => 'Безготівковий рахунок', 'PROVIDER' => 'invoice', 'DESCRIPTION' => 'Рахунок для ФОП/ТОВ після перевірки менеджером.', 'IS_ONLINE' => 0, 'SORT_ORDER' => 40],
            ['CODE' => 'manager_confirm', 'NAME' => 'Після підтвердження менеджером', 'PROVIDER' => 'manual', 'DESCRIPTION' => 'Менеджер погодить наявність, доставку та спосіб оплати.', 'IS_ONLINE' => 0, 'SORT_ORDER' => 50],
        ];
    }
}
