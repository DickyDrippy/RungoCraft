<?php
$reviewCount = (int)($product['review_count'] ?? 0);
$ratingValue = $reviewCount > 0 ? (float)($product['rating'] ?? 0) : 0.0;
$ratingStarsFilled = $reviewCount > 0 ? max(1, min(5, (int)round($ratingValue))) : 0;
$ratingStars = $ratingStarsFilled > 0 ? str_repeat('★', $ratingStarsFilled) . str_repeat('☆', 5 - $ratingStarsFilled) : '☆';
$isStaffUser = has_role(['manager','warehouse','admin']);
$isWished = !empty($product['is_wished']);
$isCompared = !empty($product['is_compared']);
$productStatusClass = product_status_class($product['status'] ?? 'in_stock');
$productStatusText = product_status_text($product);
$productCanOrder = product_can_order($product);
?>
<article class="product-card reveal <?= $isWished ? 'is-in-wishlist' : '' ?> <?= $isCompared ? 'is-in-comparison' : '' ?>" data-product-id="<?= (int)$product['id'] ?>" data-product-name="<?= e($product['name']) ?>" data-product-price="<?= e((string)$product['price']) ?>">
    <div class="product-card__image-wrap">
        <a class="product-card__image" href="/product?id=<?= (int)$product['id'] ?>">
            <img src="<?= e(media_url($product['image'] ?? 'cement.svg')) ?>" alt="<?= e($product['name']) ?>">
        </a>
        <?php if (!$isStaffUser): ?>
        <form method="post" class="tiny-action-form product-card__wish-form">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="wishlist_toggle">
            <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
            <input type="hidden" name="return_to" value="<?= e($_SERVER['REQUEST_URI'] ?? '/catalog') ?>">
            <button class="wish-btn wish-btn--overlay <?= $isWished ? 'is-active' : '' ?>" type="submit" data-wishlist title="<?= $isWished ? 'Вже у бажаному' : 'Додати в бажане' ?>" aria-label="<?= $isWished ? 'Вже у бажаному' : 'Додати в бажане' ?>"><?= $isWished ? '♥' : '♡' ?></button>
        </form>
        <?php endif; ?>
    </div>
    <div class="product-card__body">
        <div class="product-card__top">
            <span><?= e($product['brand'] ?? '') ?></span>
        </div>
        <h3><a href="/product?id=<?= (int)$product['id'] ?>"><?= e($product['name']) ?></a></h3>
        <?php if ($reviewCount > 0): ?>
            <div class="rating"><?= e($ratingStars) ?> <small><?= e(number_format($ratingValue, 1, '.', '')) ?> · <?= $reviewCount ?> відг.</small></div>
        <?php else: ?>
            <div class="rating rating--empty">☆ <small>0,0 рейтинг</small></div>
        <?php endif; ?>
        <ul class="mini-attrs">
            <?php foreach (array_slice(($product['attrs'] ?? []), 0, 3, true) as $key => $value): ?>
                <li><span><?= e($key) ?></span><b><?= e($value) ?></b></li>
            <?php endforeach; ?>
        </ul>
        <p class="stock stock--<?= e($productStatusClass) ?>"><?= e($productStatusText) ?></p>
        <div class="price-row">
            <strong><?= money($product['price']) ?></strong>
            <?php if (!empty($product['old_price'])): ?><del><?= money($product['old_price']) ?></del><?php endif; ?>
        </div>
        <?php if (!$isStaffUser && $productCanOrder): ?>
        <div class="product-actions">
            <form method="post" class="inline-cart-form">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="cart_add">
                <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
                <input type="hidden" name="quantity" value="1">
                <input type="hidden" name="return_to" value="<?= e($_SERVER['REQUEST_URI'] ?? '/catalog') ?>">
                <button class="btn btn-primary" type="submit">В кошик</button>
            </form>
            <button class="btn btn-light quick-buy-btn" type="button" data-modal="quick" data-quick-product-id="<?= (int)$product['id'] ?>" data-quick-product-name="<?= e($product['name']) ?>">1 клік</button>
            <form method="post" class="tiny-action-form compare-form">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="comparison_toggle">
                <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
                <input type="hidden" name="return_to" value="<?= e($_SERVER['REQUEST_URI'] ?? '/catalog') ?>">
                <button class="compare-btn <?= $isCompared ? 'is-active' : '' ?>" type="submit" data-compare title="<?= $isCompared ? 'Вже у порівнянні' : 'Додати до порівняння' ?>" aria-label="<?= $isCompared ? 'Вже у порівнянні' : 'Додати до порівняння' ?>">⚖</button>
            </form>
        </div>
        <?php elseif (!$isStaffUser): ?>
        <div class="product-actions product-actions--unavailable">
            <a class="btn btn-light" href="/product?id=<?= (int)$product['id'] ?>">Переглянути картку</a>
            <span class="product-unavailable-note"><?= e(product_status_label($product['status'] ?? '')) ?></span>
            <form method="post" class="tiny-action-form compare-form">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="comparison_toggle">
                <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
                <input type="hidden" name="return_to" value="<?= e($_SERVER['REQUEST_URI'] ?? '/catalog') ?>">
                <button class="compare-btn <?= $isCompared ? 'is-active' : '' ?>" type="submit" data-compare title="<?= $isCompared ? 'Вже у порівнянні' : 'Додати до порівняння' ?>" aria-label="<?= $isCompared ? 'Вже у порівнянні' : 'Додати до порівняння' ?>">⚖</button>
            </form>
        </div>
        <?php else: ?>
            <a class="btn btn-light" href="/product?id=<?= (int)$product['id'] ?>">Переглянути картку</a>
        <?php endif; ?>
    </div>
</article>
