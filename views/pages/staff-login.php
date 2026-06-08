<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вхід працівника RungoCraft</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
    <link rel="stylesheet" href="/assets/css/account.css">
    <link rel="stylesheet" href="/assets/css/recaptcha-security.css">
</head>
<body class="staff-login-body">
    <main class="staff-login-page">
        <section class="staff-login-card reveal is-visible">
            <a class="staff-login-logo" href="/">
                <img src="/assets/img/logo.png" alt="RungoCraft">
                <span><b>RungoCraft</b><small>службовий вхід</small></span>
            </a>
            <div class="auth-headline">
                <span>Панель працівника</span>
                <h1>Вхід для адміністратора, менеджера і складу</h1>
                <p>Цей лінк призначений тільки для працівників. Тут використовується лише логін і пароль, без клієнтського SMS/email-коду.</p>
            </div>

            <?php if (!empty($staffFlash)): ?>
                <div class="staff-login-alert"><?= e($staffFlash) ?></div>
            <?php endif; ?>

            <?php
            $staffRecaptchaSiteKey = isset($auth) && method_exists($auth, 'recaptchaSiteKey') ? $auth->recaptchaSiteKey() : '';
            $staffRecaptchaProvider = isset($auth) && method_exists($auth, 'recaptchaProvider') ? $auth->recaptchaProvider() : 'classic';
            ?>
            <?php if ($staffRecaptchaSiteKey !== ''): ?>
                <?php if ($staffRecaptchaProvider === 'enterprise'): ?>
                    <script src="https://www.google.com/recaptcha/enterprise.js?render=<?= e($staffRecaptchaSiteKey) ?>"></script>
                <?php else: ?>
                    <script src="https://www.google.com/recaptcha/api.js?render=<?= e($staffRecaptchaSiteKey) ?>"></script>
                <?php endif; ?>
                <script>
                    window.RC_RECAPTCHA_SITE_KEY = <?= json_encode($staffRecaptchaSiteKey) ?>;
                    window.RC_RECAPTCHA_PROVIDER = <?= json_encode($staffRecaptchaProvider) ?>;
                </script>
                <script defer src="/assets/js/recaptcha-auth.js?v=8"></script>
            <?php endif; ?>

            <form method="post" class="auth-main-form staff-login-form" enctype="multipart/form-data" data-recaptcha-action="staff_login">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="staff_login">
                <label>Логін працівника</label>
                <input type="text" name="login" placeholder="admin@rungo.test або телефон" autocomplete="username" required autofocus>
                <label>Пароль</label>
                <input type="password" name="password" placeholder="Пароль" autocomplete="current-password" required>
                <label>Файловий ключ працівника</label>
                <input type="file" name="staff_key_file" accept=".rungo-key,.key,.txt">
                <small class="muted">Якщо адміністратор уже видав ключ, без нього службовий вхід не пройде.</small>
                <?php if ($staffRecaptchaSiteKey === ''): ?>
                    <label>Перевірка безпеки: скільки буде 3 + 4?</label>
                    <input type="text" name="staff_captcha" placeholder="7" required>
                <?php endif; ?>
                <button class="btn btn-primary btn-full" type="submit">Увійти в панель</button>
            </form>
            <a class="staff-login-back" href="/account">← Повернутися до клієнтського входу</a>
        </section>
    </main>
</body>
</html>
