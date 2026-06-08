<?php
declare(strict_types=1);

final class BusinessClientService
{
    public function handle(string $action, array $data, ?array $user): array
    {
        if (!$user && in_array($action, ['business_apply', 'business_supplier_apply'], true)) {
            return ['ok' => false, 'message' => 'Для подання заявки потрібно увійти або зареєструватися в кабінеті.'];
        }

        return match ($action) {
            'business_apply' => $this->apply($data, $user),
            'business_admin_update' => $this->adminUpdate($data, $user),
            'business_invoice_create' => $this->createInvoice($data, $user),
            'business_invoice_update' => $this->updateInvoice($data, $user),
            'business_supplier_apply' => $this->supplierApply($data, $user),
            'business_supplier_update' => $this->supplierUpdate($data, $user),
            default => ['ok' => false, 'message' => 'Невідома дія модуля оптових клієнтів.'],
        };
    }

    public function adminData(?array $user = null): array
    {
        $clients = Database::fetchAll(
            "SELECT
                bc.id,
                bc.user_id,
                bc.company_name,
                bc.edrpou,
                bc.tax_number,
                bc.contact_name,
                bc.phone,
                bc.email,
                bc.client_type,
                bc.price_group,
                bc.discount_percent,
                bc.credit_limit,
                bc.status,
                bc.manager_comment,
                bc.created_at,
                u.full_name AS user_name
             FROM rc_business_clients bc
             LEFT JOIN rc_users u ON u.id = bc.user_id
             ORDER BY bc.id DESC
             FETCH FIRST 100 ROWS ONLY"
        );

        $invoices = Database::fetchAll(
            "SELECT
                i.id,
                i.invoice_number,
                i.order_id,
                i.business_client_id,
                i.amount,
                i.status,
                i.due_date,
                i.created_at,
                bc.company_name,
                o.customer_name,
                o.customer_phone
             FROM rc_invoices i
             LEFT JOIN rc_business_clients bc ON bc.id = i.business_client_id
             LEFT JOIN rc_orders o ON o.id = i.order_id
             ORDER BY i.id DESC
             FETCH FIRST 100 ROWS ONLY"
        );

        $supplierRequests = Database::fetchAll(
            "SELECT id, company_name, contact_name, phone, email, product_direction, message, status, created_at
             FROM rc_supplier_requests
             ORDER BY id DESC
             FETCH FIRST 80 ROWS ONLY"
        );

        $ordersWithoutInvoice = Database::fetchAll(
            "SELECT o.id, o.customer_name, o.customer_phone, o.total_amount, o.payment_type, o.status, o.created_at
             FROM rc_orders o
             WHERE NOT EXISTS (SELECT 1 FROM rc_invoices i WHERE i.order_id = o.id)
             ORDER BY o.id DESC
             FETCH FIRST 60 ROWS ONLY"
        );

        $stats = [
            'clients' => (int)($this->scalar("SELECT COUNT(*) cnt FROM rc_business_clients") ?? 0),
            'pending_clients' => (int)($this->scalar("SELECT COUNT(*) cnt FROM rc_business_clients WHERE status = 'pending'") ?? 0),
            'active_clients' => (int)($this->scalar("SELECT COUNT(*) cnt FROM rc_business_clients WHERE status = 'active'") ?? 0),
            'invoices' => (int)($this->scalar("SELECT COUNT(*) cnt FROM rc_invoices") ?? 0),
            'unpaid_invoices' => (int)($this->scalar("SELECT COUNT(*) cnt FROM rc_invoices WHERE status IN ('draft','sent','overdue')") ?? 0),
            'supplier_requests' => (int)($this->scalar("SELECT COUNT(*) cnt FROM rc_supplier_requests") ?? 0),
        ];

        return [
            'stats' => $stats,
            'clients' => $clients,
            'invoices' => $invoices,
            'supplier_requests' => $supplierRequests,
            'orders_without_invoice' => $ordersWithoutInvoice,
        ];
    }

    public function currentClient(?array $user): ?array
    {
        if (!$user) {
            return null;
        }

        return Database::fetchOne(
            "SELECT * FROM rc_business_clients WHERE user_id = :user_id ORDER BY id DESC FETCH FIRST 1 ROWS ONLY",
            ['user_id' => (int)$user['id']]
        );
    }

    public function publicStats(): array
    {
        return [
            'clients' => (int)($this->scalar("SELECT COUNT(*) cnt FROM rc_business_clients WHERE status = 'active'") ?? 0),
            'invoices' => (int)($this->scalar("SELECT COUNT(*) cnt FROM rc_invoices") ?? 0),
            'price_groups' => (int)($this->scalar("SELECT COUNT(DISTINCT price_group) cnt FROM rc_business_clients WHERE status = 'active'") ?? 0),
        ];
    }

    private function apply(array $data, ?array $user): array
    {
        $companyName = trim((string)($data['company_name'] ?? ''));
        $contactName = trim((string)($data['contact_name'] ?? ''));
        $phone = $this->normalizePhone((string)($data['phone'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $edrpou = preg_replace('/\D+/', '', (string)($data['edrpou'] ?? ''));

        if ($companyName === '' || $contactName === '' || $phone === '') {
            return ['ok' => false, 'message' => 'Вкажіть назву компанії, контактну особу та телефон.'];
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Некоректний email.'];
        }

        $ok = Database::execute(
            "MERGE INTO rc_business_clients bc
             USING (
                SELECT
                    :user_id AS user_id,
                    :company_name AS company_name,
                    :edrpou AS edrpou,
                    :tax_number AS tax_number,
                    :legal_address AS legal_address,
                    :delivery_address AS delivery_address,
                    :contact_name AS contact_name,
                    :phone AS phone,
                    :email AS email,
                    :client_type AS client_type,
                    :comment_text AS manager_comment
                FROM dual
             ) v
             ON (bc.phone = v.phone AND LOWER(NVL(bc.email, '')) = LOWER(NVL(v.email, '')))
             WHEN MATCHED THEN UPDATE SET
                bc.user_id = NVL(v.user_id, bc.user_id),
                bc.company_name = v.company_name,
                bc.edrpou = v.edrpou,
                bc.tax_number = v.tax_number,
                bc.legal_address = v.legal_address,
                bc.delivery_address = v.delivery_address,
                bc.contact_name = v.contact_name,
                bc.client_type = v.client_type,
                bc.status = 'pending',
                bc.manager_comment = v.manager_comment,
                bc.updated_at = CURRENT_TIMESTAMP
             WHEN NOT MATCHED THEN INSERT (
                user_id, company_name, edrpou, tax_number, legal_address, delivery_address,
                contact_name, phone, email, client_type, price_group, discount_percent, credit_limit, status, manager_comment
             ) VALUES (
                v.user_id, v.company_name, v.edrpou, v.tax_number, v.legal_address, v.delivery_address,
                v.contact_name, v.phone, v.email, v.client_type, 'base', 0, 0, 'pending', v.manager_comment
             )",
            [
                'user_id' => $user ? (int)$user['id'] : null,
                'company_name' => $companyName,
                'edrpou' => $edrpou,
                'tax_number' => trim((string)($data['tax_number'] ?? '')),
                'legal_address' => trim((string)($data['legal_address'] ?? '')),
                'delivery_address' => trim((string)($data['delivery_address'] ?? '')),
                'contact_name' => $contactName,
                'phone' => $phone,
                'email' => $email,
                'client_type' => (string)($data['client_type'] ?? 'company'),
                'comment_text' => trim((string)($data['comment_text'] ?? '')),
            ]
        );

        $this->queueManagerNotification('Нова заявка оптового клієнта', $companyName . ' · ' . $phone);
        $this->log($user, 'business_apply', 'business_client', null, $companyName);

        return ['ok' => $ok, 'message' => $ok ? 'Заявку оптового клієнта прийнято. Менеджер звʼяжеться з вами.' : 'Не вдалося зберегти заявку.'];
    }

    private function adminUpdate(array $data, ?array $user): array
    {
        if (!$this->canManage($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав для роботи з оптовими клієнтами.'];
        }

        $clientId = (int)($data['client_id'] ?? 0);
        if ($clientId <= 0) {
            return ['ok' => false, 'message' => 'Клієнта не знайдено.'];
        }

        $status = (string)($data['status'] ?? 'pending');
        $allowedStatuses = ['pending', 'active', 'paused', 'rejected'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'pending';
        }

        $priceGroup = (string)($data['price_group'] ?? 'base');
        $discount = max(0, min(50, (float)($data['discount_percent'] ?? 0)));
        $creditLimit = max(0, (float)($data['credit_limit'] ?? 0));
        $comment = trim((string)($data['manager_comment'] ?? ''));

        $ok = Database::execute(
            "UPDATE rc_business_clients
             SET status = :status,
                 price_group = :price_group,
                 discount_percent = :discount_percent,
                 credit_limit = :credit_limit,
                 manager_comment = :manager_comment,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            [
                'status' => $status,
                'price_group' => $priceGroup,
                'discount_percent' => $discount,
                'credit_limit' => $creditLimit,
                'manager_comment' => $comment,
                'id' => $clientId,
            ]
        );

        $this->log($user, 'business_admin_update', 'business_client', $clientId, $status);
        return ['ok' => $ok, 'message' => $ok ? 'Дані оптового клієнта оновлено.' : 'Не вдалося оновити клієнта.'];
    }

    private function createInvoice(array $data, ?array $user): array
    {
        if (!$this->canManage($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав для створення рахунку.'];
        }

        $orderId = (int)($data['order_id'] ?? 0);
        $clientId = (int)($data['business_client_id'] ?? 0);
        $amount = (float)($data['amount'] ?? 0);
        $comment = trim((string)($data['comment_text'] ?? ''));

        $order = null;
        if ($orderId > 0) {
            $order = Database::fetchOne("SELECT id, total_amount FROM rc_orders WHERE id = :id", ['id' => $orderId]);
            if (!$order) {
                return ['ok' => false, 'message' => 'Замовлення не знайдено.'];
            }
            $amount = (float)$order['TOTAL_AMOUNT'];
        }

        if ($amount <= 0) {
            return ['ok' => false, 'message' => 'Сума рахунку має бути більшою за 0.'];
        }

        $next = (int)($this->scalar("SELECT NVL(MAX(id), 0) + 1 cnt FROM rc_invoices") ?? 1);
        $invoiceNumber = 'INV' . date('Ymd') . '-' . str_pad((string)$next, 5, '0', STR_PAD_LEFT);

        $ok = Database::execute(
            "INSERT INTO rc_invoices (
                order_id, business_client_id, invoice_number, amount, status, due_date, comment_text
             ) VALUES (
                :order_id, :business_client_id, :invoice_number, :amount, 'sent', CURRENT_TIMESTAMP + INTERVAL '7' DAY, :comment_text
             )",
            [
                'order_id' => $orderId > 0 ? $orderId : null,
                'business_client_id' => $clientId > 0 ? $clientId : null,
                'invoice_number' => $invoiceNumber,
                'amount' => $amount,
                'comment_text' => $comment,
            ]
        );

        if ($ok && $orderId > 0) {
            Database::execute(
                "UPDATE rc_orders
                 SET payment_type = 'invoice', payment_status = 'invoice_sent', payment_reference = :ref, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id",
                ['ref' => $invoiceNumber, 'id' => $orderId]
            );
        }

        $this->queueManagerNotification('Створено рахунок', $invoiceNumber . ' на суму ' . number_format($amount, 2, '.', ' ') . ' грн');
        $this->log($user, 'business_invoice_create', 'invoice', null, $invoiceNumber);

        return ['ok' => $ok, 'message' => $ok ? 'Рахунок створено: ' . $invoiceNumber : 'Не вдалося створити рахунок.'];
    }

    private function updateInvoice(array $data, ?array $user): array
    {
        if (!$this->canManage($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав для оновлення рахунку.'];
        }

        $invoiceId = (int)($data['invoice_id'] ?? 0);
        $status = (string)($data['status'] ?? 'sent');
        $allowed = ['draft', 'sent', 'paid', 'overdue', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            $status = 'sent';
        }

        $ok = Database::execute(
            "UPDATE rc_invoices SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
            ['status' => $status, 'id' => $invoiceId]
        );

        $invoice = Database::fetchOne("SELECT order_id, invoice_number FROM rc_invoices WHERE id = :id", ['id' => $invoiceId]);
        if ($ok && $invoice && !empty($invoice['ORDER_ID'])) {
            Database::execute(
                "UPDATE rc_orders SET payment_status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
                ['status' => $status === 'paid' ? 'paid' : 'invoice_' . $status, 'id' => (int)$invoice['ORDER_ID']]
            );
        }

        $this->log($user, 'business_invoice_update', 'invoice', $invoiceId, $status);
        return ['ok' => $ok, 'message' => $ok ? 'Статус рахунку оновлено.' : 'Не вдалося оновити рахунок.'];
    }

    private function supplierApply(array $data, ?array $user): array
    {
        $companyName = trim((string)($data['company_name'] ?? ''));
        $contactName = trim((string)($data['contact_name'] ?? ''));
        $phone = $this->normalizePhone((string)($data['phone'] ?? ''));

        if ($companyName === '' || $contactName === '' || $phone === '') {
            return ['ok' => false, 'message' => 'Вкажіть компанію, контактну особу та телефон.'];
        }

        $ok = Database::execute(
            "INSERT INTO rc_supplier_requests (company_name, contact_name, phone, email, product_direction, message, status)
             VALUES (:company_name, :contact_name, :phone, :email, :product_direction, :message, 'new')",
            [
                'company_name' => $companyName,
                'contact_name' => $contactName,
                'phone' => $phone,
                'email' => trim((string)($data['email'] ?? '')),
                'product_direction' => trim((string)($data['product_direction'] ?? '')),
                'message' => trim((string)($data['message'] ?? '')),
            ]
        );

        $this->queueManagerNotification('Нова заявка постачальника', $companyName . ' · ' . $phone);
        return ['ok' => $ok, 'message' => $ok ? 'Заявку постачальника прийнято.' : 'Не вдалося зберегти заявку.'];
    }

    private function supplierUpdate(array $data, ?array $user): array
    {
        if (!$this->canManage($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав.'];
        }

        $requestId = (int)($data['request_id'] ?? 0);
        $status = (string)($data['status'] ?? 'new');
        if (!in_array($status, ['new', 'processing', 'accepted', 'rejected'], true)) {
            $status = 'new';
        }

        $ok = Database::execute(
            "UPDATE rc_supplier_requests SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
            ['status' => $status, 'id' => $requestId]
        );

        $this->log($user, 'business_supplier_update', 'supplier_request', $requestId, $status);
        return ['ok' => $ok, 'message' => $ok ? 'Статус заявки постачальника оновлено.' : 'Не вдалося оновити заявку.'];
    }

    private function canManage(?array $user): bool
    {
        return $user && in_array((string)($user['role'] ?? ''), ['admin', 'manager'], true);
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $row = Database::fetchOne($sql, $params);
        if (!$row) {
            return null;
        }
        $first = array_values($row)[0] ?? null;
        return $first;
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '0')) {
            return '+38' . $digits;
        }
        if (str_starts_with($digits, '380')) {
            return '+' . $digits;
        }
        return $phone !== '' ? trim($phone) : '';
    }

    private function queueManagerNotification(string $subject, string $message): void
    {
        Database::execute(
            "INSERT INTO rc_notifications (channel, recipient, subject, message, status, event_type)
             SELECT 'manager', 'manager_panel', :subject, :message, 'queued', 'business_event'
             FROM dual
             WHERE EXISTS (SELECT 1 FROM user_tables WHERE table_name = 'RC_NOTIFICATIONS')",
            ['subject' => $subject, 'message' => $message]
        );
    }

    private function log(?array $user, string $action, ?string $entityType, ?int $entityId, string $details = ''): void
    {
        Database::execute(
            "INSERT INTO rc_admin_logs (user_id, action, entity_type, entity_id, details, ip_address)
             VALUES (:user_id, :action, :entity_type, :entity_id, :details, :ip_address)",
            [
                'user_id' => $user['id'] ?? null,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => $details,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]
        );
    }
}
