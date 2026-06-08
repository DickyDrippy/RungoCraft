(() => {
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

  const money = (value) => new Intl.NumberFormat('uk-UA').format(Math.round(value)) + ' грн';
  const toast = (message) => {
    const node = $('[data-toast]');
    if (!node) return;
    node.textContent = message;
    node.classList.add('is-open');
    setTimeout(() => node.classList.remove('is-open'), 7000);
  };

  document.addEventListener('click', (event) => {
    const wishButton = event.target.closest('[data-wishlist]');
    if (wishButton) {
      const active = !wishButton.classList.contains('is-active');
      wishButton.classList.toggle('is-active', active);
      const card = wishButton.closest('.product-card');
      card?.classList.toggle('is-in-wishlist', active);
      if (wishButton.classList.contains('wish-btn')) {
        wishButton.textContent = active ? '♥' : '♡';
      } else {
        wishButton.textContent = active ? '♥ У бажаному' : '♡ Бажане';
      }
      wishButton.setAttribute('aria-label', active ? 'Вже у бажаному' : 'Додати в бажане');
      toast(active ? 'Товар додано в бажане' : 'Список бажаного оновлено');
    }

    const compareButton = event.target.closest('[data-compare]');
    if (compareButton) {
      const active = !compareButton.classList.contains('is-active');
      compareButton.classList.toggle('is-active', active);
      compareButton.closest('.product-card')?.classList.toggle('is-in-comparison', active);
      if (!compareButton.classList.contains('compare-btn')) {
        compareButton.textContent = active ? '✓ У порівнянні' : '⚖ Порівняти';
      }
      compareButton.setAttribute('aria-label', active ? 'Вже у порівнянні' : 'Додати до порівняння');
      toast(active ? 'Товар додано до порівняння' : 'Порівняння оновлено');
    }
  });


  const menuButton = $('[data-menu-toggle]');
  const menuPanel = $('[data-menu-panel]');
  const catalogButton = $('[data-catalog-toggle]');
  const catalogPanel = $('[data-catalog-panel]');

  menuButton?.addEventListener('click', () => {
    menuPanel?.classList.toggle('is-open');
    catalogPanel?.classList.remove('is-open');
    menuButton.setAttribute('aria-expanded', menuPanel?.classList.contains('is-open') ? 'true' : 'false');
  });

  catalogButton?.addEventListener('click', () => {
    catalogPanel?.classList.toggle('is-open');
    menuPanel?.classList.remove('is-open');
    catalogButton.setAttribute('aria-expanded', catalogPanel?.classList.contains('is-open') ? 'true' : 'false');
  });

  document.addEventListener('click', (event) => {
    if (!event.target.closest('.main-nav')) {
      menuPanel?.classList.remove('is-open');
      catalogPanel?.classList.remove('is-open');
    }
  });

  const closeCatalogBranch = (branch) => {
    if (!branch) return;
    branch.classList.remove('is-open');
    branch.querySelectorAll('.catalog-subrow.is-open').forEach((child) => child.classList.remove('is-open'));
    branch.querySelectorAll('[data-catalog-branch-toggle]').forEach((toggle) => toggle.setAttribute('aria-expanded', 'false'));
  };

  $$('[data-category-row]').forEach((button) => {
    button.addEventListener('click', () => {
      const row = button.closest('.catalog-row');
      const panel = button.closest('[data-catalog-panel]');
      if (!row || !panel) return;

      const willOpen = !row.classList.contains('is-open');
      $$('.catalog-row.is-open', panel).forEach((item) => {
        if (item !== row) {
          item.classList.remove('is-open');
          item.querySelector('[data-category-row]')?.setAttribute('aria-expanded', 'false');
          item.querySelectorAll('.catalog-subrow.is-open').forEach((branch) => closeCatalogBranch(branch));
        }
      });

      row.classList.toggle('is-open', willOpen);
      button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
      if (!willOpen) {
        row.querySelectorAll('.catalog-subrow.is-open').forEach((branch) => closeCatalogBranch(branch));
      }
    });
  });

  $$('[data-catalog-branch-toggle]').forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();

      const branch = button.closest('[data-catalog-branch]');
      if (!branch) return;
      const parent = branch.parentElement;
      const willOpen = !branch.classList.contains('is-open');

      if (parent) {
        [...parent.children].forEach((item) => {
          if (item !== branch && item.classList?.contains('catalog-subrow')) {
            closeCatalogBranch(item);
          }
        });
      }

      branch.classList.toggle('is-open', willOpen);
      button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
      if (!willOpen) {
        closeCatalogBranch(branch);
      }
    });
  });

  $$('[data-grid]').forEach((button) => {
    button.addEventListener('click', () => {
      $$('[data-grid]').forEach((btn) => btn.classList.remove('is-active'));
      button.classList.add('is-active');
      $('[data-products-grid]')?.classList.toggle('is-list', button.dataset.grid === 'list');
    });
  });

  document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-modal]');
    if (opener) {
      const modal = document.querySelector(`[data-modal-window="${opener.dataset.modal}"]`);
      if (modal && opener.dataset.modal === 'quick') {
        const card = opener.closest('[data-product-id]');
        const productId = opener.dataset.quickProductId || card?.dataset.productId || '';
        const productName = opener.dataset.quickProductName || card?.dataset.productName || '';
        const idInput = modal.querySelector('[data-quick-product-id-input]');
        const nameInput = modal.querySelector('[data-quick-product-name-input]');
        if (idInput) idInput.value = productId;
        if (nameInput) nameInput.value = productName;
      }
      modal?.classList.add('is-open');
      modal?.setAttribute('aria-hidden', 'false');
    }
    if (event.target.closest('[data-modal-close]') || event.target.classList.contains('modal')) {
      const modal = event.target.closest('.modal') || event.target;
      modal?.classList.remove('is-open');
      modal?.setAttribute('aria-hidden', 'true');
    }
  });


  $$('[data-catalog-directory]').forEach((root) => {
    const buttons = $$('[data-catalog-dir-tab]', root);
    const panels = $$('[data-catalog-dir-panel]', root);

    buttons.forEach((button) => {
      button.addEventListener('click', () => {
        const code = button.dataset.catalogDirTab;

        buttons.forEach((item) => item.classList.remove('is-active'));
        panels.forEach((item) => item.classList.remove('is-active'));

        button.classList.add('is-active');
        $(`[data-catalog-dir-panel="${code}"]`, root)?.classList.add('is-active');
      });
    });
  });

  $$('[data-tabs]').forEach((tabsRoot) => {
    tabsRoot.addEventListener('click', (event) => {
      const button = event.target.closest('[data-tab]');
      if (!button) return;
      const scope = tabsRoot.closest('.dashboard-card, .tabs-card, .admin-shell, .login-card') || document;
      $$('[data-tab]', tabsRoot).forEach((btn) => btn.classList.remove('is-active'));
      button.classList.add('is-active');
      $$('[data-tab-panel]', scope).forEach((panel) => panel.classList.remove('is-active'));
      $(`[data-tab-panel="${button.dataset.tab}"]`, scope)?.classList.add('is-active');
      if (tabsRoot.closest('.admin-shell')) history.replaceState(null, '', '#'+button.dataset.tab);
    });
  });


  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-tab]');
    if (!button) return;
    const tabsRoot = button.closest('[data-tabs]');
    if (!tabsRoot) return;
    const scope = tabsRoot.closest('.dashboard-card, .tabs-card, .admin-shell, .login-card') || document;
    tabsRoot.querySelectorAll('[data-tab]').forEach((btn) => btn.classList.remove('is-active'));
    button.classList.add('is-active');
    scope.querySelectorAll('[data-tab-panel]').forEach((panel) => panel.classList.remove('is-active'));
    scope.querySelector(`[data-tab-panel="${CSS.escape(button.dataset.tab)}"]`)?.classList.add('is-active');
    if (tabsRoot.closest('.admin-shell')) history.replaceState(null, '', '#'+button.dataset.tab);
  });

  const initialHashTab = window.location.hash ? window.location.hash.slice(1) : '';
  if (initialHashTab) {
    const adminShell = document.querySelector('.admin-shell');
    const tabButton = adminShell?.querySelector(`[data-tab="${CSS.escape(initialHashTab)}"]`);
    if (tabButton) tabButton.click();
  }

  const loginInput = $('[data-login-input]');
  const secretInput = $('[data-secret-input]');
  const secretLabel = $('[data-secret-label]');
  loginInput?.addEventListener('input', () => {
    const value = loginInput.value.trim();
    const phoneMode = /^\+?[0-9\s\-()]{10,}$/.test(value);
    if (secretLabel) secretLabel.textContent = phoneMode ? 'Код підтвердження' : 'Пароль';
    if (secretInput) {
      secretInput.type = phoneMode ? 'text' : 'password';
      secretInput.placeholder = phoneMode ? 'Код з SMS' : 'Пароль';
    }
  });

  const registerEmail = $('[data-register-form] input[name="email"]');
  const registerPasswordBlock = $('[data-register-password-block]');
  const toggleRegisterPassword = () => {
    if (!registerEmail || !registerPasswordBlock) return;
    registerPasswordBlock.style.display = registerEmail.value.trim() ? 'block' : 'none';
  };
  registerEmail?.addEventListener('input', toggleRegisterPassword);
  toggleRegisterPassword();

  $$('[data-fake-submit]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      form.reset();
      toast('Дані прийнято. Менеджер звʼяжеться з вами.');
      form.closest('.modal')?.classList.remove('is-open');
    });
  });



  $$('[data-product-gallery]').forEach((gallery) => {
    const main = $('[data-gallery-main]', gallery);
    const thumbs = $$('[data-gallery-thumb]', gallery);
    const show = (index) => {
      if (!main || thumbs.length === 0) return;
      const nextIndex = (index + thumbs.length) % thumbs.length;
      const thumb = thumbs[nextIndex];
      main.src = thumb.dataset.src || main.src;
      thumbs.forEach((item) => item.classList.remove('is-active'));
      thumb.classList.add('is-active');
      gallery.dataset.currentIndex = String(nextIndex);
    };
    thumbs.forEach((thumb, index) => thumb.addEventListener('click', () => show(index)));
    $('[data-gallery-prev]', gallery)?.addEventListener('click', () => show((Number(gallery.dataset.currentIndex || 0) || 0) - 1));
    $('[data-gallery-next]', gallery)?.addEventListener('click', () => show((Number(gallery.dataset.currentIndex || 0) || 0) + 1));
  });


  
  $$('[data-zoom-area]').forEach((area) => {
    const setZoomPoint = (event) => {
      const rect = area.getBoundingClientRect();
      const x = ((event.clientX - rect.left) / Math.max(rect.width, 1)) * 100;
      const y = ((event.clientY - rect.top) / Math.max(rect.height, 1)) * 100;
      area.style.setProperty('--zoom-x', `${Math.max(0, Math.min(100, x))}%`);
      area.style.setProperty('--zoom-y', `${Math.max(0, Math.min(100, y))}%`);
    };
    area.addEventListener('pointermove', setZoomPoint);
    area.addEventListener('pointerleave', () => {
      area.style.removeProperty('--zoom-x');
      area.style.removeProperty('--zoom-y');
    });
  });

  const chatWidget = $('[data-chat-widget]');
  const chatBody = $('[data-chat-body]');
  const openChat = () => chatWidget?.classList.add('is-open');
  $('[data-chat-toggle]')?.addEventListener('click', openChat);
  $('[data-chat-close]')?.addEventListener('click', () => chatWidget?.classList.remove('is-open'));
  $$('[data-open-chat]').forEach((button) => button.addEventListener('click', openChat));

  const addChatMessage = (message, type = 'client') => {
    if (!chatBody) return;
    const div = document.createElement('div');
    div.className = `msg msg-${type}`;
    div.textContent = message;
    chatBody.appendChild(div);
    chatBody.scrollTop = chatBody.scrollHeight;
  };

  const managerAnswer = (message) => {
    setTimeout(() => {
      const text = message.toLowerCase();
      const rules = [
        { keys: ['достав', 'нова пошта', 'відділен', 'кур'], answer: 'Доставка оформлюється під час замовлення: можна обрати Нову пошту, курʼєра або самовивіз. Для важких матеріалів менеджер уточнює вартість і час.' },
        { keys: ['оплат', 'liqpay', 'wayforpay', 'карт'], answer: 'Доступні оплата при отриманні, онлайн-оплата та рахунок для юр. осіб. Онлайн-оплата працює після підтвердження merchant-акаунта і callback URL.' },
        { keys: ['рахунок', 'юр', 'фоп', 'тов', 'безгот'], answer: 'Для юридичних осіб менеджер може сформувати рахунок. У формі замовлення або в розділі “Опт” залиште реквізити компанії.' },
        { keys: ['матеріал', 'спис', 'розрах', 'кошторис'], answer: 'Надішліть список матеріалів, площу або короткий опис робіт. Менеджер підготує розрахунок і запропонує товари з каталогу.' },
        { keys: ['наяв', 'склад', 'залиш'], answer: 'Наявність видно в картці товару. Якщо залишку не вистачає, менеджер перевірить склад і запропонує заміну або очікування поставки.' },
        { keys: ['поверн', 'гарант', 'обмін'], answer: 'Повернення та обмін узгоджуються з менеджером відповідно до стану товару, документів і типу матеріалу.' }
      ];
      let answer = 'Я не зміг точно визначити тему питання. Напишіть у підтримку або зверніться напряму: +38 (093) 727-85-61, fatoha359@gmail.com.';
      const matched = rules.find((rule) => rule.keys.some((key) => text.includes(key)));
      if (matched) answer = matched.answer;
      addChatMessage(answer, 'manager');
    }, 550);
  };

  $$('[data-chat-question]').forEach((button) => {
    button.addEventListener('click', () => {
      const text = button.dataset.chatQuestion || button.textContent;
      addChatMessage(text, 'client');
      managerAnswer(text);
    });
  });

  $('[data-chat-form]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const input = $('input[name="message"]', form) || $('input', form);
    const text = input?.value.trim();
    if (!text) return;
    addChatMessage(text, 'client');
    try {
      const formData = new FormData(form);
      formData.set('message', text);
      input.value = '';
      const response = await fetch(form.action || '/', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      });
      const data = await response.json();
      addChatMessage(data.answer || data.message || 'Я проаналізував питання, але потрібне уточнення.', 'manager');
    } catch (error) {
      managerAnswer(text);
    }
  });

  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) entry.target.classList.add('is-visible');
    });
  }, { threshold: 0.12 });
  $$('.reveal').forEach((node) => {
    observer.observe(node);
    if (node.getBoundingClientRect().top < window.innerHeight) node.classList.add('is-visible');
  });



  
  
  const ajaxPostSelector = 'form[method="post"]';

  const statusLabels = {
    created: 'Створено', waiting_confirmation: 'Очікує підтвердження', confirmed: 'Підтверджено',
    waiting_payment: 'Очікує оплати', paid: 'Оплачено', processing: 'В обробці', picking: 'Комплектується на складі',
    packing: 'Комплектується', packed: 'Зібрано', ready_for_delivery: 'Готово до відправлення', sent: 'Передано в доставку',
    delivering: 'Доставляється', delivered: 'Доставлено', cancelled: 'Скасовано', returned: 'Повернення',
    pending: 'Очікує', ttn_created: 'ТТН створено', in_transit: 'У дорозі', manager_confirm: 'Очікує менеджера',
    failed: 'Помилка', done: 'Виконано', new: 'Нове'
  };

  const activateAdminTab = (tabName) => {
    if (!tabName) return;
    const shell = document.querySelector('.admin-shell');
    if (!shell) return;
    shell.querySelectorAll('[data-tab]').forEach((btn) => btn.classList.toggle('is-active', btn.dataset.tab === tabName));
    shell.querySelectorAll('[data-tab-panel]').forEach((panel) => panel.classList.toggle('is-active', panel.dataset.tabPanel === tabName));
  };

  document.addEventListener('submit', (event) => {
    const form = event.target.closest('form');
    if (!form || !form.closest('.admin-shell')) return;
    const row = form.closest('[data-admin-row], tr[id]');
    if (row?.id) sessionStorage.setItem('rungoAdminFocusRow', row.id);
    const returnTo = form.querySelector('input[name="return_to"]');
    if (!returnTo || !returnTo.value.startsWith('/admin')) return;
    const activeTab = document.querySelector('.admin-shell [data-tab].is-active')?.dataset.tab;
    if (activeTab) returnTo.value = `/admin#${activeTab}`;
  }, true);

  const focusRow = sessionStorage.getItem('rungoAdminFocusRow');
  if (focusRow) {
    sessionStorage.removeItem('rungoAdminFocusRow');
    setTimeout(() => {
      const target = document.getElementById(focusRow);
      if (target) {
        target.scrollIntoView({ block: 'center', behavior: 'smooth' });
        target.classList.add('admin-row-focus');
        setTimeout(() => target.classList.remove('admin-row-focus'), 3500);
      }
    }, 350);
  }

  const adminRowSearchText = (row) => {
    let text = `${row.textContent || ''} ${row.dataset.adminSearch || ''}`;
    row.querySelectorAll('input, textarea, select').forEach((field) => {
      const type = (field.getAttribute('type') || '').toLowerCase();
      if (type === 'password' || type === 'file') return;
      if (field.tagName === 'SELECT') {
        const selectedText = Array.from(field.selectedOptions || []).map((option) => option.textContent || '').join(' ');
        text += ` ${field.value || ''} ${selectedText}`;
      } else if (type === 'checkbox' || type === 'radio') {
        if (field.checked) text += ` ${field.value || ''}`;
      } else {
        text += ` ${field.value || ''}`;
      }
      text += ` ${field.getAttribute('placeholder') || ''}`;
    });
    return text.toLowerCase();
  };

  const updateAdminTableVisibility = (tableName) => {
    if (!tableName) return;
    const table = document.querySelector(`[data-admin-table="${CSS.escape(tableName)}"]`);
    if (!table) return;
    const input = document.querySelector(`[data-admin-table-filter="${CSS.escape(tableName)}"]`);
    const toggle = document.querySelector(`[data-table-toggle="${CSS.escape(tableName)}"]`);
    const query = (input?.value || '').trim().toLowerCase();
    const limit = Math.max(1, parseInt(table.dataset.rowLimit || '0', 10) || 0);
    const expanded = table.dataset.expanded === '1' || query !== '';
    let matched = 0;
    table.querySelectorAll('tbody tr').forEach((row) => {
      const isMatch = query === '' || adminRowSearchText(row).includes(query);
      if (!isMatch) {
        row.hidden = true;
        return;
      }
      const shouldLimit = limit > 0 && !expanded && matched >= limit;
      row.hidden = shouldLimit;
      matched += 1;
    });
    if (toggle) {
      toggle.hidden = !(limit > 0 && matched > limit && query === '');
      toggle.textContent = table.dataset.expanded === '1'
        ? (toggle.dataset.expandedLabel || 'Згорнути')
        : (toggle.dataset.collapsedLabel || 'Показати всі');
    }
  };

  document.addEventListener('input', (event) => {
    const input = event.target.closest('[data-admin-table-filter]');
    if (!input) return;
    updateAdminTableVisibility(input.dataset.adminTableFilter);
  });

  document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-table-toggle]');
    if (!toggle) return;
    const table = document.querySelector(`[data-admin-table="${CSS.escape(toggle.dataset.tableToggle)}"]`);
    if (!table) return;
    table.dataset.expanded = table.dataset.expanded === '1' ? '0' : '1';
    updateAdminTableVisibility(toggle.dataset.tableToggle);
  });

  $$('[data-admin-table][data-row-limit]').forEach((table) => updateAdminTableVisibility(table.dataset.adminTable));

  const selectFilterCache = new WeakMap();

  const normalizeSelectFilterText = (value) => String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[’'`]/g, '')
    .replace(/ё/g, 'е')
    .replace(/і/g, 'и')
    .replace(/ї/g, 'и')
    .replace(/є/g, 'е')
    .replace(/\s+/g, ' ');

  const getSelectOriginalOptions = (select) => {
    if (!selectFilterCache.has(select)) {
      selectFilterCache.set(select, Array.from(select.options).map((option) => ({
        value: option.value,
        text: option.textContent || '',
        disabled: option.disabled,
        defaultSelected: option.defaultSelected
      })));
    }
    return selectFilterCache.get(select) || [];
  };

  const rebuildFilteredSelect = (select, query) => {
    const normalizedQuery = normalizeSelectFilterText(query);
    const originalOptions = getSelectOriginalOptions(select);
    const previousValue = select.value;
    const fragment = document.createDocumentFragment();
    let firstMatchValue = '';
    let hasPreviousValue = false;

    originalOptions.forEach((optionData, index) => {
      const isDefaultOption = index === 0 || optionData.value === '';
      const optionText = normalizeSelectFilterText(`${optionData.text} ${optionData.value}`);
      const isMatch = isDefaultOption || normalizedQuery === '' || optionText.includes(normalizedQuery);

      if (!isMatch) return;

      const option = document.createElement('option');
      option.value = optionData.value;
      option.textContent = optionData.text;
      option.disabled = optionData.disabled;
      option.defaultSelected = optionData.defaultSelected;
      fragment.appendChild(option);

      if (!isDefaultOption && firstMatchValue === '') firstMatchValue = optionData.value;
      if (optionData.value === previousValue) hasPreviousValue = true;
    });

    select.replaceChildren(fragment);

    if (hasPreviousValue) {
      select.value = previousValue;
    } else if (normalizedQuery !== '' && firstMatchValue !== '') {
      select.value = firstMatchValue;
    } else {
      select.value = '';
    }

    select.dataset.filteredCount = String(Math.max(0, select.options.length - 1));
  };

  document.addEventListener('focusin', (event) => {
    const filterInput = event.target.closest('[data-select-filter]');
    if (!filterInput) return;
    const select = document.getElementById(filterInput.dataset.selectFilter || '');
    if (select) getSelectOriginalOptions(select);
  });

  document.addEventListener('input', (event) => {
    const filterInput = event.target.closest('[data-select-filter]');
    if (!filterInput) return;
    const select = document.getElementById(filterInput.dataset.selectFilter || '');
    if (!select) return;
    rebuildFilteredSelect(select, filterInput.value);
  });

  document.addEventListener('keydown', (event) => {
    const filterInput = event.target.closest('[data-select-filter]');
    if (!filterInput || event.key !== 'Enter') return;
    const select = document.getElementById(filterInput.dataset.selectFilter || '');
    if (!select) return;
    event.preventDefault();
    if (select.options.length > 1 && select.selectedIndex <= 0) select.selectedIndex = 1;
    select.focus();
  });

  document.addEventListener('click', (event) => {
    const clearButton = event.target.closest('[data-select-filter-clear]');
    if (!clearButton) return;
    const input = document.querySelector(`[data-select-filter="${CSS.escape(clearButton.dataset.selectFilterClear || '')}"]`);
    if (!input) return;
    const select = document.getElementById(input.dataset.selectFilter || '');
    input.value = '';
    if (select) rebuildFilteredSelect(select, '');
    input.focus();
  });


  const productEditForms = () => Array.from(document.querySelectorAll('.admin-product-edit-form'));

  const productFormRow = (form) => {
    if (!form) return null;
    const id = form.id;
    if (!id) return null;
    return document.querySelector(`[data-product-edit-row] input[form="${CSS.escape(id)}"]`)?.closest('[data-product-edit-row]')
      || document.querySelector(`[data-product-edit-row] textarea[form="${CSS.escape(id)}"]`)?.closest('[data-product-edit-row]')
      || document.querySelector(`[data-product-edit-row] select[form="${CSS.escape(id)}"]`)?.closest('[data-product-edit-row]')
      || null;
  };

  const updateProductDirtyCounter = () => {
    const dirtyForms = productEditForms().filter((form) => form.dataset.dirty === '1');
    const counter = document.querySelector('[data-products-dirty-counter]');
    const saveAll = document.querySelector('[data-products-save-all]');
    if (counter) {
      counter.textContent = dirtyForms.length > 0
        ? `Є незбережені зміни: ${dirtyForms.length}`
        : 'Немає змін';
    }
    if (saveAll) saveAll.disabled = dirtyForms.length === 0 || saveAll.dataset.saving === '1';
  };

  const setProductFormDirty = (form, isDirty) => {
    if (!form) return;
    form.dataset.dirty = isDirty ? '1' : '0';
    const row = productFormRow(form);
    row?.classList.toggle('product-row-dirty', Boolean(isDirty));
    const state = row?.querySelector('[data-product-row-state]');
    if (state) {
      state.textContent = isDirty ? 'Є зміни' : 'Збережено';
      state.classList.toggle('is-dirty', Boolean(isDirty));
      state.classList.toggle('is-saved', !isDirty);
    }
    updateProductDirtyCounter();
  };

  const formForProductField = (field) => {
    const formId = field?.getAttribute('form');
    if (formId) return document.getElementById(formId);
    return field?.closest('form') || null;
  };

  const saveProductFormAjax = async (form, submitter = null) => {
    if (!form) return { ok: false, message: 'Форму товару не знайдено.' };
    const row = productFormRow(form);
    const state = row?.querySelector('[data-product-row-state]');
    if (state) {
      state.textContent = 'Збереження...';
      state.classList.remove('is-dirty', 'is-saved', 'is-error');
      state.classList.add('is-saving');
    }

    const data = new FormData(form);
    if (submitter?.name) data.set(submitter.name, submitter.value || submitter.textContent || '');

    const response = await fetch(form.action || window.location.href, {
      method: form.method || 'POST',
      body: data,
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      }
    });

    let result = null;
    const contentType = response.headers.get('content-type') || '';
    if (contentType.includes('application/json')) {
      result = await response.json();
    } else {
      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      result = { ok: response.ok, message: doc.querySelector('.flash')?.textContent?.trim() || (response.ok ? 'Збережено.' : 'Помилка збереження.') };
    }

    if (result.ok) {
      setProductFormDirty(form, false);
      if (state) {
        state.textContent = result.message || 'Збережено';
        state.classList.remove('is-saving', 'is-dirty', 'is-error');
        state.classList.add('is-saved');
      }
    } else {
      if (state) {
        state.textContent = result.message || 'Помилка';
        state.classList.remove('is-saving', 'is-saved');
        state.classList.add('is-error');
      }
    }
    return result;
  };

  document.addEventListener('input', (event) => {
    const field = event.target.closest('.product-edit-table input, .product-edit-table textarea, .product-edit-table select');
    if (!field) return;
    const form = formForProductField(field);
    if (form?.classList.contains('admin-product-edit-form')) setProductFormDirty(form, true);
  });

  document.addEventListener('change', (event) => {
    const field = event.target.closest('.product-edit-table input, .product-edit-table textarea, .product-edit-table select');
    if (!field) return;
    const form = formForProductField(field);
    if (form?.classList.contains('admin-product-edit-form')) setProductFormDirty(form, true);
  });

  document.addEventListener('submit', async (event) => {
    const form = event.target.closest('.admin-product-edit-form');
    if (!form) return;
    event.preventDefault();
    const submitter = event.submitter;
    const originalText = submitter?.textContent || '';
    if (submitter) {
      submitter.disabled = true;
      submitter.textContent = 'Збереження...';
    }
    try {
      const result = await saveProductFormAjax(form, submitter);
      if (!result.ok) alert(result.message || 'Не вдалося зберегти товар.');
    } catch (error) {
      alert('Не вдалося зберегти товар без перезавантаження сторінки. Перевірте підключення або повторіть дію.');
    } finally {
      if (submitter) {
        submitter.disabled = false;
        submitter.textContent = originalText;
      }
      updateProductDirtyCounter();
    }
  }, true);

  document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-products-save-all]');
    if (!button) return;
    const dirtyForms = productEditForms().filter((form) => form.dataset.dirty === '1');
    if (dirtyForms.length === 0) return;

    button.dataset.saving = '1';
    button.disabled = true;
    const originalText = button.textContent;
    const counter = document.querySelector('[data-products-dirty-counter]');
    let saved = 0;
    let failed = 0;

    for (let i = 0; i < dirtyForms.length; i += 1) {
      button.textContent = `Збереження ${i + 1}/${dirtyForms.length}...`;
      if (counter) counter.textContent = `Зберігаю товар ${i + 1} з ${dirtyForms.length}`;
      try {
        const result = await saveProductFormAjax(dirtyForms[i]);
        if (result.ok) saved += 1;
        else failed += 1;
      } catch (error) {
        failed += 1;
        const row = productFormRow(dirtyForms[i]);
        const state = row?.querySelector('[data-product-row-state]');
        if (state) {
          state.textContent = 'Помилка збереження';
          state.classList.remove('is-saving', 'is-saved');
          state.classList.add('is-error');
        }
      }
    }

    button.dataset.saving = '0';
    button.textContent = originalText;
    updateProductDirtyCounter();
    if (counter) {
      counter.textContent = failed > 0
        ? `Збережено: ${saved}, з помилкою: ${failed}`
        : `Усі зміни збережено: ${saved}`;
      setTimeout(updateProductDirtyCounter, 4500);
    }
  });

  updateProductDirtyCounter();

  document.addEventListener('submit', async (event) => {
    const form = event.target.closest(ajaxPostSelector);
    if (!form || form.dataset.noAjax === '1') return;
    const actionValue = form.querySelector('[name="action"]')?.value || (event.submitter?.name === 'action' ? event.submitter.value : '');


    if (form.closest('.admin-shell')) return;
    if (form.closest('.checkout-form') || form.closest('.auth-main-form')) return;
    if (/^(login|register|logout|auth_|order_create|staff_login)/.test(actionValue)) return;

    event.preventDefault();
    const submitter = event.submitter;
    const originalText = submitter ? submitter.textContent : '';
    if (submitter) {
      submitter.disabled = true;
      submitter.textContent = 'Зачекайте...';
    }

    try {
      const data = new FormData(form);
      if (submitter?.name) data.set(submitter.name, submitter.value || submitter.textContent || '');
      const response = await fetch(form.action || window.location.href, {
        method: form.method || 'POST',
        body: data,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const flash = doc.querySelector('.flash')?.textContent?.trim();
      const looksError = /не вдалося|не знайдено|недостатньо|помилка|спочатку|потрібно|некорект/i.test(flash || '');
      toast(flash || 'Дію виконано. Дані оновлено на сервері.');

      if (form.closest('.cart-layout')) {
        const nextCart = doc.querySelector('.cart-layout');
        const currentCart = document.querySelector('.cart-layout');
        if (nextCart && currentCart) {
          currentCart.replaceWith(nextCart);
          nextCart.querySelectorAll('.reveal').forEach((node) => node.classList.add('is-visible'));
        } else {
          window.location.href = '/cart';
          return;
        }
        const nextTotal = doc.querySelector('[data-cart-total]');
        const currentTotal = document.querySelector('[data-cart-total]');
        if (nextTotal && currentTotal) currentTotal.textContent = nextTotal.textContent;
      }

      if (form.closest('.admin-shell')) {
        const activeTab = document.querySelector('.admin-shell [data-tab].is-active')?.dataset.tab;
        const nextShell = doc.querySelector('.admin-shell');
        const currentShell = document.querySelector('.admin-shell');
        if (nextShell && currentShell) {
          currentShell.replaceWith(nextShell);
          activateAdminTab(activeTab);
        }
      }

      const selectedStatus = form.querySelector('select[name="status"]')?.value;
      const chip = form.closest('tr')?.querySelector('.status-chip');
      if (!looksError && selectedStatus && chip) chip.textContent = statusLabels[selectedStatus] || selectedStatus;
    } catch (error) {
      toast(error.message || 'Не вдалося виконати дію без оновлення сторінки.');
    } finally {
      if (submitter) {
        submitter.disabled = false;
        submitter.textContent = originalText;
      }
    }
  });

  document.addEventListener('input', (event) => {
    const input = event.target.closest('.cart-qty-auto-form input[name="quantity"]');
    if (!input) return;
    clearTimeout(input._cartTimer);
    input._cartTimer = setTimeout(() => {
      input.closest('form')?.requestSubmit();
    }, 650);
  });

  setTimeout(() => $$('.flash').forEach((node) => node.remove()), 7000);


  const initWarehouseProductSearch = () => {
    const input = document.querySelector('[data-select-filter="warehouse-product-select"]');
    const select = document.getElementById('warehouse-product-select');
    if (!input || !select || select.dataset.v65SearchReady === '1') return;
    select.dataset.v65SearchReady = '1';

    const originalOptions = Array.from(select.options).map((option, index) => ({
      value: option.value,
      text: option.textContent || option.innerText || '',
      disabled: option.disabled,
      isDefault: index === 0 || option.value === ''
    }));

    const normalize = (value) => String(value || '')
      .toLowerCase()
      .replace(/[’'`]/g, '')
      .replace(/ё/g, 'е')
      .replace(/і/g, 'и')
      .replace(/ї/g, 'и')
      .replace(/є/g, 'е')
      .replace(/ґ/g, 'г')
      .replace(/[^a-zа-я0-9]+/gi, ' ')
      .replace(/\s+/g, ' ')
      .trim();

    const rebuild = () => {
      const q = normalize(input.value);
      const previous = select.value;
      select.innerHTML = '';
      let first = '';
      let keepPrevious = false;

      originalOptions.forEach((item) => {
        const haystack = normalize(item.text + ' ' + item.value);
        if (!item.isDefault && q && !haystack.includes(q)) return;
        const option = document.createElement('option');
        option.value = item.value;
        option.textContent = item.text;
        option.disabled = item.disabled;
        select.appendChild(option);
        if (!item.isDefault && !first) first = item.value;
        if (item.value === previous) keepPrevious = true;
      });

      if (keepPrevious) select.value = previous;
      else if (q && first) select.value = first;
      else select.value = '';

      select.size = q ? Math.min(Math.max(select.options.length, 2), 8) : 1;
      select.dispatchEvent(new Event('change', { bubbles: true }));
    };

    input.addEventListener('input', rebuild);
    input.addEventListener('focus', rebuild);
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        if (select.options.length > 1 && select.selectedIndex <= 0) select.selectedIndex = 1;
        select.size = 1;
        select.focus();
      }
      if (event.key === 'Escape') {
        input.value = '';
        rebuild();
        select.size = 1;
      }
    });
    select.addEventListener('blur', () => { select.size = 1; });
    select.addEventListener('change', () => { select.size = 1; });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initWarehouseProductSearch);
  } else {
    initWarehouseProductSearch();
  }

})();
