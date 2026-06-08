<?php if (!has_role(['admin', 'manager'])): ?>
<section class="page-hero">
    <div class="container">
        <p class="breadcrumbs"><a href="/">Головна</a> / Email-розсилки</p>
        <h1>Email-розсилки</h1>
        <p>Цей розділ доступний менеджеру або адміністратору.</p>
        <a class="btn btn-primary" href="/account">Увійти</a>
    </div>
</section>
<?php return; endif; ?>

<link rel="stylesheet" href="/assets/css/notifications.css">
<?php $notificationAdminData = $notificationAdminData ?? (new NotificationService())->adminData(); ?>

<section class="page-hero notification-hero">
    <div class="container">
        <p class="breadcrumbs"><a href="/">Головна</a> / Email-розсилки</p>
        <h1>Email-розсилки RungoCraft</h1>
        <p>Рекламні листи для підписаних користувачів. Telegram-інтеграція вимкнена; Telegram-канал ведеться вручну через посилання у футері.</p>
    </div>
</section>

<section class="section">
    <div class="container notification-grid">
        <div class="notification-card"><span>Усього</span><b><?= e($notificationAdminData['summary']['total'] ?? 0) ?></b></div>
        <div class="notification-card"><span>У черзі</span><b><?= e($notificationAdminData['summary']['queued'] ?? 0) ?></b></div>
        <div class="notification-card"><span>Відправлено</span><b><?= e($notificationAdminData['summary']['sent'] ?? 0) ?></b></div>
        <div class="notification-card"><span>Помилки</span><b><?= e($notificationAdminData['summary']['failed'] ?? 0) ?></b></div>
        <div class="notification-card"><span>Email-підписників</span><b><?= e($notificationAdminData['email_subscriber_count'] ?? 0) ?></b></div>
    </div>

    <div class="container notification-box">
        <h2>Рекламна email-розсилка</h2>
        <p class="hint-text">Отримувачі: усі користувачі з email, у яких увімкнено Email-сповіщення та Акції. Ліміт отримувачів прибрано. Доступно: <b><?= e($notificationAdminData['email_subscriber_count'] ?? 0) ?></b>.</p>
        <form class="admin-form" method="post" enctype="multipart/form-data" data-no-ajax="1">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="notification_send_email_campaign">
            <input type="hidden" name="return_to" value="/notifications">
            <label>Тема листа
                <input type="text" name="subject" value="Акційна пропозиція RungoCraft" required>
            </label>
            <label>Текст розсилки
                <textarea name="message" rows="5" required>Вітаємо! У RungoCraft діє спеціальна пропозиція на будівельні матеріали.</textarea>
            </label>
            <label>Зображення для банера листа
                <input type="file" name="campaign_image" accept=".jpg,.jpeg,.png,.webp,.gif">
            </label>
            <label>Тестовий email
                <input type="email" name="test_email" placeholder="Заповніть тільки для тестового листа">
            </label>
            <button class="btn btn-primary" type="submit">Надіслати розсилку</button>
        </form>
    </div>

    <div class="container notification-box">
        <h2>Останні email-повідомлення</h2>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead><tr><th>ID</th><th>Канал</th><th>Отримувач</th><th>Тема</th><th>Статус</th><th>Дата</th></tr></thead>
                <tbody>
                <?php foreach (($notificationAdminData['recent'] ?? []) as $item): ?>
                    <tr>
                        <td>#<?= e($item['ID']) ?></td>
                        <td><?= e($item['CHANNEL']) ?></td>
                        <td><?= e($item['RECIPIENT']) ?></td>
                        <td><?= e($item['SUBJECT']) ?></td>
                        <td><span class="status-chip"><?= e($item['STATUS']) ?></span></td>
                        <td><?= e(format_db_datetime($item['CREATED_AT'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
