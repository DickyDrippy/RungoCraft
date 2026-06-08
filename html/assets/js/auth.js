(() => {
    const CODE_COOLDOWN_SECONDS = 120;
    const processingForms = new WeakSet();

    document.querySelectorAll('[data-tabs]').forEach((tabs) => {
        const buttons = tabs.querySelectorAll('[data-tab]');
        const scope = tabs.closest('.auth-card') || document;
        const panels = scope.querySelectorAll('[data-tab-panel]');

        buttons.forEach((button) => {
            button.addEventListener('click', () => {
                const target = button.dataset.tab;
                buttons.forEach((item) => item.classList.toggle('is-active', item === button));
                panels.forEach((panel) => panel.classList.toggle('is-active', panel.dataset.tabPanel === target));
            });
        });
    });

    const loginForm = document.querySelector('[data-login-mode]');
    const loginInput = document.querySelector('[data-login-input]');
    const passwordBlock = document.querySelector('[data-email-password-block]');
    const passwordInput = document.querySelector('[data-password-input]');
    const loginCodeBlock = document.querySelector('[data-login-code-block]');
    const loginCodeInput = document.querySelector('[data-login-code-input]');
    const codeLabel = document.querySelector('[data-code-label]');
    const authHint = document.querySelector('[data-auth-hint]');
    const contactHint = document.querySelector('[data-auth-contact-hint]');

    function looksLikePhone(value) {
        const raw = String(value || '').trim();
        const digits = raw.replace(/\D+/g, '');
        return !raw.includes('@') && (raw.startsWith('+') || digits.length >= 9);
    }

    function updateLoginMode() {
        if (!loginInput) return;
        const value = loginInput.value.trim();
        const phoneMode = looksLikePhone(value);

        if (phoneMode) {
            if (passwordBlock) passwordBlock.classList.add('is-hidden');
            if (passwordInput) {
                passwordInput.required = false;
                passwordInput.value = '';
            }
            if (codeLabel) codeLabel.textContent = 'SMS-код';
            if (loginCodeInput) {
                loginCodeInput.placeholder = 'Код із SMS';
                loginCodeInput.required = true;
                loginCodeInput.inputMode = 'numeric';
                loginCodeInput.autocomplete = 'one-time-code';
            }
            if (authHint) authHint.textContent = 'Натисніть “Отримати код”, дочекайтесь SMS і введіть код тут.';
            if (contactHint) contactHint.textContent = 'Для входу телефоном використовується SMS-код без другого поля з телефоном.';
        } else {
            if (passwordBlock) passwordBlock.classList.remove('is-hidden');
            if (passwordInput) passwordInput.required = true;
            if (codeLabel) codeLabel.textContent = 'Код із листа';
            if (loginCodeInput) {
                loginCodeInput.placeholder = 'Код з email';
                loginCodeInput.required = true;
                loginCodeInput.inputMode = 'numeric';
                loginCodeInput.autocomplete = 'one-time-code';
            }
            if (authHint) authHint.textContent = 'Для входу email потрібно ввести і пароль, і код підтвердження з листа.';
            if (contactHint) contactHint.textContent = 'Email-вхід захищено подвійно: пароль + код із листа.';
        }
    }

    loginInput?.addEventListener('input', updateLoginMode);
    updateLoginMode();

    loginForm?.addEventListener('submit', () => {
        
        updateLoginMode();
    });

    const registerForm = document.querySelector('[data-register-form]');
    const registerLogin = document.querySelector('[data-register-login]');
    const registerPasswordBlock = document.querySelector('[data-register-password-block]');
    const registerPasswordInput = registerPasswordBlock?.querySelector('input[name="password"]');

    function updateRegisterMode() {
        if (!registerLogin || !registerPasswordBlock || !registerPasswordInput) return;
        const value = registerLogin.value.trim();
        const emailMode = value.includes('@');
        registerPasswordBlock.style.display = 'block';
        registerPasswordInput.required = emailMode;
    }

    registerLogin?.addEventListener('input', updateRegisterMode);
    updateRegisterMode();

    registerForm?.addEventListener('submit', () => {
        const last = registerForm.querySelector('[data-last-name]')?.value.trim() || '';
        const first = registerForm.querySelector('[data-first-name]')?.value.trim() || '';
        const middle = registerForm.querySelector('[data-middle-name]')?.value.trim() || '';
        const full = registerForm.querySelector('[data-full-name]');
        if (full) full.value = [last, first, middle].filter(Boolean).join(' ');
    });

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-auth-send-code]');
        if (!button) return;

        event.preventDefault();
        event.stopPropagation();

        const form = button.closest('form');
        if (!form || processingForms.has(form)) return;

        const destinationInput = form.querySelector('[data-code-destination]')
            || form.querySelector('[name="destination"]')
            || form.querySelector('[name="login"]')
            || form.querySelector('[name="register_login"]');
        const destination = destinationInput?.value.trim() || '';
        if (!destination) {
            showAuthMessage(form, 'Вкажіть телефон або email для отримання коду.', 'error');
            destinationInput?.focus();
            return;
        }

        const purpose = button.dataset.authPurpose || form.querySelector('[name="purpose"]')?.value || 'login';
        const cooldownKey = buildCooldownKey(purpose, destination);
        const cooldownLeft = getCooldownLeft(cooldownKey);
        if (cooldownLeft > 0) {
            lockButton(button, cooldownLeft);
            showAuthMessage(form, `Код уже надіслано. Повторити можна через ${cooldownLeft} с.`, 'success');
            revealCodeFields(form, destination, purpose);
            return;
        }

        processingForms.add(form);
        const originalText = button.dataset.originalText || button.textContent;
        button.dataset.originalText = originalText;
        button.disabled = true;
        button.textContent = 'Надсилаємо...';

        try {
            if (window.RC_RECAPTCHA && typeof window.RC_RECAPTCHA.attachTokenToForm === 'function') {
                await window.RC_RECAPTCHA.attachTokenToForm(form, String(`send_code_${purpose}`));
            }

            const formData = new FormData(form);
            formData.set('action', 'auth_send_code');
            formData.set('purpose', purpose);
            formData.set('destination', destination);

            const response = await fetch('/account', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            });

            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (error) {
                throw new Error('Сервер повернув не JSON. Перевірте обробник auth_send_code.');
            }

            if (!data.ok) {
                throw new Error(data.message || 'Не вдалося надіслати код.');
            }

            const cooldown = Number(data.cooldown || CODE_COOLDOWN_SECONDS);
            setCooldown(cooldownKey, cooldown);
            lockButton(button, cooldown);
            revealCodeFields(form, destination, purpose);
            showAuthMessage(form, data.message || 'Код підтвердження надіслано.', 'success');
        } catch (error) {
            button.disabled = false;
            button.textContent = originalText;
            showAuthMessage(form, error.message || 'Помилка відправки коду.', 'error');
        } finally {
            processingForms.delete(form);
        }
    });

    function revealCodeFields(form, destination, purpose) {
        if (purpose === 'login') {
            if (loginInput) {
                loginInput.value = destination;
                loginInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
            loginCodeBlock?.classList.remove('is-hidden');
            if (loginCodeInput) {
                loginCodeInput.required = true;
                loginCodeInput.focus();
            }
            return;
        }

        if (purpose === 'register') {
            if (registerLogin) {
                registerLogin.value = destination;
                registerLogin.dispatchEvent(new Event('input', { bubbles: true }));
            }
            const block = form.querySelector('[data-register-code-block]') || document.querySelector('[data-register-code-block]');
            const input = form.querySelector('[data-register-code-input]') || document.querySelector('[data-register-code-input]');
            block?.classList.remove('is-hidden');
            if (input) {
                input.required = true;
                input.focus();
            }
        }
    }

    function showAuthMessage(form, message, type) {
        const panel = form.closest('[data-tab-panel]') || document;
        let root = panel.querySelector('[data-auth-message-root]');
        if (!root) {
            root = document.createElement('div');
            root.setAttribute('data-auth-message-root', '1');
            panel.prepend(root);
        }

        root.innerHTML = '';
        const box = document.createElement('div');
        box.className = `auth-message auth-message--${type}`;
        box.textContent = message;
        root.appendChild(box);
    }

    function buildCooldownKey(purpose, destination) {
        return `rc_code_${purpose}_${destination.replace(/\s+/g, '').toLowerCase()}`;
    }

    function setCooldown(key, seconds) {
        const until = Date.now() + Math.max(1, Number(seconds || CODE_COOLDOWN_SECONDS)) * 1000;
        localStorage.setItem(key, String(until));
    }

    function getCooldownLeft(key) {
        const until = Number(localStorage.getItem(key) || 0);
        if (!until) return 0;
        const left = Math.ceil((until - Date.now()) / 1000);
        if (left <= 0) {
            localStorage.removeItem(key);
            return 0;
        }
        return left;
    }

    function lockButton(button, seconds) {
        if (!button) return;
        if (!button.dataset.originalText) button.dataset.originalText = button.textContent;
        let left = Math.max(1, Number(seconds || CODE_COOLDOWN_SECONDS));
        button.disabled = true;

        const tick = () => {
            button.textContent = `Повторити через ${left} с`;
            left -= 1;
            if (left < 0) {
                window.clearInterval(timer);
                button.disabled = false;
                button.textContent = button.dataset.originalText || 'Отримати код';
            }
        };

        tick();
        const timer = window.setInterval(tick, 1000);
    }
})();
