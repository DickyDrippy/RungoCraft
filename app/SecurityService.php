<?php
declare(strict_types=1);

final class SecurityService
{
    private const WINDOW_SECONDS = 300;
    private const SOFT_LIMIT = 60;
    private const HARD_LIMIT = 120;

    public function trackRequest(?array $user = null): array
    {
        $now = time();
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

        if (!isset($_SESSION['security_hits']) || !is_array($_SESSION['security_hits'])) {
            $_SESSION['security_hits'] = [];
        }

        $_SESSION['security_hits'][] = $now;
        $_SESSION['security_hits'] = array_values(array_filter(
            $_SESSION['security_hits'],
            static fn (int $time): bool => $time >= $now - self::WINDOW_SECONDS
        ));

        $count = count($_SESSION['security_hits']);
        $level = $count >= self::HARD_LIMIT ? 'hard' : ($count >= self::SOFT_LIMIT ? 'soft' : 'normal');

        if ($level !== 'normal') {
            $lastLogged = (int)($_SESSION['security_last_log_at'] ?? 0);
            if ($now - $lastLogged > 60) {
                $_SESSION['security_last_log_at'] = $now;
                $this->log('many_page_refreshes', $level, [
                    'hits_5m' => $count,
                    'uri' => $uri,
                    'ip' => $ip,
                    'user_id' => $user['id'] ?? null,
                ]);
            }
        }

        $_SESSION['security_level'] = $level;
        return ['level' => $level, 'hits' => $count, 'captcha_recommended' => $level !== 'normal'];
    }

    public function currentLevel(): string
    {
        return (string)($_SESSION['security_level'] ?? 'normal');
    }

    public function shouldProtectAction(string $action): bool
    {
        if (in_array($action, [
            'login',
            'register',
            'auth_send_code',
            'auth_password_reset_request',
            'auth_reset_password_confirm',
            'order_create',
        ], true)) {
            return true;
        }

        return $this->currentLevel() !== 'normal' && in_array($action, [
            'support_create',
            'business_client_request',
            'business_supplier_request',
            'quick_order',
        ], true);
    }

    private function log(string $eventType, string $level, array $payload = []): void
    {
        if (!class_exists('Database')) {
            return;
        }

        try {
            Database::execute(
                "INSERT INTO rc_security_events (
                    event_type,
                    level_code,
                    ip_address,
                    user_agent,
                    page_url,
                    payload
                 ) VALUES (
                    :event_type,
                    :level_code,
                    :ip_address,
                    :user_agent,
                    :page_url,
                    :payload
                 )",
                [
                    'event_type' => substr($eventType, 0, 80),
                    'level_code' => substr($level, 0, 30),
                    'ip_address' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
                    'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                    'page_url' => substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 500),
                    'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
        } catch (Throwable) {
            return;
        }
    }
}
