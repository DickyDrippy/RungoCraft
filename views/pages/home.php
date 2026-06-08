<section class="hero hero-clean">
    <div class="container hero-grid">
        <div class="hero-content reveal">
            <span class="eyebrow">Склад-магазин у Києві · <?= e($company['address']) ?></span>
            <h1>Будівельні матеріали та інструменти для ремонту й будівництва</h1>
            <p>Підберіть матеріали за категоріями, обирайте потрібні товари або надішліть список для подальшого розрахунку персоналом.</p>
            <div class="hero-actions">
                <a class="btn btn-primary" href="/catalog">Перейти в каталог</a>
                <button class="btn btn-light" type="button" data-modal="calc">Замовити розрахунок</button>
            </div>
        </div>

        <div class="hero-logo-card reveal">
            <img src="/assets/img/logo.png" alt="RungoCraft">
        </div>
    </div>
</section>

<section class="section services-under-hero">
    <div class="container service-strip">
        <a class="service-card reveal" href="/delivery"><span>🚚</span><b>Доставка</b><small>по Києву та самовивіз</small></a>
        <a class="service-card reveal" href="/wholesale"><span>🧾</span><b>Рахунок</b><small>для ФОП та юридичних осіб</small></a>
        <button class="service-card reveal" type="button" data-open-chat><span>💬</span><b>Підтримка</b><small>чат із менеджером</small></button>
        <button class="service-card reveal" type="button" data-modal="calc"><span>📐</span><b>Розрахунок</b><small>підбір за списком матеріалів</small></button>
    </div>
</section>

<section class="section popular-category-section">
    <div class="container section-head">
        <div>
            <span class="eyebrow">Категорії</span>
            <h2>Популярні категорії</h2>
            <p>Основні розділи каталогу доступні на головній сторінці. Асортимент оновлюється через панель керування.</p>
        </div>
        <a href="/catalog">Усі товари →</a>
    </div>

    <div class="container category-grid popular-category-grid">
        <?php $homeCategories = $popularCategories ?? $categories ?? []; ?>
        <?php if (!empty($homeCategories)): ?>
            <?php foreach ($homeCategories as $category): ?>
                <a class="category-card reveal" href="/catalog?category=<?= e($category['slug']) ?>">
                    <span><?= e($category['icon'] ?? '▦') ?></span>
                    <h3><?= e($category['name']) ?></h3>
                    <p>Переглянути товари розділу</p>
                </a>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-card wide-card">Категорії ще не додані в базу даних.</div>
        <?php endif; ?>
    </div>
</section>

<section class="section catalog-home-section">
    <div class="container section-head">
        <div>
            <span class="eyebrow">Каталог</span>
            <h2>Каталог товарів</h2>
        </div>
        <a href="/catalog">Усі товари →</a>
    </div>

    <?php $groups = $catalogGroups ?? []; ?>
    <?php if (!empty($groups)): ?>
        <div class="container catalog-directory reveal" data-catalog-directory>
            <h3>Каталог товарів</h3>

            <div class="catalog-directory-tabs" role="tablist">
                <?php foreach ($groups as $index => $group): ?>
                    <button
                        type="button"
                        class="<?= $index === 0 ? 'is-active' : '' ?>"
                        data-catalog-dir-tab="<?= e($group['code']) ?>"
                    ><?= e($group['title']) ?></button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($groups as $index => $group): ?>
                <div class="catalog-directory-panel <?= $index === 0 ? 'is-active' : '' ?>" data-catalog-dir-panel="<?= e($group['code']) ?>">
                    <?php foreach ($group['items'] as $item): ?>
                        <a href="/catalog?q=<?= urlencode($item['name']) ?>"><?= e($item['name']) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="container empty-card reveal">Алфавітний каталог ще не доданий у базу даних.</div>
    <?php endif; ?>
</section>

<?php if (!empty($reviews ?? [])): ?>
<section class="section section-muted">
    <div class="container section-head">
        <div>
            <span class="eyebrow">Відгуки</span>
            <h2>Відгуки клієнтів</h2>
            <p>Відгук можна залишити після успішного замовлення.</p>
        </div>
    </div>

    <div class="container reviews-grid">
        <?php foreach (array_slice($reviews, 0, 3) as $review): ?>
            <article class="review-card reveal">
                <div class="review-stars"><?= str_repeat('★', max(1, min(5, (int)($review['rating'] ?? 5)))) . str_repeat('☆', 5 - max(1, min(5, (int)($review['rating'] ?? 5)))) ?></div>
                <?php if (($review['type'] ?? 'site') === 'product' && !empty($review['product_name'])): ?>
                    <small class="review-context">Відгук до товару: <?= e($review['product_name']) ?></small>
                <?php else: ?>
                    <small class="review-context">Відгук про сервіс RungoCraft</small>
                <?php endif; ?>
                <p><?= e($review['text']) ?></p>
                <b><?= e($review['author'] ?? $review['name'] ?? 'Клієнт RungoCraft') ?></b>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
