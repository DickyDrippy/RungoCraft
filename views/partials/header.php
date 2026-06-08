<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="<?= e($pwaSettings['theme_color'] ?? '#004574') ?>">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($company['name']) ?> - інтернет-магазин будівельних матеріалів</title>
    <meta name="description" content="<?= e($company['name']) ?> - будівельні матеріали, склад-магазин у Києві, онлайн-замовлення та менеджерський супровід.">
    <link rel="icon" type="image/png" href="/assets/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/assets/img/logo.png">
    <link rel="apple-touch-icon" href="/assets/img/logo.png">
    <link rel="stylesheet" href="/assets/css/style.css?v=45">
    <link rel="stylesheet" href="/assets/css/cart.css?v=45">
    <link rel="stylesheet" href="/assets/css/account.css?v=45">
    <link rel="stylesheet" href="/assets/css/support.css">
    <link rel="stylesheet" href="/assets/css/delivery.css?v=65">
    <link rel="stylesheet" href="/assets/css/payment.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
    <link rel="stylesheet" href="/assets/css/analytics.css">
    <link rel="stylesheet" href="/assets/css/notifications.css">
    <link rel="stylesheet" href="/assets/css/warehouse.css?v=65">
    <link rel="stylesheet" href="/assets/css/business.css">
    <script defer src="/assets/js/main.js?v=66"></script>
    <script defer src="/assets/js/auth.js"></script>
    <script defer src="/assets/js/analytics.js"></script>
    <script defer src="/assets/js/notifications.js"></script>
</head>
<body>
<?php $isStaffUser = has_role(['manager','warehouse','admin']); ?>
<header class="site-header">
    <div class="container header-main">
        <a class="brand brand-logo" href="/" aria-label="RungoCraft">
            <img src="/assets/img/logo.png" alt="RungoCraft">
            <span class="brand__text"><b><?= e($company['name']) ?></b><small><?= e($company['tagline']) ?></small></span>
        </a>
        <div class="header-contact-stack">
            <a class="header-phone" href="tel:<?= e($company['phone']) ?>">
                <span>☎</span>
                <b><?= e($company['phone_label']) ?></b>
            </a>
            <div class="header-info header-info-inline">
                <span><?= e($company['worktime']) ?></span>
                <a href="/contacts">⌖ <?= e($company['address']) ?></a>
            </div>
        </div>
        <div class="header-user-actions">
            <?php if ($isStaffUser): ?>
                <a class="icon-link employee-link" href="/admin" title="Панель працівника">🛠 <span>Панель</span></a>
                <a class="icon-link" href="/account?tab=profile" title="Профіль працівника">👤 <span>Профіль</span></a>
            <?php else: ?>
                <a class="icon-link" href="/account" title="Особистий кабінет">👤 <span>Кабінет</span></a>
                <a class="cart-pill" href="/cart" title="Кошик">🛒 <span data-cart-total><?= (int)($cartData['count'] ?? 0) ?></span></a>
                <a class="icon-link" href="/account?tab=wishlist" title="Бажане">♡ <span>Бажане</span><?php if (($wishlistCount ?? 0) > 0): ?><b class="mini-count"><?= (int)$wishlistCount ?></b><?php endif; ?></a>
                <a class="icon-link" href="/account?tab=comparison" title="Порівняння">⚖ <span>Порівняння</span><?php if (($comparisonCount ?? 0) > 0): ?><b class="mini-count"><?= (int)$comparisonCount ?></b><?php endif; ?></a>
            <?php endif; ?>
        </div>
    </div>
    <nav class="main-nav">
        <div class="container nav-inner">
            <button class="menu-btn" type="button" data-menu-toggle aria-expanded="false">☰ <span>Меню</span></button>
            <button class="catalog-btn" type="button" data-catalog-toggle aria-expanded="false">▤ Каталог товарів</button>
            <a class="nav-link <?= active($currentRoute, 'catalog') ?>" href="/catalog">Каталог</a>
            <a class="nav-link <?= active($currentRoute, 'delivery') ?>" href="/delivery">Доставка та оплата</a>
            <a class="nav-link <?= active($currentRoute, 'wholesale') ?>" href="/wholesale">Опт</a>
            <a class="nav-link <?= active($currentRoute, 'support') ?>" href="/support">Підтримка</a>
            <form class="search-form" action="/catalog" method="get">
                <input type="search" name="q" placeholder="Гіпсокартон, цемент, профіль..." value="<?= e($_GET['q'] ?? '') ?>">
                <button type="submit">⌕</button>
            </form>
            <?php if (!$isStaffUser): ?><button class="calc-btn" type="button" data-modal="calc">Замовити розрахунок</button><?php endif; ?>
        </div>
        <div class="container menu-panel" data-menu-panel>
            <a href="/delivery">Доставка товарів</a>
            <a href="/delivery#payment">Умови оплати</a>
            <a href="/wholesale">Опт</a>
            <a href="/support">Підтримка та FAQ</a>
            <a href="/contacts">Контакти</a>
            <?php if ($isStaffUser): ?>
                <a href="/admin">Панель працівника</a>
                <a href="/account?tab=profile">Профіль працівника</a>
            <?php else: ?>
                <a href="/account">Особистий кабінет</a>
                <a href="/account?tab=wishlist">Список бажаного</a>
                <a href="/account?tab=comparison">Порівняння товарів</a>
            <?php endif; ?>
        </div>
        <div class="container catalog-panel" data-catalog-panel>
            <div class="catalog-panel__list">
                <?php
                $renderCatalogBranch = function (array $items, int $level = 1) use (&$renderCatalogBranch): void {
                    foreach ($items as $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        $children = is_array($item['children'] ?? null) ? $item['children'] : [];
                        $hasChildren = $children !== [];
                        ?>
                        <div class="catalog-subrow catalog-subrow--level-<?= min($level, 5) ?> <?= $hasChildren ? 'has-children' : '' ?>" data-catalog-branch>
                            <div class="catalog-subrow__head">
                                <a class="catalog-tree-link" href="/catalog?category=<?= e($item['slug'] ?? '') ?>"><?= e($item['name'] ?? '') ?></a>
                                <?php if ($hasChildren): ?>
                                    <button class="catalog-subrow__toggle" type="button" data-catalog-branch-toggle aria-expanded="false" aria-label="Розгорнути підкатегорії <?= e($item['name'] ?? '') ?>">⌄</button>
                                <?php endif; ?>
                            </div>
                            <?php if ($hasChildren): ?>
                                <div class="catalog-subrow__children">
                                    <?php $renderCatalogBranch($children, $level + 1); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php
                    }
                };
                ?>
                <?php if (!empty($categories)): ?>
                    <?php foreach ($categories as $index => $category): ?>
                        <div class="catalog-row <?= $index === 0 ? 'is-open' : '' ?>">
                            <button type="button" data-category-row aria-expanded="<?= $index === 0 ? 'true' : 'false' ?>">
                                <span class="cat-icon"><?= e($category['icon'] ?? '▦') ?></span>
                                <span><?= e($category['name']) ?></span>
                                <b>⌄</b>
                            </button>
                            <div class="catalog-row__children catalog-row__children--tree">
                                <a class="catalog-tree-link catalog-tree-link--all" href="/catalog?category=<?= e($category['slug']) ?>">Усі товари розділу</a>
                                <?php $renderCatalogBranch(($category['children'] ?? []), 1); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="catalog-empty">Категорії ще не додані в базу даних.</div>
                <?php endif; ?>
            </div>
        </div>
    </nav>
</header>
