<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../app/helpers.php';
    require_once __DIR__ . '/../app/Database.php';
    require_once __DIR__ . '/../app/DeliveryDirectoryService.php';

    $service = new DeliveryDirectoryService();
    $payload = $service->api($_GET);

    $ok = (bool)($payload['ok'] ?? $payload['success'] ?? false);
    $payload['ok'] = $ok;
    $payload['success'] = $ok;
    $payload['items'] = is_array($payload['items'] ?? null) ? $payload['items'] : [];

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(200);

    echo json_encode([
        'ok' => false,
        'success' => false,
        'message' => 'Помилка довідника доставки: ' . $e->getMessage(),
        'items' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
