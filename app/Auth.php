<?php
declare(strict_types=1);

require_once __DIR__ . '/Phone.php';
require_once __DIR__ . '/CaptchaService.php';
require_once __DIR__ . '/OutboundMessageService.php';

final class Auth
{
    private const DEV_CODE = '1111';
    private const CODE_TTL_MINUTES = 15;

    private CaptchaService $captchaService;
    private OutboundMessageService $outboundService;

    public function __construct(private Repository $repo)
    {
        $this->captchaService = new CaptchaService();
        $this->outboundService = new OutboundMessageService();
    }

    public function attempt(string $login, string $passwordOrCode, string $recaptchaToken = '', string $emailCode = ''): array
    {
        $captcha = $this->captchaService->verify('login', $recaptchaToken, null);
        if (!$captcha['ok']) {
            return $captcha;
        }

        $login = trim($login);
        $passwordOrCode = trim($passwordOrCode);

        if ($login === '') {
            return ['ok' => false, 'message' => 'Вкажіть email або номер телефону.'];
        }

        $isPhone = $this->isPhone($login);
        $user = $this->findUserInDb($login, $isPhone);

        if (!$user) {
            $this->logAttempt($login, 'login_failed_user_not_found');
            return ['ok' => false, 'message' => 'Користувача не знайдено. Перевірте email/телефон або зареєструйтесь.'];
        }

        if ((int)($user['is_active'] ?? 1) !== 1) {
            return ['ok' => false, 'message' => 'Обліковий запис вимкнено. Зверніться до адміністратора.'];
        }

        if ($isPhone) {
            $phoneCode = $emailCode !== '' ? $emailCode : $passwordOrCode;
            if ($phoneCode === '') {
                return ['ok' => false, 'message' => 'Введіть SMS-код для входу телефоном.'];
            }
            $valid = $this->verifyCode($login, $phoneCode, 'login');
            if (!$valid) {
                $this->logAttempt($login, 'login_failed_phone_code');
                return ['ok' => false, 'message' => 'Код не прийнято. Перевірте, що вводите останній код із SMS для цього номера, або запросіть новий код.'];
            }
            $this->markCodeUsed($login, 'login');
        } else {
            $emailCode = trim($emailCode);
            if ($passwordOrCode === '' || $emailCode === '') {
                return ['ok' => false, 'message' => 'Для входу через email потрібно ввести пароль і код підтвердження з листа.'];
            }
            if (!password_verify($passwordOrCode, (string)($user['password_hash'] ?? ''))) {
                
                
                
                $matchingUsers = Database::fetchAll(
                    "SELECT u.id, u.full_name, u.phone, u.email, u.password_hash, u.is_active, r.code AS role_code
                     FROM rc_users u
                     JOIN rc_roles r ON r.id = u.role_id
                     WHERE LOWER(TRIM(NVL(u.email, ''))) = :email
                     ORDER BY u.id DESC
                     FETCH FIRST 20 ROWS ONLY",
                    ['email' => strtolower(trim($login))]
                );
                $matchedUser = null;
                foreach ($matchingUsers as $candidate) {
                    if (password_verify($passwordOrCode, (string)($candidate['PASSWORD_HASH'] ?? ''))) {
                        $matchedUser = [
                            'id' => (int)$candidate['ID'],
                            'name' => (string)($candidate['FULL_NAME'] ?? 'Користувач'),
                            'phone' => (string)($candidate['PHONE'] ?? ''),
                            'email' => (string)($candidate['EMAIL'] ?? ''),
                            'password_hash' => (string)($candidate['PASSWORD_HASH'] ?? ''),
                            'role' => (string)($candidate['ROLE_CODE'] ?? 'client'),
                            'is_active' => (int)($candidate['IS_ACTIVE'] ?? 1),
                        ];
                        break;
                    }
                }
                if ($matchedUser === null) {
                    $this->logAttempt($login, 'login_failed_email_password');
                    return ['ok' => false, 'message' => 'Невірний пароль.'];
                }
                $user = $matchedUser;
            }
            if (!$this->verifyCode($login, $emailCode, 'login')) {
                $this->logAttempt($login, 'login_failed_email_code');
                return ['ok' => false, 'message' => 'Email-код не прийнято. Запросіть новий код і введіть останній код із листа.'];
            }
            $this->markCodeUsed($login, 'login');
        }

        $this->login($user);
        $this->markLastLogin((int)$user['id']);
        $this->logAttempt($login, 'login_success', (int)$user['id']);

        return ['ok' => true, 'message' => 'Вхід виконано.'];
    }


    public function attemptStaff(string $login, string $password, array $keyFile = [], string $captchaToken = '', string $captchaAnswer = ''): array
    {
        $login = trim($login);
        $password = trim($password);

        $captcha = $this->captchaService->verify('staff_login', $captchaToken, $captchaAnswer !== '' ? $captchaAnswer : null);
        if (!$captcha['ok']) {
            return $captcha;
        }

        if ($login === '' || $password === '') {
            return ['ok' => false, 'message' => 'Вкажіть логін і пароль працівника.'];
        }

        $isPhone = $this->isPhone($login);
        $user = $this->findUserInDb($login, $isPhone);

        if (!$user || !in_array((string)($user['role'] ?? ''), ['manager', 'warehouse', 'admin'], true)) {
            $this->logAttempt($login, 'staff_login_forbidden');
            return ['ok' => false, 'message' => 'Доступ дозволений тільки для адміністратора, менеджера або працівника складу.'];
        }

        if ((int)($user['is_active'] ?? 1) !== 1) {
            return ['ok' => false, 'message' => 'Обліковий запис працівника вимкнено.'];
        }

        if (!password_verify($password, (string)($user['password_hash'] ?? ''))) {
            $this->logAttempt($login, 'staff_login_failed_password', (int)$user['id']);
            return ['ok' => false, 'message' => 'Невірний логін або пароль.'];
        }

        $keyCheck = $this->verifyStaffFileKey((int)$user['id'], $keyFile);
        if (!$keyCheck['ok']) {
            $this->logAttempt($login, 'staff_login_failed_key', (int)$user['id']);
            return $keyCheck;
        }

        $this->login($user);
        $this->markLastLogin((int)$user['id']);
        $this->logAttempt($login, 'staff_login_success', (int)$user['id']);

        return ['ok' => true, 'message' => 'Вхід у панель працівника виконано.'];
    }

    public function register(array $data): array
    {
        $fullNameInput = trim((string)($data['full_name'] ?? ''));
        $lastName = trim((string)($data['last_name'] ?? ''));
        $firstName = trim((string)($data['first_name'] ?? ''));
        $middleName = trim((string)($data['middle_name'] ?? ''));
        if ($fullNameInput !== '' && $lastName === '' && $firstName === '') {
            $parts = preg_split('/\s+/u', $fullNameInput) ?: [];
            $lastName = (string)($parts[0] ?? '');
            $firstName = (string)($parts[1] ?? '');
            $middleName = trim(implode(' ', array_slice($parts, 2)));
        }
        $contact = trim((string)($data['register_login'] ?? $data['contact'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $code = trim((string)($data['code'] ?? ''));
        $captcha = trim((string)($data['captcha'] ?? ''));
        $recaptchaToken = trim((string)($data['g-recaptcha-response'] ?? ''));

        $human = $this->captchaService->verify('register', $recaptchaToken, $captcha);
        if (!$human['ok']) {
            return $human;
        }

        if ($lastName === '' || $firstName === '') {
            return ['ok' => false, 'message' => 'Вкажіть прізвище та імʼя.'];
        }

        if ($contact === '') {
            if ($phone !== '') {
                $contact = $phone;
            } elseif ($email !== '') {
                $contact = $email;
            }
        }

        if ($contact === '') {
            return ['ok' => false, 'message' => 'Вкажіть телефон або email для реєстрації.'];
        }

        $contactIsPhone = $this->isPhone($contact);
        $contactIsEmail = filter_var($contact, FILTER_VALIDATE_EMAIL) !== false;

        if (!$contactIsPhone && !$contactIsEmail) {
            return ['ok' => false, 'message' => 'Логін має бути телефоном або email.'];
        }

        if ($contactIsPhone) {
            $phone = $contact;
        } else {
            $email = strtolower($contact);
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => 'Email має некоректний формат.'];
        }

        if ($phone !== '') {
            $phoneCheck = Phone::normalizeUa($phone);
            if (!$phoneCheck['ok']) {
                return ['ok' => false, 'message' => $phoneCheck['message']];
            }
            $phone = $phoneCheck['phone'];
        }

        if ($email !== '' && strlen($password) < 6) {
            return ['ok' => false, 'message' => 'Для входу через email пароль має містити мінімум 6 символів.'];
        }

        if ($email !== '' && $this->findUserInDb($email, false)) {
            return ['ok' => false, 'message' => 'Користувач із таким email уже існує. Увійдіть у кабінет або скористайтеся відновленням пароля.'];
        }

        if ($phone !== '' && $this->findUserInDb($phone, true)) {
            return ['ok' => false, 'message' => 'Користувач із таким телефоном уже існує. Увійдіть у кабінет або використайте інший номер.'];
        }

        $verificationDestination = $contactIsPhone ? $phone : $email;
        if (!$this->verifyCode($verificationDestination, $code, 'register')) {
            return ['ok' => false, 'message' => 'Код не прийнято. Перевірте, що вводите останній код із SMS/email для цього номера, або запросіть новий код.'];
        }

        $role = Database::fetchOne("SELECT id FROM rc_roles WHERE code = 'client' FETCH FIRST 1 ROWS ONLY");
        if (!$role) {
            return ['ok' => false, 'message' => 'У БД немає ролі client. Спочатку додайте базові ролі.'];
        }

        $fullName = trim($lastName . ' ' . $firstName . ' ' . $middleName);
        $passwordHash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;

        $ok = Database::execute(
            "INSERT INTO rc_users (
                role_id,
                full_name,
                phone,
                email,
                password_hash,
                is_active,
                phone_verified_at,
                email_verified_at
             ) VALUES (
                :role_id,
                :full_name,
                :phone,
                :email,
                :password_hash,
                1,
                CASE WHEN :phone_verified = 1 THEN CURRENT_TIMESTAMP ELSE NULL END,
                CASE WHEN :email_verified = 1 THEN CURRENT_TIMESTAMP ELSE NULL END
             )",
            [
                'role_id' => (int)$role['ID'],
                'full_name' => $fullName,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? strtolower($email) : null,
                'password_hash' => $passwordHash,
                'phone_verified' => $contactIsPhone ? 1 : 0,
                'email_verified' => $contactIsEmail ? 1 : 0,
            ]
        );

        if (!$ok) {
            $error = Database::lastError();
            if ($this->isUniqueConstraintError($error)) {
                return ['ok' => false, 'message' => 'Користувач із таким телефоном або email уже існує. Увійдіть у кабінет або використайте інші дані.'];
            }
            return ['ok' => false, 'message' => 'Не вдалося створити користувача: ' . ($error ?? 'невідома помилка')];
        }

        $created = $this->findUserInDb($contactIsPhone ? $phone : $email, $contactIsPhone);
        if ($created) {
            $this->markCodeUsed($verificationDestination, 'register');
            $this->login($created);
            $this->logAttempt($contact, 'register_success', (int)$created['id']);
        }

        return ['ok' => true, 'message' => 'Реєстрацію виконано. Кабінет створено.'];
    }

    public function sendVerificationCode(string $destination, string $purpose = 'login', string $recaptchaToken = ''): array
    {
        $purpose = in_array($purpose, ['login', 'register', 'password_reset', 'email_verify'], true) ? $purpose : 'login';

        $captcha = $this->captchaService->verify('send_code_' . $purpose, $recaptchaToken, null);
        if (!$captcha['ok']) {
            return $captcha;
        }

        $destination = trim($destination);
        if ($destination === '') {
            return ['ok' => false, 'message' => 'Вкажіть телефон або email для отримання коду.'];
        }

        $type = $this->isPhone($destination) ? 'phone' : 'email';

        if ($type === 'phone') {
            $normalizedPhone = Phone::normalizeUa($destination);
            if (!$normalizedPhone['ok']) {
                return ['ok' => false, 'message' => $normalizedPhone['message']];
            }
            $normalized = $normalizedPhone['phone'];
        } else {
            if (filter_var($destination, FILTER_VALIDATE_EMAIL) === false) {
                return ['ok' => false, 'message' => 'Email має некоректний формат.'];
            }
            $normalized = strtolower($destination);
        }

        if ($purpose === 'register') {
            $existing = $this->findUserInDb($normalized, $type === 'phone');
            if ($existing) {
                return [
                    'ok' => false,
                    'message' => $type === 'phone'
                        ? 'Користувач із таким телефоном уже існує. Увійдіть у кабінет або використайте інший номер.'
                        : 'Користувач із таким email уже існує. Увійдіть у кабінет або скористайтеся відновленням пароля.',
                ];
            }
        }

        if ($purpose === 'login') {
            $existing = $this->findUserInDb($normalized, $type === 'phone');
            if (!$existing) {
                
                return [
                    'ok' => true,
                    'cooldown' => 120,
                    'message' => 'Якщо такий акаунт існує, код підтвердження буде надіслано.',
                ];
            }
        }

        $cooldownSeconds = 120;
        $cooldownKey = 'verification_code_' . $purpose . '_' . sha1($normalized);
        $now = time();
        $lastSentAt = (int)($_SESSION[$cooldownKey] ?? 0);

        if ($lastSentAt > 0 && ($now - $lastSentAt) < $cooldownSeconds) {
            $left = $cooldownSeconds - ($now - $lastSentAt);
            return [
                'ok' => true,
                'already_sent' => true,
                'cooldown' => $left,
                'message' => 'Код уже надіслано. Зачекайте SMS або повторіть запит через ' . $left . ' с.',
            ];
        }

        $recent = $this->recentActiveVerification($normalized, $purpose, $cooldownSeconds);
        if ($recent) {
            $_SESSION[$cooldownKey] = $now;
            return [
                'ok' => true,
                'already_sent' => true,
                'cooldown' => $cooldownSeconds,
                'message' => 'Код уже надіслано. Дочекайтеся SMS, не натискайте кнопку повторно.',
            ];
        }

        $_SESSION[$cooldownKey] = $now;
        
        
        

        $devMode = $this->verificationDevMode();
        $code = $devMode ? ($this->settings()['dev_verification_code'] ?? self::DEV_CODE) : (string)random_int(100000, 999999);
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        
        
        
        $linkToken = ($type === 'email' && $purpose === 'email_verify') ? bin2hex(random_bytes(32)) : null;
        $tokenHash = $linkToken !== null ? hash('sha256', $linkToken) : null;

        $ok = Database::execute(
            "INSERT INTO rc_verification_codes (
                target,
                destination,
                destination_type,
                code_hash,
                token_hash,
                purpose,
                channel,
                provider,
                ip_address,
                user_agent,
                expires_at
             ) VALUES (
                :target,
                :destination,
                :destination_type,
                :code_hash,
                :token_hash,
                :purpose,
                :channel,
                :provider,
                :ip_address,
                :user_agent,
                SYSTIMESTAMP + INTERVAL '15' MINUTE
             )",
            [
                'target' => $normalized,
                'destination' => $normalized,
                'destination_type' => $type,
                'code_hash' => $codeHash,
                'token_hash' => $tokenHash,
                'purpose' => $purpose,
                'channel' => $type === 'phone' ? 'sms' : 'email',
                'provider' => $type === 'phone' ? 'turbosms' : 'smtp',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]
        );

        if (!$ok) {
            unset($_SESSION[$cooldownKey]);
            return ['ok' => false, 'message' => 'Не вдалося створити код підтвердження: ' . (Database::lastError() ?? 'невідома помилка')];
        }

        $send = $this->outboundService->sendVerification($normalized, $type, $code, $purpose, $linkToken);
        $this->updateLastVerificationSendStatus($normalized, $purpose, (string)($send['status'] ?? 'queued'), (string)($send['message'] ?? ''));

        if (empty($send['ok'])) {
            unset($_SESSION[$cooldownKey]);
        }

        return [
            'ok' => (bool)($send['ok'] ?? false),
            'cooldown' => $cooldownSeconds,
            'message' => (string)($send['message'] ?? 'Код підтвердження створено.'),
        ];
    }

    public function verifyEmailLink(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'message' => 'Токен підтвердження відсутній.'];
        }

        $tokenHash = hash('sha256', $token);
        $row = Database::fetchOne(
            "SELECT id, destination, purpose
             FROM rc_verification_codes
             WHERE destination_type = 'email'
               AND token_hash = :token_hash
               AND used_at IS NULL
               AND expires_at > SYSTIMESTAMP
             ORDER BY created_at DESC, id DESC
             FETCH FIRST 1 ROWS ONLY",
            ['token_hash' => $tokenHash]
        );

        if (!$row) {
            return ['ok' => false, 'message' => 'Посилання підтвердження не знайдено або воно прострочене.'];
        }

        $email = strtolower((string)$row['DESTINATION']);
        Database::execute(
            "UPDATE rc_users
             SET email_verified_at = COALESCE(email_verified_at, CURRENT_TIMESTAMP)
             WHERE LOWER(email) = :email",
            ['email' => $email]
        );

        Database::execute(
            "UPDATE rc_verification_codes
             SET used_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            ['id' => (int)$row['ID']]
        );

        return ['ok' => true, 'message' => 'Email підтверджено. Тепер можна увійти в кабінет.'];
    }


    public function requestPasswordReset(string $email, string $recaptchaToken = ''): array
    {
        $captcha = $this->captchaService->verify('password_reset_request', $recaptchaToken, null);
        if (!$captcha['ok']) {
            return $captcha;
        }

        $email = strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => 'Вкажіть коректний email для відновлення пароля.'];
        }

        $user = $this->findUserInDb($email, false);
        $publicMessage = 'Якщо email є в системі, ми надіслали посилання для відновлення пароля.';
        if (!$user) {
            $this->logAttempt($email, 'password_reset_requested_unknown');
            return ['ok' => true, 'message' => $publicMessage];
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $codeHash = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);

        Database::execute(
            "INSERT INTO rc_verification_codes (
                target,
                destination,
                destination_type,
                code_hash,
                token_hash,
                purpose,
                channel,
                provider,
                ip_address,
                user_agent,
                expires_at
             ) VALUES (
                :target,
                :destination,
                'email',
                :code_hash,
                :token_hash,
                'password_reset',
                'email',
                'smtp',
                :ip_address,
                :user_agent,
                SYSTIMESTAMP + INTERVAL '30' MINUTE
             )",
            [
                'target' => 'user:' . (int)$user['id'],
                'destination' => $email,
                'code_hash' => $codeHash,
                'token_hash' => $tokenHash,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]
        );

        $_SESSION['password_reset_tokens'][$tokenHash] = [
            'email' => $email,
            'user_id' => (int)$user['id'],
            'expires' => time() + 3600,
        ];

        $send = $this->outboundService->sendPasswordResetEmail($email, $token);
        $this->updateLastVerificationSendStatus($email, 'password_reset', (string)($send['status'] ?? 'queued'), (string)($send['message'] ?? ''));
        $this->logAttempt($email, 'password_reset_requested', (int)$user['id']);

        return ['ok' => true, 'message' => $publicMessage];
    }

    public function resetPasswordByToken(string $token, string $password, string $recaptchaToken = ''): array
    {
        $captcha = $this->captchaService->verify('reset_password', $recaptchaToken, null);
        if (!$captcha['ok']) {
            return $captcha;
        }

        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'message' => 'Токен відновлення відсутній.'];
        }

        $password = trim($password);
        if (strlen($password) < 6) {
            return ['ok' => false, 'message' => 'Новий пароль має містити мінімум 6 символів.'];
        }

        $tokenHash = hash('sha256', $token);
        $row = Database::fetchOne(
            "SELECT id, target, destination
             FROM rc_verification_codes
             WHERE purpose = 'password_reset'
               AND token_hash = :token_hash
               AND created_at >= CURRENT_TIMESTAMP - NUMTODSINTERVAL(1440, 'MINUTE')
             ORDER BY created_at DESC, id DESC
             FETCH FIRST 1 ROWS ONLY",
            ['token_hash' => $tokenHash]
        );

        if (!$row && !empty($_SESSION['password_reset_tokens'][$tokenHash])) {
            $cached = $_SESSION['password_reset_tokens'][$tokenHash];
            if ((int)($cached['expires'] ?? 0) >= time()) {
                $row = [
                    'ID' => 0,
                    'TARGET' => 'user:' . (int)($cached['user_id'] ?? 0),
                    'DESTINATION' => (string)($cached['email'] ?? ''),
                ];
            }
        }

        if (!$row) {
            return ['ok' => false, 'message' => 'Посилання відновлення не знайдено або воно прострочене.'];
        }

        $email = strtolower(trim((string)$row['DESTINATION']));
        if ($email === '') {
            return ['ok' => false, 'message' => 'У токені відновлення не знайдено email акаунта. Запросіть нове посилання.'];
        }

        $target = trim((string)($row['TARGET'] ?? ''));
        $targetUserId = 0;
        if (preg_match('/^user:(\d+)$/', $target, $m)) {
            $targetUserId = (int)$m[1];
        }

        $user = null;
        if ($targetUserId > 0) {
            $user = Database::fetchOne(
                "SELECT id, password_hash
                 FROM rc_users
                 WHERE id = :id
                   AND LOWER(TRIM(NVL(email, ''))) = :email
                 FETCH FIRST 1 ROWS ONLY",
                ['id' => $targetUserId, 'email' => $email]
            );
        }

        if (!$user) {
            $user = Database::fetchOne(
                "SELECT id, password_hash
                 FROM rc_users
                 WHERE LOWER(TRIM(NVL(email, ''))) = :email
                 ORDER BY id DESC
                 FETCH FIRST 1 ROWS ONLY",
                ['email' => $email]
            );
        }

        if (!$user) {
            return ['ok' => false, 'message' => 'Акаунт для цього email не знайдено. Перевірте email або зареєструйтесь заново.'];
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        
        
        $affected = Database::executeAffected(
            "UPDATE rc_users
             SET password_hash = :password_hash
             WHERE LOWER(TRIM(NVL(email, ''))) = :email",
            ['password_hash' => $passwordHash, 'email' => $email]
        );

        if ($affected === null) {
            return ['ok' => false, 'message' => 'Пароль не оновлено через помилку БД: ' . (Database::lastError() ?? 'невідома помилка')];
        }

        if ($affected < 1) {
            return ['ok' => false, 'message' => 'Пароль не оновлено: акаунт не був змінений. Запросіть нове посилання або зверніться до адміністратора.'];
        }

        $checkUsers = Database::fetchAll(
            "SELECT id, password_hash
             FROM rc_users
             WHERE LOWER(TRIM(NVL(email, ''))) = :email
             FETCH FIRST 20 ROWS ONLY",
            ['email' => $email]
        );
        foreach ($checkUsers as $checkUser) {
            if (!password_verify($password, (string)($checkUser['PASSWORD_HASH'] ?? ''))) {
                return ['ok' => false, 'message' => 'Пароль записано некоректно для одного з акаунтів з цим email. Спробуйте ще раз або зверніться до адміністратора.'];
            }
        }

        if ((int)($row['ID'] ?? 0) > 0) {
            Database::execute(
                "UPDATE rc_verification_codes
                 SET used_at = CURRENT_TIMESTAMP
                 WHERE id = :id",
                ['id' => (int)$row['ID']]
            );
        }
        unset($_SESSION['password_reset_tokens'][$tokenHash]);

        $this->logAttempt($email, 'password_reset_completed', (int)$user['ID']);
        return ['ok' => true, 'message' => 'Пароль оновлено. Для email-входу тепер введіть новий пароль, натисніть «Отримати код» і введіть код з листа.'];
    }

    private function verifyStaffFileKey(int $userId, array $keyFile): array
    {
        if ($userId <= 0) {
            return ['ok' => false, 'message' => 'Працівника не знайдено.'];
        }

        $keyRows = [];
        try {
            $table = Database::fetchOne("SELECT COUNT(*) AS cnt FROM user_tables WHERE table_name = 'RC_STAFF_FILE_KEYS'");
            if ($table && (int)($table['CNT'] ?? 0) > 0) {
                $keyRows = Database::fetchAll(
                    "SELECT key_hash, is_active
                     FROM rc_staff_file_keys
                     WHERE user_id = :user_id AND NVL(is_active, 1) = 1",
                    ['user_id' => $userId]
                );
            }
        } catch (Throwable) {
            $keyRows = [];
        }

        
        
        if (!$keyRows) {
            return ['ok' => true, 'message' => 'Файловий ключ ще не налаштований для цього працівника.'];
        }

        if (empty($keyFile['tmp_name']) || !is_uploaded_file((string)$keyFile['tmp_name'])) {
            return ['ok' => false, 'message' => 'Додайте персональний файловий ключ працівника.'];
        }

        $content = file_get_contents((string)$keyFile['tmp_name']);
        if ($content === false || trim($content) === '') {
            return ['ok' => false, 'message' => 'Файловий ключ порожній або не читається.'];
        }

        
        
        
        
        $normalized = str_replace(["\r\n", "\r"], "\n", (string)$content);
        $trimmed = trim($normalized);
        $candidates = array_unique([
            (string)$content,
            $normalized,
            $trimmed,
            $trimmed . "\n",
            $trimmed . "\r\n",
        ]);
        $hashes = array_map(static fn($value) => hash('sha256', $value), $candidates);

        foreach ($keyRows as $row) {
            $expected = strtolower(trim((string)($row['KEY_HASH'] ?? '')));
            foreach ($hashes as $hash) {
                if ($expected !== '' && hash_equals($expected, strtolower($hash))) {
                    return ['ok' => true, 'message' => 'Файловий ключ підтверджено.'];
                }
            }
        }

        return ['ok' => false, 'message' => 'Файловий ключ не підходить для цього працівника. Перевірте, що завантажений TXT-файл належить саме цьому email/ролі або створіть новий ключ.'];
    }

    public function logout(): void
    {
        unset($_SESSION['user']);
        session_regenerate_id(true);
    }

    public function recaptchaSiteKey(): string
    {
        return $this->captchaService->siteKey();
    }

    public function recaptchaProvider(): string
    {
        return $this->captchaService->provider();
    }

    private function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int)($user['id'] ?? 0),
            'name' => (string)($user['name'] ?? 'Користувач'),
            'email' => (string)($user['email'] ?? ''),
            'phone' => (string)($user['phone'] ?? ''),
            'role' => (string)($user['role'] ?? 'client'),
        ];
    }

    private function findUserInDb(string $login, bool $isPhone): ?array
    {
        $value = $isPhone ? $this->normalizePhone($login) : strtolower(trim($login));

        $where = $isPhone
            ? "REGEXP_REPLACE(NVL(u.phone, ''), '[^0-9]', '') = :login"
            : "LOWER(TRIM(NVL(u.email, ''))) = :login";

        $row = Database::fetchOne(
            "SELECT
                u.id,
                u.full_name,
                u.phone,
                u.email,
                u.password_hash,
                u.is_active,
                r.code AS role_code
             FROM rc_users u
             JOIN rc_roles r ON r.id = u.role_id
             WHERE {$where}
             ORDER BY u.id DESC
             FETCH FIRST 1 ROWS ONLY",
            ['login' => $value]
        );

        if (!$row) {
            return null;
        }

        return [
            'id' => (int)$row['ID'],
            'name' => (string)($row['FULL_NAME'] ?? 'Користувач'),
            'phone' => (string)($row['PHONE'] ?? ''),
            'email' => (string)($row['EMAIL'] ?? ''),
            'password_hash' => (string)($row['PASSWORD_HASH'] ?? ''),
            'role' => (string)($row['ROLE_CODE'] ?? 'client'),
            'is_active' => (int)($row['IS_ACTIVE'] ?? 1),
        ];
    }

    private function verifyCode(string $destination, string $code, string $purpose): bool
    {
        $rawCode = trim($code);
        $digitCode = preg_replace('/[^0-9]/', '', $rawCode) ?? '';
        $codesToTry = array_values(array_unique(array_filter([$rawCode, $digitCode], static fn($value) => $value !== '')));

        if ($codesToTry === []) {
            return false;
        }

        if ($this->verificationDevMode()) {
            $devCode = (string)($this->settings()['dev_verification_code'] ?? self::DEV_CODE);
            foreach ($codesToTry as $candidate) {
                if (hash_equals($devCode, $candidate)) {
                    return true;
                }
            }
        }

        $purposeList = $this->verificationPurposeList($purpose);
        $isPhone = $this->isPhone($destination);

        if ($isPhone) {
            $displayPhone = $this->normalizePhoneDisplay($destination);
            $digitsPhone = $this->normalizePhone($destination);
            $rows = Database::fetchAll(
                "SELECT id, code_hash, purpose, created_at
                 FROM rc_verification_codes
                 WHERE purpose IN (:purpose_1, :purpose_2)
                   AND used_at IS NULL
                   AND created_at >= CURRENT_TIMESTAMP - NUMTODSINTERVAL(:ttl_minutes, 'MINUTE')
                   AND (
                        destination = :display_phone
                     OR target = :display_phone_2
                     OR REGEXP_REPLACE(NVL(destination, ''), '[^0-9]', '') = :digits_phone
                     OR REGEXP_REPLACE(NVL(target, ''), '[^0-9]', '') = :digits_phone_2
                   )
                 ORDER BY created_at DESC, id DESC
                 FETCH FIRST 30 ROWS ONLY",
                [
                    'purpose_1' => $purposeList[0],
                    'purpose_2' => $purposeList[1],
                    'ttl_minutes' => self::CODE_TTL_MINUTES,
                    'display_phone' => $displayPhone,
                    'display_phone_2' => $displayPhone,
                    'digits_phone' => $digitsPhone,
                    'digits_phone_2' => $digitsPhone,
                ]
            );
        } else {
            $email = strtolower(trim($destination));
            $rows = Database::fetchAll(
                "SELECT id, code_hash, purpose, created_at
                 FROM rc_verification_codes
                 WHERE purpose IN (:purpose_1, :purpose_2)
                   AND used_at IS NULL
                   AND created_at >= CURRENT_TIMESTAMP - NUMTODSINTERVAL(:ttl_minutes, 'MINUTE')
                   AND (LOWER(NVL(destination, '')) = :email OR LOWER(NVL(target, '')) = :email_2)
                 ORDER BY created_at DESC, id DESC
                 FETCH FIRST 30 ROWS ONLY",
                [
                    'purpose_1' => $purposeList[0],
                    'purpose_2' => $purposeList[1],
                    'ttl_minutes' => self::CODE_TTL_MINUTES,
                    'email' => $email,
                    'email_2' => $email,
                ]
            );
        }

        foreach ($rows as $row) {
            $hash = (string)($row['CODE_HASH'] ?? '');
            if ($hash === '') {
                continue;
            }

            foreach ($codesToTry as $candidate) {
                if (password_verify($candidate, $hash)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function markCodeUsed(string $destination, string $purpose): void
    {
        $purposeList = $this->verificationPurposeList($purpose);

        if ($this->isPhone($destination)) {
            $displayPhone = $this->normalizePhoneDisplay($destination);
            $digitsPhone = $this->normalizePhone($destination);
            Database::execute(
                "UPDATE rc_verification_codes
                 SET used_at = CURRENT_TIMESTAMP
                 WHERE purpose IN (:purpose_1, :purpose_2)
                   AND used_at IS NULL
                   AND (
                        destination = :display_phone
                     OR target = :display_phone_2
                     OR REGEXP_REPLACE(NVL(destination, ''), '[^0-9]', '') = :digits_phone
                     OR REGEXP_REPLACE(NVL(target, ''), '[^0-9]', '') = :digits_phone_2
                   )",
                [
                    'purpose_1' => $purposeList[0],
                    'purpose_2' => $purposeList[1],
                    'display_phone' => $displayPhone,
                    'display_phone_2' => $displayPhone,
                    'digits_phone' => $digitsPhone,
                    'digits_phone_2' => $digitsPhone,
                ]
            );
            return;
        }

        $email = strtolower(trim($destination));
        Database::execute(
            "UPDATE rc_verification_codes
             SET used_at = CURRENT_TIMESTAMP
             WHERE purpose IN (:purpose_1, :purpose_2)
               AND used_at IS NULL
               AND (LOWER(NVL(destination, '')) = :email OR LOWER(NVL(target, '')) = :email_2)",
            [
                'purpose_1' => $purposeList[0],
                'purpose_2' => $purposeList[1],
                'email' => $email,
                'email_2' => $email,
            ]
        );
    }

    private function isUniqueConstraintError(?string $error): bool
    {
        $error = (string)$error;
        return str_contains($error, 'ORA-00001') || stripos($error, 'unique constraint') !== false;
    }

    private function settings(): array
    {
        $rows = Database::fetchAll(
            "SELECT setting_key, setting_value
             FROM rc_site_settings
             WHERE setting_key IN ('dev_verification_code', 'verification_dev_mode', 'recaptcha_enabled', 'recaptcha_site_key', 'recaptcha_secret_key')"
        );

        $settings = [];
        foreach ($rows as $row) {
            $settings[(string)$row['SETTING_KEY']] = (string)$row['SETTING_VALUE'];
        }
        return $settings;
    }

    private function verificationDevMode(): bool
    {
        $settings = $this->settings();
        return (string)($settings['verification_dev_mode'] ?? '1') === '1';
    }

    private function recentActiveVerification(string $destination, string $purpose, int $seconds): ?array
    {
        $purposeList = $this->verificationPurposeList($purpose);

        if ($this->isPhone($destination)) {
            $displayPhone = $this->normalizePhoneDisplay($destination);
            $digitsPhone = $this->normalizePhone($destination);
            $row = Database::fetchOne(
                "SELECT id, send_status, created_at
                 FROM rc_verification_codes
                 WHERE purpose IN (:purpose_1, :purpose_2)
                   AND used_at IS NULL
                   AND created_at >= CURRENT_TIMESTAMP - NUMTODSINTERVAL(:seconds, 'SECOND')
                   AND (
                        destination = :display_phone
                     OR target = :display_phone_2
                     OR REGEXP_REPLACE(NVL(destination, ''), '[^0-9]', '') = :digits_phone
                     OR REGEXP_REPLACE(NVL(target, ''), '[^0-9]', '') = :digits_phone_2
                   )
                 ORDER BY created_at DESC, id DESC
                 FETCH FIRST 1 ROWS ONLY",
                [
                    'purpose_1' => $purposeList[0],
                    'purpose_2' => $purposeList[1],
                    'seconds' => $seconds,
                    'display_phone' => $displayPhone,
                    'display_phone_2' => $displayPhone,
                    'digits_phone' => $digitsPhone,
                    'digits_phone_2' => $digitsPhone,
                ]
            );
            return $row ?: null;
        }

        $email = strtolower(trim($destination));
        $row = Database::fetchOne(
            "SELECT id, send_status, created_at
             FROM rc_verification_codes
             WHERE purpose IN (:purpose_1, :purpose_2)
               AND used_at IS NULL
               AND created_at >= CURRENT_TIMESTAMP - NUMTODSINTERVAL(:seconds, 'SECOND')
               AND (LOWER(NVL(destination, '')) = :email OR LOWER(NVL(target, '')) = :email_2)
             ORDER BY created_at DESC, id DESC
             FETCH FIRST 1 ROWS ONLY",
            [
                'purpose_1' => $purposeList[0],
                'purpose_2' => $purposeList[1],
                'seconds' => $seconds,
                'email' => $email,
                'email_2' => $email,
            ]
        );

        return $row ?: null;
    }

    private function verificationPurposeList(string $purpose): array
    {
        if (in_array($purpose, ['login', 'register'], true)) {
            return ['login', 'register'];
        }

        return [$purpose, $purpose];
    }

    private function expirePreviousVerificationCodes(string $destination, string $purpose): void
    {
        Database::execute(
            "UPDATE rc_verification_codes
             SET used_at = COALESCE(used_at, CURRENT_TIMESTAMP)
             WHERE (destination = :destination OR target = :destination)
               AND purpose = :purpose
               AND used_at IS NULL
               AND expires_at > SYSTIMESTAMP",
            [
                'destination' => $destination,
                'purpose' => $purpose,
            ]
        );
    }

    private function updateLastVerificationSendStatus(string $destination, string $purpose, string $status, string $message): void
    {
        Database::execute(
            "UPDATE rc_verification_codes
             SET send_status = :send_status,
                 send_error = :send_error,
                 sent_at = CASE WHEN :send_status_2 = 'sent' THEN CURRENT_TIMESTAMP ELSE sent_at END
             WHERE id = (
                SELECT id
                FROM rc_verification_codes
                WHERE destination = :destination
                  AND purpose = :purpose
                ORDER BY created_at DESC, id DESC
                FETCH FIRST 1 ROWS ONLY
             )",
            [
                'send_status' => substr($status, 0, 40),
                'send_status_2' => substr($status, 0, 40),
                'send_error' => $status === 'sent' ? null : substr($message, 0, 1000),
                'destination' => $destination,
                'purpose' => $purpose,
            ]
        );
    }

    private function markLastLogin(int $userId): void
    {
        Database::execute(
            "UPDATE rc_users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id",
            ['id' => $userId]
        );
    }

    private function logAttempt(string $login, string $status, ?int $userId = null): void
    {
        Database::execute(
            "INSERT INTO rc_auth_attempts (user_id, login_value, status, ip_address, user_agent)
             VALUES (:user_id, :login_value, :status, :ip_address, :user_agent)",
            [
                'user_id' => $userId,
                'login_value' => substr($login, 0, 180),
                'status' => $status,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]
        );
    }

    private function isPhone(string $value): bool
    {
        $check = Phone::normalizeUa($value);
        return (bool)$check['ok'];
    }

    private function normalizePhone(string $phone): string
    {
        $check = Phone::normalizeUa($phone);
        if ($check['ok']) {
            return preg_replace('/[^0-9]/', '', (string)$check['phone']) ?? '';
        }

        return preg_replace('/[^0-9]/', '', $phone) ?? '';
    }

    private function normalizePhoneDisplay(string $phone): string
    {
        $check = Phone::normalizeUa($phone);
        return $check['ok'] ? (string)$check['phone'] : $phone;
    }
}
