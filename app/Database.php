<?php
declare(strict_types=1);

final class Database
{
    private static $connection = null;
    private static ?string $lastError = null;

    public static function configPath(): string
    {
        return __DIR__ . '/../config/database.php';
    }

    public static function isConfigured(): bool
    {
        return file_exists(self::configPath());
    }

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    public static function connect()
    {
        if (self::$connection) {
            return self::$connection;
        }

        self::$lastError = null;

        if (!self::isConfigured()) {
            self::$lastError = 'Файл config/database.php не створено.';
            return null;
        }

        if (!function_exists('oci_connect')) {
            self::$lastError = 'Драйвер бази даних не встановлено або не увімкнено.';
            return null;
        }

        $config = require self::configPath();
        $walletPath = $config['wallet_path'] ?? $config['tns_admin'] ?? '/opt/oracle/wallet';
        putenv('TNS_ADMIN=' . $walletPath);

        $user = (string)($config['db_user'] ?? '');
        $pass = (string)($config['db_pass'] ?? '');
        $name = (string)($config['db_name'] ?? '');

        $conn = @oci_connect($user, $pass, $name, 'AL32UTF8');
        if (!$conn) {
            $error = oci_error();
            self::$lastError = $error['message'] ?? 'Невідома помилка підключення до бази даних.';
            return null;
        }

        self::$connection = $conn;
        return self::$connection;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        $conn = self::connect();
        if (!$conn) {
            return [];
        }

        $stmt = oci_parse($conn, $sql);
        if (!$stmt) {
            $error = oci_error($conn);
            self::$lastError = $error['message'] ?? 'Помилка oci_parse.';
            return [];
        }

        $binds = [];
        foreach ($params as $name => $value) {
            $key = ':' . ltrim((string)$name, ':');
            $binds[$key] = $value;
            oci_bind_by_name($stmt, $key, $binds[$key]);
        }

        $ok = @oci_execute($stmt, OCI_NO_AUTO_COMMIT);
        if (!$ok) {
            $error = oci_error($stmt);
            self::$lastError = $error['message'] ?? 'Помилка oci_execute.';
            oci_free_statement($stmt);
            return [];
        }

        $rows = [];
        while (($row = oci_fetch_assoc($stmt)) !== false) {
            foreach ($row as $field => $value) {
                if (is_object($value) && method_exists($value, 'load')) {
                    $loaded = $value->load();
                    $row[$field] = $loaded === false ? '' : (string)$loaded;
                }
            }
            $rows[] = $row;
        }
        oci_free_statement($stmt);

        return $rows;
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = self::fetchAll($sql, $params);
        return $rows[0] ?? null;
    }

    public static function execute(string $sql, array $params = []): bool
    {
        $conn = self::connect();
        if (!$conn) {
            return false;
        }

        $stmt = oci_parse($conn, $sql);
        if (!$stmt) {
            $error = oci_error($conn);
            self::$lastError = $error['message'] ?? 'Помилка oci_parse.';
            return false;
        }

        $binds = [];
        foreach ($params as $name => $value) {
            $key = ':' . ltrim((string)$name, ':');
            $binds[$key] = $value;
            oci_bind_by_name($stmt, $key, $binds[$key]);
        }

        $ok = @oci_execute($stmt, OCI_COMMIT_ON_SUCCESS);
        if (!$ok) {
            $error = oci_error($stmt);
            self::$lastError = $error['message'] ?? 'Помилка oci_execute.';
        }
        oci_free_statement($stmt);
        return (bool)$ok;
    }


    public static function executeAffected(string $sql, array $params = []): ?int
    {
        $conn = self::connect();
        if (!$conn) {
            return null;
        }

        $stmt = oci_parse($conn, $sql);
        if (!$stmt) {
            $error = oci_error($conn);
            self::$lastError = $error['message'] ?? 'Помилка oci_parse.';
            return null;
        }

        $binds = [];
        foreach ($params as $name => $value) {
            $key = ':' . ltrim((string)$name, ':');
            $binds[$key] = $value;
            oci_bind_by_name($stmt, $key, $binds[$key]);
        }

        $ok = @oci_execute($stmt, OCI_COMMIT_ON_SUCCESS);
        if (!$ok) {
            $error = oci_error($stmt);
            self::$lastError = $error['message'] ?? 'Помилка oci_execute.';
            oci_free_statement($stmt);
            return null;
        }

        $rows = oci_num_rows($stmt);
        oci_free_statement($stmt);
        return (int)$rows;
    }

    public static function status(): array
    {
        $conn = self::connect();
        return [
            'configured' => self::isConfigured(),
            'oci8' => function_exists('oci_connect'),
            'connected' => (bool)$conn,
            'error' => self::lastError(),
        ];
    }
}
