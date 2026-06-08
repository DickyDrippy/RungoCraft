(() => {
    function getProvider() {
        return String(window.RC_RECAPTCHA_PROVIDER || 'classic').toLowerCase() === 'enterprise'
            ? 'enterprise'
            : 'classic';
    }

    function getClient() {
        if (!window.grecaptcha) return null;
        return getProvider() === 'enterprise' ? (window.grecaptcha.enterprise || null) : window.grecaptcha;
    }

    function ensureTokenInput(form) {
        let input = form.querySelector('input[name="g-recaptcha-response"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'g-recaptcha-response';
            form.appendChild(input);
        }
        return input;
    }

    function ensureErrorBox(form) {
        let box = form.querySelector('[data-rc-captcha-error]');
        if (!box) {
            box = document.createElement('small');
            box.className = 'rc-captcha-error';
            box.setAttribute('data-rc-captcha-error', '1');
            box.hidden = true;
            const badge = form.querySelector('[data-recaptcha-status]');
            if (badge) {
                badge.insertAdjacentElement('afterend', box);
            } else {
                form.appendChild(box);
            }
        }
        return box;
    }

    function createStatusCard(form) {
        let card = form.querySelector('[data-recaptcha-status]');
        if (card) return card;

        card = document.createElement('div');
        card.className = 'rc-captcha-mini';
        card.setAttribute('data-recaptcha-status', '1');
        card.innerHTML = '<img src="/assets/img/capt.png" alt="Google reCAPTCHA захищено" loading="lazy">';

        const submit = form.querySelector('button[type="submit"]');
        if (submit) {
            submit.insertAdjacentElement('beforebegin', card);
        } else {
            form.appendChild(card);
        }
        return card;
    }

    function setStatus(form, state, message = '') {
        const card = createStatusCard(form);
        card.classList.remove('is-verifying', 'is-error');
        if (state === 'verifying') card.classList.add('is-verifying');
        if (state === 'error') card.classList.add('is-error');

        const errorBox = ensureErrorBox(form);
        if (state === 'error' && message) {
            errorBox.textContent = message;
            errorBox.hidden = false;
        } else {
            errorBox.textContent = '';
            errorBox.hidden = true;
        }
    }

    function restoreButtons(buttons) {
        buttons.forEach((button) => {
            button.disabled = false;
            button.textContent = button.dataset.originalText || button.textContent || 'Відправити';
        });
    }

    function lockButtons(buttons) {
        buttons.forEach((button) => {
            button.disabled = true;
            if (!button.dataset.originalText) button.dataset.originalText = button.textContent;
            button.textContent = 'Перевірка...';
        });
    }

    const forms = Array.from(document.querySelectorAll('form[data-recaptcha-action]'))
        .filter((form) => !form.matches('[data-auth-code-form], [data-auth-register-code-form]'));
    const siteKey = String(window.RC_RECAPTCHA_SITE_KEY || '').trim();

    forms.forEach((form) => createStatusCard(form));

    window.RC_RECAPTCHA = {
        attachTokenToForm(form, actionName) {
            return new Promise((resolve, reject) => {
                if (!siteKey) {
                    resolve('');
                    return;
                }

                const client = getClient();
                if (!client || typeof client.ready !== 'function' || typeof client.execute !== 'function') {
                    reject(new Error('reCAPTCHA не завантажилась. Оновіть сторінку.'));
                    return;
                }

                const action = String(actionName || form.dataset.recaptchaAction || 'submit').replace(/[^A-Za-z0-9_]/g, '_');
                client.ready(() => {
                    client.execute(siteKey, { action })
                        .then((token) => {
                            if (!token) {
                                reject(new Error('Не отримано токен reCAPTCHA.'));
                                return;
                            }
                            ensureTokenInput(form).value = token;
                            resolve(token);
                        })
                        .catch(() => reject(new Error('Не вдалося отримати токен reCAPTCHA.')));
                });
            });
        }
    };

    forms.forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.dataset.recaptchaReady === '1') {
                form.dataset.recaptchaReady = '0';
                return;
            }

            if (!siteKey) {
                return;
            }

            const client = getClient();
            if (!client || typeof client.ready !== 'function' || typeof client.execute !== 'function') {
                event.preventDefault();
                setStatus(form, 'error', 'reCAPTCHA не завантажилась. Перевірте ключ v3, домен 127.0.0.1 і очистіть кеш.');
                return;
            }

            event.preventDefault();
            const action = String(form.dataset.recaptchaAction || 'submit').replace(/[^A-Za-z0-9_]/g, '_');
            const submitButtons = form.querySelectorAll('button[type="submit"]');
            lockButtons(submitButtons);
            setStatus(form, 'verifying');

            client.ready(() => {
                client.execute(siteKey, { action })
                    .then((token) => {
                        if (!token) throw new Error('empty token');
                        ensureTokenInput(form).value = token;
                        form.dataset.recaptchaReady = '1';
                        setStatus(form, 'ready');
                        if (typeof form.requestSubmit === 'function') {
                            form.requestSubmit();
                        } else {
                            form.submit();
                        }
                    })
                    .catch(() => {
                        restoreButtons(submitButtons);
                        setStatus(form, 'error', 'Не вдалося отримати токен reCAPTCHA. Оновіть сторінку і спробуйте ще раз.');
                    });
            });
        });
    });
})();
