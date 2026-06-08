<?php
declare(strict_types=1);

final class NewsletterService
{
    public function subscribe(array $data, ?array $user = null): array
    {
        $name = trim((string)($data['name'] ?? ($user['name'] ?? '')));
        $email = strtolower(trim((string)($data['email'] ?? ($user['email'] ?? ''))));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => 'Вкажіть коректний email для підписки.'];
        }

        $ok = Database::execute(
            "MERGE INTO rc_newsletter_subscribers s
             USING (
                SELECT CAST(:email AS VARCHAR2(255)) AS email,
                       CAST(:full_name AS VARCHAR2(255)) AS full_name,
                       CAST(:user_id AS NUMBER) AS user_id
                FROM dual
             ) v
             ON (LOWER(s.email) = LOWER(v.email))
             WHEN MATCHED THEN UPDATE SET
                s.full_name = COALESCE(v.full_name, s.full_name),
                s.user_id = COALESCE(v.user_id, s.user_id),
                s.status = 'active',
                s.promo_consent = 1,
                s.confirmed_at = COALESCE(s.confirmed_at, CURRENT_TIMESTAMP),
                s.updated_at = CURRENT_TIMESTAMP
             WHEN NOT MATCHED THEN INSERT (
                user_id, full_name, email, status, promo_consent, source, confirmed_at, created_at, updated_at
             ) VALUES (
                v.user_id, v.full_name, v.email, 'active', 1, 'site_footer', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )",
            [
                'email' => $email,
                'full_name' => $name !== '' ? $name : null,
                'user_id' => !empty($user['id']) ? (int)$user['id'] : null,
            ]
        );

        if (!$ok) {
            return ['ok' => false, 'message' => 'Підписку не збережено: ' . (Database::lastError() ?? 'невідома помилка')];
        }

        if (!empty($user['id'])) {
            Database::execute(
                "MERGE INTO rc_user_notification_settings s
                 USING (SELECT :user_id AS user_id FROM dual) v
                 ON (s.user_id = v.user_id)
                 WHEN MATCHED THEN UPDATE SET
                    s.email_notifications = 1,
                    s.promo_notifications = 1,
                    s.updated_at = CURRENT_TIMESTAMP
                 WHEN NOT MATCHED THEN INSERT (
                    user_id, email_notifications, sms_notifications, order_status_notifications, promo_notifications
                 ) VALUES (
                    v.user_id, 1, 0, 1, 1
                 )",
                ['user_id' => (int)$user['id']]
            );
        }

        $mailResult = $this->sendWelcomeEmail($email, $name);
        if (empty($mailResult['ok'])) {
            return ['ok' => true, 'message' => 'Підписку збережено, але лист не надіслано: ' . ($mailResult['message'] ?? 'помилка')];
        }

        return ['ok' => true, 'message' => 'Підписку оформлено. На email надіслано рекламний банер-підтвердження.'];
    }

    private function sendWelcomeEmail(string $email, string $name): array
    {
        $safeName = htmlspecialchars($name !== '' ? $name : 'друже', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subject = 'Ви підписалися на розсилку RungoCraft';
        $html = '<div style="font-family:Arial,sans-serif;color:#16212c;line-height:1.55;max-width:680px;margin:0 auto">'
            . '<div style="background:linear-gradient(135deg,#003b63,#ff9800);border-radius:24px;padding:34px;color:#fff">'
            . '<h1 style="margin:0 0 10px;font-size:30px">RungoCraft</h1>'
            . '<p style="font-size:18px;margin:0">Будматеріали та інструменти для ремонту й будівництва</p>'
            . '</div>'
            . '<h2 style="margin-top:26px">Вітаємо, ' . $safeName . '!</h2>'
            . '<p>Ви підписалися на новини, акції, спецпропозиції та оновлення цін RungoCraft.</p>'
            . '<div style="border:1px solid #e5e7eb;border-radius:18px;padding:20px;margin:22px 0;background:#fff7e8">'
            . '<b style="font-size:20px">Підписку активовано</b>'
            . '<p style="margin-bottom:0">Ви отримуватимете новини, акції та корисні пропозиції на email.</p>'
            . '</div>'
            . '<p style="font-size:12px;color:#667085">Якщо ви не підписувались на розсилку, просто проігноруйте цей лист.</p>'
            . '</div>';
        $text = "Вітаємо! Ви підписалися на розсилку RungoCraft: новини, акції, спецпропозиції та оновлення цін.";

        return (new OutboundMessageService())->sendEmail($email, $subject, $html, $text, 'newsletter_subscribe');
    }
}
