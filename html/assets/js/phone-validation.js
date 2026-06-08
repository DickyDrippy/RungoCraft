(() => {
    const SELECTOR = 'input[type="tel"], input[name="customer_phone"], input[name="phone"], input[name="user_phone"]';
    const phoneInputs = Array.from(document.querySelectorAll(SELECTOR));

    if (!phoneInputs.length) {
        return;
    }

    function digitsOnly(value) {
        return String(value || '').replace(/\D+/g, '');
    }

    function normalizeDigits(value) {
        let digits = digitsOnly(value);

        if (digits.startsWith('380')) {
            digits = digits.slice(0, 12);
        } else if (digits.startsWith('38') && digits.length > 2) {
            digits = digits.slice(0, 12);
        } else if (digits.startsWith('0')) {
            digits = ('38' + digits).slice(0, 12);
        } else if (digits.startsWith('80')) {
            digits = ('3' + digits).slice(0, 12);
        } else if (digits.length > 0) {
            digits = ('380' + digits).slice(0, 12);
        }

        return digits;
    }

    function formatPhone(value) {
        const digits = normalizeDigits(value);

        if (!digits) {
            return '';
        }

        const operator = digits.slice(2, 5);
        const first = digits.slice(5, 8);
        const second = digits.slice(8, 10);
        const third = digits.slice(10, 12);

        let result = '+38';

        if (operator.length > 0) {
            result += ' (' + operator;
            if (operator.length === 3) {
                result += ')';
            }
        }

        if (first.length > 0) {
            result += ' ' + first;
        }

        if (second.length > 0) {
            result += '-' + second;
        }

        if (third.length > 0) {
            result += '-' + third;
        }

        return result;
    }

    function isValidPhone(value) {
        return /^380\d{9}$/.test(normalizeDigits(value));
    }

    function applyInput(input) {
        input.setAttribute('inputmode', 'tel');
        input.setAttribute('autocomplete', 'tel');
        input.setAttribute('placeholder', input.getAttribute('placeholder') || '+38 (0__) ___-__-__');
        input.dataset.phoneUa = '1';

        input.addEventListener('input', () => {
            const cursorAtEnd = input.selectionStart === input.value.length;
            input.value = formatPhone(input.value);

            if (cursorAtEnd) {
                input.setSelectionRange(input.value.length, input.value.length);
            }

            input.setCustomValidity('');
            input.classList.remove('is-invalid');
        });

        input.addEventListener('blur', () => {
            if (input.value.trim() === '') {
                input.setCustomValidity('');
                input.classList.remove('is-invalid');
                return;
            }

            if (!isValidPhone(input.value)) {
                input.setCustomValidity('Введіть телефон у форматі +38 (0XX) XXX-XX-XX.');
                input.classList.add('is-invalid');
                return;
            }

            input.value = formatPhone(input.value);
            input.setCustomValidity('');
            input.classList.remove('is-invalid');
        });

        const form = input.closest('form');
        if (form && !form.dataset.phoneValidationBound) {
            form.dataset.phoneValidationBound = '1';
            form.addEventListener('submit', (event) => {
                const invalid = Array.from(form.querySelectorAll(SELECTOR)).find((field) => {
                    if (!field.required && field.value.trim() === '') {
                        field.setCustomValidity('');
                        field.classList.remove('is-invalid');
                        return false;
                    }

                    const ok = isValidPhone(field.value);
                    field.setCustomValidity(ok ? '' : 'Введіть телефон у форматі +38 (0XX) XXX-XX-XX.');
                    field.classList.toggle('is-invalid', !ok);
                    return !ok;
                });

                if (invalid) {
                    event.preventDefault();
                    invalid.reportValidity();
                    invalid.focus();
                }
            });
        }
    }

    phoneInputs.forEach(applyInput);
})();
