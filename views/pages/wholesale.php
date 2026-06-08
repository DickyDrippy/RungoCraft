<link rel="stylesheet" href="/assets/css/business.css">

<?php
$businessService = $businessService ?? new BusinessClientService();
$businessStats = $businessService->publicStats();
$currentBusinessClient = $businessService->currentClient(current_user());
?>

<section class="page-hero business-hero">
    <div class="container business-hero-grid">
        <div>
            <p class="breadcrumbs"><a href="/">Головна</a> / Опт</p>
            <span class="eyebrow">B2B RungoCraft</span>
            <h1>Кабінет для оптових клієнтів, майстрів та юридичних осіб</h1>
            <p>Окремий сценарій закупівель для компаній, виконробів, ремонтних бригад і постачальників: заявки, рахунки, персональні умови та менеджерський супровід.</p>
            <div class="hero-actions">
                <a class="btn btn-primary" href="#business-apply">Подати заявку</a>
                <a class="btn btn-light" href="#supplier-apply">Стати постачальником</a>
            </div>
        </div>
        <div class="business-hero-card">
            <div><span>Активні B2B клієнти</span><b><?= e($businessStats['clients'] ?? 0) ?></b></div>
            <div><span>Рахунки в системі</span><b><?= e($businessStats['invoices'] ?? 0) ?></b></div>
            <div><span>Цінові групи</span><b><?= e($businessStats['price_groups'] ?? 0) ?></b></div>
        </div>
    </div>
</section>

<section class="section business-features-section">
    <div class="container section-head">
        <span class="eyebrow">Можливості</span>
        <h2>Що отримує оптовий клієнт</h2>
        <p>Для оптових клієнтів доступні персональні умови, рахунки та супровід менеджера.</p>
    </div>

    <div class="container business-feature-grid">
        <div class="business-feature-card">
            <b>01</b>
            <h3>Персональні ціни</h3>
            <p>Цінова група, знижка та кредитний ліміт задаються менеджером у панелі керування.</p>
        </div>
        <div class="business-feature-card">
            <b>02</b>
            <h3>Безготівкові рахунки</h3>
            <p>Менеджер створює рахунок для замовлення або клієнта, а статус оплати контролюється в адмінці.</p>
        </div>
        <div class="business-feature-card">
            <b>03</b>
            <h3>Закупівлі на обʼєкт</h3>
            <p>Передбачені адреси будівельних обʼєктів, доставка, коментарі та супровід менеджера.</p>
        </div>
        <div class="business-feature-card">
            <b>04</b>
            <h3>Панель менеджера</h3>
            <p>Менеджер працює із заявками, рахунками, статусами та історією комунікацій.</p>
        </div>
    </div>
</section>

<section class="section business-steps-section">
    <div class="container business-steps">
        <div>
            <span class="eyebrow">Процес</span>
            <h2>Як підключитися до B2B-кабінету</h2>
        </div>
        <ol>
            <li><b>Заявка</b><span>Клієнт залишає реквізити компанії та контакт менеджера.</span></li>
            <li><b>Перевірка</b><span>Менеджер перевіряє дані, задає цінову групу та умови співпраці.</span></li>
            <li><b>Замовлення</b><span>Клієнт оформлює закупівлю, менеджер готує рахунок та доставку.</span></li>
            <li><b>Історія закупівель</b><span>Клієнт і менеджер бачать попередні замовлення та умови співпраці.</span></li>
        </ol>
    </div>
</section>

<section class="section business-form-section" id="business-apply">
    <div class="container business-form-grid">
        <div>
            <span class="eyebrow">Заявка</span>
            <h2>Подати заявку оптового клієнта</h2>
            <p>Заповніть форму, і менеджер розгляне заявку та погодить умови співпраці.</p>

            <?php if ($currentBusinessClient): ?>
                <div class="business-current-card">
                    <b>Ваш поточний B2B-статус</b>
                    <span><?= e($currentBusinessClient['STATUS'] ?? 'pending') ?></span>
                    <p><?= e($currentBusinessClient['COMPANY_NAME'] ?? '') ?> · група: <?= e($currentBusinessClient['PRICE_GROUP'] ?? 'base') ?></p>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$user): ?>
            <div class="locked-card business-form"><p>Заявка доступна після входу або реєстрації, щоб менеджер бачив ваш профіль і контакти.</p><a class="btn btn-primary" href="/account">Увійти / зареєструватися</a></div>
        <?php else: ?>
        <form class="business-form" method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="business_apply">
            <input type="hidden" name="return_to" value="/wholesale">

            <label>Тип клієнта
                <select name="client_type">
                    <option value="company">Юридична особа</option>
                    <option value="fop">ФОП</option>
                    <option value="team">Бригада / виконроб</option>
                    <option value="wholesale">Оптовий покупець</option>
                </select>
            </label>

            <label>Назва компанії / ПІБ ФОП
                <input type="text" name="company_name" required>
            </label>

            <label>ЄДРПОУ / ІПН
                <input type="text" name="edrpou" placeholder="12345678">
            </label>

            <label>ІПН платника ПДВ
                <input type="text" name="tax_number" placeholder="за наявності">
            </label>

            <label>Контактна особа
                <input type="text" name="contact_name" required>
            </label>

            <label>Телефон
                <input type="tel" name="phone" required>
            </label>

            <label>Email
                <input type="email" name="email">
            </label>

            <label>Юридична адреса
                <input type="text" name="legal_address">
            </label>

            <label class="span-2">Адреса доставки / будівельного обʼєкта
                <input type="text" name="delivery_address" placeholder="місто, вулиця, обʼєкт">
            </label>

            <label class="span-2">Що плануєте закуповувати?
                <textarea name="comment_text" placeholder="Наприклад: гіпсокартон, профіль, суміші, кріплення для обʼєкта"></textarea>
            </label>

            <button class="btn btn-primary span-2" type="submit">Надіслати заявку</button>
        </form>
        <?php endif; ?>
    </div>
</section>

<section class="section supplier-section" id="supplier-apply">
    <div class="container business-form-grid business-form-grid-reverse">
        <div>
            <span class="eyebrow">Постачальникам</span>
            <h2>Запропонувати співпрацю</h2>
            <p>Надішліть пропозицію співпраці, напрям продукції та контактні дані для звʼязку з менеджером.</p>
        </div>

        <?php if (!$user): ?>
            <div class="locked-card business-form"><p>Заявка доступна після входу або реєстрації, щоб менеджер бачив ваш профіль і контакти.</p><a class="btn btn-primary" href="/account">Увійти / зареєструватися</a></div>
        <?php else: ?>
        <form class="business-form" method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="business_supplier_apply">
            <input type="hidden" name="return_to" value="/wholesale">

            <label>Компанія
                <input type="text" name="company_name" required>
            </label>
            <label>Контактна особа
                <input type="text" name="contact_name" required>
            </label>
            <label>Телефон
                <input type="tel" name="phone" required>
            </label>
            <label>Email
                <input type="email" name="email">
            </label>
            <label class="span-2">Напрям продукції
                <input type="text" name="product_direction" placeholder="суміші, утеплювач, інструмент, кріплення">
            </label>
            <label class="span-2">Повідомлення
                <textarea name="message" placeholder="Коротко опишіть умови співпраці"></textarea>
            </label>
            <button class="btn btn-dark span-2" type="submit">Надіслати пропозицію</button>
        </form>
        <?php endif; ?>
    </div>
</section>
