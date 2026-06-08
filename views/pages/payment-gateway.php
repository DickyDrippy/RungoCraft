<?php
$provider = strtolower((string)($_GET['provider'] ?? ''));
$paymentId = (int)($_GET['payment_id'] ?? 0);
$gateway = (new IntegrationService())->gatewayPayload($paymentId, $provider);
$payment = $paymentId > 0 ? Database::fetchOne(
    "SELECT p.*, o.customer_name, o.customer_email, o.customer_phone
     FROM rc_payments p
     LEFT JOIN rc_orders o ON o.id = p.order_id
     WHERE p.id = :id",
    ['id' => $paymentId]
) : null;
$isLocalHost = in_array($_SERVER['HTTP_HOST'] ?? '', ['127.0.0.1:8000','localhost:8000','127.0.0.1','localhost'], true);
?>
<link rel="stylesheet" href="/assets/css/payment.css">
<section class="page-hero compact">
    <div class="container">
        <p class="breadcrumbs"><a href="/">Головна</a> / Оплата</p>
        <h1>Перехід до оплати</h1>
        <p>Система сформувала платіжні дані для сервісу <?= e($provider !== '' ? strtoupper($provider) : 'онлайн-оплати') ?>.</p>
    </div>
</section>

<section class="section">
    <div class="container payment-gateway-card reveal">
        <?php if (!$gateway || !$payment): ?>
            <div class="empty-card">Платіж не знайдено або платіжний сервіс не налаштовано.</div>
            <a class="btn btn-primary" href="/account">Повернутися в кабінет</a>
        <?php else: ?>
            <div class="payment-gateway-summary">
                <h2>Замовлення #<?= e($payment['ORDER_ID'] ?? '') ?></h2>
                <p>Сума: <b><?= money($payment['AMOUNT'] ?? 0) ?></b></p>
                <p>Провайдер: <b><?= e($provider) ?></b></p>
                <p>Статус у системі: <b><?= e($payment['STATUS'] ?? 'pending') ?></b></p>
            </div>
            <div class="payment-gateway-warning">
                <b>Онлайн-оплата:</b> перейдіть на захищену сторінку платіжного сервісу, введіть картку та після успішної оплати поверніться на сайт. Статус замовлення оновиться автоматично через callback або сторінку повернення.
            </div>
            <form id="gatewayForm" data-no-ajax="1" method="<?= e($gateway['method'] ?? 'POST') ?>" action="<?= e($gateway['action']) ?>">
                <?php foreach (($gateway['fields'] ?? []) as $name => $value): ?>
                    <input type="hidden" name="<?= e((string)$name) ?>" value="<?= e((string)$value) ?>">
                <?php endforeach; ?>
                <button class="btn btn-light" type="submit">Перейти до платіжного сервісу</button>
                <a class="btn btn-light" href="/order-success?id=<?= e($payment['ORDER_ID'] ?? 0) ?>">Повернутися до замовлення</a>
            </form>
        <?php endif; ?>
    </div>
</section>
