<?php
declare(strict_types=1);


ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
error_reporting(E_ALL);

$__forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
$__isHttps = $__forwardedProto === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if ($__isHttps) {
    ini_set('session.cookie_secure', '1');
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $__isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
    if ($__isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
    $csp = "default-src 'self'; "
        . "base-uri 'self'; object-src 'none'; frame-ancestors 'self'; "
        . "img-src 'self' data: blob: https:; "
        . "font-src 'self' data: https://fonts.gstatic.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://www.gstatic.com; "
        . "script-src 'self' 'unsafe-inline' https://www.google.com https://www.gstatic.com https://www.recaptcha.net; "
        . "frame-src https://www.google.com https://www.recaptcha.net; "
        . "connect-src 'self' https://www.google.com https://www.recaptcha.net https://api.novaposhta.ua https://www.delivery-auto.com; "
        . "form-action 'self' https://www.liqpay.ua https://secure.wayforpay.com;";
    if ($__isHttps) {
        $csp .= ' upgrade-insecure-requests;';
    }
    header('Content-Security-Policy: ' . $csp);
}

require_once __DIR__ . '/app/helpers.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/Repository.php';
require_once __DIR__ . '/app/Auth.php';
require_once __DIR__ . '/app/CaptchaService.php';
require_once __DIR__ . '/app/OutboundMessageService.php';
require_once __DIR__ . '/app/SecurityService.php';
require_once __DIR__ . '/app/AdminService.php';
require_once __DIR__ . '/app/CartService.php';
require_once __DIR__ . '/app/AccountService.php';
require_once __DIR__ . '/app/SupportService.php';
require_once __DIR__ . '/app/DeliveryService.php';
require_once __DIR__ . '/app/PaymentService.php';
require_once __DIR__ . '/app/AnalyticsService.php';
require_once __DIR__ . '/app/NotificationService.php';
require_once __DIR__ . '/app/WarehouseService.php';
require_once __DIR__ . '/app/BusinessClientService.php';
require_once __DIR__ . '/app/PwaService.php';
require_once __DIR__ . '/app/IntegrationService.php';
require_once __DIR__ . '/app/NewsletterService.php';
require_once __DIR__ . '/app/ReviewService.php';
require_once __DIR__ . '/app/AccountingExportService.php';




$requestPathForMedia = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($requestPathForMedia, '/uploads/')) {
    $relativeMedia = rawurldecode(substr($requestPathForMedia, strlen('/uploads/')));
    $relativeMedia = str_replace('\\', '/', $relativeMedia);
    if ($relativeMedia === '' || str_contains($relativeMedia, '..')) {
        http_response_code(404);
        exit;
    }
    if (str_starts_with($relativeMedia, 'calculations/')) {
        http_response_code(404);
        exit;
    }
    $uploadsRoot = realpath(__DIR__ . '/uploads');
    $mediaFile = $uploadsRoot !== false ? realpath($uploadsRoot . '/' . $relativeMedia) : false;
    if ($uploadsRoot === false || $mediaFile === false || !str_starts_with($mediaFile, $uploadsRoot . DIRECTORY_SEPARATOR) || !is_file($mediaFile)) {
        http_response_code(404);
        exit;
    }
    $ext = strtolower(pathinfo($mediaFile, PATHINFO_EXTENSION));
    
    $types = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'pdf' => 'application/pdf',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    if (!isset($types[$ext])) {
        http_response_code(404);
        exit;
    }
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $types[$ext]);
    header('Content-Length: ' . filesize($mediaFile));
    header('Cache-Control: public, max-age=86400');
    readfile($mediaFile);
    exit;
}

$fallbackData = require __DIR__ . '/app/data.php';
$repo = new Repository($fallbackData);
$auth = new Auth($repo);
$adminService = new AdminService();
$cartService = new CartService();
$accountService = new AccountService($cartService);
$supportService = new SupportService();
$deliveryService = new DeliveryService();
$paymentService = new PaymentService();
$analyticsService = new AnalyticsService();
$notificationService = new NotificationService();
$warehouseService = new WarehouseService();
$businessService = new BusinessClientService();
$pwaService = new PwaService();
$integrationService = new IntegrationService();
$newsletterService = new NewsletterService();
$reviewService = new ReviewService();
$accountingExportService = new AccountingExportService();
$securityService = new SecurityService();
$captchaService = new CaptchaService();
$flash = null;
$securityState = $securityService->trackRequest(current_user());

$callbackRoute = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($callbackRoute, ['payment-callback-liqpay', 'payment-callback-wayforpay'], true)) {
    if ($callbackRoute === 'payment-callback-liqpay') {
        $result = $integrationService->handleLiqPayCallback($_POST);
        http_response_code($result['ok'] ? 200 : 400);
        echo $result['ok'] ? 'OK' : $result['message'];
        exit;
    }

    $result = $integrationService->handleWayForPayCallback($_POST);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $user = current_user();

    if ($action === 'staff_login') {
        $result = $auth->attemptStaff(
            (string)($_POST['login'] ?? ''),
            (string)($_POST['password'] ?? ''),
            $_FILES['staff_key_file'] ?? [],
            (string)($_POST['g-recaptcha-response'] ?? ''),
            (string)($_POST['staff_captcha'] ?? '')
        );
        $_SESSION['flash'] = $result['message'];
        redirect_to(!empty($result['ok']) ? '/admin' : '/staff');
    }

    if ($action === 'login') {
        $passwordValue = (string)($_POST['password'] ?? $_POST['secret'] ?? '');
        $emailCodeValue = (string)($_POST['code'] ?? '');
        $result = $auth->attempt(
            (string)($_POST['login'] ?? ''),
            $passwordValue,
            (string)($_POST['g-recaptcha-response'] ?? ''),
            $emailCodeValue
        );
        $_SESSION['flash'] = $result['message'];
        redirect_to('/account');
    }

    if ($action === 'auth_send_code') {
        $result = $auth->sendVerificationCode(
            (string)($_POST['destination'] ?? ''),
            (string)($_POST['purpose'] ?? 'login'),
            (string)($_POST['g-recaptcha-response'] ?? '')
        );

        $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $_SESSION['flash'] = $result['message'];
        $returnTo = (string)($_POST['return_to'] ?? '/account');
        if (!str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            $returnTo = '/account';
        }
        redirect_to($returnTo);
    }

    if ($action === 'register') {
        $result = $auth->register([
            'full_name' => (string)($_POST['full_name'] ?? ''),
            'last_name' => (string)($_POST['last_name'] ?? ''),
            'first_name' => (string)($_POST['first_name'] ?? ''),
            'middle_name' => (string)($_POST['middle_name'] ?? ''),
            'register_login' => (string)($_POST['register_login'] ?? ''),
            'phone' => (string)($_POST['phone'] ?? ''),
            'email' => (string)($_POST['email'] ?? ''),
            'password' => (string)($_POST['password'] ?? ''),
            'code' => (string)($_POST['code'] ?? ''),
            'captcha' => (string)($_POST['captcha'] ?? ''),
            'g-recaptcha-response' => (string)($_POST['g-recaptcha-response'] ?? ''),
        ]);
        $_SESSION['flash'] = $result['message'];
        redirect_to('/account');
    }

    if ($action === 'auth_password_reset_request') {
        $result = $auth->requestPasswordReset(
            (string)($_POST['email'] ?? ''),
            (string)($_POST['g-recaptcha-response'] ?? '')
        );
        $_SESSION['flash'] = $result['message'];
        redirect_to('/account');
    }

    if ($action === 'auth_reset_password_confirm') {
        $result = $auth->resetPasswordByToken(
            (string)($_POST['token'] ?? ''),
            (string)($_POST['password'] ?? ''),
            (string)($_POST['g-recaptcha-response'] ?? '')
        );
        $_SESSION['flash'] = $result['message'];
        redirect_to('/account');
    }

    if ($action === 'logout') {
        $auth->logout();
        $_SESSION['flash'] = 'Ви вийшли з кабінету.';
        redirect_to('/account');
    }

    if ($action === 'cart_add') {
        $result = $cartService->addProduct((int)($_POST['product_id'] ?? 0), (float)($_POST['quantity'] ?? 1), $user);
        if (!empty($result['ok'])) {
            $analyticsService->track('cart_add', 'product', (int)($_POST['product_id'] ?? 0), ['quantity' => (float)($_POST['quantity'] ?? 1)], $user);
        }
        $_SESSION['flash'] = $result['message'];
        redirect_to($cartService->safeReturn((string)($_POST['return_to'] ?? '/cart')));
    }

    if ($action === 'cart_update') {
        $result = $cartService->updateItem((int)($_POST['item_id'] ?? 0), (float)($_POST['quantity'] ?? 1), $user);
        $_SESSION['flash'] = $result['message'];
        redirect_to('/cart');
    }

    if ($action === 'cart_remove') {
        $result = $cartService->removeItem((int)($_POST['item_id'] ?? 0), $user);
        $_SESSION['flash'] = $result['message'];
        redirect_to('/cart');
    }

    if ($action === 'cart_clear') {
        $result = $cartService->clear($user);
        $_SESSION['flash'] = $result['message'];
        redirect_to('/cart');
    }

    if ($action === 'order_create') {
        if (!$user || empty($user['id'])) {
            $_SESSION['flash'] = 'Оформлення замовлення з кошика доступне тільки після входу або реєстрації.';
            redirect_to('/account');
        }
        $captchaResult = $captchaService->verify('checkout', (string)($_POST['g-recaptcha-response'] ?? ''), null);
        if (!$captchaResult['ok']) {
            $_SESSION['flash'] = $captchaResult['message'];
            redirect_to('/checkout');
        }

        $result = $cartService->createOrder($_POST, $user);
        $_SESSION['flash'] = $result['message'];
        if (!empty($result['ok']) && !empty($result['order_id'])) {
            $analyticsService->track('order_create', 'order', (int)$result['order_id'], ['payment_type' => (string)($_POST['payment_type'] ?? 'cash')], $user);
            $paymentService->ensureForOrder((int)$result['order_id'], (string)($_POST['payment_type'] ?? 'cash'), $user);
            $integrationService->preparePaymentForOrder((int)$result['order_id']);
            $notificationService->notifyNewOrder((int)$result['order_id']);
            redirect_to('/order-success?id=' . (int)$result['order_id']);
        }
        redirect_to('/checkout');
    }

    if ($action === 'quick_order') {
        $name = trim((string)($_POST['name'] ?? ($user['name'] ?? '')));
        $phone = trim((string)($_POST['phone'] ?? ($user['phone'] ?? '')));
        $productId = (int)($_POST['product_id'] ?? 0);
        $productName = trim((string)($_POST['product_name'] ?? ''));
        if ($name === '' || $phone === '') {
            $_SESSION['flash'] = 'Для купівлі в 1 клік вкажіть імʼя та телефон.';
        } else {
            $comment = 'Купити в 1 клік' . ($productName !== '' ? ': ' . $productName : '') . ($productId > 0 ? ' (ID ' . $productId . ')' : '');
            Database::execute(
                "INSERT INTO rc_callback_requests (user_id, name, phone, preferred_time, comment_text, status, source)
                 VALUES (:user_id, :name, :phone, :preferred_time, :comment_text, 'new', 'one_click')",
                [
                    'user_id' => !empty($user['id']) ? (int)$user['id'] : null,
                    'name' => $name,
                    'phone' => $phone,
                    'preferred_time' => null,
                    'comment_text' => $comment,
                ]
            );
            $_SESSION['flash'] = 'Заявку “1 клік” надіслано менеджеру.';
        }
        redirect_to('/catalog');
    }

    if ($action === 'newsletter_subscribe') {
        $result = $newsletterService->subscribe($_POST, $user);
        $_SESSION['flash'] = $result['message'];
        $returnTo = (string)($_POST['return_to'] ?? '/');
        if (!str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            $returnTo = '/';
        }
        redirect_to($returnTo);
    }

    if (str_starts_with($action, 'review_')) {
        $result = $reviewService->handle($action, $_POST, $user);
        $_SESSION['flash'] = $result['message'];
        $returnTo = (string)($_POST['return_to'] ?? '/account?tab=orders');
        if (!str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            $returnTo = '/account?tab=orders';
        }
        redirect_to($returnTo);
    }

    if (str_starts_with($action, 'admin_')) {
        $result = $adminService->handle($action, $_POST, $user);
        $returnTo = (string)($_POST['return_to'] ?? ($result['return_to'] ?? '/admin'));
        if (!str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            $returnTo = '/admin';
        }

        $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => !empty($result['ok']),
                'message' => (string)($result['message'] ?? ''),
                'return_to' => $returnTo,
                'action' => $action,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $_SESSION['flash'] = $result['message'];
        redirect_to($returnTo);
    }

    if (
        str_starts_with($action, 'account_')
        || str_starts_with($action, 'wishlist_')
        || str_starts_with($action, 'comparison_')
        || $action === 'repeat_order'
    ) {
        $result = $accountService->handle($action, $_POST, $user);
        if (!empty($result['ok'])) {
            if (str_starts_with($action, 'wishlist_')) {
                $analyticsService->track($action === 'wishlist_add' ? 'wishlist_add' : 'wishlist_change', 'product', (int)($_POST['product_id'] ?? 0), [], $user);
            } elseif (str_starts_with($action, 'comparison_')) {
                $analyticsService->track($action === 'comparison_add' ? 'comparison_add' : 'comparison_change', 'product', (int)($_POST['product_id'] ?? 0), [], $user);
            } elseif ($action === 'repeat_order') {
                $analyticsService->track('repeat_order', 'order', (int)($_POST['order_id'] ?? 0), [], $user);
            }
        }
        $_SESSION['flash'] = $result['message'];
        $returnTo = (string)($_POST['return_to'] ?? '/account');
        if (!str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            $returnTo = '/account';
        }
        redirect_to($returnTo);
    }


    if (str_starts_with($action, 'support_')) {
        $result = $supportService->handle($action, $_POST, $user);
        $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        if ($isAjax && $action === 'support_chat_create') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }
        $_SESSION['flash'] = $result['message'];
        $returnTo = (string)($_POST['return_to'] ?? '/support');
        if (!str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            $returnTo = '/support';
        }
        redirect_to($returnTo);
    }

    if (str_starts_with($action, 'delivery_')) {
        try {
            $result = $deliveryService->handle($action, $_POST, $user);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'message' => 'Помилка доставки: ' . $e->getMessage()];
        }
        $_SESSION['flash'] = $result['message'];
        $returnTo = (string)($_POST['return_to'] ?? '/admin#delivery');
        if (!str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            $returnTo = '/admin';
        }
        redirect_to($returnTo);
    }

    if (str_starts_with($action, 'payment_')) {
        $result = $paymentService->handle($action, $_POST, $user);
        $_SESSION['flash'] = $result['message'];
        $returnTo = (string)($_POST['return_to'] ?? '/account');
        if (!str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            $returnTo = '/account';
        }
        redirect_to($returnTo);
    }

    if (str_starts_with($action, 'analytics_')) {
        $result = $analyticsService->handle($action, $_POST, $user);
        $_SESSION['flash'] = $result['message'];
        $returnTo = (string)($_POST['return_to'] ?? '/admin#analytics');
        if (!str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            $returnTo = '/admin';
        }
        redirect_to($returnTo);
    }

    if (str_starts_with($action, 'notification_')) {
        try {
            $result = $notificationService->handle($action, $_POST, $user);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'message' => 'Помилка сповіщень: ' . $e->getMessage()];
        }
        $_SESSION['flash'] = $result['message'];
        $returnTo = (string)($_POST['return_to'] ?? '/admin#notifications');
        if (!str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            $returnTo = '/admin';
        }
        redirect_to($returnTo);
    }

    if (str_starts_with($action, 'integration_')) {
        try {
            $result = $integrationService->handle($action, $_POST, $user);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'message' => 'Помилка інтеграції: ' . $e->getMessage()];
        }
        $_SESSION['flash'] = $result['message'];
        $returnTo = (string)($_POST['return_to'] ?? '/admin#integrations');
        if (!str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            $returnTo = '/admin';
        }
        redirect_to($returnTo);
    }


    if (str_starts_with($action, 'business_')) {
        $result = $businessService->handle($action, $_POST, $user);
        $_SESSION['flash'] = $result['message'];
        $returnTo = (string)($_POST['return_to'] ?? '/wholesale');
        if (!str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            $returnTo = '/wholesale';
        }
        redirect_to($returnTo);
    }


    if (str_starts_with($action, 'pwa_')) {
        $result = $pwaService->handle($action, $_POST, $user);
        $_SESSION['flash'] = $result['message'];
        $returnTo = (string)($_POST['return_to'] ?? '/pwa');
        if (!str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            $returnTo = '/pwa';
        }
        redirect_to($returnTo);
    }

    if (str_starts_with($action, 'warehouse_')) {
        $result = $warehouseService->handle($action, $_POST, $user);
        $_SESSION['flash'] = $result['message'];
        $returnTo = (string)($_POST['return_to'] ?? '/admin');
        if (!str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            $returnTo = '/admin';
        }
        redirect_to($returnTo);
    }

}

if (!empty($_SESSION['flash'])) {
    $flash = (string)$_SESSION['flash'];
    unset($_SESSION['flash']);
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$route = trim($path, '/');
$route = $route === '' ? 'home' : $route;
if ($route === 'staff') {
    $route = 'staff-login';
}

if ($route === 'verify-email') {
    $result = $auth->verifyEmailLink((string)($_GET['token'] ?? ''));
    $_SESSION['flash'] = $result['message'];
    redirect_to('/account');
}

$allowed = ['home','catalog','product','cart','checkout','order-success','payment-result','payment-gateway','account','support','wholesale','delivery','admin','notifications','contacts','db-check','reset-password','staff-login'];
$page = in_array($route, $allowed, true) ? $route : '404';
$currentRoute = $page;

if ($route === 'admin-staff-key-download') {
    $current = current_user();
    if (!$current || ($current['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo 'Ключ недоступний.';
        exit;
    }

    $keyId = (int)($_GET['id'] ?? 0);
    $download = null;

    if ($keyId > 0) {
        $row = Database::fetchOne(
            "SELECT id, user_id, label, key_file_content, created_at
             FROM rc_staff_file_keys
             WHERE id = :id",
            ['id' => $keyId]
        );
        $rawKeyContent = $row['KEY_FILE_CONTENT'] ?? '';
        if (is_object($rawKeyContent) && method_exists($rawKeyContent, 'load')) { $rawKeyContent = $rawKeyContent->load(); }
        
        $content = (string)$rawKeyContent;
        if ($row && trim($content) !== '') {
            $download = [
                'filename' => 'rungocraft_staff_key_' . (int)$row['ID'] . '_user_' . (int)$row['USER_ID'] . '.txt',
                'content' => $content,
            ];
        }
    } elseif (!empty($_SESSION['staff_key_download']['content'])) {
        $download = $_SESSION['staff_key_download'];
        unset($_SESSION['staff_key_download']);
    }

    if (!$download) {
        http_response_code(404);
        echo 'Файл ключа не збережено. Для старих ключів створіть новий ключ після міграції 034.';
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)$download['filename']) . '"');
    echo (string)$download['content'];
    exit;
}

if ($route === 'admin-calculation-file') {
    $current = current_user();
    if (!$current || !in_array((string)($current['role'] ?? ''), ['admin','manager'], true)) {
        http_response_code(403);
        echo 'Доступ заборонено.';
        exit;
    }

    $requestId = (int)($_GET['request_id'] ?? 0);
    $fileParam = rawurldecode((string)($_GET['file'] ?? ''));
    $fileParam = str_replace('\\', '/', $fileParam);
    if ($requestId <= 0 || !str_starts_with($fileParam, '/uploads/calculations/') || str_contains($fileParam, '..')) {
        http_response_code(404);
        echo 'Файл не знайдено.';
        exit;
    }

    $requestRow = Database::fetchOne(
        'SELECT comment_text FROM rc_calculation_requests WHERE id = :id',
        ['id' => $requestId]
    );
    $commentText = $requestRow['COMMENT_TEXT'] ?? '';
    if (is_object($commentText) && method_exists($commentText, 'load')) { $commentText = $commentText->load(); }
    if (!$requestRow || !str_contains((string)$commentText, $fileParam)) {
        http_response_code(404);
        echo 'Файл не належить цій заявці.';
        exit;
    }

    $relativeFile = ltrim(substr($fileParam, strlen('/uploads/')), '/');
    $uploadsRoot = realpath(__DIR__ . '/uploads');
    $downloadFile = $uploadsRoot !== false ? realpath($uploadsRoot . '/' . $relativeFile) : false;
    if ($uploadsRoot === false || $downloadFile === false || !str_starts_with($downloadFile, $uploadsRoot . DIRECTORY_SEPARATOR) || !is_file($downloadFile)) {
        http_response_code(404);
        echo 'Файл не знайдено.';
        exit;
    }
    $ext = strtolower(pathinfo($downloadFile, PATHINFO_EXTENSION));
    $types = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
        'pdf' => 'application/pdf', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    if (!isset($types[$ext])) {
        http_response_code(404);
        echo 'Тип файлу не дозволено.';
        exit;
    }

    $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename($downloadFile)) ?: ('calculation.' . $ext);
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $types[$ext]);
    header('Content-Length: ' . filesize($downloadFile));
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Cache-Control: private, no-store, max-age=0');
    readfile($downloadFile);
    exit;
}

if ($route === 'admin-ttn-document') {
    $current = current_user();
    if (!$current || !in_array((string)($current['role'] ?? ''), ['admin','manager','warehouse'], true)) {
        http_response_code(403);
        echo 'Доступ заборонено.';
        exit;
    }
    $shipmentId = (int)($_GET['shipment_id'] ?? 0);
    $shipment = Database::fetchOne(
        "SELECT ds.*, o.customer_name, o.customer_phone, o.customer_email, o.total_amount, o.delivery_type, o.created_at AS order_created_at
         FROM rc_delivery_shipments ds
         JOIN rc_orders o ON o.id = ds.order_id
         WHERE ds.id = :id",
        ['id' => $shipmentId]
    );
    if (!$shipment) {
        http_response_code(404);
        echo 'ТТН не знайдено.';
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="uk"><head><meta charset="utf-8"><title>ТТН #' . e($shipment['ID']) . '</title><style>body{font-family:Arial,sans-serif;margin:40px;color:#102033}.doc{max-width:820px;margin:0 auto}.top{display:flex;justify-content:space-between;gap:24px;border-bottom:2px solid #102033;padding-bottom:18px}h1{margin:0 0 8px}.box{border:1px solid #d8e0ea;border-radius:14px;padding:16px;margin:16px 0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.muted{color:#667085}.btn{display:inline-block;padding:10px 14px;border:1px solid #ddd;border-radius:10px;text-decoration:none;color:#102033}@media print{.no-print{display:none}}</style></head><body><div class="doc">';
    echo '<p class="no-print"><button onclick="window.print()" class="btn">Друк / зберегти як PDF</button> <a class="btn" href="/admin#delivery">Назад</a></p>';
    echo '<div class="top"><div><h1>ТТН / квитанція доставки</h1><p class="muted">RungoCraft, м. Київ, вул. Куренівська, 15</p></div><div><b>Номер:</b> ' . e($shipment['TTN'] ?? '—') . '<br><b>Дата:</b> ' . e(format_db_datetime($shipment['CREATED_AT'] ?? '')) . '<br><b>Статус:</b> ' . e(delivery_status_label($shipment['DELIVERY_STATUS'] ?? '')) . '</div></div>';
    echo '<div class="grid"><div class="box"><h3>Отримувач</h3><p><b>' . e($shipment['RECIPIENT_NAME'] ?? $shipment['CUSTOMER_NAME'] ?? 'Клієнт') . '</b><br>' . e($shipment['RECIPIENT_PHONE'] ?? $shipment['CUSTOMER_PHONE'] ?? '') . '<br>' . e($shipment['CUSTOMER_EMAIL'] ?? '') . '</p></div><div class="box"><h3>Доставка</h3><p><b>Перевізник:</b> ' . e($shipment['CARRIER_CODE'] ?? '') . '<br><b>Тип:</b> ' . e(delivery_type_label($shipment['DELIVERY_TYPE'] ?? '')) . '<br><b>Місто:</b> ' . e($shipment['CITY_NAME'] ?? '') . '<br><b>Адреса/відділення:</b> ' . e($shipment['WAREHOUSE_NAME'] ?? $shipment['ADDRESS_TEXT'] ?? '') . '</p></div></div>';
    echo '<div class="box"><h3>Вантаж</h3><p>Будівельні матеріали за замовленням #' . e($shipment['ORDER_ID'] ?? '') . '. Оголошена сума: <b>' . money($shipment['TOTAL_AMOUNT'] ?? 0) . '</b>. Орієнтовна ціна доставки: <b>' . money($shipment['ESTIMATED_PRICE'] ?? 0) . '</b>.</p></div>';
    echo '<p style="margin-top:60px">Відповідальний: ____________________</p></div></body></html>';
    exit;
}

if ($route === 'admin-order-document') {
    $current = current_user();
    if (!$current || !in_array((string)($current['role'] ?? ''), ['admin','manager'], true)) {
        http_response_code(403);
        echo 'Доступ заборонено.';
        exit;
    }
    $orderId = (int)($_GET['order_id'] ?? 0);
    $type = trim((string)($_GET['type'] ?? 'invoice'));
    $order = Database::fetchOne('SELECT * FROM rc_orders WHERE id = :id', ['id' => $orderId]);
    if (!$order) {
        http_response_code(404);
        echo 'Замовлення не знайдено.';
        exit;
    }
    $items = Database::fetchAll('SELECT * FROM rc_order_items WHERE order_id = :order_id ORDER BY id', ['order_id' => $orderId]);
    $title = $type === 'receipt' ? 'Товарний чек' : 'Видаткова накладна';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="uk"><head><meta charset="utf-8"><title>' . e($title) . ' #' . (int)$orderId . '</title><style>body{font-family:Arial,sans-serif;margin:40px;color:#102033}h1{margin:0 0 8px}table{width:100%;border-collapse:collapse;margin-top:24px}td,th{border:1px solid #d8e0ea;padding:10px;text-align:left}.top{display:flex;justify-content:space-between;gap:20px}.muted{color:#667085}.btn{display:inline-block;padding:10px 14px;border:1px solid #ddd;border-radius:10px;text-decoration:none;color:#102033}@media print{.no-print{display:none}}</style></head><body>';
    echo '<p class="no-print"><button onclick="window.print()" class="btn">Друк / зберегти як PDF</button> <a class="btn" href="/admin#orders">Назад</a></p>';
    echo '<div class="top"><div><h1>' . e($title) . ' #' . (int)$orderId . '</h1><p class="muted">RungoCraft, м. Київ, вул. Куренівська, 15</p></div><div><b>Дата:</b> ' . e(format_db_datetime($order['CREATED_AT'] ?? date('Y-m-d'))) . '<br><b>Статус:</b> ' . e(order_status_label($order['STATUS'] ?? '')) . '</div></div>';
    echo '<p><b>Покупець:</b> ' . e((string)($order['CUSTOMER_NAME'] ?? '')) . '<br><b>Телефон:</b> ' . e((string)($order['CUSTOMER_PHONE'] ?? '')) . '<br><b>Email:</b> ' . e((string)($order['CUSTOMER_EMAIL'] ?? '')) . '</p>';
    echo '<p><b>Доставка:</b> ' . e(delivery_type_label($order['DELIVERY_TYPE'] ?? '')) . ', ' . e(delivery_status_label($order['DELIVERY_STATUS'] ?? '')) . (!empty($order['DELIVERY_TTN']) ? ', ТТН ' . e((string)$order['DELIVERY_TTN']) : '') . '<br><b>Оплата:</b> ' . e(payment_type_label($order['PAYMENT_TYPE'] ?? '')) . ', ' . e(payment_status_label($order['PAYMENT_STATUS'] ?? '')) . '</p>';
    echo '<table><thead><tr><th>№</th><th>Товар</th><th>К-сть</th><th>Ціна</th><th>Сума</th></tr></thead><tbody>';
    $n=1; foreach ($items as $item) { echo '<tr><td>' . $n++ . '</td><td>' . e((string)($item['PRODUCT_NAME'] ?? 'Товар')) . '</td><td>' . e((string)($item['QUANTITY'] ?? '')) . '</td><td>' . money($item['PRICE'] ?? 0) . '</td><td>' . money($item['LINE_TOTAL'] ?? 0) . '</td></tr>'; }
    echo '</tbody></table><h2 style="text-align:right">Разом: ' . money($order['TOTAL_AMOUNT'] ?? 0) . '</h2><p style="margin-top:60px">Відповідальний: ____________________</p></body></html>';
    exit;
}

if ($route === 'admin-accounting-export') {
    $accountingExportService->outputCsv(current_user());
    exit;
}
if ($route === 'admin-analytics-export') {
    $analyticsService->outputCsv(current_user());
    exit;
}

$company = $repo->company();
$categories = $repo->categories();
$catalogAttributeFilters = [];
if (isset($_GET['attr']) && is_array($_GET['attr'])) {
    foreach ($_GET['attr'] as $name => $value) {
        $name = trim((string)$name);
        $values = is_array($value) ? $value : [$value];
        $cleanValues = [];
        foreach ($values as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $cleanValues[$item] = true;
            }
        }
        if ($name !== '' && $cleanValues !== []) {
            $catalogAttributeFilters[$name] = array_keys($cleanValues);
        }
    }
}
$products = $repo->products([
    'category' => trim((string)($_GET['category'] ?? '')),
    'q' => trim((string)($_GET['q'] ?? '')),
    'letter' => trim((string)($_GET['letter'] ?? '')),
    'sort' => trim((string)($_GET['sort'] ?? '')),
    'price_min' => trim((string)($_GET['price_min'] ?? '')),
    'price_max' => trim((string)($_GET['price_max'] ?? '')),
    'in_stock' => !empty($_GET['in_stock']),
    'attrs' => $catalogAttributeFilters,
]);
$catalogFilterOptions = $repo->catalogFilterOptions(trim((string)($_GET['category'] ?? '')));
$allProducts = $repo->products();
$roles = $repo->roles();
$reviews = $repo->reviews();
$catalogGroups = $repo->catalogGroups();
$popularCategories = $repo->popularCategories(8);
$user = current_user();
if ($page === 'checkout' && (!$user || empty($user['id']))) {
    $_SESSION['flash'] = 'Оформлення замовлення доступне після входу або реєстрації.';
    redirect_to('/account');
}
$cartData = $cartService->cart($user);
$accountData = $user ? $accountService->dashboardData($user) : [];
$wishlistCount = $accountService->wishlistCount($user);
$comparisonCount = $accountService->comparisonCount($user);
$supportAdminData = $supportService->adminData($user);
$deliveryMethods = $deliveryService->methods();
$novaPoshtaCities = $deliveryService->novaPoshtaCities();
$novaPoshtaWarehouses = $deliveryService->novaPoshtaWarehouses();
$deliveryAdminData = $deliveryService->adminData($user);
$paymentMethods = $paymentService->methods();
$paymentAdminData = $paymentService->adminData($user);
$analyticsService->trackPage($page, $user);
$analyticsAdminData = $analyticsService->adminData();
$notificationAdminData = $notificationService->adminData();
$warehouseAdminData = $warehouseService->adminData($user);
$businessAdminData = $businessService->adminData($user);
$businessCurrentClient = $businessService->currentClient($user);
$pwaSettings = $pwaService->settings();
$pwaAdminData = $pwaService->adminData();
$integrationAdminData = $integrationService->adminData($user);

require __DIR__ . '/views/layout.php';
