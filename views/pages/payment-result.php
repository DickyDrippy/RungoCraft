<?php
$orderId = (int)($_GET['order_id'] ?? $_GET['id'] ?? 0);
$paymentId = (int)($_GET['payment_id'] ?? 0);
$provider = strtolower(trim((string)($_GET['provider'] ?? '')));
if ($orderId <= 0 && !empty($_POST['data'])) {
    $decoded = json_decode(base64_decode((string)$_POST['data']), true);
    if (is_array($decoded)) {
        $orderId = (int)($decoded['order_id'] ?? 0);
        $provider = 'liqpay';
    }
}
if ($orderId <= 0 && !empty($_SESSION['last_order_id'])) {
    $orderId = (int)$_SESSION['last_order_id'];
}
$payment = null;
if (!isset($paymentService)) { $paymentService = new PaymentService(); }
$shouldConfirmGatewayReturn = $orderId > 0 && (
    !empty($_GET['return_ok'])
    || $paymentId > 0
    || $provider !== ''
    || isset($_GET['order_id'])
);
if ($shouldConfirmGatewayReturn) {
    $payment = $paymentService->confirmGatewayReturn($orderId, $paymentId, $provider);
}
if ($orderId > 0 && !$payment) {
    $payment = $paymentService->paymentForOrder($orderId);
}
?>
<section class="page-hero">
    <div class="container">
        <p class="breadcrumbs"><a href="/">Головна</a> / Онлайн-оплата</p>
        <h1>Результат оплати</h1>
        <p>Після повернення з платіжного сервісу статус платежу оновлюється у системі.</p>
    </div>
</section>

<section class="section">
    <div class="container success-card reveal">
        <link rel="stylesheet" href="/assets/css/payment.css">
        <?php if ($orderId > 0): ?>
            <h2>Замовлення #<?= e($orderId) ?></h2>
            <?php if ($payment): ?>
                <p>Провайдер: <b><?= e($payment['PROVIDER'] ?? '') ?></b></p>
                <p>Статус: <b><?= e(payment_status_label($payment['STATUS'] ?? '')) ?></b></p>
                <p>Сума: <b><?= money($payment['AMOUNT'] ?? 0) ?></b></p>
                <?php if (!empty($payment['TRANSACTION_REF'])): ?><p>Номер платежу: <b><?= e($payment['TRANSACTION_REF']) ?></b></p><?php endif; ?>
            <?php else: ?>
                <p>Платіж для цього замовлення не знайдено.</p>
            <?php endif; ?>
            <div class="success-actions">
                <a class="btn btn-primary" href="/order-success?id=<?= e($orderId) ?>">Повернутися до замовлення</a>
                <a class="btn btn-light" href="/account?tab=orders">Мої замовлення</a>
            </div>
        <?php else: ?>
            <h2>Оплату оброблено</h2>
            <p>Поверніться до кабінету, щоб переглянути актуальний статус замовлення.</p>
            <a class="btn btn-primary" href="/account?tab=orders">Перейти в кабінет</a>
        <?php endif; ?>
    </div>
</section>
