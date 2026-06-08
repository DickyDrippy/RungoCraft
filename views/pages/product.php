<?php
$productId = (int)($_GET['id'] ?? 0);
$product = $productId > 0 ? $repo->product($productId) : null;
if (!$product) {
    require __DIR__ . '/404.php';
    return;
}
$productReviews = isset($reviewService) ? $reviewService->productReviews($productId) : [];
$canReviewProduct = isset($reviewService) ? $reviewService->canReviewProduct($user ?? null, $productId) : false;
$productReviewCount = (int)($product['review_count'] ?? 0);
$productRating = $productReviewCount > 0 ? (float)($product['rating'] ?? 0) : 0.0;
$productStarsFilled = $productReviewCount > 0 ? max(1, min(5, (int)round($productRating))) : 0;
$productStars = $productStarsFilled > 0 ? str_repeat('★', $productStarsFilled) . str_repeat('☆', 5 - $productStarsFilled) : '☆';
$isStaffUser = has_role(['manager','warehouse','admin']);
$isWished = !empty($product['is_wished']);
$isCompared = !empty($product['is_compared']);
$productStatusClass = product_status_class($product['status'] ?? 'in_stock');
$productStatusText = product_status_text($product);
$productCanOrder = product_can_order($product);
?>
<section class="page-hero compact">
    <div class="container">
        <p class="breadcrumbs"><a href="/">Головна</a> / <a href="/catalog">Каталог</a> / <?= e($product['name']) ?></p>
    </div>
</section>
<section class="section">
    <div class="container product-detail">
        <?php
            $galleryImages = $product['images'] ?? [];
            if (empty($galleryImages)) {
                $galleryImages = [[
                    'path' => $product['image'] ?? 'cement.svg',
                    'alt' => $product['name'] ?? 'Товар',
                    'is_main' => true,
                ]];
            }
            $mainGalleryImage = $galleryImages[0]['path'] ?? ($product['image'] ?? 'cement.svg');
        ?>
        <div class="product-gallery reveal" data-product-gallery>
            <div class="product-gallery__main" data-zoom-area title="Наведіть курсор, щоб збільшити фото">
                <?php if (count($galleryImages) > 1): ?><button class="product-gallery__nav product-gallery__nav--prev" type="button" data-gallery-prev aria-label="Попереднє фото">‹</button><?php endif; ?>
                <img data-gallery-main src="<?= e(media_url($mainGalleryImage)) ?>" alt="<?= e($product['name']) ?>">
                <?php if (count($galleryImages) > 1): ?><button class="product-gallery__nav product-gallery__nav--next" type="button" data-gallery-next aria-label="Наступне фото">›</button><?php endif; ?>
            </div>
            <?php if (count($galleryImages) > 1): ?>
                <div class="product-gallery__thumbs">
                    <?php foreach ($galleryImages as $idx => $image): ?>
                        <button type="button" class="<?= $idx === 0 ? 'is-active' : '' ?>" data-gallery-thumb data-src="<?= e(media_url($image['path'] ?? 'cement.svg')) ?>" aria-label="Фото <?= $idx + 1 ?>">
                            <img src="<?= e(media_url($image['path'] ?? 'cement.svg')) ?>" alt="<?= e($image['alt'] ?? $product['name']) ?>">
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="product-info reveal">
            <p class="sku">Код товару: <?= e($product['sku'] ?? $product['id']) ?></p>
            <h1><?= e($product['name']) ?></h1>
            <?php if ($productReviewCount > 0): ?>
                <div class="rating"><?= e($productStars) ?> <small><?= e(number_format($productRating, 1, '.', '')) ?> · <?= $productReviewCount ?> відг.</small></div>
            <?php else: ?>
                <div class="rating rating--empty">☆ <small>0,0 рейтинг</small></div>
            <?php endif; ?>
            <p class="stock stock--<?= e($productStatusClass) ?>"><?= e($productStatusText) ?></p>
            <div class="price-big"><?= money($product['price']) ?> <?php if (!empty($product['old_price'])): ?><del><?= money($product['old_price']) ?></del><?php endif; ?></div>
            <div class="detail-actions" data-product-id="<?= (int)$product['id'] ?>" data-product-name="<?= e($product['name']) ?>" data-product-price="<?= e((string)$product['price']) ?>">
                <?php if (!$isStaffUser && $productCanOrder): ?>
                    <form method="post" class="product-add-form">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="cart_add">
                        <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
                        <input type="hidden" name="return_to" value="/cart">
                        <label class="qty-mini">Кількість
                            <input type="number" name="quantity" value="1" min="1" max="<?= e(max(1, (float)($product['stock'] ?? 1))) ?>" step="1">
                        </label>
                        <button class="btn btn-primary" type="submit">Додати в кошик</button>
                    </form>
                    <button class="btn btn-light" type="button" data-modal="quick" data-quick-product-id="<?= (int)$product['id'] ?>" data-quick-product-name="<?= e($product['name']) ?>">Купити в 1 клік</button>
                    <button class="btn btn-ghost" type="button" data-modal="product-question">Задати питання</button>
                    <button class="btn btn-ghost" type="button" data-modal="notify">Повідомити про появу</button>
                <?php elseif (!$isStaffUser): ?>
                    <div class="stock-warning">
                        <b><?= e(product_status_label($product['status'] ?? '')) ?></b>
                        <span>Зараз товар не додається в кошик. Можна залишити запит або поставити питання менеджеру.</span>
                    </div>
                    <button class="btn btn-ghost" type="button" data-modal="notify">Повідомити про появу</button>
                    <button class="btn btn-ghost" type="button" data-modal="product-question">Задати питання</button>
                <?php else: ?>
                    <div class="staff-product-note">Працівник переглядає товар у службовому режимі. Кошик, бажане та купівля приховані.</div>
                    <button class="btn btn-ghost" type="button" data-modal="product-question">Задати питання</button>
                <?php endif; ?>

                <?php if (!$isStaffUser): ?>
                    <form method="post" class="inline-icon-form">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="wishlist_toggle">
                        <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
                        <input type="hidden" name="return_to" value="/product?id=<?= (int)$product['id'] ?>">
                        <button class="btn btn-ghost <?= $isWished ? 'is-active' : '' ?>" type="submit" data-wishlist><?= $isWished ? '♥ У бажаному' : '♡ Бажане' ?></button>
                    </form>
                    <form method="post" class="inline-icon-form">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="comparison_toggle">
                        <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
                        <input type="hidden" name="return_to" value="/product?id=<?= (int)$product['id'] ?>">
                        <button class="btn btn-ghost <?= $isCompared ? 'is-active' : '' ?>" type="submit" data-compare><?= $isCompared ? '✓ У порівнянні' : '⚖ Порівняти' ?></button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="service-note">
                <b>Отримання:</b> самовивіз зі складу-магазину <?= e($company['address']) ?> або доставка по Києву.
            </div>
        </div>
    </div>
</section>
<section class="section section-muted">
    <div class="container tabs-card reveal">
        <div class="tabs" data-tabs>
            <button class="is-active" data-tab="attrs">Характеристики</button>
            <button data-tab="delivery">Доставка</button>
            <button data-tab="payment">Оплата</button>
            <button data-tab="questions">Питання</button>
            <button data-tab="reviews">Відгуки <?= !empty($product['review_count']) ? '(' . (int)$product['review_count'] . ')' : '' ?></button>
        </div>
        <div class="tab-panel is-active" data-tab-panel="attrs">
            <table class="attrs-table">
                <?php foreach (($product['attrs'] ?? []) as $key => $value): ?>
                    <tr><th><?= e($key) ?></th><td><?= e($value) ?></td></tr>
                <?php endforeach; ?>
                <tr><th>Бренд</th><td><?= e($product['brand'] ?? '') ?></td></tr>
                <tr><th>Одиниця виміру</th><td><?= e($product['unit'] ?? 'шт.') ?></td></tr>
                <tr><th>Категорія</th><td><?= e($product['category_name'] ?? '') ?></td></tr>
            </table>
        </div>
        <div class="tab-panel" data-tab-panel="delivery">
            <p>Доставка по Києву або самовивіз: <?= e($company['address']) ?>. Для габаритних матеріалів менеджер підтверджує умови доставки після оформлення.</p>
            <p>Доступна доставка по Києву, самовивіз і відправлення Новою поштою після підтвердження замовлення.</p>
        </div>
        <div class="tab-panel" data-tab-panel="payment">
            <p>Оплата готівкою, карткою, онлайн-оплата або безготівковий рахунок для юридичних осіб.</p>
            <p>Доступна оплата при отриманні, онлайн-оплата або безготівковий рахунок для юридичних осіб.</p>
        </div>
        <div class="tab-panel" data-tab-panel="questions">
            <p>Маєте питання щодо товару? Його можна надіслати менеджеру прямо з картки товару.</p>
            <div class="detail-actions">
                <button class="btn btn-primary" type="button" data-modal="product-question">Задати питання по товару</button>
                <button class="btn btn-light" type="button" data-open-chat>Написати в чат</button>
            </div>
        </div>
        <div class="tab-panel" data-tab-panel="reviews">
            <div class="product-reviews-head">
                <div>
                    <h3>Відгуки до товару</h3>
                    <p class="muted">Відгук до товару можна залишити після завершеного замовлення з цим товаром.</p>
                </div>
                <div class="rating big-rating <?= $productReviewCount > 0 ? '' : 'rating--empty' ?>"><?= e($productReviewCount > 0 ? $productStars : '☆') ?> <?= e($productReviewCount > 0 ? number_format($productRating, 1, '.', '') : '0,0') ?> <small><?= $productReviewCount ?> відгуків</small></div>
            </div>

            <?php if ($canReviewProduct): ?>
                <form method="post" class="review-form">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="review_create_product">
                    <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
                    <input type="hidden" name="return_to" value="/product?id=<?= (int)$product['id'] ?>">
                    <label>Оцінка
                        <select name="rating">
                            <option value="5">5 — відмінно</option>
                            <option value="4">4 — добре</option>
                            <option value="3">3 — нормально</option>
                            <option value="2">2 — погано</option>
                            <option value="1">1 — дуже погано</option>
                        </select>
                    </label>
                    <label>Ваш відгук
                        <textarea name="review_text" rows="4" placeholder="Опишіть якість товару, доставку або досвід використання" required></textarea>
                    </label>
                    <button class="btn btn-primary" type="submit">Опублікувати відгук</button>
                </form>
            <?php elseif (!$user): ?>
                <p class="muted">Щоб залишити відгук, увійдіть у кабінет.</p>
            <?php else: ?>
                <p class="muted">Форма відгуку відкриється після завершеного замовлення з цим товаром.</p>
            <?php endif; ?>

            <?php if (empty($productReviews)): ?>
                <div class="empty-card">До цього товару ще немає відгуків.</div>
            <?php else: ?>
                <div class="reviews-grid product-reviews-grid">
                    <?php foreach ($productReviews as $review): ?>
                        <article class="review-card">
                            <div class="review-stars"><?= str_repeat('★', max(1, min(5, (int)($review['RATING'] ?? 5)))) ?></div>
                            <p><?= e($review['REVIEW_TEXT'] ?? '') ?></p>
                            <b><?= e($review['CUSTOMER_NAME'] ?? 'Клієнт RungoCraft') ?></b>
                            <small><?= e($review['CREATED_AT'] ?? '') ?></small>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<div class="modal" data-modal-window="product-question" aria-hidden="true">
    <div class="modal__dialog">
        <button class="modal__close" type="button" data-modal-close>×</button>
        <h2>Питання по товару</h2>
        <p><?= e($product['name']) ?></p>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="support_product_question_create">
            <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
            <input type="hidden" name="return_to" value="/product?id=<?= (int)$product['id'] ?>">
            <input type="text" name="name" placeholder="Імʼя" value="<?= e($user['name'] ?? '') ?>">
            <input type="tel" name="phone" placeholder="Телефон" value="<?= e($user['phone'] ?? '') ?>">
            <input type="email" name="email" placeholder="Email" value="<?= e($user['email'] ?? '') ?>">
            <textarea name="message" placeholder="Ваше питання" required></textarea>
            <button class="btn btn-primary" type="submit">Надіслати питання</button>
        </form>
    </div>
</div>

<div class="modal" data-modal-window="notify" aria-hidden="true">
    <div class="modal__dialog">
        <button class="modal__close" type="button" data-modal-close>×</button>
        <h2>Повідомити про появу</h2>
        <p>Залиште контакт, і менеджер повідомить, коли товар буде доступний.</p>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="support_notify_create">
            <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
            <input type="hidden" name="return_to" value="/product?id=<?= (int)$product['id'] ?>">
            <input type="text" name="name" placeholder="Імʼя" value="<?= e($user['name'] ?? '') ?>">
            <input type="tel" name="phone" placeholder="Телефон" value="<?= e($user['phone'] ?? '') ?>">
            <input type="email" name="email" placeholder="Email" value="<?= e($user['email'] ?? '') ?>">
            <button class="btn btn-primary" type="submit">Зберегти запит</button>
        </form>
    </div>
</div>
