<?php
$activeTab = (string)($_GET['tab'] ?? (has_role(['manager','warehouse','admin']) ? 'profile' : 'overview'));
if (has_role(['manager','warehouse','admin']) && !in_array($activeTab, ['profile','notifications'], true)) {
    $activeTab = 'profile';
}
$profile = $accountData['profile'] ?? [];
$orders = $accountData['orders'] ?? [];
$wishlist = $accountData['wishlist'] ?? [];
$comparison = $accountData['comparison'] ?? [];
$addresses = $accountData['addresses'] ?? [];
$notifications = $accountData['notifications'] ?? [];
$statusLabels = $accountData['status_labels'] ?? [];
$comparisonAttrs = [];
foreach ($comparison as $item) {
    foreach (($item['attrs'] ?? []) as $name => $value) {
        $comparisonAttrs[$name] = true;
    }
}
$comparisonAttrNames = array_keys($comparisonAttrs);
$comparisonByCategory = [];
foreach ($comparison as $item) {
    $categoryName = (string)($item['category_name'] ?? 'Інші товари');
    if (!isset($comparisonByCategory[$categoryName])) {
        $comparisonByCategory[$categoryName] = [
            'items' => [],
            'attrs' => [],
        ];
    }
    $comparisonByCategory[$categoryName]['items'][] = $item;
    foreach (($item['attrs'] ?? []) as $name => $value) {
        $comparisonByCategory[$categoryName]['attrs'][$name] = true;
    }
}
?>
<section class="page-hero account-hero">
    <div class="container">
        <p class="breadcrumbs"><a href="/">Головна</a> / Кабінет</p>
        <h1>Особистий кабінет</h1>
        <p>Переглядайте замовлення, адреси доставки, бажане, порівняння та особисті налаштування.</p>
    </div>
</section>

<?php if (!$user): ?>
<link rel="stylesheet" href="/assets/css/auth.css?v=17">
<link rel="stylesheet" href="/assets/css/recaptcha-security.css?v=17">
<script defer src="/assets/js/auth.js?v=17"></script>
<script defer src="/assets/js/recaptcha-auth.js?v=17"></script>
<?php
$recaptchaSiteKey = isset($auth) && method_exists($auth, 'recaptchaSiteKey') ? $auth->recaptchaSiteKey() : '';
$recaptchaProvider = isset($auth) && method_exists($auth, 'recaptchaProvider') ? $auth->recaptchaProvider() : 'classic';
?>
<?php if ($recaptchaSiteKey !== ''): ?>
<?php if ($recaptchaProvider === 'enterprise'): ?>
<script src="https://www.google.com/recaptcha/enterprise.js?render=<?= e($recaptchaSiteKey) ?>"></script>
<?php else: ?>
<script src="https://www.google.com/recaptcha/api.js?render=<?= e($recaptchaSiteKey) ?>"></script>
<?php endif; ?>
<script>
window.RC_RECAPTCHA_SITE_KEY = <?= json_encode($recaptchaSiteKey) ?>;
window.RC_RECAPTCHA_PROVIDER = <?= json_encode($recaptchaProvider) ?>;
</script>
<?php endif; ?>
<section class="section auth-section">
    <div class="container auth-layout auth-layout--single">
        <div class="login-card auth-card reveal">
            <div class="auth-tabs" data-tabs>
                <button class="is-active" type="button" data-tab="login">Вхід</button>
                <button type="button" data-tab="register">Реєстрація</button>
                <button class="auth-tab-forgot" type="button" data-tab="forgot">Забули пароль?</button>
            </div>

            <div class="tab-panel is-active" data-tab-panel="login">
                <div class="auth-headline">
                    <span>Єдиний вхід</span>
                    <h2>Увійти в кабінет</h2>
                    <p>Email підтверджується одночасно паролем і кодом з листа. Телефон входить через SMS-код.</p>
                </div>

                <div data-auth-message-root></div>

                <form method="post" data-login-mode class="auth-main-form" data-recaptcha-action="login">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="purpose" value="login">
                    <input type="hidden" name="return_to" value="/account">

                    <label>Email або номер телефону</label>
                    <div class="auth-inline auth-inline--contact">
                        <input type="text" name="login" placeholder="email@site.ua або +380..." required data-login-input data-code-destination autocomplete="username">
                        <button class="btn btn-light" type="button" data-auth-send-code data-auth-purpose="login">Отримати код</button>
                    </div>
                    <small class="hint" data-auth-contact-hint>Для email введіть пароль і код з листа. Для телефону достатньо SMS-коду.</small>

                    <div data-email-password-block>
                        <label>Пароль</label>
                        <input type="password" name="password" placeholder="Пароль від акаунта" data-password-input autocomplete="current-password">
                    </div>

                    <div data-login-code-block>
                        <label data-code-label>Код підтвердження</label>
                        <input type="text" name="code" placeholder="Код з email або SMS" required data-login-code-input autocomplete="one-time-code" inputmode="numeric">
                        <small class="hint" data-auth-hint>Натисніть “Отримати код”, дочекайтесь листа або SMS і введіть код тут.</small>
                    </div>

                    <button class="btn btn-primary btn-full" type="submit">Увійти</button>
                </form>
            </div>

            <div class="tab-panel" data-tab-panel="register">
                <div class="auth-headline">
                    <span>Новий користувач</span>
                    <h2>Реєстрація</h2>
                    <p>Заповніть ПІБ, один основний контакт і підтвердіть його кодом.</p>
                </div>

                <div data-auth-message-root></div>

                <form method="post" data-register-form class="auth-main-form" data-recaptcha-action="register">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="register">
                    <input type="hidden" name="purpose" value="register">
                    <input type="hidden" name="return_to" value="/account">
                    <input type="hidden" name="full_name" data-full-name>

                    <label>Прізвище</label>
                    <input type="text" name="last_name" placeholder="Прізвище" required autocomplete="family-name" data-last-name>

                    <label>Імʼя</label>
                    <input type="text" name="first_name" placeholder="Імʼя" required autocomplete="given-name" data-first-name>

                    <label>По батькові</label>
                    <input type="text" name="middle_name" placeholder="По батькові" autocomplete="additional-name" data-middle-name>

                    <label>Телефон або email</label>
                    <div class="auth-inline auth-inline--contact">
                        <input type="text" name="register_login" placeholder="+380... або email@site.ua" required data-register-login data-code-destination autocomplete="username">
                        <button class="btn btn-light" type="button" data-auth-send-code data-auth-purpose="register">Отримати код</button>
                    </div>
                    <small class="hint">Це єдиний контакт для реєстрації. Повторний акаунт на той самий телефон або email створити не можна.</small>

                    <div data-register-password-block>
                        <label>Пароль</label>
                        <input type="password" name="password" placeholder="Мінімум 6 символів для email-входу" autocomplete="new-password">
                        <small class="hint">Для email пароль обовʼязковий. Для телефону можна входити через SMS-код.</small>
                    </div>

                    <div class="is-hidden" data-register-code-block>
                        <label>Код підтвердження</label>
                        <input type="text" name="code" placeholder="Код з SMS або email" inputmode="numeric" autocomplete="one-time-code" data-register-code-input>
                        <small class="hint">Поле активується після натискання “Отримати код”.</small>
                    </div>

                    <?php if ($recaptchaSiteKey === ''): ?>
                        <label>Локальна перевірка безпеки: скільки буде 3 + 4?</label>
                        <input type="text" name="captcha" required>
                    <?php endif; ?>

                    <button class="btn btn-primary btn-full" type="submit">Зареєструватися</button>
                </form>
            </div>

            <div class="tab-panel" data-tab-panel="forgot">
                <div class="auth-headline">
                    <span>Відновлення доступу</span>
                    <h2>Забули пароль?</h2>
                    <p>Введіть email, і ми надішлемо посилання для створення нового пароля.</p>
                </div>

                <form method="post" class="auth-main-form" data-recaptcha-action="password_reset_request">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="auth_password_reset_request">
                    <label>Email</label>
                    <input type="email" name="email" placeholder="email@site.ua" required autocomplete="email">
                    <button class="btn btn-primary btn-full" type="submit">Надіслати посилання</button>
                </form>
            </div>
        </div>
    </div>
</section>
<?php else: ?>
<section class="section account-section">
    <div class="container account-shell">
        <aside class="account-sidebar reveal">
            <div class="account-user-card">
                <strong><?= e($profile['full_name'] ?? $user['name']) ?></strong>
                <span><?= e($profile['role_name'] ?? ($roles[$user['role']] ?? $user['role'])) ?></span>
            </div>

            <nav class="account-nav">
                <?php if (has_role(['manager','warehouse','admin'])): ?>
                    <a href="/admin">Панель працівника</a>
                    <a class="<?= $activeTab === 'profile' ? 'is-active' : '' ?>" href="/account?tab=profile">Профіль працівника</a>
                    <a class="<?= $activeTab === 'notifications' ? 'is-active' : '' ?>" href="/account?tab=notifications">Сповіщення</a>
                <?php else: ?>
                    <a class="<?= $activeTab === 'overview' ? 'is-active' : '' ?>" href="/account?tab=overview">Огляд</a>
                    <a class="<?= $activeTab === 'orders' ? 'is-active' : '' ?>" href="/account?tab=orders">Замовлення <b><?= count($orders) ?></b></a>
                    <a class="<?= $activeTab === 'wishlist' ? 'is-active' : '' ?>" href="/account?tab=wishlist">Бажане <b><?= count($wishlist) ?></b></a>
                    <a class="<?= $activeTab === 'comparison' ? 'is-active' : '' ?>" href="/account?tab=comparison">Порівняння <b><?= count($comparison) ?></b></a>
                    <a class="<?= $activeTab === 'addresses' ? 'is-active' : '' ?>" href="/account?tab=addresses">Адреси доставки</a>
                    <a class="<?= $activeTab === 'profile' ? 'is-active' : '' ?>" href="/account?tab=profile">Профіль</a>
                    <a class="<?= $activeTab === 'notifications' ? 'is-active' : '' ?>" href="/account?tab=notifications">Сповіщення</a>
                <?php endif; ?>
            </nav>

            <form method="post">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="btn btn-light btn-full" type="submit">Вийти</button>
            </form>
        </aside>

        <main class="account-content reveal">
            <?php if ($activeTab === 'overview'): ?>
                <div class="account-headline">
                    <p>Кабінет</p>
                    <h2>Вітаємо, <?= e($profile['full_name'] ?? $user['name']) ?></h2>
                </div>

                <div class="account-stats-grid">
                    <a href="/account?tab=orders"><b><?= count($orders) ?></b><span>замовлень</span></a>
                    <a href="/account?tab=wishlist"><b><?= count($wishlist) ?></b><span>у бажаному</span></a>
                    <a href="/account?tab=comparison"><b><?= count($comparison) ?></b><span>у порівнянні</span></a>
                    <a href="/account?tab=addresses"><b><?= count($addresses) ?></b><span>адрес доставки</span></a>
                </div>

                <div class="account-panel">
                    <h3>Останні замовлення</h3>
                    <?php if (empty($orders)): ?>
                        <p class="muted">Замовлень ще немає. Перейдіть у каталог і додайте товари в кошик.</p>
                        <a class="btn btn-primary" href="/catalog">Перейти в каталог</a>
                    <?php else: ?>
                        <div class="orders-list compact-orders">
                            <?php foreach (array_slice($orders, 0, 3) as $order): ?>
                                <div class="order-card-mini">
                                    <div><b>№<?= (int)$order['id'] ?></b><span><?= e($order['created_at']) ?></span></div>
                                    <strong><?= money($order['total_amount']) ?></strong>
                                    <em><?= e(order_status_label($order['status'])) ?></em>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($activeTab === 'orders'): ?>
                <div class="account-headline"><p>Історія</p><h2>Мої замовлення</h2></div>
                <?php if (empty($orders)): ?>
                    <div class="empty-state"><h3>Замовлень ще немає</h3><p>Після оформлення покупки тут зʼявиться історія, статуси та повтор замовлення.</p><a class="btn btn-primary" href="/catalog">До каталогу</a></div>
                <?php else: ?>
                    <div class="orders-list">
                        <?php foreach ($orders as $order): ?>
                            <article class="order-card">
                                <header>
                                    <div><span>Замовлення</span><h3>№<?= (int)$order['id'] ?></h3></div>
                                    <div><span>Статус</span><b><?= e(order_status_label($order['status'])) ?></b></div>
                                    <div><span>Сума</span><b><?= money($order['total_amount']) ?></b></div>
                                    <div><span>Дата</span><b><?= e($order['created_at']) ?></b></div>
                                </header>

                                <div class="order-details-grid">
                                    <div><span>Покупець</span><b><?= e($order['customer_name'] ?: ($profile['full_name'] ?? $user['name'])) ?></b></div>
                                    <div><span>Телефон</span><b><?= e($order['customer_phone']) ?></b></div>
                                    <?php if (!empty($order['customer_email'])): ?><div><span>Email</span><b><?= e($order['customer_email']) ?></b></div><?php endif; ?>
                                    <div><span>Доставка</span><b><?= e(delivery_type_label($order['delivery_type'])) ?></b></div>
                                    <?php if (!empty($order['delivery_address'])): ?><div class="span-2"><span>Адреса / відділення</span><b><?= e($order['delivery_address']) ?></b></div><?php endif; ?>
                                    <div><span>Оплата</span><b><?= e(payment_type_label($order['payment_type'])) ?></b></div>
                                    <?php if (!empty($order['comment_text'])): ?><div class="span-2"><span>Коментар</span><b><?= e($order['comment_text']) ?></b></div><?php endif; ?>
                                    <?php if (!empty($order['updated_at'])): ?><div><span>Оновлено</span><b><?= e($order['updated_at']) ?></b></div><?php endif; ?>
                                </div>

                                <div class="order-items-title">Склад замовлення</div>
                                <div class="order-items-table">
                                    <?php foreach ($order['items'] as $item): ?>
                                        <div>
                                            <span><?= e($item['PRODUCT_NAME'] ?? '') ?><small><?= e($item['SKU'] ?? '') ?></small></span>
                                            <b><?= e((string)($item['QUANTITY'] ?? 0)) ?> <?= e($item['UNIT'] ?? '') ?></b>
                                            <strong><?= money($item['LINE_TOTAL'] ?? 0) ?></strong>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php if (!empty($order['history'])): ?>
                                    <div class="status-timeline">
                                        <?php foreach ($order['history'] as $history): ?>
                                            <span><b><?= e(order_status_label($history['STATUS'])) ?></b><small><?= e($history['CREATED_AT']) ?></small></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <form method="post" class="order-actions">
                                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="repeat_order">
                                    <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                                    <input type="hidden" name="return_to" value="/cart">
                                    <button class="btn btn-primary" type="submit">Повторити замовлення</button>
                                </form>

                                <?php
                                    $canReviewOrder = isset($reviewService) ? $reviewService->canReviewOrder($user, (int)$order['id']) : false;
                                    $alreadyReviewedOrder = isset($reviewService) ? $reviewService->alreadyReviewedService($user) : false;
                                ?>
                                <?php if ($canReviewOrder && !$alreadyReviewedOrder): ?>
                                    <form method="post" class="order-review-form">
                                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="review_create_order">
                                        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                                        <input type="hidden" name="return_to" value="/account?tab=orders">
                                        <h4>Залишити відгук про сервіс RungoCraft</h4>
                                        <div class="form-grid two">
                                            <label>Оцінка
                                                <select name="rating">
                                                    <option value="5">5 — відмінно</option>
                                                    <option value="4">4 — добре</option>
                                                    <option value="3">3 — нормально</option>
                                                    <option value="2">2 — погано</option>
                                                    <option value="1">1 — дуже погано</option>
                                                </select>
                                            </label>
                                            <label>Відгук
                                                <textarea name="review_text" rows="3" placeholder="Оцініть роботу сервісу: оформлення, звʼязок менеджера, доставку та загальне враження" required></textarea>
                                            </label>
                                        </div>
                                        <button class="btn btn-light" type="submit">Опублікувати відгук про сервіс</button>
                                    </form>
                                <?php elseif ($alreadyReviewedOrder): ?>
                                    <p class="muted">Відгук про сервіс уже залишено. Один клієнт може залишити тільки один сервісний відгук.</p>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($activeTab === 'wishlist'): ?>
                <div class="account-headline"><p>Бажане</p><h2>Список бажаного</h2></div>
                <?php if (empty($wishlist)): ?>
                    <div class="empty-state"><h3>Список бажаного порожній</h3><p>Додавайте товари кнопкою “♡” у каталозі або картці товару.</p><a class="btn btn-primary" href="/catalog">Перейти до товарів</a></div>
                <?php else: ?>
                    <div class="account-products-grid">
                        <?php foreach ($wishlist as $item): ?>
                            <article class="account-product-card">
                                <a class="account-product-image" href="/product?id=<?= (int)$item['id'] ?>"><img src="<?= e(media_url($item['image'] ?? 'cement.svg')) ?>" alt="<?= e($item['name']) ?>"></a>
                                <div><a class="account-product-title" href="/product?id=<?= (int)$item['id'] ?>"><b><?= e($item['name']) ?></b></a><span><?= e($item['brand']) ?> · <?= e($item['category_name']) ?></span><strong><?= money($item['price']) ?></strong></div>
                                <div class="account-product-actions">
                                    <form method="post">
                                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="cart_add">
                                        <input type="hidden" name="product_id" value="<?= (int)$item['id'] ?>">
                                        <input type="hidden" name="quantity" value="1">
                                        <input type="hidden" name="return_to" value="/account?tab=wishlist">
                                        <button class="btn btn-primary" type="submit">В кошик</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="wishlist_remove">
                                        <input type="hidden" name="product_id" value="<?= (int)$item['id'] ?>">
                                        <input type="hidden" name="return_to" value="/account?tab=wishlist">
                                        <button class="btn btn-light" type="submit">Видалити</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($activeTab === 'comparison'): ?>
                <div class="account-headline"><p>Порівняння</p><h2>Порівняння товарів</h2></div>
                <?php if (empty($comparison)): ?>
                    <div class="empty-state"><h3>Немає товарів для порівняння</h3><p>Додавайте товари кнопкою “⚖” у каталозі або картці товару.</p><a class="btn btn-primary" href="/catalog">До каталогу</a></div>
                <?php else: ?>
                    <div class="comparison-category-list">
                        <?php foreach ($comparisonByCategory as $categoryName => $group): ?>
                            <?php
                                $categoryItems = $group['items'];
                                $categoryAttrNames = array_keys($group['attrs']);
                            ?>
                            <section class="comparison-category-card">
                                <div class="comparison-category-head">
                                    <div>
                                        <span>Категорія</span>
                                        <h3><?= e($categoryName) ?></h3>
                                    </div>
                                    <b><?= count($categoryItems) ?> товар(и)</b>
                                </div>
                                <div class="comparison-wrap">
                                    <table class="comparison-table comparison-table--category">
                                        <tr>
                                            <th>Параметр</th>
                                            <?php foreach ($categoryItems as $item): ?>
                                                <td>
                                                    <a class="comparison-product-link" href="/product?id=<?= (int)$item['id'] ?>">
                                                        <img src="<?= e(media_url($item['image'] ?? 'cement.svg')) ?>" alt="<?= e($item['name']) ?>">
                                                        <b><?= e($item['name']) ?></b>
                                                    </a>
                                                    <strong><?= money($item['price']) ?></strong>
                                                    <form method="post">
                                                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="comparison_remove">
                                                        <input type="hidden" name="product_id" value="<?= (int)$item['id'] ?>">
                                                        <input type="hidden" name="return_to" value="/account?tab=comparison">
                                                        <button type="submit">прибрати</button>
                                                    </form>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                        <tr><th>Бренд</th><?php foreach ($categoryItems as $item): ?><td><?= e($item['brand']) ?></td><?php endforeach; ?></tr>
                                        <tr><th>Наявність</th><?php foreach ($categoryItems as $item): ?><td><?= e((string)$item['stock_qty']) ?> <?= e($item['unit']) ?></td><?php endforeach; ?></tr>
                                        <?php foreach ($categoryAttrNames as $attrName): ?>
                                            <tr><th><?= e($attrName) ?></th><?php foreach ($categoryItems as $item): ?><td><?= e($item['attrs'][$attrName] ?? '—') ?></td><?php endforeach; ?></tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($activeTab === 'addresses'): ?>
                <div class="account-headline"><p>Доставка</p><h2>Адреси доставки</h2></div>
                <div class="account-two-col">
                    <div class="account-panel">
                        <h3>Додати адресу</h3>
                        <form method="post" class="stack-form">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="account_create_address">
                            <input type="hidden" name="return_to" value="/account?tab=addresses">
                            <label>Назва адреси</label><input type="text" name="label" placeholder="Дім, офіс, обʼєкт">
                            <label>Отримувач</label><input type="text" name="recipient_name" value="<?= e($profile['full_name'] ?? '') ?>" required>
                            <label>Телефон</label><input type="tel" name="phone" value="<?= e($profile['phone'] ?? '') ?>" required>
                            <label>Місто</label><input type="text" name="city" value="Київ" required>
                            <label>Адреса / відділення</label><textarea name="address" required></textarea>
                            <label>Тип доставки</label>
                            <select name="delivery_type"><option value="courier">Курʼєр</option><option value="pickup">Самовивіз</option><option value="nova_poshta">Нова пошта</option><option value="object">Будівельний обʼєкт</option></select>
                            <label class="check-row"><input type="checkbox" name="is_default" value="1"> Основна адреса</label>
                            <button class="btn btn-primary" type="submit">Зберегти адресу</button>
                        </form>
                    </div>
                    <div class="account-panel">
                        <h3>Мої адреси</h3>
                        <?php if (empty($addresses)): ?><p class="muted">Адреси ще не додані.</p><?php endif; ?>
                        <?php foreach ($addresses as $address): ?>
                            <div class="address-card">
                                <b><?= e($address['LABEL']) ?> <?= (int)$address['IS_DEFAULT'] === 1 ? '· основна' : '' ?></b>
                                <span><?= e($address['RECIPIENT_NAME']) ?>, <?= e($address['PHONE']) ?></span>
                                <p><?= e($address['CITY']) ?>, <?= e($address['ADDRESS']) ?></p>
                                <small><?= e($address['DELIVERY_TYPE']) ?></small>
                                <form method="post">
                                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="account_delete_address">
                                    <input type="hidden" name="address_id" value="<?= (int)$address['ID'] ?>">
                                    <input type="hidden" name="return_to" value="/account?tab=addresses">
                                    <button class="link-button" type="submit">видалити</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($activeTab === 'profile'): ?>
                <div class="account-headline"><p>Дані</p><h2>Профіль користувача</h2></div>
                <div class="account-panel narrow-panel">
                    <form method="post" class="stack-form">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="account_update_profile">
                        <input type="hidden" name="return_to" value="/account?tab=profile">
                        <label>ПІБ</label><input type="text" name="full_name" value="<?= e($profile['full_name'] ?? '') ?>" required>
                        <label>Телефон</label><input type="tel" name="phone" value="<?= e($profile['phone'] ?? '') ?>" required>
                        <label>Email</label><input type="email" name="email" value="<?= e($profile['email'] ?? '') ?>">
                        <label>Новий пароль</label><input type="password" name="password" placeholder="Заповніть тільки якщо потрібно змінити">
                        <button class="btn btn-primary" type="submit">Оновити профіль</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($activeTab === 'notifications'): ?>
                <div class="account-headline"><p>Комунікації</p><h2>Налаштування сповіщень</h2></div>
                <div class="account-panel narrow-panel">
                    <form method="post" class="stack-form">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="account_save_notifications">
                        <input type="hidden" name="return_to" value="/account?tab=notifications">
                        <label class="check-row"><input type="checkbox" name="order_status_notifications" value="1" <?= checked(!empty($notifications['order_status_notifications'])) ?>> Статуси замовлень</label>
                        <label class="check-row"><input type="checkbox" name="email_notifications" value="1" <?= checked(!empty($notifications['email_notifications'])) ?>> Email-сповіщення</label>
                        <label class="check-row"><input type="checkbox" name="sms_notifications" value="1" <?= checked(!empty($notifications['sms_notifications'])) ?>> SMS-сповіщення</label>
                        <label class="check-row"><input type="checkbox" name="promo_notifications" value="1" <?= checked(!empty($notifications['promo_notifications'])) ?>> Акції та персональні пропозиції</label>
                        <div class="integration-note">Оберіть, які повідомлення про замовлення, доставку та акції ви хочете отримувати.</div>
                        <button class="btn btn-primary" type="submit">Зберегти</button>
                    </form>
                </div>
            <?php endif; ?>
        </main>
    </div>
</section>
<?php endif; ?>
