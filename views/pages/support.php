<link rel="stylesheet" href="/assets/css/support.css">

<section class="page-hero support-hero">
    <div class="container">
        <p class="breadcrumbs"><a href="/">Головна</a> / Підтримка</p>
        <h1>Підтримка RungoCraft</h1>
        <p>Менеджер допоможе з підбором матеріалів, замовленням, доставкою, оплатою або рахунком для юридичної особи.</p>
    </div>
</section>

<section class="section support-section">
    <div class="container support-grid">
        <div class="support-card reveal">
            <span class="support-icon">☎</span>
            <h2>Замовити дзвінок</h2>
            <p>Залиште номер, і менеджер передзвонить для консультації.</p>
            <?php if (!$user): ?>
                <div class="locked-card"><p>Ця заявка доступна після входу або реєстрації.</p><a class="btn btn-primary" href="/account">Увійти / зареєструватися</a></div>
            <?php else: ?>
            <form method="post" class="support-form">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="support_callback_create">
                <input type="hidden" name="return_to" value="/support">
                <input type="text" name="name" placeholder="Імʼя" value="<?= e($user['name'] ?? '') ?>" required>
                <input type="tel" name="phone" placeholder="Телефон" value="<?= e($user['phone'] ?? '') ?>" required>
                <input type="text" name="preferred_time" placeholder="Зручний час дзвінка">
                <textarea name="comment" placeholder="Коротко опишіть питання"></textarea>
                <button class="btn btn-primary" type="submit">Надіслати заявку</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="support-card reveal">
            <span class="support-icon">📐</span>
            <h2>Розрахунок матеріалів</h2>
            <p>Надішліть список матеріалів або тип робіт — менеджер підготує кошторис.</p>
            <?php if (!$user): ?>
                <div class="locked-card"><p>Ця заявка доступна після входу або реєстрації.</p><a class="btn btn-primary" href="/account">Увійти / зареєструватися</a></div>
            <?php else: ?>
            <form method="post" class="support-form" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="support_calculation_create">
                <input type="hidden" name="return_to" value="/support">
                <input type="text" name="name" placeholder="Імʼя" value="<?= e($user['name'] ?? '') ?>" required>
                <input type="tel" name="phone" placeholder="Телефон" value="<?= e($user['phone'] ?? '') ?>" required>
                <input type="email" name="email" placeholder="Email" value="<?= e($user['email'] ?? '') ?>">
                <select name="project_type">
                    <option value="">Тип робіт</option>
                    <option value="repair">Ремонт квартири</option>
                    <option value="house">Будинок / дача</option>
                    <option value="brigade">Будівельна бригада</option>
                    <option value="legal">Юридична особа / обʼєкт</option>
                </select>
                <textarea name="materials_list" placeholder="Список матеріалів або площа робіт"></textarea>
                <label class="file-input-label">Файли до розрахунку (PDF, DOCX, фото)
                    <input type="file" name="calculation_files[]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" multiple>
                </label>
                <button class="btn btn-primary" type="submit">Замовити розрахунок</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="support-card support-card-wide reveal">
            <span class="support-icon">💬</span>
            <h2>Звернення в підтримку</h2>
            <p>Створіть звернення. Воно потрапить у менеджерську панель і буде оброблене як заявка.</p>
            <?php if (!$user): ?>
                <div class="locked-card"><p>Ця заявка доступна після входу або реєстрації.</p><a class="btn btn-primary" href="/account">Увійти / зареєструватися</a></div>
            <?php else: ?>
            <form method="post" class="support-form support-form-grid">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="support_ticket_create">
                <input type="hidden" name="return_to" value="/support">
                <input type="text" name="name" placeholder="Імʼя" value="<?= e($user['name'] ?? '') ?>" required>
                <input type="tel" name="phone" placeholder="Телефон" value="<?= e($user['phone'] ?? '') ?>">
                <input type="email" name="email" placeholder="Email" value="<?= e($user['email'] ?? '') ?>">
                <input type="text" name="subject" placeholder="Тема звернення" required>
                <textarea class="span-2" name="message" placeholder="Опишіть питання" required></textarea>
                <button class="btn btn-primary" type="submit">Створити звернення</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="section section-muted support-section">
    <div class="container integration-grid">
        <div class="integration-card reveal">
            <b>Підбір матеріалів</b>
            <span>Менеджер допоможе підібрати товари за списком, площею або типом робіт.</span>
        </div>
        <div class="integration-card reveal">
            <b>Статуси замовлень</b>
            <span>Клієнт може перевірити замовлення в кабінеті, а менеджер обробляє звернення в панелі керування.</span>
        </div>
        <div class="integration-card reveal">
            <b>Сервісна підтримка</b>
            <span>Звернення з форм, карток товарів і чату обробляються менеджером.</span>
        </div>
    </div>
</section>

<section class="section faq-section">
    <div class="container">
        <div class="section-head"><p>FAQ</p><h2>Популярні питання</h2></div>
        <div class="faq-list">
            <details open><summary>Як швидко менеджер відповідає?</summary><p>Заявки потрапляють у менеджерську панель. У робочий час менеджер обробляє їх у порядку надходження.</p></details>
            <details><summary>Чи можна отримати рахунок для юридичної особи?</summary><p>Так. Для юридичних осіб передбачені реквізити, безготівкова оплата та менеджерський супровід.</p></details>
            <details><summary>Чи можна оформити доставку Новою поштою?</summary><p>Так. Під час оформлення замовлення можна вказати місто та відділення.</p></details>
            <details><summary>Чи є онлайн-оплата?</summary><p>Так. Після оформлення замовлення можна перейти до оплати або погодити спосіб оплати з менеджером.</p></details>
        </div>
    </div>
</section>
