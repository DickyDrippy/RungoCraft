<?php
declare(strict_types=1);

final class CaptchaService
{
    public function verify(string $actionName, string $token = '', ?string $fallbackAnswer = null): array
    {
        $recaptcha = $this->recaptchaConfig();
        $enabled = (bool)($recaptcha['enabled'] ?? false);

        if (!$enabled) {
            if ($fallbackAnswer === null) {
                return ['ok' => true, 'message' => 'reCAPTCHA вимкнена.'];
            }

            return trim($fallbackAnswer) === '7'
                ? ['ok' => true, 'message' => 'Локальна перевірка пройдена.']
                : ['ok' => false, 'message' => 'Підтвердіть, що ви не робот.'];
        }

        if (trim($token) === '') {
            return ['ok' => false, 'message' => 'Не отримано токен reCAPTCHA. Оновіть сторінку і спробуйте ще раз.'];
        }

        $provider = strtolower((string)($recaptcha['provider'] ?? 'classic'));

        if ($provider === 'enterprise') {
            return $this->verifyEnterprise($actionName, $token, $recaptcha);
        }

        return $this->verifyClassic($actionName, $token, $recaptcha);
    }

    public function siteKey(): string
    {
        $recaptcha = $this->recaptchaConfig();

        if (!(bool)($recaptcha['enabled'] ?? false)) {
            return '';
        }

        $key = trim((string)($recaptcha['site_key'] ?? ''));
        return $key === 'CHANGE_ME' ? '' : $key;
    }

    public function provider(): string
    {
        $recaptcha = $this->recaptchaConfig();
        return strtolower((string)($recaptcha['provider'] ?? 'classic')) === 'enterprise' ? 'enterprise' : 'classic';
    }

    private function verifyClassic(string $actionName, string $token, array $recaptcha): array
    {
        $secret = trim((string)($recaptcha['secret_key'] ?? ''));
        $verifyUrl = trim((string)($recaptcha['verify_url'] ?? 'https://www.google.com/recaptcha/api/siteverify'));

        if ($secret === '' || $secret === 'CHANGE_ME') {
            return ['ok' => false, 'message' => 'reCAPTCHA увімкнена, але classic secret key не налаштований.'];
        }

        $payload = http_build_query([
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        $result = $this->httpPost($verifyUrl, $payload, ['Content-Type: application/x-www-form-urlencoded']);

        if (!$result['ok']) {
            $this->logCheck($actionName, false, null, $result['error']);
            return ['ok' => false, 'message' => 'Не вдалося перевірити reCAPTCHA: ' . $result['error']];
        }

        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded)) {
            $this->logCheck($actionName, false, null, $result['body']);
            return ['ok' => false, 'message' => 'reCAPTCHA повернула некоректну відповідь.'];
        }

        $success = (bool)($decoded['success'] ?? false);
        $score = isset($decoded['score']) ? (float)$decoded['score'] : null;
        $minScore = isset($recaptcha['min_score']) ? (float)$recaptcha['min_score'] : 0.5;

        if ($success && $score !== null && $score < $minScore) {
            $success = false;
        }

        if ($success && isset($decoded['action']) && (string)$decoded['action'] !== $actionName) {
            $success = false;
        }

        $this->logCheck($actionName, $success, $score, json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $success
            ? ['ok' => true, 'message' => 'reCAPTCHA пройдена.']
            : ['ok' => false, 'message' => 'Перевірка reCAPTCHA не пройдена. Спробуйте ще раз.'];
    }

    private function verifyEnterprise(string $actionName, string $token, array $recaptcha): array
    {
        $projectId = trim((string)($recaptcha['project_id'] ?? ''));
        $apiKey = trim((string)($recaptcha['api_key'] ?? ''));
        $siteKey = trim((string)($recaptcha['site_key'] ?? ''));

        if ($projectId === '' || $apiKey === '' || $siteKey === '') {
            return ['ok' => false, 'message' => 'reCAPTCHA Enterprise увімкнена, але project_id, api_key або site_key не налаштовані.'];
        }

        $url = 'https://recaptchaenterprise.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/assessments?key=' . rawurlencode($apiKey);
        $payload = json_encode([
            'event' => [
                'token' => $token,
                'siteKey' => $siteKey,
                'expectedAction' => $actionName,
                'userIpAddress' => $_SERVER['REMOTE_ADDR'] ?? '',
                'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $result = $this->httpPost($url, (string)$payload, ['Content-Type: application/json']);

        if (!$result['ok']) {
            $this->logCheck($actionName, false, null, $result['error']);
            return ['ok' => false, 'message' => 'Не вдалося перевірити reCAPTCHA Enterprise: ' . $result['error']];
        }

        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded)) {
            $this->logCheck($actionName, false, null, $result['body']);
            return ['ok' => false, 'message' => 'reCAPTCHA Enterprise повернула некоректну відповідь.'];
        }

        $tokenProps = $decoded['tokenProperties'] ?? [];
        $risk = $decoded['riskAnalysis'] ?? [];
        $valid = (bool)($tokenProps['valid'] ?? false);
        $actionOk = !isset($tokenProps['action']) || (string)$tokenProps['action'] === $actionName;
        $score = isset($risk['score']) ? (float)$risk['score'] : null;
        $minScore = isset($recaptcha['min_score']) ? (float)$recaptcha['min_score'] : 0.5;

        $success = $valid && $actionOk && ($score === null || $score >= $minScore);

        $this->logCheck($actionName, $success, $score, json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $success
            ? ['ok' => true, 'message' => 'reCAPTCHA Enterprise пройдена.']
            : ['ok' => false, 'message' => 'Перевірка reCAPTCHA Enterprise не пройдена.'];
    }

    private function httpPost(string $url, string $payload, array $headers): array
    {
        if (!extension_loaded('curl')) {
            return ['ok' => false, 'body' => '', 'error' => 'PHP extension curl не увімкнено.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 7,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return ['ok' => false, 'body' => '', 'error' => $error];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return ['ok' => false, 'body' => (string)$body, 'error' => 'HTTP ' . $httpCode . ': ' . substr((string)$body, 0, 500)];
        }

        return ['ok' => true, 'body' => (string)$body, 'error' => ''];
    }

    private function recaptchaConfig(): array
    {
        $config = $this->config();
        $recaptcha = is_array($config['recaptcha'] ?? null) ? $config['recaptcha'] : [];
        $settings = $this->siteSettings();

        $clean = static function ($value, string $fallback = ''): string {
            $value = trim((string)($value ?? $fallback));
            return ($value === 'CHANGE_ME' || $value === 'null') ? '' : $value;
        };

        $fromConfig = static function (string $key, string $fallback = '') use ($recaptcha, $clean): string {
            return $clean($recaptcha[$key] ?? $fallback);
        };

        $fromDb = static function (string $key) use ($settings, $clean): string {
            return $clean($settings['recaptcha_' . $key] ?? '');
        };

        $enabled = (bool)($recaptcha['enabled'] ?? false);
        $dbEnabled = trim((string)($settings['recaptcha_enabled'] ?? ''));
        if ($dbEnabled === '1') {
            $enabled = true;
        }

        $provider = strtolower($fromConfig('provider', 'classic'));
        $dbProvider = strtolower($fromDb('provider'));
        if ($dbProvider !== '') {
            $provider = $dbProvider;
        }

        $siteKey = $fromConfig('site_key');
        $secretKey = $fromConfig('secret_key');
        $projectId = $fromConfig('project_id');
        $apiKey = $fromConfig('api_key');
        $verifyUrl = $fromConfig('verify_url', 'https://www.google.com/recaptcha/api/siteverify');

        foreach (['site_key', 'secret_key', 'project_id', 'api_key', 'verify_url'] as $key) {
            $dbValue = $fromDb($key);
            if ($dbValue === '') {
                continue;
            }

            if ($key === 'site_key') $siteKey = $dbValue;
            if ($key === 'secret_key') $secretKey = $dbValue;
            if ($key === 'project_id') $projectId = $dbValue;
            if ($key === 'api_key') $apiKey = $dbValue;
            if ($key === 'verify_url') $verifyUrl = $dbValue;
        }

        
        
        if ($provider === 'enterprise' && ($projectId === '' || $apiKey === '') && $secretKey !== '') {
            $provider = 'classic';
        }

        if ($provider !== 'enterprise') {
            $provider = 'classic';
        }

        $minScore = isset($recaptcha['min_score']) ? (float)$recaptcha['min_score'] : 0.5;
        $dbMinScore = $fromDb('min_score');
        if ($dbMinScore !== '' && is_numeric($dbMinScore)) {
            $minScore = (float)$dbMinScore;
        }

        return [
            'enabled' => $enabled,
            'provider' => $provider,
            'site_key' => $siteKey,
            'secret_key' => $secretKey,
            'project_id' => $projectId,
            'api_key' => $apiKey,
            'verify_url' => $verifyUrl !== '' ? $verifyUrl : 'https://www.google.com/recaptcha/api/siteverify',
            'min_score' => $minScore,
        ];
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

    private function siteSettings(): array
    {
        if (!class_exists('Database')) {
            return [];
        }

        try {
            $rows = Database::fetchAll(
                "SELECT setting_key, setting_value
                 FROM rc_site_settings
                 WHERE setting_key LIKE 'recaptcha_%'"
            );
        } catch (Throwable) {
            return [];
        }

        $settings = [];
        foreach ($rows as $row) {
            $settings[(string)$row['SETTING_KEY']] = (string)$row['SETTING_VALUE'];
        }

        return $settings;
    }

    private function logCheck(string $actionName, bool $success, ?float $score, string $response): void
    {
        if (!class_exists('Database')) {
            return;
        }

        try {
            $columns = Database::fetchAll(
                "SELECT column_name
                 FROM user_tab_columns
                 WHERE table_name = 'RC_RECAPTCHA_CHECKS'"
            );

            $existing = [];
            foreach ($columns as $column) {
                $existing[(string)$column['COLUMN_NAME']] = true;
            }

            $data = [];
            if (isset($existing['ACTION_NAME'])) $data['action_name'] = substr($actionName, 0, 80);
            if (isset($existing['SUCCESS'])) $data['success'] = $success ? 1 : 0;
            if (isset($existing['IS_SUCCESS'])) $data['is_success'] = $success ? 1 : 0;
            if (isset($existing['SCORE'])) $data['score'] = $score;
            if (isset($existing['IP_ADDRESS'])) $data['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
            if (isset($existing['ERROR_CODES'])) $data['error_codes'] = substr($response, 0, 3900);
            if (isset($existing['PROVIDER_RESPONSE'])) $data['provider_response'] = substr($response, 0, 3900);

            if (empty($data)) {
                return;
            }

            $cols = array_keys($data);
            $sql = 'INSERT INTO rc_recaptcha_checks (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')';
            Database::execute($sql, $data);
        } catch (Throwable) {
            return;
        }
    }
}
