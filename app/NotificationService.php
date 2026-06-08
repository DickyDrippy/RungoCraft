<?php
declare(strict_types=1);

final class NotificationService
{
    public function handle(string $action, array $data, ?array $user): array
    {
        if (!$this->canManage($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав для керування сповіщеннями.'];
        }

        if ($action === 'notification_create_manual') {
            return $this->createManual($data, $user);
        }

        if ($action === 'notification_mark_sent') {
            return $this->markSent((int)($data['notification_id'] ?? 0));
        }

        if ($action === 'notification_mark_failed') {
            return $this->markFailed((int)($data['notification_id'] ?? 0), (string)($data['error_message'] ?? 'Помилка відправки'));
        }

        if ($action === 'notification_toggle_subscriber') {
            return $this->toggleSubscriber((int)($data['subscriber_id'] ?? 0));
        }

        if ($action === 'notification_add_telegram_subscriber' || $action === 'notification_send_telegram_campaign' || $action === 'notification_toggle_subscriber') {
            return ['ok' => false, 'message' => 'Telegram-розсилки тимчасово вимкнені. Залишено email/SMS та внутрішню чергу.'];
        }

        if ($action === 'notification_send_email_campaign') {
            return $this->sendEmailCampaign($data, $user);
        }

        return ['ok' => false, 'message' => 'Невідома дія сповіщень.'];
    }

    public function notifyNewOrder(int $orderId): void
    {
        $order = Database::fetchOne(
            "SELECT id, customer_name, customer_phone, customer_email, total_amount, status
             FROM rc_orders
             WHERE id = :id",
            ['id' => $orderId]
        );

        if (!$order) {
            return;
        }

        $subject = 'Нове замовлення #' . (int)$order['ID'];
        $message = 'Нове замовлення #' . (int)$order['ID'] . ' на суму ' . number_format((float)$order['TOTAL_AMOUNT'], 2, '.', ' ') . ' грн. '
            . 'Клієнт: ' . (string)$order['CUSTOMER_NAME'] . ', телефон: ' . (string)$order['CUSTOMER_PHONE'] . '.';

        $this->queue([
            'order_id' => (int)$order['ID'],
            'channel' => 'admin',
            'recipient_type' => 'manager_panel',
            'recipient' => 'admin',
            'subject' => $subject,
            'message' => $message,
            'payload' => [
                'event' => 'new_order',
                'order_id' => (int)$order['ID'],
                'total_amount' => (float)$order['TOTAL_AMOUNT'],
            ],
        ]);

        $customerEmail = trim((string)($order['CUSTOMER_EMAIL'] ?? ''));
        if ($customerEmail !== '') {
            $this->queue([
                'order_id' => (int)$order['ID'],
                'channel' => 'email',
                'recipient_type' => 'customer_email',
                'recipient' => $customerEmail,
                'subject' => 'Ваше замовлення #' . (int)$order['ID'] . ' прийнято',
                'message' => 'Дякуємо за замовлення. Менеджер RungoCraft перевірить наявність і звʼяжеться з вами.',
                'payload' => ['event' => 'customer_order_created', 'order_id' => (int)$order['ID']],
            ]);
        }
    }

    public function notifyOrderStatus(int $orderId, string $status): void
    {
        $order = Database::fetchOne(
            "SELECT id, user_id, customer_name, customer_phone, customer_email, status
             FROM rc_orders
             WHERE id = :id",
            ['id' => $orderId]
        );

        if (!$order) {
            return;
        }

        $message = 'Статус замовлення #' . (int)$order['ID'] . ' змінено на: ' . $status . '.';
        $email = trim((string)($order['CUSTOMER_EMAIL'] ?? ''));
        $phone = trim((string)($order['CUSTOMER_PHONE'] ?? ''));

        if ($email !== '') {
            $this->queue([
                'user_id' => $order['USER_ID'] !== null ? (int)$order['USER_ID'] : null,
                'order_id' => (int)$order['ID'],
                'channel' => 'email',
                'recipient_type' => 'customer_email',
                'recipient' => $email,
                'subject' => 'Оновлення статусу замовлення #' . (int)$order['ID'],
                'message' => $message,
                'payload' => ['event' => 'order_status_changed', 'status' => $status],
            ]);
        }

        if ($phone !== '') {
            $this->queue([
                'user_id' => $order['USER_ID'] !== null ? (int)$order['USER_ID'] : null,
                'order_id' => (int)$order['ID'],
                'channel' => 'sms',
                'recipient_type' => 'customer_phone',
                'recipient' => $phone,
                'subject' => 'Статус замовлення',
                'message' => $message,
                'payload' => ['event' => 'order_status_changed', 'status' => $status],
            ]);
        }
    }

    public function notifySupportRequest(string $type, int $requestId): void
    {
        $labels = [
            'callback' => 'Нова заявка на дзвінок',
            'calculation' => 'Нова заявка на розрахунок матеріалів',
            'ticket' => 'Нове звернення підтримки',
            'chat' => 'Нове повідомлення з чату',
        ];
        $tables = [
            'callback' => 'rc_callback_requests',
            'calculation' => 'rc_calculation_requests',
            'ticket' => 'rc_support_tickets',
            'chat' => 'rc_chat_messages',
        ];
        if ($requestId <= 0 || !isset($tables[$type])) {
            return;
        }

        $row = Database::fetchOne('SELECT * FROM ' . $tables[$type] . ' WHERE id = :id', ['id' => $requestId]);
        if (!$row) {
            return;
        }

        $name = (string)($row['NAME'] ?? $row['CUSTOMER_NAME'] ?? 'Клієнт');
        $phone = (string)($row['PHONE'] ?? '');
        $email = (string)($row['EMAIL'] ?? '');
        $text = (string)($row['MESSAGE'] ?? $row['COMMENT_TEXT'] ?? $row['MATERIALS_LIST'] ?? $row['SUBJECT'] ?? '');
        if (is_object($text) && method_exists($text, 'load')) {
            $text = (string)$text->load();
        }

        $subject = $labels[$type] ?? 'Нова заявка';
        $message = $subject . ' #' . $requestId . "\n" .
            'Клієнт: ' . $name . "\n" .
            'Телефон: ' . ($phone !== '' ? $phone : '—') . "\n" .
            'Email: ' . ($email !== '' ? $email : '—') . "\n" .
            'Текст: ' . mb_substr(trim($text), 0, 800);

        $this->queue([
            'channel' => 'admin',
            'recipient_type' => 'manager_panel',
            'recipient' => 'admin',
            'subject' => $subject,
            'message' => $message,
            'payload' => ['event' => 'support_request', 'type' => $type, 'request_id' => $requestId],
        ]);

        
    }

    public function queue(array $data): void
    {
        $payload = $data['payload'] ?? [];
        $payloadJson = $payload !== []
            ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $channel = mb_substr((string)($data['channel'] ?? 'admin'), 0, 40);
        $recipient = mb_substr((string)($data['recipient'] ?? 'admin'), 0, 255);
        $subject = mb_substr((string)($data['subject'] ?? 'Сповіщення'), 0, 255);
        $message = (string)($data['message'] ?? '');
        $status = 'queued';
        $errorMessage = null;
        $sentAtSql = 'NULL';

        if ($channel === 'email' && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $safe = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            $html = '<div style="font-family:Arial,sans-serif;line-height:1.55;color:#18212f"><h2>'
                . htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2><p>' . $safe . '</p></div>';
            $result = (new OutboundMessageService())->sendEmail($recipient, $subject, $html, $message, 'order_notification');
            $status = (string)($result['status'] ?? (!empty($result['ok']) ? 'sent' : 'failed'));
            if ($status === 'sent') {
                $sentAtSql = 'CURRENT_TIMESTAMP';
            }
            if (empty($result['ok'])) {
                $errorMessage = mb_substr((string)($result['message'] ?? 'Помилка SMTP'), 0, 1000);
            }
        }

        Database::execute(
            "INSERT INTO rc_notifications (
                user_id,
                order_id,
                channel,
                recipient_type,
                recipient,
                subject,
                message,
                payload,
                status,
                attempts,
                sent_at,
                error_message,
                created_at,
                updated_at
            ) VALUES (
                :user_id,
                :order_id,
                :channel,
                :recipient_type,
                :recipient,
                :subject,
                :message,
                :payload,
                :status,
                1,
                {$sentAtSql},
                :error_message,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )",
            [
                'user_id' => $data['user_id'] ?? null,
                'order_id' => $data['order_id'] ?? null,
                'channel' => $channel,
                'recipient_type' => mb_substr((string)($data['recipient_type'] ?? 'internal'), 0, 80),
                'recipient' => $recipient,
                'subject' => $subject,
                'message' => $message,
                'payload' => $payloadJson,
                'status' => $status,
                'error_message' => $errorMessage,
            ]
        );
    }

    public function adminData(): array
    {
        return [
            'summary' => $this->summary(),
            'channels' => $this->channels(),
            'pending' => $this->pending(),
            'recent' => $this->recent(),
            'notifications' => $this->recent(),
            'subscribers' => $this->subscribers(),
            'email_subscribers' => $this->emailSubscribers(10),
            'email_subscriber_count' => $this->emailSubscriberCount(),
            'templates' => $this->templates(),
            'settings' => $this->settings(),
        ];
    }

    private function config(): array
    {
        $example = __DIR__ . '/../config/integrations.example.php';
        $local = __DIR__ . '/../config/integrations.php';
        $config = is_file($example) ? require $example : [];
        if (is_file($local)) {
            $config = array_replace_recursive(is_array($config) ? $config : [], require $local);
        }
        return is_array($config) ? $config : [];
    }

    private function saveCampaignImage(): string
    {
        $file = $_FILES['campaign_image'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return '';
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp) || $size <= 0 || $size > 8 * 1024 * 1024) {
            return '';
        }
        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) {
            return '';
        }
        $imageInfo = @getimagesize($tmp);
        $mime = strtolower((string)($imageInfo['mime'] ?? ''));
        $allowedMimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        if ($imageInfo === false || ($allowedMimes[$ext] ?? '') !== $mime) {
            return '';
        }
        $dir = __DIR__ . '/../uploads/campaigns/' . date('Y/m');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $base = preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo((string)($file['name'] ?? 'campaign'), PATHINFO_FILENAME)) ?: 'campaign';
        $fileName = date('His') . '_' . bin2hex(random_bytes(4)) . '_' . $base . '.' . $ext;
        if (!move_uploaded_file($tmp, $dir . '/' . $fileName)) {
            return '';
        }
        $relative = '/uploads/campaigns/' . date('Y/m') . '/' . $fileName;
        $config = $this->config();
        $baseUrl = rtrim((string)($config['app']['public_url'] ?? ''), '/');
        $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $scheme = $forwardedProto !== '' ? $forwardedProto : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
        $host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'rungocraft.xyz')) ?: 'rungocraft.xyz';
        if ($baseUrl === '' || ($scheme === 'http' && str_starts_with($baseUrl, 'https://'))) {
            $baseUrl = $scheme . '://' . $host;
        }
        return $baseUrl . $relative;
    }

    private function createManual(array $data, ?array $user): array
    {
        $channel = trim((string)($data['channel'] ?? 'admin'));
        $recipient = trim((string)($data['recipient'] ?? 'admin'));
        $subject = trim((string)($data['subject'] ?? 'Тестове сповіщення'));
        $message = trim((string)($data['message'] ?? 'Перевірка системи сповіщень RungoCraft.'));

        if ($message === '') {
            return ['ok' => false, 'message' => 'Введіть текст сповіщення.'];
        }

        $this->queue([
            'user_id' => $user['id'] ?? null,
            'channel' => $channel !== '' ? $channel : 'admin',
            'recipient_type' => $channel === 'telegram' ? 'telegram_chat_id' : 'manual',
            'recipient' => $recipient !== '' ? $recipient : 'admin',
            'subject' => $subject !== '' ? $subject : 'Тестове сповіщення',
            'message' => $message,
            'payload' => ['event' => 'manual_notification'],
        ]);

        return ['ok' => true, 'message' => 'Сповіщення додано в чергу.'];
    }

    private function sendEmailCampaign(array $data, ?array $user): array
    {
        $subject = trim((string)($data['subject'] ?? 'Акція RungoCraft'));
        $message = trim((string)($data['message'] ?? ''));
        $testEmail = strtolower(trim((string)($data['test_email'] ?? '')));
        $imageUrl = $this->saveCampaignImage();

        if ($subject === '' || $message === '') {
            return ['ok' => false, 'message' => 'Вкажіть тему та текст рекламної розсилки.'];
        }

        $recipients = [];
        $isTest = $testEmail !== '';

        if ($isTest) {
            if (filter_var($testEmail, FILTER_VALIDATE_EMAIL) === false) {
                return ['ok' => false, 'message' => 'Тестовий email має некоректний формат.'];
            }
            $recipients[] = [
                'ID' => null,
                'FULL_NAME' => 'Тестовий отримувач',
                'EMAIL' => $testEmail,
            ];
        } else {
            $recipients = $this->emailSubscribers(100000);
            if ($recipients === []) {
                return ['ok' => false, 'message' => 'Немає отримувачів: додайте підписників через форму на головній або ввімкніть email-акції у профілях користувачів.'];
            }
        }

        $mailer = new OutboundMessageService();
        $sent = 0;
        $failed = 0;
        $queued = 0;

        foreach ($recipients as $recipient) {
            $email = strtolower(trim((string)($recipient['EMAIL'] ?? '')));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $failed++;
                continue;
            }

            $result = $mailer->sendMarketingEmail($email, $subject, $message, (string)($recipient['FULL_NAME'] ?? ''), $imageUrl);
            $status = (string)($result['status'] ?? (!empty($result['ok']) ? 'sent' : 'failed'));
            if ($status === 'sent') {
                $sent++;
            } elseif ($status === 'queued') {
                $queued++;
            } else {
                $failed++;
            }

            Database::execute(
                "INSERT INTO rc_notifications (
                    user_id, channel, recipient_type, recipient, subject, message, payload,
                    status, attempts, sent_at, error_message, created_at, updated_at
                ) VALUES (
                    :user_id, 'email', :recipient_type, :recipient, :subject, :message, :payload,
                    :status, 1,
                    CASE WHEN :status_for_sent = 'sent' THEN CURRENT_TIMESTAMP ELSE NULL END,
                    :error_message, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )",
                [
                    'user_id' => $recipient['ID'] !== null ? (int)$recipient['ID'] : null,
                    'recipient_type' => $isTest ? 'promo_test_email' : 'promo_subscriber_email',
                    'recipient' => $email,
                    'subject' => mb_substr($subject, 0, 255),
                    'message' => $message,
                    'payload' => json_encode([
                        'event' => 'email_promo_campaign',
                        'mode' => $isTest ? 'test' : 'subscribers',
                        'admin_user_id' => $user['id'] ?? null,
                        'image_url' => $imageUrl,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'status' => $status,
                    'status_for_sent' => $status,
                    'error_message' => empty($result['ok']) ? mb_substr((string)($result['message'] ?? 'Помилка відправки'), 0, 1000) : null,
                ]
            );
        }

        return [
            'ok' => $failed === 0,
            'message' => 'Розсилку виконано. Надіслано: ' . $sent . ', у черзі: ' . $queued . ', помилки: ' . $failed . '.',
        ];
    }

    private function sendTelegramCampaign(array $data, ?array $user): array
    {
        $subject = trim((string)($data['subject'] ?? 'Акція RungoCraft'));
        $message = trim((string)($data['message'] ?? ''));
        $target = trim((string)($data['target_scope'] ?? 'all'));
        $testChatId = trim((string)($data['test_chat_id'] ?? ''));
        $limit = max(1, min(300, (int)($data['limit'] ?? 50)));

        if ($subject === '' || $message === '') {
            return ['ok' => false, 'message' => 'Вкажіть тему та текст Telegram-розсилки.'];
        }

        $recipients = [];
        $isTest = $testChatId !== '';
        if ($isTest) {
            $recipients[] = ['CHAT_ID' => $testChatId, 'ID' => null, 'FULL_NAME' => 'Тестовий чат'];
        } else {
            $recipients = $this->telegramCampaignRecipients($target, $limit);
            if ($recipients === []) {
                return ['ok' => false, 'message' => 'Немає активних Telegram-підписників для розсилки. Додайте chat_id у таблицю підписників або через бота.'];
            }
        }

        $text = "📣 {$subject}\n\n{$message}\n\nRungoCraft";
        $sent = 0;
        $failed = 0;
        $queued = 0;

        foreach ($recipients as $recipient) {
            $chatId = trim((string)($recipient['CHAT_ID'] ?? ''));
            if ($chatId === '') {
                $failed++;
                continue;
            }
            $result = $this->sendTelegramMessage($chatId, $text);
            $status = (string)($result['status'] ?? 'failed');
            if ($status === 'sent') {
                $sent++;
            } elseif ($status === 'queued') {
                $queued++;
            } else {
                $failed++;
            }
            $this->logTelegramNotification($chatId, $subject, $text, $result, [
                'event' => 'telegram_promo_campaign',
                'mode' => $isTest ? 'test' : 'subscribers',
                'target_scope' => $target,
                'admin_user_id' => $user['id'] ?? null,
            ]);
        }

        return [
            'ok' => $failed === 0,
            'message' => 'Telegram-розсилку виконано. Надіслано: ' . $sent . ', у черзі: ' . $queued . ', помилки: ' . $failed . '.',
        ];
    }

    private function markSent(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Некоректне сповіщення.'];
        }

        Database::execute(
            "UPDATE rc_notifications
             SET status = 'sent', sent_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP, attempts = attempts + 1, error_message = NULL
             WHERE id = :id",
            ['id' => $id]
        );

        return ['ok' => true, 'message' => 'Сповіщення позначено як відправлене.'];
    }

    private function markFailed(int $id, string $error): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Некоректне сповіщення.'];
        }

        Database::execute(
            "UPDATE rc_notifications
             SET status = 'failed', error_message = :error_message, updated_at = CURRENT_TIMESTAMP, attempts = attempts + 1
             WHERE id = :id",
            ['id' => $id, 'error_message' => mb_substr($error, 0, 1000)]
        );

        return ['ok' => true, 'message' => 'Сповіщення позначено як помилкове.'];
    }

    private function addTelegramSubscriber(array $data): array
    {
        $chatId = trim((string)($data['chat_id'] ?? ''));
        $username = trim((string)($data['username'] ?? ''));
        $fullName = trim((string)($data['full_name'] ?? ''));
        $roleScope = trim((string)($data['role_scope'] ?? 'client'));
        if ($chatId === '') {
            return ['ok' => false, 'message' => 'Вкажіть Telegram chat_id.'];
        }
        if (!in_array($roleScope, ['client', 'manager', 'admin', 'all'], true)) {
            $roleScope = 'client';
        }

        $ok = Database::execute(
            "MERGE INTO rc_telegram_subscribers s
             USING (SELECT :chat_id AS chat_id FROM dual) v
             ON (s.chat_id = v.chat_id)
             WHEN MATCHED THEN UPDATE SET
                s.username = :username,
                s.full_name = :full_name,
                s.role_scope = :role_scope,
                s.is_active = 1
             WHEN NOT MATCHED THEN INSERT (chat_id, username, full_name, role_scope, is_active)
             VALUES (:chat_id, :username, :full_name, :role_scope, 1)",
            [
                'chat_id' => $chatId,
                'username' => $username !== '' ? $username : null,
                'full_name' => $fullName !== '' ? $fullName : null,
                'role_scope' => $roleScope,
            ]
        );

        return [
            'ok' => $ok,
            'message' => $ok ? 'Telegram-підписника додано/оновлено.' : 'Підписника не збережено: ' . (Database::lastError() ?? 'невідома помилка'),
        ];
    }

    private function toggleSubscriber(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Некоректний підписник.'];
        }

        Database::execute(
            "UPDATE rc_telegram_subscribers
             SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
             WHERE id = :id",
            ['id' => $id]
        );

        return ['ok' => true, 'message' => 'Статус Telegram-підписника оновлено.'];
    }

    private function summary(): array
    {
        $total = Database::fetchOne("SELECT COUNT(*) AS cnt FROM rc_notifications");
        $queued = Database::fetchOne("SELECT COUNT(*) AS cnt FROM rc_notifications WHERE status = 'queued'");
        $sent = Database::fetchOne("SELECT COUNT(*) AS cnt FROM rc_notifications WHERE status = 'sent'");
        $failed = Database::fetchOne("SELECT COUNT(*) AS cnt FROM rc_notifications WHERE status = 'failed'");
        return [
            'total' => (int)($total['CNT'] ?? 0),
            'queued' => (int)($queued['CNT'] ?? 0),
            'sent' => (int)($sent['CNT'] ?? 0),
            'failed' => (int)($failed['CNT'] ?? 0),
            'telegram' => 0,
            'subscribers' => 0,
        ];
    }

    private function channels(): array
    {
        return Database::fetchAll(
            "SELECT code, name, is_active, description
             FROM rc_notification_channels
             ORDER BY sort_order, code"
        );
    }

    private function pending(): array
    {
        return Database::fetchAll(
            "SELECT id, order_id, channel, recipient_type, recipient, subject, message, status, attempts, created_at
             FROM rc_notifications
             WHERE status = 'queued'
             ORDER BY id DESC
             FETCH FIRST 30 ROWS ONLY"
        );
    }

    private function recent(): array
    {
        return Database::fetchAll(
            "SELECT id, order_id, channel, recipient, subject, status, attempts, sent_at, error_message, created_at
             FROM rc_notifications
             ORDER BY id DESC
             FETCH FIRST 30 ROWS ONLY"
        );
    }

    private function subscribers(): array
    {
        return Database::fetchAll(
            "SELECT id, user_id, chat_id, username, full_name, role_scope, is_active, created_at
             FROM rc_telegram_subscribers
             ORDER BY is_active DESC, id DESC
             FETCH FIRST 50 ROWS ONLY"
        );
    }

    private function emailSubscribers(int $limit = 0): array
    {
        $limit = $limit > 0 ? min(100000, $limit) : 0;
        $sql = "SELECT MIN(id) AS id, MAX(full_name) AS full_name, email
             FROM (
                SELECT u.id AS id, u.full_name AS full_name, LOWER(TRIM(u.email)) AS email
                FROM rc_users u
                LEFT JOIN rc_user_notification_settings s ON s.user_id = u.id
                WHERE u.email IS NOT NULL
                  AND TRIM(u.email) IS NOT NULL
                  AND u.is_active = 1
                  AND NVL(s.email_notifications, 1) = 1
                  AND NVL(s.promo_notifications, 0) = 1
                UNION ALL
                SELECT ns.user_id AS id, ns.full_name AS full_name, LOWER(TRIM(ns.email)) AS email
                FROM rc_newsletter_subscribers ns
                WHERE ns.email IS NOT NULL
                  AND TRIM(ns.email) IS NOT NULL
                  AND NVL(ns.status, 'active') = 'active'
                  AND NVL(ns.promo_consent, 1) = 1
             )
             WHERE email IS NOT NULL
             GROUP BY email
             ORDER BY email";
        if ($limit > 0) {
            $sql .= "
             FETCH FIRST {$limit} ROWS ONLY";
        }
        return Database::fetchAll($sql);
    }

    private function emailSubscriberCount(): int
    {
        $row = Database::fetchOne(
            "SELECT COUNT(*) AS cnt
             FROM (
                SELECT LOWER(TRIM(u.email)) AS email
                FROM rc_users u
                LEFT JOIN rc_user_notification_settings s ON s.user_id = u.id
                WHERE u.email IS NOT NULL
                  AND TRIM(u.email) IS NOT NULL
                  AND u.is_active = 1
                  AND NVL(s.email_notifications, 1) = 1
                  AND NVL(s.promo_notifications, 0) = 1
                UNION
                SELECT LOWER(TRIM(ns.email)) AS email
                FROM rc_newsletter_subscribers ns
                WHERE ns.email IS NOT NULL
                  AND TRIM(ns.email) IS NOT NULL
                  AND NVL(ns.status, 'active') = 'active'
                  AND NVL(ns.promo_consent, 1) = 1
             )"
        );

        return (int)($row['CNT'] ?? 0);
    }

    private function templates(): array
    {
        return Database::fetchAll(
            "SELECT template_key, channel, subject_template, body_template, is_active
             FROM rc_notification_templates
             ORDER BY template_key, channel"
        );
    }

    private function settings(): array
    {
        $rows = Database::fetchAll(
            "SELECT setting_key, setting_value
             FROM rc_site_settings
             WHERE setting_key IN (
                'telegram_bot_enabled',
                'telegram_bot_username',
                'telegram_bot_token_config_key',
                'notification_queue_mode'
             )
             ORDER BY setting_key"
        );

        $settings = [];
        foreach ($rows as $row) {
            $settings[(string)$row['SETTING_KEY']] = (string)$row['SETTING_VALUE'];
        }

        return $settings;
    }

    private function telegramManagers(): array
    {
        return [];
    }

    private function telegramCampaignRecipients(string $target, int $limit): array
    {
        $limit = max(1, min(300, $limit));
        $where = "is_active = 1";
        if (in_array($target, ['client', 'manager', 'admin'], true)) {
            $where .= " AND role_scope = :target";
            return Database::fetchAll(
                "SELECT id, chat_id, username, full_name, role_scope
                 FROM rc_telegram_subscribers
                 WHERE {$where}
                 ORDER BY id DESC
                 FETCH FIRST {$limit} ROWS ONLY",
                ['target' => $target]
            );
        }

        return Database::fetchAll(
            "SELECT id, chat_id, username, full_name, role_scope
             FROM rc_telegram_subscribers
             WHERE {$where}
             ORDER BY id DESC
             FETCH FIRST {$limit} ROWS ONLY"
        );
    }

    private function sendTelegramMessage(string $chatId, string $message): array
    {
        $config = [];
        $path = __DIR__ . '/../config/integrations.php';
        if (file_exists($path)) {
            $config = require $path;
        }

        $token = trim((string)($config['telegram_bot_token'] ?? ($config['telegram']['bot_token'] ?? '')));
        $queueMode = (bool)($config['notifications_queue_mode'] ?? false);

        if ($token === '') {
            return ['ok' => true, 'status' => 'queued', 'message' => 'Telegram token не налаштовано. Повідомлення додано в чергу.'];
        }

        if ($queueMode) {
            return ['ok' => true, 'status' => 'queued', 'message' => 'Увімкнено queue mode. Повідомлення додано в чергу.'];
        }

        if (!extension_loaded('curl')) {
            return ['ok' => false, 'status' => 'failed', 'message' => 'PHP extension curl не увімкнено.'];
        }

        $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $chatId,
                'text' => $message,
                'disable_web_page_preview' => '1',
            ]),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            return ['ok' => false, 'status' => 'failed', 'message' => $error !== '' ? $error : ('Telegram HTTP ' . $status . ': ' . (string)$body)];
        }

        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded) || empty($decoded['ok'])) {
            return ['ok' => false, 'status' => 'failed', 'message' => 'Telegram API повернув помилку: ' . (string)$body];
        }

        return ['ok' => true, 'status' => 'sent', 'message' => 'Telegram повідомлення надіслано.'];
    }

    private function logTelegramNotification(string $chatId, string $subject, string $message, array $result, array $payload = []): void
    {
        Database::execute(
            "INSERT INTO rc_notifications (
                channel, recipient_type, recipient, subject, message, payload,
                status, attempts, sent_at, error_message, created_at, updated_at
             ) VALUES (
                'telegram', 'telegram_chat_id', :recipient, :subject, :message, :payload,
                :status, 1,
                CASE WHEN :status_for_sent = 'sent' THEN CURRENT_TIMESTAMP ELSE NULL END,
                :error_message, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )",
            [
                'recipient' => $chatId,
                'subject' => mb_substr($subject, 0, 255),
                'message' => $message,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => (string)($result['status'] ?? 'failed'),
                'status_for_sent' => (string)($result['status'] ?? 'failed'),
                'error_message' => empty($result['ok']) ? mb_substr((string)($result['message'] ?? 'Помилка Telegram'), 0, 1000) : null,
            ]
        );
    }

    private function canManage(?array $user): bool
    {
        $role = $user['role'] ?? ($_SESSION['user']['role'] ?? null);
        return in_array($role, ['admin', 'manager'], true);
    }
}
