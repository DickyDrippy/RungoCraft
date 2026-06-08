<section class="store-photo-section">
    <div class="container store-photo-grid">
        <div class="newsletter-card">
            <h2>Підписатися на розсилку</h2>
            <p>Новини, акції, спецпропозиції та оновлення цін.</p>
            <form class="inline-form newsletter-form" method="post">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="newsletter_subscribe">
                <input type="hidden" name="return_to" value="<?= e($_SERVER['REQUEST_URI'] ?? '/') ?>">
                <input type="text" name="name" placeholder="Імʼя" value="<?= e($user['name'] ?? '') ?>" required>
                <input type="email" name="email" placeholder="Email" value="<?= e($user['email'] ?? '') ?>" required>
                <button class="btn btn-primary" type="submit">Підписатися</button>
            </form>
        </div>
        <div class="store-photo-image">
            <img src="/assets/img/img_go.png" alt="RungoCraft - доставка та склад-магазин">
        </div>
    </div>
</section>

<footer class="footer">
    <div class="container footer-grid footer-grid-public">
        <div>
            <a class="brand footer-brand" href="/">
                <img src="/assets/img/logo.png" alt="RungoCraft">
                <span class="brand__text"><b><?= e($company['name']) ?></b><small><?= e($company['tagline']) ?></small></span>
            </a>
            <div class="footer-socials">
                <a href="<?= e($company['youtube_url'] ?? '#') ?>" target="_blank" rel="noopener">YouTube</a>
                <a href="<?= e($company['telegram_url'] ?? 'https://t.me/rungocraft') ?>" target="_blank" rel="noopener">Telegram</a>
            </div>
        </div>
        <div>
            <h3>Каталог</h3>
            <a href="/catalog">Усі товари</a>
            <a href="/catalog?category=sumishi">Будівельні суміші</a>
            <a href="/catalog?category=gipsokarton">Гіпсокартонні системи</a>
            <a href="/catalog?category=instrument">Інструмент</a>
        </div>
        <div>
            <h3>Покупцям</h3>
            <a href="/delivery">Доставка та оплата</a>
            <a href="/account">Особистий кабінет</a>
            <a href="/cart">Кошик</a>
            <a href="/support">Підтримка</a>
        </div>
        <div>
            <h3>Контакти</h3>
            <a href="tel:<?= e($company['phone']) ?>"><?= e($company['phone_label']) ?></a>
            <a href="mailto:<?= e($company['email']) ?>"><?= e($company['email']) ?></a>
            <a href="<?= e($company['google_maps_url'] ?? '#') ?>" target="_blank" rel="noopener"><?= e($company['address']) ?></a>
        </div>
    </div>
    <div class="container footer-bottom">
        <span>© <?= date('Y') ?> RungoCraft</span>
        <span>Будівельні матеріали та інструменти</span>
    </div>
</footer>

<div class="chat-widget" data-chat-widget>
    <button class="chat-toggle" type="button" data-chat-toggle>💬</button>
    <div class="chat-box">
        <div class="chat-head"><b>Підтримка RungoCraft</b><button type="button" data-chat-close>×</button></div>
        <div class="chat-body" data-chat-body>
            <div class="msg msg-manager">Вітаю! Напишіть питання, і я спробую відповісти за правилами доставки, оплати та даними каталогу.</div>
        </div>
        <form class="chat-form" method="post" data-chat-form data-ai-chat="1">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="support_chat_create">
            <input type="hidden" name="return_to" value="<?= e($_SERVER['REQUEST_URI'] ?? '/') ?>">
            <input type="text" name="message" placeholder="Напишіть повідомлення..." required>
            <button type="submit">➤</button>
        </form>
    </div>
</div>

<div class="modal" data-modal-window="calc" aria-hidden="true">
    <div class="modal__dialog">
        <button class="modal__close" type="button" data-modal-close>×</button>
        <h2>Замовити розрахунок</h2>
        <p>Залиште контакти, і менеджер підготує кошторис за вашим списком матеріалів.</p>
        <?php if (!$user): ?>
            <div class="locked-card"><p>Розрахунок доступний після входу або реєстрації.</p><a class="btn btn-primary" href="/account">Увійти / зареєструватися</a></div>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="support_calculation_create">
            <input type="hidden" name="return_to" value="<?= e($_SERVER['REQUEST_URI'] ?? '/') ?>">
            <input type="text" name="name" placeholder="Імʼя" value="<?= e($user['name'] ?? '') ?>" required>
            <input type="tel" name="phone" placeholder="Телефон" value="<?= e($user['phone'] ?? '') ?>" required>
            <input type="email" name="email" placeholder="Email" value="<?= e($user['email'] ?? '') ?>">
            <select name="project_type"><option value="">Тип робіт</option><option value="repair">Ремонт</option><option value="object">Будівельний обʼєкт</option><option value="wholesale">Оптова закупівля</option></select>
            <textarea name="materials_list" placeholder="Що потрібно порахувати?"></textarea>
            <label class="file-input-label">Додати файли
                <input type="file" name="calculation_files[]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" multiple>
            </label>
            <button class="btn btn-primary" type="submit">Надіслати заявку</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="modal" data-modal-window="quick" aria-hidden="true">
    <div class="modal__dialog">
        <button class="modal__close" type="button" data-modal-close>×</button>
        <h2>Купити в 1 клік</h2>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="quick_order">
            <input type="hidden" name="product_id" data-quick-product-id-input value="">
            <input type="hidden" name="product_name" data-quick-product-name-input value="">
            <input type="text" name="name" placeholder="Імʼя" value="<?= e($user['name'] ?? '') ?>" required>
            <input type="tel" name="phone" placeholder="Телефон" value="<?= e($user['phone'] ?? '') ?>" required>
            <button class="btn btn-primary" type="submit">Надіслати менеджеру</button>
        </form>
    </div>
</div>

<div class="toast" data-toast></div>
</body>
</html>
