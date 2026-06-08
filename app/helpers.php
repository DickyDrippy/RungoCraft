<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(int|float|string $value): string
{
    return number_format((float)$value, 0, '.', ' ') . ' грн';
}



function product_status_label(?string $status): string
{
    $status = trim((string)$status);
    $labels = [
        'in_stock' => 'В наявності',
        'low_stock' => 'Мало',
        'out_of_stock' => 'Немає в наявності',
        'preorder' => 'Під замовлення',
        'expected' => 'Очікується',
        'archived' => 'Архівний товар',
    ];

    return $labels[$status] ?? ($status !== '' ? $status : 'Не вказано');
}

function product_status_class(?string $status): string
{
    $status = trim((string)$status);
    $allowed = ['in_stock', 'low_stock', 'out_of_stock', 'preorder', 'expected', 'archived'];
    return in_array($status, $allowed, true) ? $status : 'unknown';
}

function product_status_text(array $product): string
{
    $status = trim((string)($product['status'] ?? 'in_stock'));
    $stock = (float)($product['stock'] ?? $product['stock_qty'] ?? 0);
    $unit = trim((string)($product['unit'] ?? 'шт.'));
    $stockText = rtrim(rtrim(number_format($stock, 3, '.', ''), '0'), '.');
    if ($stockText === '') {
        $stockText = '0';
    }

    return match ($status) {
        'in_stock' => $stock > 0 ? 'В наявності: ' . $stockText . ' ' . $unit : 'Немає в наявності',
        'low_stock' => $stock > 0 ? 'Мало: ' . $stockText . ' ' . $unit : 'Мало / уточнюйте в менеджера',
        'out_of_stock' => 'Немає в наявності',
        'preorder' => 'Під замовлення',
        'expected' => 'Очікується',
        'archived' => 'Архівний товар',
        default => product_status_label($status),
    };
}

function product_can_order(array $product): bool
{
    $status = trim((string)($product['status'] ?? 'in_stock'));
    $stock = (float)($product['stock'] ?? $product['stock_qty'] ?? 0);

    
    if ($status === 'preorder') {
        return true;
    }

    return in_array($status, ['in_stock', 'low_stock'], true) && $stock > 0;
}

function media_url(?string $path, string $fallback = 'cement.svg'): string
{
    $path = trim((string)$path);
    if ($path === '') {
        $path = $fallback;
    }

    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }

    $normalized = str_replace('\\', '/', $path);
    $normalized = preg_replace('~^/?var/RungoCraft/~', '/', $normalized) ?? $normalized;
    $normalized = preg_replace('~^/?html/~', '/', $normalized) ?? $normalized;
    $normalized = preg_replace('~^/?public/~', '/', $normalized) ?? $normalized;

    if (str_starts_with($normalized, '/uploads/')) {
        return $normalized;
    }
    if (str_starts_with($normalized, 'uploads/')) {
        return '/' . $normalized;
    }
    if (str_contains($normalized, '/uploads/')) {
        return substr($normalized, strpos($normalized, '/uploads/'));
    }

    if (str_starts_with($normalized, '/assets/')) {
        return $normalized;
    }
    if (str_starts_with($normalized, 'assets/')) {
        return '/' . $normalized;
    }

    return '/assets/img/' . ltrim($normalized, '/');
}

function active(string $current, string $expected): string
{
    return $current === $expected ? 'is-active' : '';
}

function redirect_to(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function selected(bool $condition): string
{
    return $condition ? 'selected' : '';
}

function checked(bool $condition): string
{
    return $condition ? 'checked' : '';
}

function find_product(array $products, int $id): ?array
{
    foreach ($products as $product) {
        if ((int)($product['id'] ?? 0) === $id) {
            return $product;
        }
    }
    return null;
}

function find_category(array $categories, string $slug, ?array $parent = null): ?array
{
    foreach ($categories as $category) {
        if (!is_array($category)) {
            continue;
        }
        if (($category['slug'] ?? '') === $slug) {
            return $parent
                ? $category + ['parent' => $parent['name'] ?? null, 'parent_slug' => $parent['slug'] ?? null]
                : $category;
        }
        $found = find_category(($category['children'] ?? []), $slug, $category);
        if ($found) {
            return $found;
        }
    }
    return null;
}

function flatten_category_tree(array $categories, int $level = 0, string $parentPath = ''): array
{
    $flat = [];
    foreach ($categories as $category) {
        if (!is_array($category)) {
            continue;
        }
        $name = (string)($category['name'] ?? '');
        $path = $parentPath !== '' ? $parentPath . ' / ' . $name : $name;
        $item = $category;
        $children = is_array($category['children'] ?? null) ? $category['children'] : [];
        $item['level'] = $level;
        $item['path'] = $path;
        $item['has_children'] = $children !== [];
        unset($item['children']);
        $flat[] = $item;
        if ($children !== []) {
            $flat = array_merge($flat, flatten_category_tree($children, $level + 1, $path));
        }
    }
    return $flat;
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function user_role(): ?string
{
    return $_SESSION['user']['role'] ?? null;
}

function has_role(array|string $roles): bool
{
    $role = user_role();
    if ($role === null) {
        return false;
    }
    return in_array($role, (array)$roles, true);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $_SESSION['flash'] = 'Сторінка застаріла або сесія була оновлена. Повторіть дію ще раз.';
        $referer = (string)($_SERVER['HTTP_REFERER'] ?? '/');
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $target = '/';
        if ($referer !== '') {
            $parts = parse_url($referer);
            if (is_array($parts) && (!isset($parts['host']) || $parts['host'] === $host)) {
                $target = (string)($parts['path'] ?? '/');
                if (!empty($parts['query'])) {
                    $target .= '?' . $parts['query'];
                }
                if (!empty($parts['fragment'])) {
                    $target .= '#' . $parts['fragment'];
                }
            }
        }
        header('Location: ' . ($target !== '' ? $target : '/'));
        exit;
    }
}

function order_status_label(?string $status): string
{
    $status = trim((string)$status);
    $labels = [
        'created' => 'Створено',
        'waiting_confirmation' => 'Очікує підтвердження',
        'confirmed' => 'Підтверджено',
        'waiting_payment' => 'Очікує оплати',
        'paid' => 'Оплачено',
        'processing' => 'В обробці',
        'picking' => 'Комплектується на складі',
        'packing' => 'Комплектується',
        'packed' => 'Зібрано',
        'ready_for_delivery' => 'Готово до відправлення',
        'sent' => 'Передано в доставку',
        'delivering' => 'Доставляється',
        'delivered' => 'Доставлено',
        'completed' => 'Завершено',
        'done' => 'Завершено',
        'cancelled' => 'Скасовано',
        'returned' => 'Повернення',
    ];

    return $labels[$status] ?? ($status !== '' ? $status : 'Не вказано');
}

function delivery_type_label(?string $type): string
{
    $type = trim((string)$type);
    $labels = [
        'pickup' => 'Самовивіз зі складу-магазину',
        'courier' => 'Курʼєрська доставка',
        'kyiv_courier' => 'Доставка курʼєром по Києву',
        'nova_poshta' => 'Нова пошта',
        'nova_poshta_branch' => 'Нова пошта — у відділення',
        'nova_poshta_courier' => 'Нова пошта — курʼєром до дверей',
        'delivery_auto_branch' => 'Delivery — у відділення',
        'delivery_auto_courier' => 'Delivery — курʼєром до дверей',
        'construction_site' => 'Доставка на будівельний обʼєкт',
        'object' => 'Доставка на обʼєкт',
    ];

    return $labels[$type] ?? ($type !== '' ? $type : 'Не вказано');
}

function delivery_status_label(?string $status): string
{
    $status = trim((string)$status);
    $labels = [
        'pending' => 'Очікує оформлення',
        'manager_confirm' => 'Очікує підтвердження менеджера',
        'created' => 'ТТН створено',
        'ttn_created' => 'ТТН створено',
        'ready_to_ship' => 'Готово до відправлення',
        'sent' => 'Передано перевізнику',
        'in_transit' => 'У дорозі',
        'delivering' => 'Доставляється',
        'delivered' => 'Доставлено',
        'completed' => 'Завершено',
        'done' => 'Завершено',
        'cancelled' => 'Скасовано',
        'returned' => 'Повернення',
    ];

    return $labels[$status] ?? ($status !== '' ? $status : 'Не вказано');
}

function payment_type_label(?string $type): string
{
    $type = trim((string)$type);
    $labels = [
        'cash' => 'Оплата при отриманні',
        'online_card' => 'Онлайн-оплата карткою',
        'liqpay' => 'Онлайн-оплата LiqPay',
        'wayforpay' => 'Онлайн-оплата WayForPay',
        'invoice' => 'Безготівковий рахунок',
        'manager_confirm' => 'Після підтвердження менеджером',
    ];

    return $labels[$type] ?? ($type !== '' ? $type : 'Не вказано');
}

function payment_status_label(?string $status): string
{
    $status = trim((string)$status);
    $labels = [
        'pending' => 'Очікує оплати',
        'manager_confirm' => 'Після підтвердження менеджером',
        'pay_on_delivery' => 'Оплата при отриманні',
        'waiting_manager' => 'Очікує підтвердження менеджером',
        'invoice_sent' => 'Рахунок виставлено',
        'paid' => 'Оплачено',
        'failed' => 'Помилка оплати',
        'cancelled' => 'Скасовано',
        'refunded' => 'Повернено',
    ];

    return $labels[$status] ?? ($status !== '' ? $status : 'Не вказано');
}


function format_db_datetime(mixed $value): string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return '—';
    }

    $normalized = preg_replace('/\s+/', ' ', $raw) ?? $raw;

    $formats = [
        'd-M-y h.i.s.u A',
        'd-M-y h.i.s A',
        'd-M-y H.i.s.u',
        'd-M-y H.i.s',
        'Y-m-d H:i:s',
        'Y-m-d H:i:s.u',
        'Y-m-d\TH:i:sP',
        'Y-m-d',
    ];

    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $normalized);
        if ($date instanceof DateTime) {
            return $date->format('d.m.Y H:i');
        }
    }

    $timestamp = strtotime(str_replace('.', ':', $normalized));
    if ($timestamp !== false) {
        return date('d.m.Y H:i', $timestamp);
    }

    return $raw;
}

function short_hash_label(string $value): string
{
    $value = trim($value);
    return $value !== '' ? mb_substr($value, 0, 12) . '…' : '—';
}
