<?php
declare(strict_types=1);

final class Phone
{
    



    public static function normalizeUa(string $value): array
    {
        $raw = trim($value);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return [
                'ok' => false,
                'phone' => '',
                'message' => 'Вкажіть номер телефону для підтвердження замовлення.',
            ];
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '38' . $digits;
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '80')) {
            $digits = '3' . $digits;
        } elseif (strlen($digits) === 12 && str_starts_with($digits, '380')) {
            
        } else {
            return [
                'ok' => false,
                'phone' => '',
                'message' => 'Введіть телефон у форматі +38 (0XX) XXX-XX-XX.',
            ];
        }

        if (!preg_match('/^380\d{9}$/', $digits)) {
            return [
                'ok' => false,
                'phone' => '',
                'message' => 'Номер телефону має містити 12 цифр у форматі +380XXXXXXXXX.',
            ];
        }

        return [
            'ok' => true,
            'phone' => '+' . $digits,
            'message' => '',
        ];
    }

    public static function formatUa(string $value): string
    {
        $normalized = self::normalizeUa($value);
        if (!$normalized['ok']) {
            return trim($value);
        }

        $digits = substr((string)$normalized['phone'], 1);

        return sprintf(
            '+38 (%s) %s-%s-%s',
            substr($digits, 2, 3),
            substr($digits, 5, 3),
            substr($digits, 8, 2),
            substr($digits, 10, 2)
        );
    }
}
