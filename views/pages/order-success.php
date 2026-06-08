<?php
$orderId = (int)($_GET['id'] ?? ($_SESSION['last_order_id'] ?? 0));
$orderData = $orderId > 0 ? $cartService->order($orderId, $user) : null;
$shipment = $orderData['shipment'] ?? null;
if (!isset($paymentService)) { $paymentService = new PaymentService(); }
$payment = $orderId > 0 ? $paymentService->paymentForOrder($orderId) : null;
?>
<section class="page-hero">
    <div class="container">
        <p class="breadcrumbs"><a href="/">Головна</a> / Замовлення створено</p>
        <h1>Дякуємо за замовлення</h1>
        <p>Замовлення прийнято. Деталі доставки та оплати доступні нижче.</p>
    </div>
</section>

<section class="section">
    <div class="container success-card reveal">
        <?php if ($orderData): ?>
            <div class="success-icon">✓</div>
            <h2>Замовлення #<?= e($orderData['order']['ID']) ?> створено</h2>
            <div class="success-summary-grid">
                <div><span>Статус</span><b><?= e(order_status_label($orderData['order']['STATUS'] ?? '')) ?></b></div>
                <div><span>Сума</span><b><?= money($orderData['order']['TOTAL_AMOUNT']) ?></b></div>
                <div><span>Покупець</span><b><?= e($orderData['order']['CUSTOMER_NAME'] ?? '') ?></b></div>
                <div><span>Телефон</span><b><?= e($orderData['order']['CUSTOMER_PHONE']) ?></b></div>
                <?php if (!empty($orderData['order']['CUSTOMER_EMAIL'])): ?><div><span>Email</span><b><?= e($orderData['order']['CUSTOMER_EMAIL']) ?></b></div><?php endif; ?>
                <?php if (!empty($orderData['order']['COMMENT_TEXT'])): ?><div class="span-2"><span>Коментар</span><b><?= e(is_object($orderData['order']['COMMENT_TEXT']) && method_exists($orderData['order']['COMMENT_TEXT'], 'load') ? $orderData['order']['COMMENT_TEXT']->load() : $orderData['order']['COMMENT_TEXT']) ?></b></div><?php endif; ?>
            </div>

            <?php if ($shipment): ?>
                <div class="order-delivery-summary">
                    <h3>Доставка</h3>
                    <p>Тип: <b><?= e(delivery_type_label($shipment['DELIVERY_TYPE'] ?? '')) ?></b></p>
                    <?php if (!empty($shipment['CITY'])): ?><p>Місто: <b><?= e($shipment['CITY']) ?></b></p><?php endif; ?>
                    <?php if (!empty($shipment['WAREHOUSE'])): ?><p>Відділення: <b><?= e($shipment['WAREHOUSE']) ?></b></p><?php endif; ?>
                    <?php if (!empty($shipment['ADDRESS_TEXT'])): ?><p>Адреса: <b><?= e($shipment['ADDRESS_TEXT']) ?></b></p><?php endif; ?>
                    <p>Статус доставки: <b><?= e(delivery_status_label($shipment['DELIVERY_STATUS'] ?? 'pending')) ?></b></p>
                    <?php if (!empty($shipment['TTN'])): ?><p>ТТН: <b><?= e($shipment['TTN']) ?></b></p><?php endif; ?>
                </div>
            <?php endif; ?>

            <link rel="stylesheet" href="/assets/css/payment.css">
            <div class="order-payment-summary">
                <h3>Оплата</h3>
                <?php if ($payment): ?>
                    <p>Метод: <b><?= e($payment['METHOD_NAME'] ?? payment_type_label($payment['METHOD_CODE'] ?? '')) ?></b></p>
                    <p>Статус: <b><?= e(payment_status_label($payment['STATUS'] ?? 'pending')) ?></b></p>
                    <p>Сума: <b><?= money($payment['AMOUNT'] ?? $orderData['order']['TOTAL_AMOUNT']) ?></b></p>
                    <?php if (!empty($payment['TRANSACTION_REF'])): ?><p>Номер платежу: <b><?= e($payment['TRANSACTION_REF']) ?></b></p><?php endif; ?>

                    <?php if (in_array((string)($payment['STATUS'] ?? ''), ['pending', 'failed'], true) && !empty($payment['CHECKOUT_URL'])): ?>
                        <a class="btn btn-primary" href="<?= e($payment['CHECKOUT_URL']) ?>">Перейти до онлайн-оплати</a>
                    <?php elseif (($payment['STATUS'] ?? '') === 'paid'): ?>
                        <p class="muted">Оплату отримано.</p>
                    <?php else: ?>
                        <p class="muted">Менеджер перевірить оплату та оновить статус замовлення.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p>Платіж ще не створено. Менеджер погодить спосіб оплати.</p>
                <?php endif; ?>
            </div>

            <div class="order-items-title">Товари у замовленні</div>
            <div class="order-success-items order-success-items--full">
                <?php foreach ($orderData['items'] as $item): ?>
                    <div>
                        <span>
                            <?= e($item['PRODUCT_NAME'] ?? ('Товар #' . ($item['PRODUCT_ID'] ?? ''))) ?>
                            <?php if (!empty($item['SKU'])): ?><small><?= e($item['SKU']) ?></small><?php endif; ?>
                        </span>
                        <b><?= e((string)($item['QUANTITY'] ?? $item['QTY'] ?? 1)) ?> <?= e($item['UNIT'] ?? '') ?></b>
                        <strong><?= money($item['LINE_TOTAL'] ?? ((float)($item['PRICE'] ?? 0) * (float)($item['QUANTITY'] ?? $item['QTY'] ?? 1))) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php
                $successOrderId = (int)($orderData['order']['ID'] ?? 0);
                $canReviewService = isset($reviewService) ? $reviewService->canReviewOrder($user ?? null, $successOrderId) : false;
                $alreadyReviewedService = isset($reviewService) ? $reviewService->alreadyReviewedService($user ?? null) : false;
            ?>
            <div class="service-review-after-order">
                <h3>Відгук про сервіс</h3>
                <?php if ($canReviewService && !$alreadyReviewedService): ?>
                    <form method="post" class="order-review-form">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="review_create_order">
                        <input type="hidden" name="order_id" value="<?= e($successOrderId) ?>">
                        <input type="hidden" name="return_to" value="/order-success?id=<?= e($successOrderId) ?>">
                        <div class="form-grid two">
                            <label>Оцінка сервісу
                                <select name="rating">
                                    <option value="5">5 — відмінно</option>
                                    <option value="4">4 — добре</option>
                                    <option value="3">3 — нормально</option>
                                    <option value="2">2 — погано</option>
                                    <option value="1">1 — дуже погано</option>
                                </select>
                            </label>
                            <label>Ваш відгук
                                <textarea name="review_text" rows="3" placeholder="Оцініть оформлення, підтримку, доставку та роботу магазину" required></textarea>
                            </label>
                        </div>
                        <button class="btn btn-light" type="submit">Опублікувати відгук про сервіс</button>
                    </form>
                <?php elseif ($alreadyReviewedService): ?>
                    <p class="muted">Відгук про сервіс уже залишено. Один клієнт може залишити тільки один сервісний відгук.</p>
                <?php else: ?>
                    <p class="muted">Після завершення або доставки замовлення в особистому кабінеті зʼявиться форма, де можна оцінити саме сервіс RungoCraft.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <h2>Замовлення створено</h2>
            <p>Менеджер звʼяжеться з вами найближчим часом.</p>
        <?php endif; ?>

        <div class="success-actions">
            <a class="btn btn-primary" href="/catalog">Повернутися до каталогу</a>
            <a class="btn btn-light" href="/account">Перейти в кабінет</a>
        </div>
    </div>
</section>
