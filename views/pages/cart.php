<?php if (has_role(['manager','warehouse','admin'])): ?>
<section class="page-hero"><div class="container"><p class="breadcrumbs"><a href="/">Головна</a> / Службовий профіль</p><h1>Службовий режим</h1><p>Кошик, бажане та оформлення замовлення доступні тільки клієнтським акаунтам.</p><a class="btn btn-primary" href="/admin">Перейти в панель працівника</a></div></section>
<?php return; endif; ?>
<section class="page-hero">
    <div class="container">
        <p class="breadcrumbs"><a href="/">Головна</a> / Кошик</p>
        <h1>Кошик</h1>
        <p>Перевірте товари, кількість і суму перед оформленням замовлення.</p>
    </div>
</section>

<section class="section">
    <div class="container cart-layout">
        <div class="cart-card reveal">
            <div class="cart-list server-cart-list">
                <?php if (empty($cartData['items'])): ?>
                    <div class="empty-card">Кошик порожній. Перейдіть у каталог і додайте товари.</div>
                <?php else: ?>
                    <?php foreach ($cartData['items'] as $item): ?>
                        <article class="cart-item cart-item-server">
                            <a class="cart-item__image" href="/product?id=<?= (int)$item['product_id'] ?>">
                                <img src="<?= e(media_url($item['image'] ?? 'cement.svg')) ?>" alt="<?= e($item['name']) ?>">
                            </a>

                            <div class="cart-item__info">
                                <small><?= e($item['sku']) ?> · <?= e($item['brand']) ?></small>
                                <h3><a href="/product?id=<?= (int)$item['product_id'] ?>"><?= e($item['name']) ?></a></h3>
                                <p class="stock stock--<?= e(product_status_class($item['status'] ?? 'in_stock')) ?>"><?= e(product_status_text($item)) ?></p>
                            </div>

                            <form class="cart-qty-form cart-qty-auto-form" method="post">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="cart_update">
                                <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                <label>
                                    Кількість
                                    <input type="number" name="quantity" min="1" max="<?= e($item['stock_qty']) ?>" step="1" value="<?= e($item['quantity']) ?>">
                                </label>
                                <button class="btn btn-light cart-update-hidden" type="submit" aria-hidden="true" tabindex="-1">Оновити</button>
                            </form>

                            <div class="cart-item__price">
                                <small><?= money($item['price']) ?> / <?= e($item['unit']) ?></small>
                                <strong><?= money($item['line_total']) ?></strong>
                            </div>

                            <form method="post" class="cart-remove-form">
                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="cart_remove">
                                <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                <button type="submit" title="Видалити">×</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <aside class="summary-card reveal">
            <h2>Разом</h2>
            <div class="summary-row"><span>Позицій</span><b><?= e($cartData['count'] ?? 0) ?></b></div>
            <div class="summary-row"><span>Товари</span><b><?= money($cartData['total'] ?? 0) ?></b></div>
            <div class="summary-row"><span>Доставка</span><b>уточнює менеджер</b></div>

            <?php if (!empty($cartData['items'])): ?>
                <a class="btn btn-primary btn-full" href="/checkout">Оформити замовлення</a>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="cart_clear">
                    <button class="btn btn-light btn-full" type="submit">Очистити кошик</button>
                </form>
            <?php else: ?>
                <a class="btn btn-primary btn-full" href="/catalog">Перейти в каталог</a>
            <?php endif; ?>

            <button class="btn btn-light btn-full" type="button" data-modal="calc">Замовити розрахунок</button>
        </aside>
    </div>
</section>
