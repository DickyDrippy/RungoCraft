<?php
declare(strict_types=1);

final class OutboundMessageService
{
    public function sendVerification(string $destination, string $type, string $code, string $purpose, ?string $linkToken = null): array
    {
        if ($type === 'email') {
            return $this->sendVerificationEmail($destination, $code, $purpose, $linkToken);
        }

        if ($type === 'phone') {
            return $this->sendVerificationSms($destination, $code, $purpose);
        }

        return ['ok' => false, 'status' => 'failed', 'message' => 'Невідомий канал підтвердження.'];
    }

    public function sendVerificationEmail(string $email, string $code, string $purpose, ?string $linkToken = null): array
    {
        $config = $this->config();
        $emailConfig = $config['email'] ?? [];
        $appUrl = $this->publicUrlBase($config);
        $enabled = (bool)($emailConfig['enabled'] ?? false) || trim((string)($emailConfig['smtp']['username'] ?? '')) !== '';

        $subject = match ($purpose) {
            'login' => 'Код входу RungoCraft',
            'password_reset' => 'Відновлення пароля RungoCraft',
            default => 'Підтвердження email RungoCraft',
        };

        $verifyLink = $linkToken ? $appUrl . '/verify-email?token=' . rawurlencode($linkToken) : '';
        $text = "Ваш код підтвердження RungoCraft: {$code}. Код дійсний 15 хвилин.";
        if ($verifyLink !== '') {
            $text .= "\n\nТакож можна підтвердити email за посиланням: {$verifyLink}";
        }

        $html = '<p>Ваш код підтвердження RungoCraft:</p>'
            . '<p style="font-size:24px;font-weight:700;letter-spacing:4px">' . htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<p>Код дійсний 15 хвилин.</p>';

        if ($verifyLink !== '') {
            $safeLink = htmlspecialchars($verifyLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<p><a href="' . $safeLink . '" style="display:inline-block;padding:12px 18px;background:#ff9800;color:#111;text-decoration:none;border-radius:10px;font-weight:700">Підтвердити email</a></p>';
            $html .= '<p>Якщо кнопка не працює, відкрийте посилання:<br><code>' . $safeLink . '</code></p>';
        }

        if (!$enabled) {
            $this->log('email', $email, $subject, $text, 'local', 'queued', 'Email disabled. Local/dev mode.');
            return ['ok' => true, 'status' => 'queued', 'message' => 'Email-сервіс вимкнений. Для локального тесту використайте код 1111.'];
        }

        try {
            $this->sendSmtp($email, $subject, $html, $text, $emailConfig);
            $this->log('email', $email, $subject, $text, 'smtp', 'sent', null);
            return ['ok' => true, 'status' => 'sent', 'message' => 'Лист із кодом підтвердження надіслано.'];
        } catch (Throwable $e) {
            $this->log('email', $email, $subject, $text, 'smtp', 'failed', $e->getMessage());
            return ['ok' => false, 'status' => 'failed', 'message' => 'Не вдалося надіслати email: ' . $e->getMessage()];
        }
    }


    public function sendPasswordResetEmail(string $email, string $token): array
    {
        $config = $this->config();
        $emailConfig = $config['email'] ?? [];
        $appUrl = $this->publicUrlBase($config);
        $enabled = (bool)($emailConfig['enabled'] ?? false) || trim((string)($emailConfig['smtp']['username'] ?? '')) !== '';
        $link = $appUrl . '/reset-password?token=' . rawurlencode($token);
        $subject = 'Відновлення пароля RungoCraft';
        $text = "Ви запросили відновлення пароля RungoCraft. Перейдіть за посиланням протягом 30 хвилин: {$link}";
        $safeLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<p>Ви запросили відновлення пароля RungoCraft.</p>'
            . '<p><a href="' . $safeLink . '" style="display:inline-block;padding:12px 18px;background:#ff9800;color:#111;text-decoration:none;border-radius:10px;font-weight:700">Створити новий пароль</a></p>'
            . '<p>Посилання дійсне 30 хвилин.</p>'
            . '<p>Якщо кнопка не працює, відкрийте посилання:<br><code>' . $safeLink . '</code></p>';

        if (!$enabled) {
            $this->log('email', $email, $subject, $text, 'local', 'queued', 'Email disabled. Local/dev mode.');
            return ['ok' => true, 'status' => 'queued', 'message' => 'Email-сервіс вимкнений. Увімкніть SMTP для надсилання посилання.'];
        }

        try {
            $this->sendSmtp($email, $subject, $html, $text, $emailConfig);
            $this->log('email', $email, $subject, $text, 'smtp', 'sent', null);
            return ['ok' => true, 'status' => 'sent', 'message' => 'Посилання для відновлення пароля надіслано на email.'];
        } catch (Throwable $e) {
            $this->log('email', $email, $subject, $text, 'smtp', 'failed', $e->getMessage());
            return ['ok' => false, 'status' => 'failed', 'message' => 'Не вдалося надіслати email: ' . $e->getMessage()];
        }
    }

    public function sendEmail(string $email, string $subject, string $html, string $text, string $category = 'manual', array $inlineImages = []): array
    {
        $config = $this->config();
        $emailConfig = $config['email'] ?? [];
        $enabled = (bool)($emailConfig['enabled'] ?? false) || trim((string)($emailConfig['smtp']['username'] ?? '')) !== '';

        $email = strtolower(trim($email));
        $subject = trim($subject);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'status' => 'failed', 'message' => 'Некоректний email отримувача.'];
        }
        if ($subject === '') {
            return ['ok' => false, 'status' => 'failed', 'message' => 'Вкажіть тему листа.'];
        }

        if (!$enabled) {
            $this->log('email', $email, $subject, $text, 'local', 'queued', 'Email disabled. Local/dev mode. Category: ' . $category);
            return ['ok' => true, 'status' => 'queued', 'message' => 'Email-сервіс вимкнений. Лист додано лише в локальний журнал.'];
        }

        try {
            $this->sendSmtp($email, $subject, $html, $text, $emailConfig, $inlineImages);
            $this->log('email', $email, $subject, $text, 'smtp', 'sent', null);
            return ['ok' => true, 'status' => 'sent', 'message' => 'Email надіслано.'];
        } catch (Throwable $e) {
            $this->log('email', $email, $subject, $text, 'smtp', 'failed', $e->getMessage());
            return ['ok' => false, 'status' => 'failed', 'message' => 'Не вдалося надіслати email: ' . $e->getMessage()];
        }
    }

    public function sendMarketingEmail(string $email, string $subject, string $message, string $recipientName = '', string $imageUrl = ''): array
    {
        $safeName = htmlspecialchars(trim($recipientName), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars(trim($message), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $hello = $safeName !== '' ? '<p>Вітаємо, <b>' . $safeName . '</b>!</p>' : '<p>Вітаємо!</p>';
        $inlineImages = [];
        $imageSrc = trim($imageUrl);
        if ($imageSrc !== '') {
            $localImage = $this->localUploadPathFromUrl($imageSrc);
            if ($localImage !== '' && is_file($localImage)) {
                $cid = 'rungocraft_campaign_' . bin2hex(random_bytes(6));
                $inlineImages[] = ['path' => $localImage, 'cid' => $cid, 'name' => basename($localImage)];
                $imageSrc = 'cid:' . $cid;
            }
        }
        $safeImage = $imageSrc !== '' ? htmlspecialchars($imageSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
        $imageHtml = $safeImage !== '' ? '<p><img src="' . $safeImage . '" alt="RungoCraft" style="max-width:100%;border-radius:16px;margin:10px 0 18px;display:block"></p>' : '';
        $html = '<div style="font-family:Arial,sans-serif;line-height:1.55;color:#18212f">'
            . '<h2 style="margin:0 0 14px">' . htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>'
            . $hello
            . $imageHtml
            . '<div>' . $safeMessage . '</div>'
            . '<hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0">'
            . '<p style="font-size:12px;color:#667085">Ви отримали цей лист, бо у кабінеті RungoCraft увімкнені акції та персональні пропозиції. Вимкнути їх можна в кабінеті: Профіль → Сповіщення.</p>'
            . '</div>';

        $text = ($recipientName !== '' ? "Вітаємо, {$recipientName}!\n\n" : "Вітаємо!\n\n")
            . trim($message)
            . "\n\nВи отримали цей лист, бо у кабінеті RungoCraft увімкнені акції та персональні пропозиції.";

        return $this->sendEmail($email, $subject, $html, $text, 'promo_campaign', $inlineImages);
    }

    public function sendVerificationSms(string $phone, string $code, string $purpose): array
    {
        $config = $this->config();
        $sms = $config['sms'] ?? [];
        $enabled = (bool)($sms['enabled'] ?? false);
        $provider = (string)($sms['provider'] ?? 'turbosms');
        $message = "RungoCraft: код підтвердження {$code}. Дійсний 15 хв.";

        if (!$enabled) {
            $this->log('sms', $phone, 'RungoCraft verification', $message, 'local', 'queued', 'SMS disabled. Local/dev mode.');
            return ['ok' => true, 'status' => 'queued', 'message' => 'SMS-сервіс вимкнений. Для локального тесту використайте код 1111.'];
        }

        if ($provider !== 'turbosms') {
            $this->log('sms', $phone, 'RungoCraft verification', $message, $provider, 'failed', 'Unsupported SMS provider.');
            return ['ok' => false, 'status' => 'failed', 'message' => 'Поки підтримується SMS-провайдер TurboSMS.'];
        }

        $turbo = $sms['turbosms'] ?? [];
        $token = trim((string)($turbo['token'] ?? ''));
        $endpoint = trim((string)($turbo['endpoint'] ?? 'https://api.turbosms.ua/message/send.json'));
        $sender = trim((string)($sms['sender'] ?? 'RungoCraft'));
        $recipient = preg_replace('/\D+/', '', $phone) ?? '';

        if ($token === '') {
            return ['ok' => false, 'status' => 'failed', 'message' => 'TurboSMS token не налаштований.'];
        }

        $payload = [
            'recipients' => [$recipient],
            'sms' => [
                'sender' => $sender,
                'text' => $message,
            ],
        ];

        try {
            if (!extension_loaded('curl')) {
                throw new RuntimeException('PHP extension curl не увімкнено.');
            }

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token,
                ],
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 8,
            ]);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($errno !== 0) {
                throw new RuntimeException($error);
            }

            $decoded = json_decode((string)$raw, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('TurboSMS повернув не JSON. HTTP ' . $httpCode);
            }

            $responseCode = (int)($decoded['response_code'] ?? -1);
            if (!in_array($responseCode, [0, 800, 801, 802, 803, 804], true)) {
                throw new RuntimeException((string)($decoded['response_status'] ?? 'TurboSMS error'));
            }

            $this->log('sms', $phone, 'RungoCraft verification', $message, 'turbosms', 'sent', null);
            return ['ok' => true, 'status' => 'sent', 'message' => 'SMS із кодом підтвердження надіслано.'];
        } catch (Throwable $e) {
            $this->log('sms', $phone, 'RungoCraft verification', $message, 'turbosms', 'failed', $e->getMessage());
            return ['ok' => false, 'status' => 'failed', 'message' => 'Не вдалося надіслати SMS: ' . $e->getMessage()];
        }
    }

    private function sendSmtp(string $to, string $subject, string $html, string $text, array $emailConfig, array $inlineImages = []): void
    {
        $smtp = $emailConfig['smtp'] ?? [];
        $host = trim((string)($smtp['host'] ?? ''));
        $port = (int)($smtp['port'] ?? 587);
        $username = (string)($smtp['username'] ?? '');
        $password = (string)($smtp['password'] ?? '');
        $encryption = strtolower((string)($smtp['encryption'] ?? 'tls'));
        $fromEmail = trim((string)($emailConfig['from_email'] ?? $username));
        $fromName = trim((string)($emailConfig['from_name'] ?? 'RungoCraft'));

        if ($host === '' || $fromEmail === '') {
            throw new RuntimeException('SMTP host/from_email не налаштовані.');
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            throw new RuntimeException('SMTP connect failed: ' . $errstr);
        }

        stream_set_timeout($socket, 20);
        $this->smtpExpect($socket, [220]);
        $this->smtpCommand($socket, 'EHLO rungocraft.local', [250]);

        if ($encryption === 'tls') {
            $this->smtpCommand($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Не вдалося увімкнути TLS для SMTP.');
            }
            $this->smtpCommand($socket, 'EHLO rungocraft.local', [250]);
        }

        if ($username !== '' || $password !== '') {
            $this->smtpCommand($socket, 'AUTH LOGIN', [334]);
            $this->smtpCommand($socket, base64_encode($username), [334]);
            $this->smtpCommand($socket, base64_encode($password), [235]);
        }

        $boundary = '=_RungoCraft_' . bin2hex(random_bytes(12));
        $relatedBoundary = '=_RungoCraft_Related_' . bin2hex(random_bytes(12));
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';

        $headers = "From: {$encodedFromName} <{$fromEmail}>\r\n"
            . "To: <{$to}>\r\n"
            . "Subject: {$encodedSubject}\r\n"
            . "MIME-Version: 1.0\r\n";

        $alternativePart = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $text . "\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $html . "\r\n\r\n"
            . "--{$boundary}--\r\n";

        if ($inlineImages !== []) {
            $message = $headers
                . "Content-Type: multipart/related; boundary=\"{$relatedBoundary}\"\r\n\r\n"
                . "--{$relatedBoundary}\r\n"
                . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n"
                . $alternativePart;

            foreach ($inlineImages as $image) {
                $path = (string)($image['path'] ?? '');
                if ($path === '' || !is_file($path)) {
                    continue;
                }
                $cid = preg_replace('/[^a-zA-Z0-9._-]+/', '', (string)($image['cid'] ?? 'rungocraft_image')) ?: 'rungocraft_image';
                $name = basename((string)($image['name'] ?? basename($path)));
                $mime = $this->mimeTypeForPath($path);
                $data = chunk_split(base64_encode((string)file_get_contents($path)));
                $message .= "\r\n--{$relatedBoundary}\r\n"
                    . "Content-Type: {$mime}; name=\"{$name}\"\r\n"
                    . "Content-Transfer-Encoding: base64\r\n"
                    . "Content-ID: <{$cid}>\r\n"
                    . "Content-Disposition: inline; filename=\"{$name}\"\r\n\r\n"
                    . $data . "\r\n";
            }

            $message .= "--{$relatedBoundary}--\r\n";
        } else {
            $message = $headers
                . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n"
                . $alternativePart;
        }

        $this->smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        $this->smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        $this->smtpCommand($socket, 'DATA', [354]);
        fwrite($socket, str_replace("\n.", "\n..", $message) . "\r\n.\r\n");
        $this->smtpExpect($socket, [250]);
        $this->smtpCommand($socket, 'QUIT', [221]);
        fclose($socket);
    }

    private function localUploadPathFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        if (!str_starts_with($path, '/uploads/')) {
            return '';
        }
        $relative = rawurldecode(substr($path, strlen('/uploads/')));
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_contains($relative, '..')) {
            return '';
        }
        $root = realpath(__DIR__ . '/../uploads');
        $file = $root !== false ? realpath($root . '/' . $relative) : false;
        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            return '';
        }
        return $file;
    }

    private function mimeTypeForPath(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    private function smtpCommand($socket, string $command, array $expected): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->smtpExpect($socket, $expected);
    }

    private function smtpExpect($socket, array $expected): string
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('SMTP error: ' . trim($response));
        }

        return $response;
    }

    private function publicUrlBase(array $config): string
    {
        $base = rtrim((string)($config['app']['public_url'] ?? ''), '/');
        $env = trim((string)getenv('APP_PUBLIC_URL'));
        if ($env !== '') {
            $base = rtrim($env, '/');
        }
        $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $requestScheme = $forwardedProto !== '' ? $forwardedProto : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
        $host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'rungocraft.xyz')) ?: 'rungocraft.xyz';
        if ($base === '' || ($requestScheme === 'http' && str_starts_with($base, 'https://'))) {
            return $requestScheme . '://' . $host;
        }
        return $base;
    }

    private function config(): array
    {
        $path = __DIR__ . '/../config/integrations.php';
        if (!is_file($path)) {
            $path = __DIR__ . '/../config/integrations.example.php';
        }

        $config = is_file($path) ? require $path : [];
        return is_array($config) ? $config : [];
    }

    private function log(string $channel, string $destination, string $subject, string $message, string $provider, string $status, ?string $error): void
    {
        if (!class_exists('Database')) {
            return;
        }

        Database::execute(
            "INSERT INTO rc_outbound_messages (
                channel,
                destination,
                subject,
                message_text,
                provider,
                status,
                error_message,
                sent_at
             ) VALUES (
                :channel,
                :destination,
                :subject,
                :message_text,
                :provider,
                :status,
                :error_message,
                CASE WHEN :sent_status = 'sent' THEN CURRENT_TIMESTAMP ELSE NULL END
             )",
            [
                'channel' => substr($channel, 0, 40),
                'destination' => substr($destination, 0, 180),
                'subject' => substr($subject, 0, 255),
                'message_text' => substr($message, 0, 3900),
                'provider' => substr($provider, 0, 80),
                'status' => substr($status, 0, 40),
                'error_message' => $error !== null ? substr($error, 0, 1000) : null,
                'sent_status' => $status,
            ]
        );
    }
}
