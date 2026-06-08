<?php
declare(strict_types=1);

final class DeliveryService
{
    public function handle(string $action, array $data, ?array $user): array
    {
        return match ($action) {
            'delivery_admin_update' => $this->updateShipment($data, $user),
            'delivery_create_ttn' => $this->createTtn($data, $user),
            default => ['ok' => false, 'message' => 'Невідома дія доставки.'],
        };
    }

    public function methods(): array
    {
        $this->ensureTableAvailable('RC_DELIVERY_METHODS');

        return Database::fetchAll(
            "SELECT code, name, description, base_price, is_active, sort_order
             FROM rc_delivery_methods
             WHERE is_active = 1
             ORDER BY sort_order, id"
        );
    }

    public function novaPoshtaCities(): array
    {
        if (!$this->tableExists('RC_NP_CITIES')) {
            return [];
        }

        return Database::fetchAll(
            "SELECT ref, name, area_name
             FROM rc_np_cities
             WHERE is_active = 1
             ORDER BY sort_order, name
             FETCH FIRST 30 ROWS ONLY"
        );
    }

    public function novaPoshtaWarehouses(string $cityRef = ''): array
    {
        if (!$this->tableExists('RC_NP_WAREHOUSES')) {
            return [];
        }

        if ($cityRef !== '') {
            return Database::fetchAll(
                "SELECT ref, city_ref, name, address_text
                 FROM rc_np_warehouses
                 WHERE is_active = 1 AND city_ref = :city_ref
                 ORDER BY sort_order, name
                 FETCH FIRST 80 ROWS ONLY",
                ['city_ref' => $cityRef]
            );
        }

        return Database::fetchAll(
            "SELECT ref, city_ref, name, address_text
             FROM rc_np_warehouses
             WHERE is_active = 1
             ORDER BY sort_order, city_ref, name
             FETCH FIRST 80 ROWS ONLY"
        );
    }

    public function adminData(?array $user): array
    {
        if (!$this->canManageDelivery($user)) {
            return [
                'stats' => [],
                'shipments' => [],
                'orders_without_shipments' => [],
                'methods' => [],
            ];
        }

        return [
            'stats' => $this->stats(),
            'shipments' => $this->shipments(),
            'orders_without_shipments' => $this->ordersWithoutShipments(),
            'methods' => $this->methodsSafe(),
        ];
    }

    public function shipmentForOrder(int $orderId): ?array
    {
        if ($orderId <= 0 || !$this->tableExists('RC_DELIVERY_SHIPMENTS')) {
            return null;
        }

        $row = Database::fetchOne(
            "SELECT *
             FROM rc_delivery_shipments
             WHERE order_id = :order_id
             ORDER BY id DESC
             FETCH FIRST 1 ROWS ONLY",
            ['order_id' => $orderId]
        );

        return $row ?: null;
    }

    private function updateShipment(array $data, ?array $user): array
    {
        if (!$this->canManageDelivery($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав для керування доставкою.'];
        }

        $shipmentId = (int)($data['shipment_id'] ?? 0);
        $status = trim((string)($data['status'] ?? ''));
        $ttn = trim((string)($data['ttn'] ?? ''));
        $comment = trim((string)($data['manager_comment'] ?? ''));

        if ($shipmentId <= 0) {
            return ['ok' => false, 'message' => 'Не знайдено доставку для оновлення.'];
        }

        $allowedStatuses = ['pending', 'manager_confirm', 'ttn_created', 'in_transit', 'delivered', 'completed', 'cancelled', 'returned'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'pending';
        }

        if (in_array($status, ['ttn_created','in_transit','delivered','completed'], true)) {
            $shipmentOrder = Database::fetchOne(
                "SELECT o.status AS order_status
                 FROM rc_delivery_shipments ds
                 JOIN rc_orders o ON o.id = ds.order_id
                 WHERE ds.id = :id",
                ['id' => $shipmentId]
            );
            if ($status === 'ttn_created' && trim($ttn) === '') {
                return ['ok' => false, 'message' => 'Для статусу “ТТН створено” потрібно ввести номер ТТН.'];
            }
            if (!in_array((string)($shipmentOrder['ORDER_STATUS'] ?? ''), ['packed','ready_for_delivery','sent','delivering'], true)) {
                return ['ok' => false, 'message' => 'Спочатку склад має зарезервувати і скомплектувати замовлення.'];
            }
        }

        $ok = Database::execute(
            "UPDATE rc_delivery_shipments
             SET delivery_status = :status,
                 ttn = :ttn,
                 manager_comment = :manager_comment,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            [
                'status' => $status,
                'ttn' => $ttn !== '' ? $ttn : null,
                'manager_comment' => $comment !== '' ? $comment : null,
                'id' => $shipmentId,
            ]
        );

        if (!$ok) {
            return ['ok' => false, 'message' => 'Доставку не оновлено: ' . (Database::lastError() ?? 'невідома помилка')];
        }

        $shipment = Database::fetchOne("SELECT order_id FROM rc_delivery_shipments WHERE id = :id", ['id' => $shipmentId]);
        if ($shipment) {
            $orderStatus = match ($status) {
                'ttn_created' => 'sent',
                'in_transit' => 'delivering',
                'delivered' => 'completed',
                'completed' => 'completed',
                'cancelled' => 'cancelled',
                'returned' => 'returned',
                default => null,
            };
            $sql = "UPDATE rc_orders
                    SET delivery_ttn = :ttn,
                        delivery_status = :status,
                        updated_at = CURRENT_TIMESTAMP";
            $params = [
                'ttn' => $ttn !== '' ? $ttn : null,
                'status' => $status,
                'order_id' => (int)$shipment['ORDER_ID'],
            ];
            if ($orderStatus !== null) {
                $sql .= ", status = :order_status";
                $params['order_status'] = $orderStatus;
            }
            $sql .= " WHERE id = :order_id";
            Database::execute($sql, $params);
            if ($orderStatus !== null) {
                (new NotificationService())->notifyOrderStatus((int)$shipment['ORDER_ID'], $orderStatus);
            }
        }

        $this->log((int)($user['id'] ?? 0), 'delivery_update', 'delivery_shipment', $shipmentId, 'Оновлено статус/ТТН доставки.');
        return ['ok' => true, 'message' => 'Доставку оновлено.'];
    }

    private function createTtn(array $data, ?array $user): array
    {
        if (!$this->canManageDelivery($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав для створення ТТН.'];
        }

        $shipmentId = (int)($data['shipment_id'] ?? 0);
        if ($shipmentId <= 0) {
            return ['ok' => false, 'message' => 'Не знайдено доставку.'];
        }

        $shipment = Database::fetchOne(
            "SELECT ds.id, ds.order_id, ds.ttn, o.status AS order_status
             FROM rc_delivery_shipments ds
             JOIN rc_orders o ON o.id = ds.order_id
             WHERE ds.id = :id",
            ['id' => $shipmentId]
        );
        if (!$shipment) {
            return ['ok' => false, 'message' => 'Доставку не знайдено.'];
        }

        if (!in_array((string)($shipment['ORDER_STATUS'] ?? ''), ['packed','ready_for_delivery','sent','delivering'], true)) {
            return ['ok' => false, 'message' => 'ТТН можна створити тільки після резерву та комплектації на складі.'];
        }

        $ttn = trim((string)($shipment['TTN'] ?? ''));
        if ($ttn === '') {
            $ttn = 'NP' . date('ymd') . str_pad((string)$shipmentId, 6, '0', STR_PAD_LEFT);
        }

        Database::execute(
            "UPDATE rc_delivery_shipments
             SET ttn = :ttn,
                 delivery_status = 'ttn_created',
                 manager_comment = 'ТТН створено менеджером.',
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            ['ttn' => $ttn, 'id' => $shipmentId]
        );

        Database::execute(
            "UPDATE rc_orders
             SET delivery_ttn = :ttn,
                 delivery_status = 'ttn_created',
                 status = 'sent',
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :order_id",
            ['ttn' => $ttn, 'order_id' => (int)$shipment['ORDER_ID']]
        );
        (new NotificationService())->notifyOrderStatus((int)$shipment['ORDER_ID'], 'sent');

        $this->log((int)($user['id'] ?? 0), 'delivery_ttn_created', 'delivery_shipment', $shipmentId, 'Створено ТТН.');
        return ['ok' => true, 'message' => 'ТТН створено: ' . $ttn];
    }

    private function stats(): array
    {
        if (!$this->tableExists('RC_DELIVERY_SHIPMENTS')) {
            return ['pending' => 0, 'ttn_created' => 0, 'in_transit' => 0, 'delivered' => 0];
        }

        $rows = Database::fetchAll(
            "SELECT delivery_status, COUNT(*) AS cnt
             FROM rc_delivery_shipments
             GROUP BY delivery_status"
        );

        $stats = ['pending' => 0, 'ttn_created' => 0, 'in_transit' => 0, 'delivered' => 0];
        foreach ($rows as $row) {
            $stats[(string)$row['DELIVERY_STATUS']] = (int)$row['CNT'];
        }

        return $stats;
    }

    private function shipments(): array
    {
        if (!$this->tableExists('RC_DELIVERY_SHIPMENTS')) {
            return [];
        }

        return Database::fetchAll(
            "SELECT
                ds.*,
                o.customer_name,
                o.customer_phone,
                o.total_amount,
                o.status AS order_status
             FROM rc_delivery_shipments ds
             JOIN rc_orders o ON o.id = ds.order_id
             ORDER BY ds.created_at DESC, ds.id DESC
             FETCH FIRST 80 ROWS ONLY"
        );
    }

    private function ordersWithoutShipments(): array
    {
        if (!$this->tableExists('RC_DELIVERY_SHIPMENTS')) {
            return [];
        }

        return Database::fetchAll(
            "SELECT o.id, o.customer_name, o.customer_phone, o.delivery_type, o.total_amount, o.created_at
             FROM rc_orders o
             WHERE NOT EXISTS (
                 SELECT 1 FROM rc_delivery_shipments ds WHERE ds.order_id = o.id
             )
             ORDER BY o.created_at DESC
             FETCH FIRST 50 ROWS ONLY"
        );
    }

    private function methodsSafe(): array
    {
        try {
            return $this->methods();
        } catch (Throwable) {
            return [];
        }
    }

    private function canManageDelivery(?array $user): bool
    {
        $role = (string)($user['role'] ?? 'guest');
        return in_array($role, ['admin', 'manager', 'warehouse'], true);
    }

    private function tableExists(string $table): bool
    {
        $row = Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM user_tables WHERE table_name = :table_name",
            ['table_name' => strtoupper($table)]
        );

        return $row && (int)$row['CNT'] > 0;
    }

    private function ensureTableAvailable(string $table): void
    {
        if (!$this->tableExists($table)) {
            throw new RuntimeException('Таблиця ' . $table . ' ще не створена. Запустіть міграцію доставки.');
        }
    }

    private function log(int $userId, string $action, string $entityType, int $entityId, string $details): void
    {
        if (!$this->tableExists('RC_ADMIN_LOGS')) {
            return;
        }

        Database::execute(
            "INSERT INTO rc_admin_logs (user_id, action, entity_type, entity_id, details, ip_address)
             VALUES (:user_id, :action, :entity_type, :entity_id, :details, :ip_address)",
            [
                'user_id' => $userId > 0 ? $userId : null,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => $details,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
    }
}
