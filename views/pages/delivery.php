<link rel="stylesheet" href="/assets/css/delivery.css">

<section class="page-hero delivery-hero">
    <div class="container">
        <p class="breadcrumbs"><a href="/">Головна</a> / Доставка та оплата</p>
        <h1>Доставка та оплата RungoCraft</h1>
        <p>Самовивіз зі складу-магазину, доставка по Києву, доставка на обʼєкт і відправлення Новою поштою.</p>
    </div>
</section>

<section class="section delivery-section">
    <div class="container delivery-methods-grid">
        <article class="delivery-card reveal">
            <span class="delivery-icon">🏬</span>
            <h2>Самовивіз</h2>
            <p>Заберіть замовлення зі складу-магазину після підтвердження менеджером.</p>
            <b><?= e($company['address']) ?></b>
            <a href="<?= e($company['google_maps_url'] ?? '#') ?>" target="_blank" rel="noopener">Відкрити на карті</a>
        </article>

        <article class="delivery-card reveal">
            <span class="delivery-icon">🚚</span>
            <h2>Доставка по Києву</h2>
            <p>Для будівельних матеріалів менеджер узгоджує час, вартість і можливість підйому.</p>
            <b>Після підтвердження</b>
        </article>

        <article class="delivery-card reveal">
            <span class="delivery-icon">📦</span>
            <h2>Нова пошта</h2>
            <p>Під час оформлення можна обрати місто, відділення або курʼєрську доставку.</p>
            <b>Відділення або курʼєр</b>
        </article>

        <article class="delivery-card reveal">
            <span class="delivery-icon">🏗</span>
            <h2>На будівельний обʼєкт</h2>
            <p>Для великих або важких замовлень доставка погоджується менеджером індивідуально.</p>
            <b>Менеджерський супровід</b>
        </article>
    </div>
</section>

<section class="section np-section">
    <div class="container np-panel np-panel--clean reveal">
        <div class="np-panel-info">
            <span class="eyebrow">Доставка</span>
            <h2>Нова пошта</h2>
            <p>Під час оформлення замовлення покупець обирає місто, тип доставки та конкретне відділення або адресу курʼєрської доставки. Після підтвердження менеджер створює ТТН і оновлює статус у замовленні.</p>
        </div>
        <div class="np-demo-grid np-demo-grid--cards">
            <div><b>1. Місто</b><span>Київ, Львів, Одеса, Дніпро, Харків та інші міста з довідника доставки.</span></div>
            <div><b>2. Відділення або адреса</b><span>Клієнт обирає відділення Нової пошти або вводить адресу для курʼєра.</span></div>
            <div><b>3. ТТН</b><span>Номер відправлення зберігається в замовленні та відображається клієнту.</span></div>
            <div><b>4. Статуси</b><span>Очікує оформлення → ТТН створено → у дорозі → доставлено.</span></div>
        </div>
    </div>
</section>

<section class="section payment-section" id="payment">
    <div class="container np-panel np-panel--clean payment-panel reveal">
        <div class="np-panel-info">
            <span class="eyebrow">Оплата</span>
            <h2>Способи оплати</h2>
            <p>Покупець обирає спосіб оплати під час оформлення замовлення. Для кожного способу система показує зрозумілий статус: очікує оплати, оплата при отриманні, оплачено або очікує менеджера.</p>
        </div>
        <div class="np-demo-grid np-demo-grid--cards payment-method-cards">
            <div><b>1. Оплата при отриманні</b><span>Замовлення одразу передається менеджеру та може потрапити на склад після перевірки наявності. Клієнт оплачує товар під час отримання.</span></div>
            <div><b>2. Онлайн-оплата</b><span>LiqPay або WayForPay формують платіж за замовленням. У тестовому режимі потрібні активовані мерчант-акаунти та HTTPS callback.</span></div>
            <div><b>3. Після підтвердження менеджером</b><span>Менеджер перевіряє склад, доставку та суму, після чого підтверджує замовлення або надсилає посилання на оплату.</span></div>
            <div><b>4. Рахунок для юр. осіб</b><span>Юридичний або оптовий клієнт залишає реквізити, а менеджер формує рахунок і контролює оплату в панелі.</span></div>
        </div>
    </div>
</section>
