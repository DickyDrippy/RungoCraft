<section class="page-hero">
    <div class="container">
        <p class="breadcrumbs"><a href="/">Головна</a> / Контакти</p>
        <h1>Контакти</h1>
        <p>Єдина адреса складу-магазину RungoCraft у Києві.</p>
    </div>
</section>
<section class="section">
    <div class="container contacts-grid">
        <div class="contact-card reveal">
            <h2>Склад-магазин</h2>
            <p><b>Адреса:</b> <?= e($company['address']) ?></p>
            <p><b>Телефон:</b> <a href="tel:<?= e($company['phone']) ?>"><?= e($company['phone_label']) ?></a></p>
            <p><b>Графік:</b> <?= e($company['worktime']) ?></p>
            <button class="btn btn-primary" type="button" data-open-chat>Написати в підтримку</button>
        </div>
        <div class="map-card map-card-google reveal">
            <iframe
                title="RungoCraft на Google Maps"
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade"
                src="https://www.google.com/maps?q=%D0%BC.%20%D0%9A%D0%B8%D1%97%D0%B2%2C%20%D0%B2%D1%83%D0%BB.%20%D0%9A%D1%83%D1%80%D0%B5%D0%BD%D1%96%D0%B2%D1%81%D1%8C%D0%BA%D0%B0%2C%2015&output=embed"></iframe>
            <div class="map-route-card">
                <span>Київ</span>
                <b>вул. Куренівська, 15</b>
                <a class="btn btn-primary" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&destination=%D0%BC.%20%D0%9A%D0%B8%D1%97%D0%B2%2C%20%D0%B2%D1%83%D0%BB.%20%D0%9A%D1%83%D1%80%D0%B5%D0%BD%D1%96%D0%B2%D1%81%D1%8C%D0%BA%D0%B0%2C%2015">↗ Прокласти маршрут</a>
            </div>
        </div>
    </div>
</section>
