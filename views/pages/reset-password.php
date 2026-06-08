<?php
$token = trim((string)($_GET['token'] ?? ''));
$recaptchaSiteKey = isset($auth) && method_exists($auth, 'recaptchaSiteKey') ? $auth->recaptchaSiteKey() : '';
$recaptchaProvider = isset($auth) && method_exists($auth, 'recaptchaProvider') ? $auth->recaptchaProvider() : 'classic';
?>
<link rel="stylesheet" href="/assets/css/auth.css">
<link rel="stylesheet" href="/assets/css/recaptcha-security.css">
<script defer src="/assets/js/recaptcha-auth.js?v=7"></script>
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

<section class="page-hero account-hero">
    <div class="container">
        <p class="breadcrumbs"><a href="/">Головна</a> / Відновлення пароля</p>
        <h1>Створення нового пароля</h1>
        <p>Введіть новий пароль для вашого кабінету.</p>
    </div>
</section>

<section class="section auth-section">
    <div class="container auth-layout">
        <div class="auth-card reveal">
            <div class="auth-headline">
                <span>Безпека</span>
                <h2>Новий пароль</h2>
                <p>Посилання дійсне обмежений час. Після зміни пароля увійдіть повторно.</p>
            </div>
            <form method="post" class="auth-main-form" data-recaptcha-action="reset_password">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="auth_reset_password_confirm">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <label>Новий пароль</label>
                <input type="password" name="password" placeholder="Мінімум 6 символів" required autocomplete="new-password">
                <button class="btn btn-primary btn-full" type="submit">Зберегти пароль</button>
            </form>
        </div>
    </div>
</section>
