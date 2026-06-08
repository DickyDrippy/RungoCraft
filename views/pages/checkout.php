<?php if (has_role(['manager','warehouse','admin'])): ?>
<section class="page-hero"><div class="container"><p class="breadcrumbs"><a href="/">Головна</a> / Службовий профіль</p><h1>Службовий режим</h1><p>Кошик, бажане та оформлення замовлення доступні тільки клієнтським акаунтам.</p><a class="btn btn-primary" href="/admin">Перейти в панель працівника</a></div></section>
<?php return; endif; ?>
<link rel="stylesheet" href="/assets/css/cart.css">
<link rel="stylesheet" href="/assets/css/delivery.css">
<script defer src="/assets/js/delivery-directory.js?v=65"></script>
<link rel="stylesheet" href="/assets/css/recaptcha-security.css">
<script defer src="/assets/js/recaptcha-auth.js?v=7"></script>
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

<section class="page-hero">
    <div class="container">
        <p class="breadcrumbs"><a href="/">Головна</a> / Оформлення</p>
        <h1>Оформлення замовлення</h1>
        <p>Заповніть контактні дані, виберіть доставку та спосіб оплати.</p>
    </div>
</section>

<section class="section">
    <div class="container checkout-grid">
        <form class="checkout-form reveal" method="post" data-delivery-directory data-recaptcha-action="checkout">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="order_create">

            <h2>Контактні дані</h2>
            <div class="form-grid">
                <input type="text" name="customer_name" placeholder="ПІБ" value="<?= e($user['name'] ?? '') ?>" required>
                <input type="tel" name="customer_phone" placeholder="Телефон" value="<?= e($user['phone'] ?? '') ?>" required>
                <input type="email" name="customer_email" placeholder="Email" value="<?= e($user['email'] ?? '') ?>">
                <input type="text" name="company_info" placeholder="Компанія / ЄДРПОУ, якщо потрібно">
            </div>

            <h2>Доставка</h2>
            <div class="delivery-choice-grid">
                <label class="delivery-choice is-active">
                    <input type="radio" name="delivery_type" value="pickup" checked data-delivery-choice>
                    <b>Самовивіз</b>
                    <span><?= e($company['address']) ?></span>
                </label>
                <label class="delivery-choice">
                    <input type="radio" name="delivery_type" value="kyiv_courier" data-delivery-choice>
                    <b>Курʼєр по Києву</b>
                    <span>Погодження часу і вартості</span>
                </label>
                <label class="delivery-choice">
                    <input type="radio" name="delivery_type" value="nova_poshta_branch" data-delivery-choice>
                    <b>Нова пошта</b>
                    <span>Відділення / поштомат</span>
                </label>
                <label class="delivery-choice">
                    <input type="radio" name="delivery_type" value="nova_poshta_courier" data-delivery-choice>
                    <b>НП курʼєр</b>
                    <span>Доставка до дверей</span>
                </label>
                <label class="delivery-choice">
                    <input type="radio" name="delivery_type" value="delivery_auto_branch" data-delivery-choice>
                    <b>Delivery Auto</b>
                    <span>Відділення Delivery</span>
                </label>
                <label class="delivery-choice">
                    <input type="radio" name="delivery_type" value="delivery_auto_courier" data-delivery-choice>
                    <b>Delivery курʼєр</b>
                    <span>Адресна доставка</span>
                </label>
                <label class="delivery-choice">
                    <input type="radio" name="delivery_type" value="construction_site" data-delivery-choice>
                    <b>На обʼєкт</b>
                    <span>Будівельний майданчик</span>
                </label>
            </div>

            <input type="hidden" name="delivery_carrier" value="pickup" data-delivery-carrier>

            <div class="delivery-extra" data-delivery-extra="address">
                <h3 data-address-delivery-title>Адресна доставка</h3>
                <p class="muted" data-address-delivery-hint></p>
                <div class="form-grid">
                    <div data-address-city-field>
                        <input type="text" name="delivery_city_address" placeholder="Місто доставки" data-address-city-input>
                    </div>
                    <input type="text" name="delivery_address" placeholder="Адреса доставки / будівельний обʼєкт" data-address-line-input>
                </div>
            </div>

            <div class="delivery-extra" data-delivery-extra="carrier-directory" data-delivery-directory-section>
                <h3 data-delivery-directory-title>Відділення служби доставки</h3>
                <p class="muted" data-delivery-directory-hint>Спочатку оберіть місто, потім відділення перевізника.</p>

                <input type="hidden" name="delivery_city" data-delivery-manual-city>
                <input type="hidden" name="delivery_city_ref" id="delivery_city_ref" data-delivery-city-ref>
                <input type="hidden" name="delivery_warehouse" data-delivery-manual-warehouse>
                <input type="hidden" name="delivery_warehouse_ref" data-delivery-warehouse-ref>
                <input type="hidden" name="delivery_warehouse_name" data-delivery-warehouse-name>

                <div class="form-grid delivery-directory-grid">
                    <div class="delivery-directory-field">
                        <label class="sr-only" for="delivery_city_search">Місто доставки</label>
                        <input id="delivery_city_search" type="text" autocomplete="off" placeholder="Почніть вводити місто" data-delivery-city-name>
                        <div class="delivery-directory-results" data-delivery-city-results hidden></div>
                    </div>

                    <div class="delivery-directory-field" data-delivery-warehouse-field>
                        <label class="sr-only" for="delivery_warehouse_search">Відділення</label>
                        <input id="delivery_warehouse_search" type="text" autocomplete="off" placeholder="Спочатку оберіть місто" data-delivery-warehouse-name-input disabled>
                        <div class="delivery-directory-results" data-delivery-warehouse-results hidden></div>
                    </div>

                    <input type="text" name="delivery_note" placeholder="Коментар для перевізника">
                </div>

                <p class="muted" data-delivery-directory-status></p>
            </div>

            <div class="delivery-extra delivery-recipient-box">
                <h3>Отримувач доставки, якщо інший</h3>
                <div class="form-grid">
                    <input type="text" name="delivery_recipient" placeholder="ПІБ іншого отримувача">
                    <input type="tel" name="delivery_recipient_phone" placeholder="Телефон іншого отримувача">
                </div>
                <p class="muted">Якщо поле заповнене, ПІБ і телефон отримувача перевіряються та використовуються для ТТН.</p>
            </div>

            <textarea name="comment_text" placeholder="Коментар до замовлення"></textarea>

            <h2 id="payment">Оплата</h2>
            <link rel="stylesheet" href="/assets/css/payment.css">
            <?php
                $checkoutPaymentMethods = $paymentMethods ?? [];
                if (empty($checkoutPaymentMethods)) {
                    $checkoutPaymentMethods = [
                        ['CODE' => 'cash', 'NAME' => 'Оплата при отриманні', 'DESCRIPTION' => 'Оплата після підтвердження менеджером.', 'IS_ONLINE' => 0],
                        ['CODE' => 'online_card', 'NAME' => 'Онлайн-оплата карткою', 'DESCRIPTION' => 'Оплата карткою онлайн.', 'IS_ONLINE' => 1],
                        ['CODE' => 'invoice', 'NAME' => 'Безготівковий рахунок', 'DESCRIPTION' => 'Рахунок для юридичних осіб.', 'IS_ONLINE' => 0],
                        ['CODE' => 'manager_confirm', 'NAME' => 'Після підтвердження менеджером', 'DESCRIPTION' => 'Менеджер погодить оплату після перевірки.', 'IS_ONLINE' => 0],
                    ];
                }
            ?>
            <div class="payment-method-grid">
                <?php foreach ($checkoutPaymentMethods as $index => $method): ?>
                    <label class="payment-method-card <?= !empty($method['IS_ONLINE']) ? 'is-online' : '' ?>">
                        <input type="radio" name="payment_type" value="<?= e($method['CODE']) ?>" <?= $index === 0 ? 'checked' : '' ?>>
                        <span class="payment-icon"><?= !empty($method['IS_ONLINE']) ? '💳' : '₴' ?></span>
                        <b><?= e($method['NAME']) ?></b>
                        <small><?= e($method['DESCRIPTION'] ?? '') ?></small>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="payment-integration-note">
                Після оформлення замовлення буде створено платіж і показано подальші інструкції.
            </div>

            <?php if (empty($cartData['items'])): ?>
                <a class="btn btn-primary" href="/catalog">Додати товари в кошик</a>
            <?php else: ?>
                <button class="btn btn-primary" type="submit">Підтвердити замовлення</button>
            <?php endif; ?>
        </form>

        <aside class="summary-card reveal">
            <h2>Ваше замовлення</h2>
            <?php if (empty($cartData['items'])): ?>
                <div class="mini-cart-list">Товари зʼявляться після додавання в кошик.</div>
            <?php else: ?>
                <div class="mini-cart-list">
                    <?php foreach ($cartData['items'] as $item): ?>
                        <div class="mini-cart-row">
                            <span><?= e($item['name']) ?><br><small><?= e($item['quantity']) ?> × <?= money($item['price']) ?></small></span>
                            <b><?= money($item['line_total']) ?></b>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="summary-row"><span>Сума</span><b><?= money($cartData['total'] ?? 0) ?></b></div>
            <p class="muted">Менеджер підтвердить наявність, доставку та оплату. Після оформлення ви побачите номер замовлення.</p>
        </aside>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const choices = Array.from(document.querySelectorAll('[data-delivery-choice]'));
    const directory = document.querySelector('[data-delivery-extra="carrier-directory"]');
    const address = document.querySelector('[data-delivery-extra="address"]');
    const cityInput = document.querySelector('[data-address-city-input]');
    const cityField = document.querySelector('[data-address-city-field]');
    const addressLineInput = document.querySelector('[data-address-line-input]');
    const addressTitle = document.querySelector('[data-address-delivery-title]');
    const addressHint = document.querySelector('[data-address-delivery-hint]');
    const directoryTitle = document.querySelector('[data-delivery-directory-title]');
    const directoryHint = document.querySelector('[data-delivery-directory-hint]');

    const directoryInputs = directory ? Array.from(directory.querySelectorAll('input, select, textarea')) : [];
    const addressInputs = address ? Array.from(address.querySelectorAll('input, select, textarea')) : [];

    const branchMethods = ['nova_poshta_branch', 'delivery_auto_branch'];
    const apiCourierMethods = ['nova_poshta_courier', 'delivery_auto_courier'];
    const ownAddressMethods = ['kyiv_courier', 'construction_site'];
    const addressMethods = ownAddressMethods.concat(apiCourierMethods);

    function setInputsEnabled(inputs, enabled) {
        inputs.forEach(function (input) {
            input.disabled = !enabled;
            if (!enabled) input.removeAttribute('required');
        });
    }

    function setFieldVisible(node, visible) {
        if (!node) return;
        node.style.display = visible ? '' : 'none';
    }

    function clearDirectoryFields() {
        document.querySelectorAll('[data-delivery-city-name], [data-delivery-manual-city], [data-delivery-city-ref], [data-delivery-warehouse-name-input], [data-delivery-manual-warehouse], [data-delivery-warehouse-ref], [data-delivery-warehouse-name]').forEach(function (node) {
            node.value = '';
        });
    }

    function updateDeliveryBlocks() {
        const checked = document.querySelector('[data-delivery-choice]:checked');
        if (!checked) return;

        document.querySelectorAll('.delivery-choice').forEach(function (node) { node.classList.remove('is-active'); });
        checked.closest('.delivery-choice')?.classList.add('is-active');

        const value = checked.value;
        const needsBranchDirectory = branchMethods.includes(value);
        const needsApiCourierDirectory = apiCourierMethods.includes(value);
        const needsDirectory = needsBranchDirectory || needsApiCourierDirectory;
        const needsAddress = addressMethods.includes(value);
        const needsPlainAddressCity = ownAddressMethods.includes(value);

        if (directory) directory.style.display = needsDirectory ? 'block' : 'none';
        if (address) address.style.display = needsAddress ? 'block' : 'none';

        setInputsEnabled(directoryInputs, needsDirectory);
        setInputsEnabled(addressInputs, needsAddress);

        if (!needsDirectory) clearDirectoryFields();

        if (cityInput) {
            cityInput.type = needsPlainAddressCity ? 'text' : 'hidden';
            cityInput.disabled = !needsPlainAddressCity || !needsAddress;
            setFieldVisible(cityField, needsPlainAddressCity);
            if (value === 'kyiv_courier') {
                cityInput.value = 'Київ';
                cityInput.type = 'hidden';
                setFieldVisible(cityField, false);
            } else if (!needsPlainAddressCity) {
                cityInput.value = '';
            } else if (cityInput.value === 'Київ' && value !== 'kyiv_courier') {
                cityInput.value = '';
            }
        }

        if (addressTitle) {
            addressTitle.textContent = needsApiCourierDirectory ? 'Адреса курʼєрської доставки' : 'Адресна доставка';
        }
        if (addressHint) {
            addressHint.textContent = needsApiCourierDirectory
                ? 'Місто оберіть нижче зі списку перевізника, а тут вкажіть тільки вулицю, будинок, квартиру або офіс.'
                : '';
            addressHint.style.display = addressHint.textContent ? '' : 'none';
        }
        if (addressLineInput) {
            addressLineInput.placeholder = needsApiCourierDirectory
                ? 'Вулиця, будинок, квартира / офіс'
                : 'Адреса доставки / будівельний обʼєкт';
        }

        if (directoryTitle) {
            directoryTitle.textContent = needsApiCourierDirectory ? 'Місто для курʼєрської доставки' : 'Відділення служби доставки';
        }
        if (directoryHint) {
            directoryHint.textContent = needsApiCourierDirectory
                ? 'Почніть вводити місто та оберіть його зі списку. Це потрібно для коректного розрахунку і створення ТТН.'
                : 'Спочатку оберіть місто, потім відділення перевізника.';
        }
    }

    choices.forEach(function (choice) {
        choice.addEventListener('change', updateDeliveryBlocks);
    });

    updateDeliveryBlocks();
});
</script>
