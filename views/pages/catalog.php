<?php
$selectedCategory = trim((string)($_GET['category'] ?? ''));
$query = trim((string)($_GET['q'] ?? ''));
$priceMin = trim((string)($_GET['price_min'] ?? ''));
$priceMax = trim((string)($_GET['price_max'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? ''));
$attributeFilters = $catalogAttributeFilters ?? [];
$filterOptions = $catalogFilterOptions ?? [];
$selectedAttributeFilterCount = 0;
foreach ($attributeFilters as $values) {
    $selectedAttributeFilterCount += is_array($values) ? count($values) : 1;
}
$currentCategory = $selectedCategory ? find_category($categories, $selectedCategory) : null;
$flatCategories = flatten_category_tree($categories);
?>
<section class="page-hero">
    <div class="container">
        <p class="breadcrumbs"><a href="/">Головна</a> / Каталог</p>
        <h1><?= $currentCategory ? e($currentCategory['name']) : 'Каталог будівельних матеріалів' ?></h1>
        <p>Фільтри за категоріями, брендами, ціною, наявністю, розміром, вагою, фасуванням і одиницями виміру.</p>
    </div>
</section>
<section class="section">
    <div class="container catalog-layout">
        <aside class="filters reveal">
            <h3>Фільтри</h3>
            <form action="/catalog" method="get" class="filter-form">
                <label>Пошук</label>
                <input type="search" name="q" value="<?= e($query) ?>" placeholder="Назва, бренд, код">
                <label>Категорія</label>
                <select name="category">
                    <option value="">Усі категорії</option>
                    <?php foreach ($flatCategories as $category): ?>
                        <?php $level = (int)($category['level'] ?? 0); ?>
                        <option value="<?= e($category['slug']) ?>" <?= selected($selectedCategory === ($category['slug'] ?? '')) ?>><?= e(str_repeat('— ', $level) . ($category['name'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Ціна, грн</label>
                <div class="range-row">
                    <input type="number" name="price_min" value="<?= e($priceMin) ?>" placeholder="від" min="0">
                    <input type="number" name="price_max" value="<?= e($priceMax) ?>" placeholder="до" min="0">
                </div>
                <label>Сортування</label>
                <select name="sort">
                    <option value="">За замовчуванням</option>
                    <option value="price_asc" <?= selected($sort === 'price_asc') ?>>Ціна ↑</option>
                    <option value="price_desc" <?= selected($sort === 'price_desc') ?>>Ціна ↓</option>
                    <option value="stock" <?= selected($sort === 'stock') ?>>Наявність</option>
                    <option value="name" <?= selected($sort === 'name') ?>>Назва</option>
                </select>
                <label class="check"><input type="checkbox" name="in_stock" value="1" <?= checked(!empty($_GET['in_stock'])) ?>> В наявності</label>

                <?php if (!empty($filterOptions)): ?>
                    <div class="attribute-filter-group">
                        <h4>Фільтри за характеристиками</h4>
                        <p class="filter-hint">Показуються реальні параметри товарів обраної категорії.</p>
                        <?php foreach ($filterOptions as $attrName => $values): ?>
                            <?php $selectedValues = $attributeFilters[$attrName] ?? []; $selectedValues = is_array($selectedValues) ? $selectedValues : [$selectedValues]; ?>
                            <div class="attribute-filter-block">
                                <label class="attribute-filter-title"><?= e($attrName) ?></label>
                                <div class="attribute-check-list">
                                    <?php foreach ($values as $item): ?>
                                        <label class="attribute-check">
                                            <input type="checkbox" name="attr[<?= e($attrName) ?>][]" value="<?= e($item['value']) ?>" <?= checked(in_array($item['value'], $selectedValues, true)) ?>>
                                            <span><?= e($item['value']) ?></span>
                                            <small><?= (int)$item['count'] ?></small>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="filter-actions-row">
                    <button class="btn btn-primary btn-full" type="submit">Застосувати</button>
                    <a class="btn btn-light btn-full" href="/catalog<?= $selectedCategory ? '?category=' . urlencode($selectedCategory) : '' ?>">Скинути</a>
                </div>
            </form>
            <h4>Категорії</h4>
            <a class="filter-link <?= $selectedCategory === '' ? 'is-active' : '' ?>" href="/catalog">Усі товари</a>
            <?php foreach ($flatCategories as $category): ?>
                <?php $level = (int)($category['level'] ?? 0); ?>
                <a class="filter-link <?= $level === 0 ? 'filter-link-main' : 'filter-link-child' ?> <?= $selectedCategory === ($category['slug'] ?? '') ? 'is-active' : '' ?>" style="margin-left: <?= $level * 12 ?>px" href="/catalog?category=<?= e($category['slug'] ?? '') ?>">
                    <?= $level > 0 ? e(str_repeat('— ', $level)) : '' ?><?= e($category['name'] ?? '') ?>
                </a>
            <?php endforeach; ?>
        </aside>
        <div class="catalog-content">
            <div class="catalog-toolbar reveal">
                <span>Знайдено: <b><?= count($products) ?></b><?php if ($selectedAttributeFilterCount > 0): ?> · фільтрів: <?= $selectedAttributeFilterCount ?><?php endif; ?></span>
                <div>
                    <button class="toolbar-btn is-active" type="button" data-grid="grid">▦</button>
                    <button class="toolbar-btn" type="button" data-grid="list">☷</button>
                </div>
            </div>
            <?php if ($products): ?>
                <div class="product-grid" data-products-grid>
                    <?php foreach ($products as $product): ?>
                        <?php require __DIR__ . '/../partials/product-card.php'; ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-card reveal">
                    <h2>Товари не знайдено</h2>
                    <p>Змініть фільтри або напишіть менеджеру в чат підтримки.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
