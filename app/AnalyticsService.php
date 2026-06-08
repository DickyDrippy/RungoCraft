<?php
declare(strict_types=1);

final class AnalyticsService
{
    public function track(
        string $eventType,
        ?string $entityType = null,
        ?int $entityId = null,
        array $payload = [],
        ?array $user = null
    ): void {
        $eventType = trim($eventType);
        if ($eventType === '') {
            return;
        }

        $userId = $user['id'] ?? ($_SESSION['user']['id'] ?? null);
        $sessionId = session_id() ?: null;
        $pageUrl = $_SERVER['REQUEST_URI'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $payloadJson = null;
        if ($payload !== []) {
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payloadJson === false) {
                $payloadJson = null;
            }
        }

        try {
            Database::execute(
                "INSERT INTO rc_analytics_events (
                    user_id,
                    session_id,
                    event_type,
                    entity_type,
                    entity_id,
                    page_url,
                    payload,
                    ip_address,
                    user_agent,
                    event_source
                ) VALUES (
                    :user_id,
                    :session_id,
                    :event_type,
                    :entity_type,
                    :entity_id,
                    :page_url,
                    :payload,
                    :ip_address,
                    :user_agent,
                    :event_source
                )",
                [
                    'user_id' => $userId !== null ? (int)$userId : null,
                    'session_id' => $sessionId,
                    'event_type' => mb_substr($eventType, 0, 80),
                    'entity_type' => $entityType !== null ? mb_substr($entityType, 0, 80) : null,
                    'entity_id' => $entityId,
                    'page_url' => $pageUrl !== null ? mb_substr($pageUrl, 0, 1000) : null,
                    'payload' => $payloadJson,
                    'ip_address' => $ip !== null ? mb_substr($ip, 0, 80) : null,
                    'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 1000) : null,
                    'event_source' => 'server',
                ]
            );
        } catch (Throwable) {
            
        }
    }

    public function trackPage(string $route, ?array $user = null): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return;
        }

        if ($route === 'db-check') {
            return;
        }

        $payload = [
            'route' => $route,
            'query' => $_GET,
        ];

        $this->track('page_view', 'page', null, $payload, $user);

        if ($route === 'product' && !empty($_GET['id'])) {
            $this->track('product_view', 'product', (int)$_GET['id'], $payload, $user);
        }

        if ($route === 'catalog') {
            if (!empty($_GET['q'])) {
                $this->track('search', 'catalog', null, ['q' => trim((string)$_GET['q'])], $user);
            }

            $filters = [];
            foreach (['category', 'letter', 'sort', 'min_price', 'max_price', 'brand'] as $key) {
                if (isset($_GET[$key]) && trim((string)$_GET[$key]) !== '') {
                    $filters[$key] = trim((string)$_GET[$key]);
                }
            }
            if ($filters !== []) {
                $this->track('filter_apply', 'catalog', null, $filters, $user);
            }
        }

        if ($route === 'checkout') {
            $this->track('checkout_view', 'checkout', null, [], $user);
        }
    }


    public function outputCsv(?array $user): void
    {
        if (!$user || (string)($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo 'Доступ заборонено.';
            return;
        }

        $rows = Database::fetchAll(
            "SELECT e.id, e.created_at, e.event_type, e.entity_type, e.entity_id, e.page_url, e.session_id, e.user_id, u.full_name
             FROM rc_analytics_events e
             LEFT JOIN rc_users u ON u.id = e.user_id
             ORDER BY e.created_at DESC, e.id DESC
             FETCH FIRST 5000 ROWS ONLY"
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rungocraft_analytics_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['ID','Дата','Подія','Тип обʼєкта','ID обʼєкта','Сторінка','Сесія','ID користувача','Користувач'], ';');
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['ID'] ?? '',
                $row['CREATED_AT'] ?? '',
                $row['EVENT_TYPE'] ?? '',
                $row['ENTITY_TYPE'] ?? '',
                $row['ENTITY_ID'] ?? '',
                $row['PAGE_URL'] ?? '',
                $row['SESSION_ID'] ?? '',
                $row['USER_ID'] ?? '',
                $row['FULL_NAME'] ?? 'гість',
            ], ';');
        }
        fclose($out);
    }

    public function handle(string $action, array $data, ?array $user): array
    {
        if ($action !== 'analytics_track') {
            return ['ok' => false, 'message' => 'Невідома дія аналітики.'];
        }

        $eventType = (string)($data['event_type'] ?? 'custom_event');
        $entityType = trim((string)($data['entity_type'] ?? '')) ?: null;
        $entityId = isset($data['entity_id']) && $data['entity_id'] !== '' ? (int)$data['entity_id'] : null;
        $payload = [];

        if (!empty($data['payload'])) {
            $decoded = json_decode((string)$data['payload'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $this->track($eventType, $entityType, $entityId, $payload, $user);

        return ['ok' => true, 'message' => 'Подію збережено.'];
    }

    public function adminData(): array
    {
        return [
            'summary' => $this->summary(),
            'events_by_type' => $this->eventsByType(),
            'popular_products' => $this->popularProducts(),
            'search_queries' => $this->searchQueries(),
            'recent_events' => $this->recentEvents(),
            'cart_funnel' => $this->cartFunnel(),
            'abandoned_carts' => $this->abandonedCarts(),
        ];
    }

    private function summary(): array
    {
        $events = Database::fetchOne("SELECT COUNT(*) AS cnt FROM rc_analytics_events");
        $sessions = Database::fetchOne("SELECT COUNT(DISTINCT session_id) AS cnt FROM rc_analytics_events WHERE session_id IS NOT NULL");
        $users = Database::fetchOne("SELECT COUNT(DISTINCT user_id) AS cnt FROM rc_analytics_events WHERE user_id IS NOT NULL");
        $orders = Database::fetchOne("SELECT COUNT(*) AS cnt FROM rc_orders");
        $cartAdds = Database::fetchOne("SELECT COUNT(*) AS cnt FROM rc_analytics_events WHERE event_type = 'cart_add'");
        $checkoutViews = Database::fetchOne("SELECT COUNT(*) AS cnt FROM rc_analytics_events WHERE event_type = 'checkout_view'");

        return [
            'events' => (int)($events['CNT'] ?? 0),
            'sessions' => (int)($sessions['CNT'] ?? 0),
            'users' => (int)($users['CNT'] ?? 0),
            'orders' => (int)($orders['CNT'] ?? 0),
            'cart_adds' => (int)($cartAdds['CNT'] ?? 0),
            'checkout_views' => (int)($checkoutViews['CNT'] ?? 0),
        ];
    }

    private function eventsByType(): array
    {
        return Database::fetchAll(
            "SELECT event_type, COUNT(*) AS cnt
             FROM rc_analytics_events
             GROUP BY event_type
             ORDER BY cnt DESC, event_type
             FETCH FIRST 20 ROWS ONLY"
        );
    }

    private function popularProducts(): array
    {
        return Database::fetchAll(
            "SELECT
                p.id,
                p.sku,
                p.name,
                COUNT(e.id) AS views_count,
                SUM(CASE WHEN e.event_type = 'cart_add' THEN 1 ELSE 0 END) AS cart_count,
                SUM(CASE WHEN e.event_type = 'wishlist_add' THEN 1 ELSE 0 END) AS wishlist_count
             FROM rc_products p
             LEFT JOIN rc_analytics_events e
                ON e.entity_type = 'product'
               AND e.entity_id = p.id
               AND e.event_type IN ('product_view', 'cart_add', 'wishlist_add')
             GROUP BY p.id, p.sku, p.name
             ORDER BY views_count DESC, cart_count DESC, p.id DESC
             FETCH FIRST 12 ROWS ONLY"
        );
    }

    private function searchQueries(): array
    {
        $rows = Database::fetchAll(
            "SELECT payload, COUNT(*) AS cnt
             FROM rc_analytics_events
             WHERE event_type = 'search'
             GROUP BY payload
             ORDER BY cnt DESC
             FETCH FIRST 20 ROWS ONLY"
        );

        $queries = [];
        foreach ($rows as $row) {
            $payload = $row['PAYLOAD'] ?? '';
            if (is_object($payload) && method_exists($payload, 'load')) {
                $payload = $payload->load();
            }
            $decoded = json_decode((string)$payload, true);
            $queries[] = [
                'query' => is_array($decoded) ? (string)($decoded['q'] ?? '') : '',
                'count' => (int)($row['CNT'] ?? 0),
            ];
        }

        return array_values(array_filter($queries, static fn(array $q): bool => $q['query'] !== ''));
    }

    private function cartFunnel(): array
    {
        $types = ['page_view', 'product_view', 'cart_add', 'checkout_view', 'order_create'];
        $result = [];
        foreach ($types as $type) {
            $row = Database::fetchOne(
                "SELECT COUNT(*) AS cnt FROM rc_analytics_events WHERE event_type = :type",
                ['type' => $type]
            );
            $result[$type] = (int)($row['CNT'] ?? 0);
        }

        return $result;
    }

    private function abandonedCarts(): array
    {
        return Database::fetchAll(
            "SELECT
                c.id,
                c.session_id,
                c.user_id,
                c.created_at,
                c.updated_at,
                COUNT(i.id) AS items_count,
                SUM(i.quantity * i.price_snapshot) AS total_amount
             FROM rc_carts c
             JOIN rc_cart_items i ON i.cart_id = c.id
             WHERE c.status = 'active'
             GROUP BY c.id, c.session_id, c.user_id, c.created_at, c.updated_at
             ORDER BY c.updated_at DESC
             FETCH FIRST 20 ROWS ONLY"
        );
    }

    private function recentEvents(): array
    {
        return Database::fetchAll(
            "SELECT
                e.id,
                e.event_type,
                e.entity_type,
                e.entity_id,
                e.page_url,
                e.created_at,
                u.full_name
             FROM rc_analytics_events e
             LEFT JOIN rc_users u ON u.id = e.user_id
             ORDER BY e.created_at DESC, e.id DESC
             FETCH FIRST 50 ROWS ONLY"
        );
    }
}
