<?php
declare(strict_types=1);

final class AccountingExportService
{
    public function outputCsv(?array $user): void
    {
        if (!$user || !in_array((string)($user['role'] ?? ''), ['admin', 'manager'], true)) {
            http_response_code(403);
            echo 'Недостатньо прав для експорту.';
            return;
        }

        $dateFrom = trim((string)($_GET['date_from'] ?? ''));
        $dateTo = trim((string)($_GET['date_to'] ?? ''));
        $params = [];
        $where = ['1 = 1'];

        if ($dateFrom !== '') {
            $where[] = "TRUNC(o.created_at) >= TO_DATE(:date_from, 'YYYY-MM-DD')";
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = "TRUNC(o.created_at) <= TO_DATE(:date_to, 'YYYY-MM-DD')";
            $params['date_to'] = $dateTo;
        }

        $rows = Database::fetchAll(
            "SELECT
                o.id AS order_id,
                TO_CHAR(o.created_at, 'DD.MM.YYYY HH24:MI') AS created_at,
                o.customer_name,
                o.customer_phone,
                o.customer_email,
                o.status,
                o.payment_type,
                o.payment_status,
                o.payment_reference,
                o.delivery_type,
                o.delivery_status,
                o.delivery_ttn,
                o.total_amount,
                COALESCE(oi.sku, p.sku) AS sku,
                COALESCE(oi.product_name, p.name) AS product_name,
                COALESCE(oi.quantity, 1) AS quantity,
                COALESCE(oi.unit, p.unit, 'шт') AS unit,
                oi.price,
                COALESCE(oi.line_total, COALESCE(oi.quantity, 1) * oi.price) AS line_total
             FROM rc_orders o
             LEFT JOIN rc_order_items oi ON oi.order_id = o.id
             LEFT JOIN rc_products p ON p.id = oi.product_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY o.created_at DESC, o.id DESC, oi.id ASC",
            $params
        );

        $filename = 'rungocraft_accounting_export_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'order_id', 'date', 'client', 'phone', 'email', 'order_status', 'payment_type', 'payment_status', 'payment_reference', 'delivery_type', 'delivery_status', 'ttn',
            'order_total_gross', 'order_total_net_without_vat', 'order_vat_20', 'sku', 'product_name', 'quantity', 'unit', 'price_gross', 'price_net_without_vat', 'price_vat_20', 'line_total_gross', 'line_net_without_vat', 'line_vat_20'
        ], ';');

        foreach ($rows as $row) {
            $orderTotalGross = (float)($row['TOTAL_AMOUNT'] ?? 0);
            $orderTotalNet = round($orderTotalGross / 1.2, 2);
            $orderVat = round($orderTotalGross - $orderTotalNet, 2);
            $priceGross = (float)($row['PRICE'] ?? 0);
            $priceNet = round($priceGross / 1.2, 2);
            $priceVat = round($priceGross - $priceNet, 2);
            $lineGross = (float)($row['LINE_TOTAL'] ?? 0);
            $lineNet = round($lineGross / 1.2, 2);
            $lineVat = round($lineGross - $lineNet, 2);

            fputcsv($out, [
                $row['ORDER_ID'] ?? '',
                $row['CREATED_AT'] ?? '',
                $row['CUSTOMER_NAME'] ?? '',
                $row['CUSTOMER_PHONE'] ?? '',
                $row['CUSTOMER_EMAIL'] ?? '',
                $row['STATUS'] ?? '',
                $row['PAYMENT_TYPE'] ?? '',
                $row['PAYMENT_STATUS'] ?? '',
                $row['PAYMENT_REFERENCE'] ?? '',
                $row['DELIVERY_TYPE'] ?? '',
                $row['DELIVERY_STATUS'] ?? '',
                $row['DELIVERY_TTN'] ?? '',
                $this->csvMoney($orderTotalGross),
                $this->csvMoney($orderTotalNet),
                $this->csvMoney($orderVat),
                $row['SKU'] ?? '',
                $row['PRODUCT_NAME'] ?? '',
                $row['QUANTITY'] ?? '',
                $row['UNIT'] ?? '',
                $this->csvMoney($priceGross),
                $this->csvMoney($priceNet),
                $this->csvMoney($priceVat),
                $this->csvMoney($lineGross),
                $this->csvMoney($lineNet),
                $this->csvMoney($lineVat),
            ], ';');
        }
        fclose($out);
    }

    private function csvMoney(float $value): string
    {
        return number_format($value, 2, ',', '');
    }
}
