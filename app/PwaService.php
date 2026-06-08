<?php
declare(strict_types=1);

final class PwaService
{
    public function settings(): array
    {
        $rows = Database::fetchAll(
            "SELECT setting_key, setting_value
             FROM rc_site_settings
             WHERE setting_key LIKE 'pwa_%'
                OR setting_key IN ('name', 'tagline', 'theme_color', 'background_color')"
        );

        $settings = [];
        foreach ($rows as $row) {
            $settings[(string)$row['SETTING_KEY']] = (string)$row['SETTING_VALUE'];
        }

        return [
            'name' => $settings['pwa_name'] ?? $settings['name'] ?? 'RungoCraft',
            'short_name' => $settings['pwa_short_name'] ?? 'RungoCraft',
            'description' => $settings['pwa_description'] ?? 'Онлайн-магазин будівельних матеріалів та інструментів.',
            'theme_color' => $settings['theme_color'] ?? '#004574',
            'background_color' => $settings['background_color'] ?? '#f3f6f8',
            'enabled' => ($settings['pwa_enabled'] ?? '1') === '1',
            'push_enabled' => ($settings['pwa_push_enabled'] ?? '0') === '1',
            'offline_enabled' => ($settings['pwa_offline_enabled'] ?? '1') === '1',
        ];
    }

    public function handle(string $action, array $data, ?array $user): array
    {
        return match ($action) {
            'pwa_install_event' => $this->trackInstallEvent($data, $user),
            'pwa_push_subscribe' => $this->savePushSubscription($data, $user),
            'pwa_push_unsubscribe' => $this->removePushSubscription($data, $user),
            default => ['ok' => false, 'message' => 'Невідома PWA-дія.'],
        };
    }

    public function trackInstallEvent(array $data, ?array $user): array
    {
        $eventType = $this->clean((string)($data['event_type'] ?? 'install_prompt'), 80);
        $platform = $this->clean((string)($data['platform'] ?? 'browser'), 80);

        Database::execute(
            "INSERT INTO rc_pwa_install_events (
                user_id, session_id, event_type, platform, user_agent, ip_address
             ) VALUES (
                :user_id, :session_id, :event_type, :platform, :user_agent, :ip_address
             )",
            [
                'user_id' => $user['id'] ?? null,
                'session_id' => session_id(),
                'event_type' => $eventType,
                'platform' => $platform,
                'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                'ip_address' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 80),
            ]
        );

        return ['ok' => true, 'message' => 'PWA-подію збережено.'];
    }

    public function savePushSubscription(array $data, ?array $user): array
    {
        $endpoint = trim((string)($data['endpoint'] ?? ''));
        if ($endpoint === '') {
            return ['ok' => false, 'message' => 'Endpoint підписки не передано.'];
        }

        $payload = (string)($data['payload'] ?? '');
        $browser = $this->clean((string)($data['browser'] ?? 'browser'), 80);
        $platform = $this->clean((string)($data['platform'] ?? 'web'), 80);

        Database::execute(
            "MERGE INTO rc_push_subscriptions s
             USING (SELECT :endpoint AS endpoint FROM dual) v
             ON (s.endpoint = v.endpoint)
             WHEN MATCHED THEN UPDATE SET
                s.user_id = :user_id,
                s.session_id = :session_id,
                s.payload = :payload,
                s.browser = :browser,
                s.platform = :platform,
                s.is_active = 1,
                s.updated_at = CURRENT_TIMESTAMP
             WHEN NOT MATCHED THEN INSERT (
                user_id, session_id, endpoint, payload, browser, platform, is_active
             ) VALUES (
                :user_id, :session_id, :endpoint, :payload, :browser, :platform, 1
             )",
            [
                'user_id' => $user['id'] ?? null,
                'session_id' => session_id(),
                'endpoint' => $endpoint,
                'payload' => $payload,
                'browser' => $browser,
                'platform' => $platform,
            ]
        );

        return ['ok' => true, 'message' => 'Push-підписку збережено.'];
    }

    public function removePushSubscription(array $data, ?array $user): array
    {
        $endpoint = trim((string)($data['endpoint'] ?? ''));
        if ($endpoint === '') {
            return ['ok' => false, 'message' => 'Endpoint підписки не передано.'];
        }

        Database::execute(
            "UPDATE rc_push_subscriptions
             SET is_active = 0, updated_at = CURRENT_TIMESTAMP
             WHERE endpoint = :endpoint",
            ['endpoint' => $endpoint]
        );

        return ['ok' => true, 'message' => 'Push-підписку вимкнено.'];
    }

    public function adminData(): array
    {
        $totals = Database::fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM rc_pwa_install_events) AS install_events,
                (SELECT COUNT(*) FROM rc_push_subscriptions WHERE is_active = 1) AS active_push,
                (SELECT COUNT(*) FROM rc_push_subscriptions) AS all_push
             FROM dual"
        );

        $events = Database::fetchAll(
            "SELECT id, user_id, session_id, event_type, platform, created_at
             FROM rc_pwa_install_events
             ORDER BY id DESC
             FETCH FIRST 20 ROWS ONLY"
        );

        $subscriptions = Database::fetchAll(
            "SELECT id, user_id, session_id, browser, platform, is_active, created_at, updated_at
             FROM rc_push_subscriptions
             ORDER BY id DESC
             FETCH FIRST 20 ROWS ONLY"
        );

        return [
            'install_events_count' => (int)($totals['INSTALL_EVENTS'] ?? 0),
            'active_push_count' => (int)($totals['ACTIVE_PUSH'] ?? 0),
            'all_push_count' => (int)($totals['ALL_PUSH'] ?? 0),
            'events' => $events,
            'subscriptions' => $subscriptions,
        ];
    }

   private function clean(string $value, int $limit = 255): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    return substr($value, 0, $limit);
}
}
