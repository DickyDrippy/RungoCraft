(function () {
    'use strict';

    const root = document.querySelector('[data-delivery-directory]');
    if (!root) return;

    const carrierInput = root.querySelector('[data-delivery-carrier]');
    const cityInput = root.querySelector('[data-delivery-city-name]');
    const cityHidden = root.querySelector('[data-delivery-manual-city]');
    const cityRefHidden = root.querySelector('[data-delivery-city-ref]');
    const cityResults = root.querySelector('[data-delivery-city-results]');

    const warehouseInput = root.querySelector('[data-delivery-warehouse-name-input]');
    const warehouseHidden = root.querySelector('[data-delivery-manual-warehouse]');
    const warehouseRefHidden = root.querySelector('[data-delivery-warehouse-ref]');
    const warehouseNameHidden = root.querySelector('[data-delivery-warehouse-name]');
    const warehouseResults = root.querySelector('[data-delivery-warehouse-results]');
    const warehouseField = root.querySelector('[data-delivery-warehouse-field]');
    const directorySection = root.querySelector('[data-delivery-directory-section]');

    const statusNode = root.querySelector('[data-delivery-directory-status]');

    const choiceToCarrier = {
        nova_poshta_branch: 'nova_poshta',
        nova_poshta_courier: 'nova_poshta',
        delivery_auto_branch: 'delivery_auto',
        delivery_auto_courier: 'delivery_auto',
        pickup: 'pickup',
        kyiv_courier: 'own',
        construction_site: 'own'
    };

    if (!cityInput || !cityResults || !warehouseInput || !warehouseResults) return;

    let cityTimer = null;
    let warehouseTimer = null;
    let activeCityRequest = 0;
    let activeWarehouseRequest = 0;
    let lastCityItems = [];
    let lastSelectedCityName = '';
    let warehouseItems = [];

    function normalize(value) {
        return String(value || '')
            .trim()
            .toLowerCase()
            .replace(/[’'`]/g, '')
            .replace(/ё/g, 'е')
            .replace(/і/g, 'и')
            .replace(/ї/g, 'и')
            .replace(/є/g, 'е')
            .replace(/\s+/g, ' ');
    }

    function currentDeliveryValue() {
        const checked = document.querySelector('[data-delivery-choice]:checked') || document.querySelector('[name="delivery_type"]:checked');
        return checked ? String(checked.value || '') : '';
    }

    function currentCarrier() {
        const value = currentDeliveryValue();
        const carrier = choiceToCarrier[value] || (value.includes('nova_poshta') ? 'nova_poshta' : (value.includes('delivery_auto') ? 'delivery_auto' : 'own'));
        if (carrierInput) carrierInput.value = carrier;
        return carrier;
    }

    function deliveryNeedsDirectory() {
        const value = currentDeliveryValue();
        return ['nova_poshta_branch', 'delivery_auto_branch', 'nova_poshta_courier', 'delivery_auto_courier'].includes(value);
    }

    function deliveryNeedsWarehouse() {
        const value = currentDeliveryValue();
        return value === 'nova_poshta_branch' || value === 'delivery_auto_branch';
    }

    function refreshWarehouseVisibility() {
        const value = currentDeliveryValue();
        const needsWarehouse = deliveryNeedsWarehouse();
        const needsDirectory = deliveryNeedsDirectory();
        if (directorySection) {
            directorySection.dataset.deliveryMode = needsWarehouse ? 'warehouse' : (needsDirectory ? 'courier' : 'none');
        }
        if (warehouseField) {
            warehouseField.hidden = !needsWarehouse;
            warehouseField.style.display = needsWarehouse ? '' : 'none';
        }
        if (warehouseInput) {
            warehouseInput.required = needsWarehouse;
            warehouseInput.disabled = !needsWarehouse;
        }
        if (cityInput) {
            cityInput.placeholder = needsDirectory && !needsWarehouse ? 'Почніть вводити місто доставки' : 'Почніть вводити місто';
        }
        if (!needsWarehouse) {
            resetWarehouse('Для курʼєрської доставки відділення не потрібне');
        }
    }

    function setStatus(text, isError) {
        if (!statusNode) return;
        statusNode.textContent = text || '';
        statusNode.classList.toggle('is-error', Boolean(isError));
    }

    function clearCityResults() {
        cityResults.innerHTML = '';
        cityResults.hidden = true;
    }

    function clearWarehouseResults() {
        warehouseResults.innerHTML = '';
        warehouseResults.hidden = true;
    }

    function resetWarehouse(message = 'Спочатку оберіть місто') {
        warehouseItems = [];
        warehouseInput.value = '';
        warehouseInput.placeholder = message;
        warehouseInput.disabled = true;
        if (warehouseHidden) warehouseHidden.value = '';
        if (warehouseRefHidden) warehouseRefHidden.value = '';
        if (warehouseNameHidden) warehouseNameHidden.value = '';
        clearWarehouseResults();
    }

    function resetCity(clearText) {
        if (cityRefHidden) cityRefHidden.value = '';
        if (cityHidden) cityHidden.value = '';
        lastCityItems = [];
        lastSelectedCityName = '';
        if (clearText) cityInput.value = '';
        clearCityResults();
        resetWarehouse();
        setStatus('', false);
    }

    async function fetchDirectory(params) {
        params.type = params.type || params.action;
        params.action = params.action || params.type;
        params._ = String(Date.now());

        const url = '/api/delivery-directory.php?' + new URLSearchParams(params).toString();
        const response = await fetch(url, {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
        });

        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (error) {
            throw new Error('API повернув не JSON: ' + text.slice(0, 160));
        }

        data.ok = Boolean(data.ok || data.success);
        data.items = Array.isArray(data.items) ? data.items : [];
        data.message = data.message || (data.ok ? 'OK' : 'Помилка');
        return data;
    }

    function renderCityResults(items) {
        clearCityResults();

        if (!items.length) {
            cityResults.innerHTML = '<div class="delivery-directory-empty">Місто не знайдено</div>';
            cityResults.hidden = false;
            return;
        }

        items.slice(0, 25).forEach(function (item) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'delivery-directory-option';
            button.textContent = item.label || item.name || 'Місто';
            button.addEventListener('mousedown', function (event) {
                event.preventDefault();
                selectCity(item);
            });
            cityResults.appendChild(button);
        });

        cityResults.hidden = false;
    }

    function exactCity(items, query) {
        const q = normalize(query);
        if (!q) return null;

        const kyiv = items.find(function (item) {
            const name = normalize(item.name);
            const area = normalize(item.area);
            return ['киив', 'киев', 'kyiv', 'kiev'].includes(q) && ['киив', 'киев', 'kyiv', 'kiev'].includes(name) && (area.includes('киив') || area.includes('киев') || area === '');
        });
        if (kyiv) return kyiv;

        const exactName = items.find(function (item) {
            return normalize(item.name) === q;
        });
        if (exactName) return exactName;

        return items.find(function (item) {
            return normalize(item.label).startsWith(q + ',');
        }) || null;
    }

    async function selectCity(item) {
        const name = item.name || item.label || '';
        const ref = item.ref || '';

        if (!ref) {
            setStatus('Сервіс доставки не повернув код міста.', true);
            return;
        }

        cityInput.value = name;
        if (cityHidden) cityHidden.value = name;
        if (cityRefHidden) cityRefHidden.value = ref;
        lastSelectedCityName = name;
        clearCityResults();
        if (!deliveryNeedsWarehouse()) {
            resetWarehouse('Для курʼєрської доставки відділення не потрібне');
            setStatus('Місто обрано. Для курʼєрської доставки вкажіть адресу.', false);
            return;
        }
        setStatus('Завантажуємо відділення...', false);
        await loadWarehouses(ref);
    }

    async function searchCities(query, autoSelectExact) {
        if (!deliveryNeedsDirectory()) return;

        if (query.length < 2) {
            clearCityResults();
            setStatus('', false);
            return;
        }

        const carrier = currentCarrier();
        const requestId = ++activeCityRequest;
        setStatus('Шукаємо місто...', false);

        try {
            const data = await fetchDirectory({ carrier: carrier, type: 'cities', q: query, limit: 30 });
            if (requestId !== activeCityRequest) return;

            lastCityItems = data.items || [];

            if (!data.ok && carrier === 'delivery_auto' && query.length >= 2) {
                lastCityItems = [{ kind: 'city', ref: 'manual:' + query, name: query, label: query + ' — ввести вручну', manual: true }];
            }

            const exact = autoSelectExact ? exactCity(lastCityItems, query) : null;

            if (exact && query.length >= 3) {
                await selectCity(exact);
                return;
            }

            renderCityResults(lastCityItems);
            setStatus(lastCityItems.length ? 'Оберіть місто зі списку.' : (data.message || 'Місто не знайдено.'), lastCityItems.length === 0 && carrier !== 'delivery_auto');
        } catch (error) {
            if (requestId !== activeCityRequest) return;
            clearCityResults();
            if (carrier === 'delivery_auto' && query.length >= 2) {
                lastCityItems = [{ kind: 'city', ref: 'manual:' + query, name: query, label: query + ' — ввести вручну', manual: true }];
                renderCityResults(lastCityItems);
                setStatus('Довідник Delivery Auto не відповів. Можна ввести місто та відділення вручну.', false);
                return;
            }
            setStatus('Не вдалося завантажити міста: ' + error.message, true);
        }
    }

    function looksLikeWarehouse(item) {
        const text = normalize((item.label || '') + ' ' + (item.name || '') + ' ' + (item.address || ''));
        if (item.kind === 'warehouse') return true;
        if (item.address) return true;
        return text.includes('виддилення') || text.includes('відділення') || text.includes('поштомат') || text.includes('склад') || text.includes('warehouse') || text.includes('№');
    }

    async function loadWarehouses(cityRef) {
        if (!deliveryNeedsDirectory() || !deliveryNeedsWarehouse()) return;

        if (!cityRef) {
            resetWarehouse();
            setStatus('Спочатку оберіть місто зі списку.', true);
            return;
        }

        const carrier = currentCarrier();
        const requestId = ++activeWarehouseRequest;
        resetWarehouse('Завантаження відділень...');

        try {
            const data = await fetchDirectory({ carrier: carrier, type: 'warehouses', city_ref: cityRef, limit: carrier === 'nova_poshta' ? 2000 : 500 });
            if (requestId !== activeWarehouseRequest) return;

            warehouseItems = (data.items || []).filter(looksLikeWarehouse);

            if (warehouseItems.length) {
                warehouseInput.disabled = false;
                warehouseInput.placeholder = 'Введіть номер, адресу або назву відділення';
                setStatus('', false);
                renderWarehouseResults(warehouseItems.slice(0, 20));
                return;
            }

            if (carrier === 'delivery_auto') {
                warehouseInput.disabled = false;
                warehouseInput.placeholder = 'Введіть відділення або адресу Delivery вручну';
                clearWarehouseResults();
                setStatus(data.message || 'Довідник Delivery Auto недоступний. Введіть відділення вручну.', false);
                return;
            }

            warehouseInput.disabled = true;
            warehouseInput.placeholder = 'Відділення не знайдено';
            setStatus(data.message || 'Відділення не знайдено.', true);
        } catch (error) {
            if (currentCarrier() === 'delivery_auto') {
                warehouseItems = [];
                warehouseInput.disabled = false;
                warehouseInput.value = '';
                warehouseInput.placeholder = 'Введіть відділення або адресу Delivery вручну';
                clearWarehouseResults();
                setStatus('Довідник Delivery Auto не відповів. Введіть відділення вручну.', false);
                return;
            }
            resetWarehouse('Помилка завантаження');
            setStatus('Не вдалося завантажити відділення: ' + error.message, true);
        }
    }

    function renderWarehouseResults(items) {
        clearWarehouseResults();

        if (!items.length) {
            warehouseResults.innerHTML = '<div class="delivery-directory-empty">Відділення не знайдено</div>';
            warehouseResults.hidden = false;
            return;
        }

        items.slice(0, 30).forEach(function (item) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'delivery-directory-option';
            button.textContent = item.label || item.name || item.address || 'Відділення';
            button.addEventListener('mousedown', function (event) {
                event.preventDefault();
                selectWarehouse(item);
            });
            warehouseResults.appendChild(button);
        });

        warehouseResults.hidden = false;
    }

    async function searchWarehouse(query) {
        const q = normalize(query);
        const cityRef = cityRefHidden ? cityRefHidden.value : '';
        const carrier = currentCarrier();

        if (!cityRef) {
            renderWarehouseResults([]);
            setStatus('Спочатку оберіть місто.', true);
            return;
        }

        if (!q) {
            renderWarehouseResults(warehouseItems.slice(0, 30));
            return;
        }

        const localFiltered = warehouseItems.filter(function (item) {
            const text = normalize((item.label || '') + ' ' + (item.name || '') + ' ' + (item.address || ''));
            return text.includes(q);
        });

        if (localFiltered.length >= 10 || carrier === 'delivery_auto') {
            renderWarehouseResults(localFiltered);
            return;
        }

        const requestId = ++activeWarehouseRequest;
        setStatus('Шукаємо відділення...', false);

        try {
            const data = await fetchDirectory({
                carrier: carrier,
                type: 'warehouses',
                city_ref: cityRef,
                q: query,
                limit: carrier === 'nova_poshta' ? 500 : 100
            });

            if (requestId !== activeWarehouseRequest) return;

            const remoteItems = (data.items || []).filter(looksLikeWarehouse);

            const byRef = new Map();
            warehouseItems.concat(remoteItems).forEach(function (item) {
                const key = item.ref || item.label || item.name;
                if (key) byRef.set(key, item);
            });
            warehouseItems = Array.from(byRef.values());

            const allFiltered = warehouseItems.filter(function (item) {
                const text = normalize((item.label || '') + ' ' + (item.name || '') + ' ' + (item.address || ''));
                return text.includes(q);
            });

            renderWarehouseResults(allFiltered);
            setStatus(allFiltered.length ? '' : (data.message || 'Відділення не знайдено.'), allFiltered.length === 0 && carrier !== 'delivery_auto');
        } catch (error) {
            renderWarehouseResults(localFiltered);
            setStatus(localFiltered.length ? '' : 'Не вдалося знайти відділення: ' + error.message, !localFiltered.length && carrier !== 'delivery_auto');
        }
    }

    function selectWarehouse(item) {
        const ref = item.ref || '';
        const label = item.label || item.name || item.address || '';

        warehouseInput.value = label;
        if (warehouseHidden) warehouseHidden.value = label;
        if (warehouseRefHidden) warehouseRefHidden.value = ref;
        if (warehouseNameHidden) warehouseNameHidden.value = label;
        clearWarehouseResults();
        setStatus('', false);
    }

    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-delivery-choice]') || event.target.matches('[name="delivery_type"]')) {
            currentCarrier();
            resetCity(true);
            refreshWarehouseVisibility();
        }
    });

    cityInput.addEventListener('input', function () {
        const value = cityInput.value.trim();
        if (cityHidden) cityHidden.value = value;

        if (normalize(value) !== normalize(lastSelectedCityName)) {
            if (cityRefHidden) cityRefHidden.value = '';
            resetWarehouse();
        }

        clearTimeout(cityTimer);
        cityTimer = setTimeout(function () {
            searchCities(value, value.length >= 3);
        }, 250);
    });

    cityInput.addEventListener('blur', function () {
        setTimeout(function () {
            const value = cityInput.value.trim();
            if (value && !(cityRefHidden && cityRefHidden.value)) {
                const exact = exactCity(lastCityItems, value);
                if (exact) selectCity(exact);
            }
        }, 180);
    });

    warehouseInput.addEventListener('input', function () {
        const value = warehouseInput.value.trim();
        if (warehouseHidden) warehouseHidden.value = value;
        if (warehouseRefHidden) warehouseRefHidden.value = '';
        if (warehouseNameHidden) warehouseNameHidden.value = value;

        clearTimeout(warehouseTimer);
        warehouseTimer = setTimeout(function () {
            searchWarehouse(value);
        }, 100);
    });

    warehouseInput.addEventListener('focus', function () {
        if (warehouseItems.length) {
            searchWarehouse(warehouseInput.value.trim());
        }
    });

    document.addEventListener('click', function (event) {
        if (!cityResults.contains(event.target) && event.target !== cityInput) {
            clearCityResults();
        }
        if (!warehouseResults.contains(event.target) && event.target !== warehouseInput) {
            clearWarehouseResults();
        }
    });

    currentCarrier();
    refreshWarehouseVisibility();
})();
