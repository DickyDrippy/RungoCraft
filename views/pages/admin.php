<link rel="stylesheet" href="/assets/css/admin.css?v=48">
<link rel="stylesheet" href="/assets/css/integrations.css">

<?php if (!has_role(['admin', 'manager', 'warehouse'])): ?>
<section class="page-hero">
    <div class="container">
        <p class="breadcrumbs"><a href="/">Головна</a> / Панель працівника</p>
        <h1>Панель працівника</h1>
        <p>Для доступу до менеджерської, складської або адміністративної частини потрібно увійти під відповідною роллю.</p>
        <a class="btn btn-primary" href="/account">Увійти в кабінет</a>
    </div>
</section>
<?php return; endif; ?>

<?php
$adminService = new AdminService();
$adminData = $adminService->dashboardData();
$adminStats = $adminData['stats'];
$adminCategories = $adminData['categories'];
$adminProducts = $adminData['products'];
$adminOrders = $adminData['orders'];
$adminSettings = $adminData['settings'];
$adminLogs = $adminData['logs'];
$role = user_role();
$roleTabs = [
    'admin' => ['orders','requests','delivery','payments','business','products','categories','warehouse','analytics','accounting','staff_keys','notifications','integrations','settings'],
    'manager' => ['orders','requests','delivery','payments','business','analytics','notifications'],
    'warehouse' => ['warehouse','delivery'],
];
$allowedAdminTabs = $roleTabs[$role] ?? ['orders'];
$defaultAdminTab = in_array('orders', $allowedAdminTabs, true) ? 'orders' : (string)($allowedAdminTabs[0] ?? 'orders');
$adminTabVisible = static fn(string $tab): bool => in_array($tab, $allowedAdminTabs, true);
?>

<section class="page-hero admin-hero">
    <div class="container">
        <p class="breadcrumbs"><a href="/">Головна</a> / Панель працівника</p>
        <h1>Панель керування RungoCraft</h1>
        <p>Робоча зона для адміністратора, менеджера та працівника складу.</p>
    </div>
</section>

<section class="section admin-dashboard-section">
    <div class="container admin-stats-grid">
        <div class="admin-stat"><span>Товари</span><b><?= e($adminStats['products']) ?></b></div>
        <div class="admin-stat"><span>Категорії</span><b><?= e($adminStats['categories']) ?></b></div>
        <div class="admin-stat"><span>Замовлення</span><b><?= e($adminStats['orders']) ?></b></div>
        <div class="admin-stat"><span>Користувачі</span><b><?= e($adminStats['users']) ?></b></div>
    </div>

    <div class="container admin-shell admin-shell-real reveal">
        <aside class="admin-menu" data-tabs>
            <?php if ($adminTabVisible('orders')): ?><button class="<?= $defaultAdminTab === 'orders' ? 'is-active' : '' ?>" data-tab="orders">Замовлення</button><?php endif; ?>
            <?php if ($adminTabVisible('requests')): ?><button class="<?= $defaultAdminTab === 'requests' ? 'is-active' : '' ?>" data-tab="requests">Заявки</button><?php endif; ?>
            <?php if ($adminTabVisible('delivery')): ?><button class="<?= $defaultAdminTab === 'delivery' ? 'is-active' : '' ?>" data-tab="delivery">Доставка</button><?php endif; ?>
            <?php if ($adminTabVisible('payments')): ?><button class="<?= $defaultAdminTab === 'payments' ? 'is-active' : '' ?>" data-tab="payments">Оплати</button><?php endif; ?>
            <?php if ($adminTabVisible('business')): ?><button class="<?= $defaultAdminTab === 'business' ? 'is-active' : '' ?>" data-tab="business">Опт</button><?php endif; ?>
            <?php if ($adminTabVisible('products')): ?><button class="<?= $defaultAdminTab === 'products' ? 'is-active' : '' ?>" data-tab="products">Товари</button><?php endif; ?>
            <?php if ($adminTabVisible('categories')): ?><button class="<?= $defaultAdminTab === 'categories' ? 'is-active' : '' ?>" data-tab="categories">Категорії</button><?php endif; ?>
            <?php if ($adminTabVisible('warehouse')): ?><button class="<?= $defaultAdminTab === 'warehouse' ? 'is-active' : '' ?>" data-tab="warehouse">Склад</button><?php endif; ?>
            <?php if ($adminTabVisible('analytics')): ?><button class="<?= $defaultAdminTab === 'analytics' ? 'is-active' : '' ?>" data-tab="analytics">Аналітика</button><?php endif; ?>
            <?php if ($adminTabVisible('accounting')): ?><button class="<?= $defaultAdminTab === 'accounting' ? 'is-active' : '' ?>" data-tab="accounting">Бухгалтерія</button><?php endif; ?>
            <?php if ($adminTabVisible('staff_keys')): ?><button class="<?= $defaultAdminTab === 'staff_keys' ? 'is-active' : '' ?>" data-tab="staff_keys">Ключі входу</button><?php endif; ?>
            <?php if ($adminTabVisible('notifications')): ?><button class="<?= $defaultAdminTab === 'notifications' ? 'is-active' : '' ?>" data-tab="notifications">Сповіщення</button><?php endif; ?>
            <?php if ($adminTabVisible('integrations')): ?><button class="<?= $defaultAdminTab === 'integrations' ? 'is-active' : '' ?>" data-tab="integrations">Інтеграції</button><?php endif; ?>
            <?php if ($adminTabVisible('settings')): ?><button class="<?= $defaultAdminTab === 'settings' ? 'is-active' : '' ?>" data-tab="settings">Налаштування</button><?php endif; ?>
        </aside>

        <div class="admin-content">
            <div class="tab-panel <?= $defaultAdminTab === 'orders' ? 'is-active' : '' ?>" data-tab-panel="orders">
                <div class="admin-panel-head">
                    <div>
                        <span class="eyebrow">Менеджер</span>
                        <h2>Замовлення</h2>
                    </div>
                    <?php if ($role === 'admin'): ?><a class="btn btn-light" href="/checkout">Перевірити checkout</a><?php endif; ?>
                </div>

                <?php if ($adminOrders): ?>
                    <div class="admin-table-tools"><input type="search" class="admin-table-search" data-admin-table-filter="orders-table" placeholder="Пошук за ID, клієнтом, телефоном, email..."></div>
                    <div class="admin-table-wrap admin-table-scroll">
                        <table class="admin-table" data-admin-table="orders-table">
                            <thead><tr><th>ID</th><th>Клієнт</th><th>Контакти</th><th>Статус</th><th>Оплата/доставка</th><th>Сума</th><th>Дата</th><th>Дія</th></tr></thead>
                            <tbody>
                            <?php foreach ($adminOrders as $order): ?>
                                <tr id="order-<?= e($order['ID']) ?>" data-admin-row="order-<?= e($order['ID']) ?>">
                                    <td>#<?= e($order['ID']) ?></td>
                                    <td><?= e($order['CUSTOMER_NAME']) ?></td>
                                    <td><?= e($order['CUSTOMER_PHONE']) ?><br><small><?= e($order['CUSTOMER_EMAIL'] ?? '') ?></small></td>
                                    <td><span class="status-chip"><?= e(order_status_label($order['STATUS'] ?? '')) ?></span><br><small>доставка: <?= e(delivery_status_label($order['DELIVERY_STATUS'] ?? 'pending')) ?></small></td>
                                    <td><?= e(payment_type_label($order['PAYMENT_TYPE'] ?? '')) ?><br><small><?= e(delivery_type_label($order['DELIVERY_TYPE'] ?? '')) ?> <?= !empty($order['DELIVERY_TTN']) ? '· ТТН ' . e($order['DELIVERY_TTN']) : '' ?></small></td>
                                    <td><?= money($order['TOTAL_AMOUNT']) ?></td>
                                    <td><?= e(format_db_datetime($order['CREATED_AT'])) ?></td>
                                    <td>
                                        <form method="post" class="admin-order-status-form">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="admin_update_order_status">
                                            <input type="hidden" name="order_id" value="<?= e($order['ID']) ?>">
                                            <input type="hidden" name="return_to" value="/admin#orders">
                                            <select name="status">
                                                <?php foreach (['created','waiting_confirmation','confirmed','waiting_payment','paid','packing','packed','sent','delivering','completed','cancelled','returned'] as $status): ?>
                                                    <option value="<?= e($status) ?>" <?= (($order['STATUS'] ?? '') === $status) ? 'selected' : '' ?>><?= e(order_status_label($status)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn btn-light" type="submit">OK</button>
                                            <input type="text" name="comment_text" placeholder="Коментар до статусу">
                                        </form>
                                        <div class="order-doc-actions">
                                            <a class="btn btn-light" target="_blank" href="/admin-order-document?type=receipt&order_id=<?= e($order['ID']) ?>">Чек/PDF</a>
                                            <a class="btn btn-light" target="_blank" href="/admin-order-document?type=invoice&order_id=<?= e($order['ID']) ?>">Накладна/PDF</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-card">Замовлень поки немає. Після підключення checkout вони зʼявляться тут.</div>
                <?php endif; ?>
            </div>


            <div class="tab-panel <?= $defaultAdminTab === 'requests' ? 'is-active' : '' ?>" data-tab-panel="requests">
                <link rel="stylesheet" href="/assets/css/support.css">
                <div class="admin-panel-head">
                    <div>
                        <span class="eyebrow">Менеджер</span>
                        <h2>Заявки та підтримка</h2>
                    </div>
                    <a class="btn btn-light" href="/support">Сторінка підтримки</a>
                </div>

                <?php $supportAdminData = $supportAdminData ?? (new SupportService())->adminData(current_user()); ?>
                <div class="support-admin-stats">
                    <div><span>Дзвінки</span><b><?= e($supportAdminData['stats']['callbacks'] ?? 0) ?></b></div>
                    <div><span>Розрахунки</span><b><?= e($supportAdminData['stats']['calculations'] ?? 0) ?></b></div>
                    <div><span>Звернення</span><b><?= e($supportAdminData['stats']['tickets'] ?? 0) ?></b></div>
                    <div><span>Питання товарів</span><b><?= e($supportAdminData['stats']['product_questions'] ?? 0) ?></b></div>
                    <div><span>Повідомити про появу</span><b><?= e($supportAdminData['stats']['availability_requests'] ?? 0) ?></b></div>
                </div>

                <?php
                    $requestBlocks = [
                        'callback' => ['title' => 'Замовлення дзвінка', 'rows' => $supportAdminData['callbacks'] ?? []],
                        'calculation' => ['title' => 'Заявки на розрахунок', 'rows' => $supportAdminData['calculations'] ?? []],
                        'ticket' => ['title' => 'Звернення підтримки', 'rows' => $supportAdminData['tickets'] ?? []],
                        'product_question' => ['title' => 'Питання по товарах', 'rows' => $supportAdminData['product_questions'] ?? []],
                        'availability' => ['title' => 'Повідомити про появу', 'rows' => $supportAdminData['availability_requests'] ?? []],
                    ];
                ?>

                <form class="admin-form request-cleanup-form" method="post" onsubmit="return confirm('Видалити записи з обраної таблиці заявок?');">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="support_admin_bulk_delete">
                    <input type="hidden" name="return_to" value="/admin#requests">
                    <div class="form-grid four">
                        <label>Таблиця
                            <select name="request_type" required>
                                <?php foreach ($requestBlocks as $requestType => $block): ?>
                                    <option value="<?= e($requestType) ?>"><?= e($block['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Дата з
                            <input type="date" name="date_from">
                        </label>
                        <label>Дата по
                            <input type="date" name="date_to">
                        </label>
                        <label class="request-cleanup-check">
                            <span>Підтвердження</span>
                            <span><input type="checkbox" name="confirm_bulk_delete" value="yes" required> видалити</span>
                        </label>
                    </div>
                    <label class="request-cleanup-all"><input type="checkbox" name="delete_all" value="1"> Видалити всі записи обраної таблиці без фільтра за датою</label>
                    <div class="form-actions"><button class="btn btn-danger" type="submit">Очистити заявки</button></div>
                </form>

                <?php foreach ($requestBlocks as $requestType => $block): ?>
                    <?php $requestTableId = 'requests-' . $requestType . '-table'; ?>
                    <h3 class="admin-subtitle"><?= e($block['title']) ?></h3>
                    <?php if (empty($block['rows'])): ?>
                        <div class="empty-card">Нових записів немає.</div>
                    <?php else: ?>
                        <div class="admin-table-tools admin-table-tools--compact">
                            <input type="search" class="admin-table-search" data-admin-table-filter="<?= e($requestTableId) ?>" placeholder="Пошук у таблиці: клієнт, контакт, текст, статус...">
                            <button class="btn btn-light" type="button" data-table-toggle="<?= e($requestTableId) ?>" data-collapsed-label="Показати всі" data-expanded-label="Згорнути">Показати всі</button>
                        </div>
                        <div class="admin-table-wrap admin-table-scroll-small">
                            <table class="admin-table compact-table requests-admin-table" data-admin-table="<?= e($requestTableId) ?>" data-row-limit="5">
                                <thead><tr><th>ID</th><th>Клієнт</th><th>Контакт</th><th>Текст</th><th>Статус</th><th>Дата</th><th>Дія</th></tr></thead>
                                <tbody>
                                <?php foreach ($block['rows'] as $request): ?>
                                    <?php
                                        $text = $request['MESSAGE'] ?? $request['COMMENT_TEXT'] ?? $request['MATERIALS_LIST'] ?? $request['SUBJECT'] ?? ('Товар ID: ' . ($request['PRODUCT_ID'] ?? ''));
                                        if (is_object($text) && method_exists($text, 'load')) { $text = $text->load(); }
                                        $contact = trim((string)($request['PHONE'] ?? '') . ' ' . (string)($request['EMAIL'] ?? '') . ' ' . (string)($request['CONTACT'] ?? ''));
                                    ?>
                                    <tr>
                                        <td>#<?= e($request['ID'] ?? '') ?></td>
                                        <td><?= e($request['NAME'] ?? $request['CUSTOMER_NAME'] ?? 'Клієнт') ?></td>
                                        <td><?= e($contact !== '' ? $contact : '—') ?></td>
                                        <td>
                                            <?= e(mb_strimwidth((string)$text, 0, 160, '...')) ?>
                                            <?php if ($requestType === 'calculation'):
                                                preg_match_all("#/uploads/calculations/[0-9]{4}/[0-9]{2}/[^\\s<>\"']+#u", (string)$text, $fileMatches);
                                                $downloadFiles = array_values(array_unique($fileMatches[0] ?? []));
                                            ?>
                                                <?php foreach ($downloadFiles as $downloadFile): ?>
                                                    <br><a class="request-file-link" href="/admin-calculation-file?request_id=<?= e($request['ID'] ?? 0) ?>&file=<?= e(rawurlencode($downloadFile)) ?>">Скачати файл</a>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="status-chip"><?= e($request['STATUS'] ?? 'new') ?></span></td>
                                        <td><?= e(format_db_datetime($request['CREATED_AT'] ?? '')) ?></td>
                                        <td>
                                            <form class="status-form" method="post">
                                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="support_admin_update">
                                                <input type="hidden" name="request_type" value="<?= e($requestType) ?>">
                                                <input type="hidden" name="request_id" value="<?= e($request['ID'] ?? 0) ?>">
                                                <input type="hidden" name="return_to" value="/admin#requests">
                                                <select name="status">
                                                    <?php foreach (['new','processing','done','cancelled'] as $requestStatus): ?>
                                                        <option value="<?= e($requestStatus) ?>" <?= (($request['STATUS'] ?? 'new') === $requestStatus) ? 'selected' : '' ?>><?= e($requestStatus) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button class="btn btn-light" type="submit">OK</button>
                                            </form>
                                            <form class="status-form request-delete-form" method="post" onsubmit="return confirm('Видалити цей запис із заявок?');">
                                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="support_admin_delete">
                                                <input type="hidden" name="request_type" value="<?= e($requestType) ?>">
                                                <input type="hidden" name="request_id" value="<?= e($request['ID'] ?? 0) ?>">
                                                <input type="hidden" name="return_to" value="/admin#requests">
                                                <button class="btn btn-danger" type="submit">Видалити</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>




            <div class="tab-panel <?= $defaultAdminTab === 'delivery' ? 'is-active' : '' ?>" data-tab-panel="delivery">
                <link rel="stylesheet" href="/assets/css/delivery.css">
                <div class="admin-panel-head">
                    <div>
                        <span class="eyebrow">Менеджер / склад</span>
                        <h2>Доставка і ТТН</h2>
                    </div>
                    <a class="btn btn-light" href="/delivery">Сторінка доставки</a>
                </div>

                <?php $deliveryAdminData = $deliveryAdminData ?? (new DeliveryService())->adminData(current_user()); ?>
                <div class="delivery-admin-stats">
                    <div><span>Очікує</span><b><?= e($deliveryAdminData['stats']['pending'] ?? 0) ?></b></div>
                    <div><span>ТТН створено</span><b><?= e($deliveryAdminData['stats']['ttn_created'] ?? 0) ?></b></div>
                    <div><span>У дорозі</span><b><?= e($deliveryAdminData['stats']['in_transit'] ?? 0) ?></b></div>
                    <div><span>Доставлено</span><b><?= e($deliveryAdminData['stats']['delivered'] ?? 0) ?></b></div>
                </div>

                <h3 class="admin-subtitle">Відправлення</h3>
                <?php if (empty($deliveryAdminData['shipments'])): ?>
                    <div class="empty-card">Доставок поки немає. Вони зʼявляться після оформлення замовлення.</div>
                <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>ID</th><th>Замовлення</th><th>Клієнт</th><th>Тип</th><th>Місто / адреса</th><th>Ціна доставки</th><th>ТТН</th><th>Статус</th><th>Дія</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($deliveryAdminData['shipments'] as $shipment): ?>
                                <tr>
                                    <td>#<?= e($shipment['ID']) ?></td>
                                    <td>#<?= e($shipment['ORDER_ID']) ?><br><small><?= money($shipment['TOTAL_AMOUNT'] ?? 0) ?></small></td>
                                    <td><?= e($shipment['CUSTOMER_NAME'] ?? $shipment['RECIPIENT_NAME'] ?? 'Клієнт') ?><br><small><?= e($shipment['CUSTOMER_PHONE'] ?? $shipment['RECIPIENT_PHONE'] ?? '') ?></small></td>
                                    <td><?= e(delivery_type_label($shipment['DELIVERY_TYPE'] ?? '')) ?></td>
                                    <td>
                                        <?= e($shipment['CITY'] ?? '') ?>
                                        <?php if (!empty($shipment['WAREHOUSE'])): ?><br><small><?= e($shipment['WAREHOUSE']) ?></small><?php endif; ?>
                                        <?php if (!empty($shipment['ADDRESS_TEXT'])): ?><br><small><?= e($shipment['ADDRESS_TEXT']) ?></small><?php endif; ?>
                                    </td>
                                    <td><?= ($shipment['ESTIMATED_PRICE'] ?? null) !== null && $shipment['ESTIMATED_PRICE'] !== '' ? money($shipment['ESTIMATED_PRICE']) : '—' ?></td>
                                    <td><b><?= e($shipment['TTN'] ?? '—') ?></b><?php if (!empty($shipment['TTN'])): ?><br><a class="mini-link" target="_blank" href="/admin-ttn-document?shipment_id=<?= e($shipment['ID']) ?>">Скачати/друк ТТН</a><?php endif; ?></td>
                                    <td><span class="status-chip"><?= e(delivery_status_label($shipment['DELIVERY_STATUS'] ?? 'pending')) ?></span></td>
                                    <td>
                                        <form class="delivery-admin-form" method="post">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delivery_admin_update">
                                            <input type="hidden" name="shipment_id" value="<?= e($shipment['ID']) ?>">
                                            <input type="hidden" name="return_to" value="/admin#delivery">
                                            <input type="text" name="ttn" value="<?= e($shipment['TTN'] ?? '') ?>" placeholder="ТТН">
                                            <select name="status">
                                                <?php foreach (['pending','manager_confirm','ttn_created','in_transit','delivered','completed','cancelled','returned'] as $st): ?>
                                                    <option value="<?= e($st) ?>" <?= (($shipment['DELIVERY_STATUS'] ?? '') === $st) ? 'selected' : '' ?>><?= e(delivery_status_label($st)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="text" name="manager_comment" value="<?= e($shipment['MANAGER_COMMENT'] ?? '') ?>" placeholder="Коментар">
                                            <button class="btn btn-light" type="submit">Оновити</button>
                                        </form>
                                        <form method="post" style="margin-top:8px">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delivery_create_ttn">
                                            <input type="hidden" name="shipment_id" value="<?= e($shipment['ID']) ?>">
                                            <input type="hidden" name="return_to" value="/admin#delivery">
                                            <button class="btn btn-primary" type="submit">Створити ТТН</button>
                                        </form>
                                        <form method="post" style="margin-top:8px">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="shipment_id" value="<?= e($shipment['ID']) ?>">
                                            <input type="hidden" name="return_to" value="/admin#delivery">
                                            <button class="btn btn-light" name="action" value="integration_np_create_ttn" type="submit">Реальна ТТН НП</button>
                                            <button class="btn btn-light" name="action" value="integration_da_calculate" type="submit">Ціна Delivery</button>
                                            <button class="btn btn-light" name="action" value="integration_da_create_ttn" type="submit">ТТН Delivery</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>



            <div class="tab-panel <?= $defaultAdminTab === 'payments' ? 'is-active' : '' ?>" data-tab-panel="payments">
                <link rel="stylesheet" href="/assets/css/payment.css">
                <div class="admin-panel-head">
                    <div>
                        <span class="eyebrow">Менеджер / адмін</span>
                        <h2>Оплати</h2>
                    </div>
                    <?php if ($role === 'admin'): ?><a class="btn btn-light" href="/checkout">Перевірити checkout</a><?php endif; ?>
                </div>

                <?php $paymentAdminData = $paymentAdminData ?? (new PaymentService())->adminData(current_user()); ?>
                <div class="payment-admin-stats">
                    <div><span>Очікує онлайн</span><b><?= e($paymentAdminData['stats']['pending'] ?? 0) ?></b></div>
                    <div><span>Очікує менеджера</span><b><?= e($paymentAdminData['stats']['waiting_manager'] ?? 0) ?></b></div>
                    <div><span>Оплачено</span><b><?= e($paymentAdminData['stats']['paid'] ?? 0) ?></b></div>
                    <div><span>Помилки</span><b><?= e($paymentAdminData['stats']['failed'] ?? 0) ?></b></div>
                </div>

                <?php if (empty($paymentAdminData['payments'])): ?>
                    <div class="empty-card">Платежів поки немає. Вони зʼявляться після оформлення замовлення.</div>
                <?php else: ?>
                    <div class="admin-table-tools admin-table-tools--split">
                        <input type="search" class="admin-table-search" data-admin-table-filter="payments-table" placeholder="Пошук за ID, клієнтом, телефоном, методом, статусом або референсом...">
                        <button class="btn btn-light" type="button" data-table-toggle="payments-table" data-collapsed-label="Показати всі" data-expanded-label="Згорнути">Показати всі</button>
                    </div>
                    <div class="admin-table-wrap admin-table-scroll-small">
                        <table class="admin-table payment-admin-table compact-table" data-admin-table="payments-table" data-row-limit="8">
                            <thead>
                                <tr><th>ID</th><th>Замовлення</th><th>Клієнт</th><th>Метод</th><th>Сума</th><th>Статус</th><th>Референс</th><th>Дія</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($paymentAdminData['payments'] as $paymentRow): ?>
                                <tr>
                                    <td>#<?= e($paymentRow['ID']) ?></td>
                                    <td>#<?= e($paymentRow['ORDER_ID']) ?><br><small><?= e($paymentRow['ORDER_STATUS'] ?? '') ?></small></td>
                                    <td><?= e($paymentRow['CUSTOMER_NAME'] ?? 'Клієнт') ?><br><small><?= e($paymentRow['CUSTOMER_PHONE'] ?? '') ?></small></td>
                                    <td><?= e($paymentRow['METHOD_NAME'] ?? $paymentRow['METHOD_CODE'] ?? '') ?><br><small><?= e($paymentRow['PROVIDER'] ?? '') ?></small></td>
                                    <td><?= money($paymentRow['AMOUNT'] ?? 0) ?></td>
                                    <td><span class="status-chip"><?= e($paymentRow['STATUS'] ?? 'pending') ?></span></td>
                                    <td><small><?= e($paymentRow['TRANSACTION_REF'] ?? '—') ?></small></td>
                                    <td>
                                        <form class="payment-admin-form" method="post">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="payment_admin_update">
                                            <input type="hidden" name="payment_id" value="<?= e($paymentRow['ID']) ?>">
                                            <input type="hidden" name="return_to" value="/admin#payments">
                                            <select name="status">
                                                <?php foreach (['pending','waiting_manager','pay_on_delivery','invoice_sent','paid','failed','cancelled','refunded'] as $st): ?>
                                                    <option value="<?= e($st) ?>" <?= (($paymentRow['STATUS'] ?? '') === $st) ? 'selected' : '' ?>><?= e($st) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="text" name="transaction_ref" value="<?= e($paymentRow['TRANSACTION_REF'] ?? '') ?>" placeholder="ID платежу / рахунку">
                                            <input type="text" name="client_note" value="<?= e($paymentRow['CLIENT_NOTE'] ?? '') ?>" placeholder="Коментар">
                                            <button class="btn btn-light" type="submit">Оновити</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <h3 class="admin-subtitle">Методи оплати</h3>
                <div class="payment-methods-admin">
                    <?php foreach (($paymentAdminData['methods'] ?? []) as $method): ?>
                        <div>
                            <b><?= e($method['NAME']) ?></b>
                            <span><?= e($method['DESCRIPTION'] ?? '') ?></span>
                            <small>Код: <?= e($method['CODE']) ?> · провайдер: <?= e($method['PROVIDER'] ?? 'manual') ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>


            <div class="tab-panel <?= $defaultAdminTab === 'business' ? 'is-active' : '' ?>" data-tab-panel="business">
                <link rel="stylesheet" href="/assets/css/business.css">
                <?php $businessAdminData = $businessAdminData ?? (new BusinessClientService())->adminData(current_user()); ?>
                <div class="admin-panel-head">
                    <div>
                        <span class="eyebrow">B2B / менеджер</span>
                        <h2>Оптові клієнти, рахунки та постачальники</h2>
                    </div>
                    <a class="btn btn-light" href="/wholesale">Сторінка для B2B</a>
                </div>

                <div class="business-admin-stats">
                    <div><span>Клієнти</span><b><?= e($businessAdminData['stats']['clients'] ?? 0) ?></b></div>
                    <div><span>Очікують</span><b><?= e($businessAdminData['stats']['pending_clients'] ?? 0) ?></b></div>
                    <div><span>Активні</span><b><?= e($businessAdminData['stats']['active_clients'] ?? 0) ?></b></div>
                    <div><span>Рахунки</span><b><?= e($businessAdminData['stats']['invoices'] ?? 0) ?></b></div>
                    <div><span>Не оплачені</span><b><?= e($businessAdminData['stats']['unpaid_invoices'] ?? 0) ?></b></div>
                    <div><span>Постачальники</span><b><?= e($businessAdminData['stats']['supplier_requests'] ?? 0) ?></b></div>
                </div>

                <h3 class="admin-subtitle">Заявки оптових клієнтів</h3>
                <?php if (empty($businessAdminData['clients'])): ?>
                    <div class="empty-card">B2B-заявок поки немає.</div>
                <?php else: ?>
                    <div class="admin-table-tools admin-table-tools--split">
                        <input type="search" class="admin-table-search" data-admin-table-filter="business-clients-table" placeholder="Пошук за компанією, ЄДРПОУ, контактом, телефоном, типом або статусом...">
                        <button class="btn btn-light" type="button" data-table-toggle="business-clients-table" data-collapsed-label="Показати всі" data-expanded-label="Згорнути">Показати всі</button>
                    </div>
                    <div class="admin-table-wrap admin-table-scroll-small">
                        <table class="admin-table compact-table" data-admin-table="business-clients-table" data-row-limit="8">
                            <thead>
                                <tr>
                                    <th>ID</th><th>Компанія</th><th>Контакт</th><th>Тип</th><th>Умови</th><th>Статус</th><th>Дія</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($businessAdminData['clients'] as $client): ?>
                                <tr>
                                    <td>#<?= e($client['ID']) ?></td>
                                    <td>
                                        <b><?= e($client['COMPANY_NAME']) ?></b><br>
                                        <small>ЄДРПОУ/ІПН: <?= e($client['EDRPOU'] ?? '—') ?></small>
                                    </td>
                                    <td>
                                        <?= e($client['CONTACT_NAME']) ?><br>
                                        <small><?= e($client['PHONE']) ?> · <?= e($client['EMAIL'] ?? '') ?></small>
                                    </td>
                                    <td><?= e($client['CLIENT_TYPE']) ?></td>
                                    <td>
                                        група: <b><?= e($client['PRICE_GROUP']) ?></b><br>
                                        <small>знижка: <?= e($client['DISCOUNT_PERCENT']) ?>% · ліміт: <?= money($client['CREDIT_LIMIT'] ?? 0) ?></small>
                                    </td>
                                    <td><span class="status-chip"><?= e($client['STATUS']) ?></span></td>
                                    <td>
                                        <form class="business-admin-form" method="post">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="business_admin_update">
                                            <input type="hidden" name="client_id" value="<?= e($client['ID']) ?>">
                                            <input type="hidden" name="return_to" value="/admin#warehouse">
                                            <select name="status">
                                                <?php foreach (['pending','active','paused','rejected'] as $st): ?>
                                                    <option value="<?= e($st) ?>" <?= (($client['STATUS'] ?? '') === $st) ? 'selected' : '' ?>><?= e($st) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <select name="price_group">
                                                <?php foreach (['base','pro','vip','object','partner'] as $grp): ?>
                                                    <option value="<?= e($grp) ?>" <?= (($client['PRICE_GROUP'] ?? '') === $grp) ? 'selected' : '' ?>><?= e($grp) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="number" step="0.1" name="discount_percent" value="<?= e($client['DISCOUNT_PERCENT'] ?? 0) ?>" placeholder="знижка %">
                                            <input type="number" step="0.01" name="credit_limit" value="<?= e($client['CREDIT_LIMIT'] ?? 0) ?>" placeholder="ліміт">
                                            <input type="text" name="manager_comment" value="<?= e($client['MANAGER_COMMENT'] ?? '') ?>" placeholder="коментар">
                                            <button class="btn btn-light" type="submit">OK</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <h3 class="admin-subtitle">Створити рахунок</h3>
                <form class="invoice-create-form" method="post">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="business_invoice_create">
                    <input type="hidden" name="return_to" value="/admin#business">
                    <select name="business_client_id">
                        <option value="0">Без привʼязки до B2B-клієнта</option>
                        <?php foreach (($businessAdminData['clients'] ?? []) as $client): ?>
                            <option value="<?= e($client['ID']) ?>"><?= e($client['COMPANY_NAME']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="order_id">
                        <option value="0">Без замовлення</option>
                        <?php foreach (($businessAdminData['orders_without_invoice'] ?? []) as $order): ?>
                            <option value="<?= e($order['ID']) ?>">#<?= e($order['ID']) ?> · <?= e($order['CUSTOMER_NAME']) ?> · <?= money($order['TOTAL_AMOUNT']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" step="0.01" name="amount" placeholder="сума, якщо без замовлення">
                    <input type="text" name="comment_text" placeholder="коментар до рахунку">
                    <button class="btn btn-primary" type="submit">Створити рахунок</button>
                </form>

                <h3 class="admin-subtitle">Рахунки</h3>
                <div class="admin-table-tools admin-table-tools--split">
                    <input type="search" class="admin-table-search" data-admin-table-filter="business-invoices-table" placeholder="Пошук за номером, клієнтом, замовленням, сумою або статусом...">
                    <button class="btn btn-light" type="button" data-table-toggle="business-invoices-table" data-collapsed-label="Показати всі" data-expanded-label="Згорнути">Показати всі</button>
                </div>
                <div class="admin-table-wrap admin-table-scroll-small">
                    <table class="admin-table compact-table" data-admin-table="business-invoices-table" data-row-limit="8">
                        <thead><tr><th>ID</th><th>Номер</th><th>Клієнт / замовлення</th><th>Сума</th><th>Статус</th><th>Дата</th><th>Дія</th></tr></thead>
                        <tbody>
                        <?php if (empty($businessAdminData['invoices'])): ?>
                            <tr><td colspan="7">Рахунків поки немає.</td></tr>
                        <?php else: ?>
                            <?php foreach ($businessAdminData['invoices'] as $invoice): ?>
                                <tr>
                                    <td>#<?= e($invoice['ID']) ?></td>
                                    <td><b><?= e($invoice['INVOICE_NUMBER']) ?></b></td>
                                    <td>
                                        <?= e($invoice['COMPANY_NAME'] ?? $invoice['CUSTOMER_NAME'] ?? '—') ?><br>
                                        <small>Замовлення: <?= !empty($invoice['ORDER_ID']) ? '#' . e($invoice['ORDER_ID']) : '—' ?></small>
                                    </td>
                                    <td><?= money($invoice['AMOUNT']) ?></td>
                                    <td><span class="status-chip"><?= e(payment_status_label($invoice['STATUS'] ?? '')) ?></span></td>
                                    <td><?= e(format_db_datetime($invoice['CREATED_AT'])) ?></td>
                                    <td>
                                        <form method="post" class="inline-action-form">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="business_invoice_update">
                                            <input type="hidden" name="invoice_id" value="<?= e($invoice['ID']) ?>">
                                            <input type="hidden" name="return_to" value="/admin#warehouse">
                                            <select name="status">
                                                <?php foreach (['draft','sent','paid','overdue','cancelled'] as $st): ?>
                                                    <option value="<?= e($st) ?>" <?= (($invoice['STATUS'] ?? '') === $st) ? 'selected' : '' ?>><?= e($st) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn btn-light" type="submit">OK</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <h3 class="admin-subtitle">Заявки постачальників</h3>
                <div class="admin-table-tools admin-table-tools--split">
                    <input type="search" class="admin-table-search" data-admin-table-filter="business-suppliers-table" placeholder="Пошук за компанією, контактом, напрямом або статусом...">
                    <button class="btn btn-light" type="button" data-table-toggle="business-suppliers-table" data-collapsed-label="Показати всі" data-expanded-label="Згорнути">Показати всі</button>
                </div>
                <div class="admin-table-wrap admin-table-scroll-small">
                    <table class="admin-table compact-table" data-admin-table="business-suppliers-table" data-row-limit="8">
                        <thead><tr><th>ID</th><th>Компанія</th><th>Контакт</th><th>Напрям</th><th>Статус</th><th>Дія</th></tr></thead>
                        <tbody>
                        <?php if (empty($businessAdminData['supplier_requests'])): ?>
                            <tr><td colspan="6">Заявок постачальників поки немає.</td></tr>
                        <?php else: ?>
                            <?php foreach ($businessAdminData['supplier_requests'] as $request): ?>
                                <tr>
                                    <td>#<?= e($request['ID']) ?></td>
                                    <td><?= e($request['COMPANY_NAME']) ?></td>
                                    <td><?= e($request['CONTACT_NAME']) ?><br><small><?= e($request['PHONE']) ?> · <?= e($request['EMAIL'] ?? '') ?></small></td>
                                    <td><?= e($request['PRODUCT_DIRECTION'] ?? '—') ?></td>
                                    <td><span class="status-chip"><?= e($request['STATUS']) ?></span></td>
                                    <td>
                                        <form method="post" class="inline-action-form">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="business_supplier_update">
                                            <input type="hidden" name="request_id" value="<?= e($request['ID']) ?>">
                                            <input type="hidden" name="return_to" value="/admin#warehouse">
                                            <select name="status">
                                                <?php foreach (['new','processing','accepted','rejected'] as $st): ?>
                                                    <option value="<?= e($st) ?>" <?= (($request['STATUS'] ?? '') === $st) ? 'selected' : '' ?>><?= e($st) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn btn-light" type="submit">OK</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="tab-panel <?= $defaultAdminTab === 'products' ? 'is-active' : '' ?>" data-tab-panel="products">
                <div class="admin-panel-head">
                    <div>
                        <span class="eyebrow">Адмін / менеджер</span>
                        <h2>Додавання товару</h2>
                    </div>
                </div>

                <?php if (has_role(['admin', 'manager'])): ?>
                    <form class="admin-form admin-form-grid" method="post" enctype="multipart/form-data" data-no-ajax="1">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="admin_create_product">
                        <input type="hidden" name="return_to" value="/admin#products">

                        <label>Категорія
                            <select name="category_id" required>
                                <option value="">Оберіть категорію</option>
                                <?php foreach ($adminCategories as $category): ?>
                                    <?php $categoryLevel = max(0, (int)($category['TREE_LEVEL'] ?? 0)); ?>
                                    <option value="<?= e($category['ID']) ?>">
                                        <?= e(str_repeat('— ', $categoryLevel) . ($category['TREE_PATH'] ?? $category['NAME'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>Артикул
                            <input type="text" name="sku" placeholder="RC-CEM-500-25" required>
                        </label>

                        <label class="span-2">Назва товару
                            <input type="text" name="name" placeholder="Цемент ПЦ ІІ/А-Ш-500, 25 кг" required>
                        </label>

                        <label>Бренд
                            <input type="text" name="brand" placeholder="RungoCraft, Knauf, Ceresit">
                        </label>

                        <label>Одиниця
                            <input type="text" name="unit" value="шт.">
                        </label>

                        <label>Ціна
                            <input type="number" name="price" min="0" step="0.01" required>
                        </label>

                        <label>Стара ціна
                            <input type="number" name="old_price" min="0" step="0.01">
                        </label>

                        <label>Залишок
                            <input type="number" name="stock_qty" min="0" step="0.001" value="0">
                        </label>

                        <label>Статус
                            <select name="status" data-product-status-select>
                                <option value="in_stock">в наявності</option>
                                <option value="low_stock">мало</option>
                                <option value="out_of_stock">немає</option>
                                <option value="preorder">під замовлення</option>
                                <option value="expected">очікується</option>
                                <option value="archived">архів</option>
                            </select>
                            
                        </label>

                        <label class="span-2">Фото товару
                            <input type="file" name="product_images[]" accept=".jpg,.jpeg,.png,.webp,.gif,.svg" multiple>
                            <small>Фото завантажуються через браузер. Перше вибране фото стане головним.</small>
                        </label>

                        <label class="span-2">Опис
                            <textarea name="description" rows="4" placeholder="Короткий опис товару для картки"></textarea>
                        </label>

                        <label class="span-2">Характеристики, кожна з нового рядка
                            <textarea name="attributes" rows="5" placeholder="Фасування = 25 кг&#10;Марка = 500&#10;Призначення = бетонні роботи"></textarea>
                        </label>

                        <div class="span-2 form-actions">
                            <button class="btn btn-primary" type="submit">Додати товар</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="empty-card">Працівник складу може редагувати залишки, але не створює нові товари.</div>
                <?php endif; ?>

                <h3 class="admin-subtitle">Останні товари / редагування карток</h3>
                <div class="admin-table-tools admin-table-tools--split product-edit-toolbar">
                    <input type="search" class="admin-table-search" data-admin-table-filter="products-table" placeholder="Пошук товару за назвою, артикулом, брендом, описом, статусом або категорією...">
                    <div class="product-edit-bulk-actions">
                        <button class="btn btn-light" type="button" data-table-toggle="products-table" data-collapsed-label="Показати всі товари" data-expanded-label="Згорнути список">Показати всі товари</button>
                        <button class="btn btn-primary" type="button" data-products-save-all disabled>Зберегти всі зміни</button>
                        <span class="product-edit-dirty-counter" data-products-dirty-counter>Немає змін</span>
                    </div>
                </div>
                <div class="admin-table-wrap product-edit-wrap admin-table-scroll-large">
                    <table class="admin-table product-edit-table" data-admin-table="products-table" data-row-limit="10" data-expanded="0">
                        <thead><tr><th>ID</th><th>Артикул</th><th>Основні дані</th><th>Ціни / залишок</th><th>Фото</th><th>Дія</th></tr></thead>
                        <tbody>
                        <?php foreach ($adminProducts as $product): ?>
                            <?php $productFormId = 'product-edit-' . (int)($product['ID'] ?? 0); ?>
                            <tr id="product-row-<?= e($product['ID']) ?>" data-product-edit-row data-product-id="<?= e($product['ID']) ?>" data-admin-search="<?= e(($product['SKU'] ?? '') . ' ' . ($product['NAME'] ?? '') . ' ' . ($product['BRAND'] ?? '') . ' ' . ($product['CATEGORY_NAME'] ?? '') . ' ' . ($product['STATUS'] ?? '') . ' ' . ($product['DESCRIPTION'] ?? '')) ?>">
                                <td>
                                    <?= e($product['ID']) ?>
                                    <form id="<?= e($productFormId) ?>" class="admin-product-edit-form" method="post" enctype="multipart/form-data" data-no-ajax="1"></form>
                                    <input form="<?= e($productFormId) ?>" type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                    <input form="<?= e($productFormId) ?>" type="hidden" name="action" value="admin_update_product">
                                    <input form="<?= e($productFormId) ?>" type="hidden" name="return_to" value="/admin#products">
                                    <input form="<?= e($productFormId) ?>" type="hidden" name="product_id" value="<?= e($product['ID']) ?>">
                                </td>
                                <td><?= e($product['SKU']) ?><br><small><?= e($product['CATEGORY_NAME']) ?></small></td>
                                <td>
                                    <input form="<?= e($productFormId) ?>" type="text" name="name" value="<?= e($product['NAME']) ?>" placeholder="Назва">
                                    <div class="form-grid two compact-edit-grid">
                                        <input form="<?= e($productFormId) ?>" type="text" name="brand" value="<?= e($product['BRAND'] ?? '') ?>" placeholder="Бренд">
                                        <input form="<?= e($productFormId) ?>" type="text" name="unit" value="<?= e($product['UNIT'] ?? 'шт.') ?>" placeholder="Одиниця">
                                    </div>
                                    <textarea form="<?= e($productFormId) ?>" name="description" rows="2" placeholder="Опис товару"><?= e($product['DESCRIPTION'] ?? '') ?></textarea>
                                </td>
                                <td>
                                    <input form="<?= e($productFormId) ?>" type="number" name="price" value="<?= e($product['PRICE']) ?>" step="0.01" min="0" placeholder="Ціна">
                                    <input form="<?= e($productFormId) ?>" type="number" name="old_price" value="<?= e($product['OLD_PRICE'] ?? '') ?>" step="0.01" min="0" placeholder="Стара ціна">
                                    <input form="<?= e($productFormId) ?>" type="number" name="stock_qty" value="<?= e($product['STOCK_QTY']) ?>" step="0.001" min="0" placeholder="Залишок">
                                    <select form="<?= e($productFormId) ?>" name="status" data-product-status-select>
                                        <?php foreach (['in_stock'=>'в наявності','low_stock'=>'мало','out_of_stock'=>'немає','preorder'=>'під замовлення','expected'=>'очікується','archived'=>'архів'] as $st => $label): ?>
                                            <option value="<?= e($st) ?>" <?= (($product['STATUS'] ?? '') === $st) ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <div class="product-admin-images">
                                        <?php $productImages = $product['PRODUCT_IMAGES'] ?? []; ?>
                                        <?php if (!empty($productImages)): ?>
                                            <?php foreach ($productImages as $img): ?>
                                                <div class="product-admin-image-item">
                                                    <img src="<?= e(media_url($img['IMAGE_PATH'] ?? '')) ?>" alt="">
                                                    <span><?= ((int)($img['IS_MAIN'] ?? 0) === 1) ? 'головне' : 'додаткове' ?></span>
                                                    <label class="delete-check"><input form="<?= e($productFormId) ?>" type="checkbox" name="delete_image_ids[]" value="<?= e($img['ID']) ?>"> видалити</label>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <small>Поточне: <?= e($product['IMAGE'] ?? '—') ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <input form="<?= e($productFormId) ?>" type="file" name="product_images[]" accept=".jpg,.jpeg,.png,.webp,.gif,.svg" multiple>
                                    <small>Нові фото додаються через браузер. Перше з нових стане головним.</small>
                                    <textarea form="<?= e($productFormId) ?>" name="attributes" rows="3" placeholder="Оновити характеристики: Назва = значення"></textarea>
                                </td>
                                <td>
                                    <button form="<?= e($productFormId) ?>" class="btn btn-primary" type="submit" data-product-save-one>Зберегти</button>
                                    <small class="product-row-save-state" data-product-row-state>Без змін</small>
                                    <?php if (has_role(['admin', 'manager'])): ?>
                                        <form class="admin-delete-form" method="post" onsubmit="return confirm('Повністю видалити картку товару <?= e($product['SKU'] ?? '') ?>?');">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="admin_delete_product">
                                            <input type="hidden" name="product_id" value="<?= e($product['ID']) ?>">
                                            <input type="hidden" name="return_to" value="/admin#products">
                                            <label class="delete-check"><input type="checkbox" name="confirm_delete" value="yes" required> підтвердити</label>
                                            <button class="btn btn-danger" type="submit">Видалити картку</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="tab-panel <?= $defaultAdminTab === 'categories' ? 'is-active' : '' ?>" data-tab-panel="categories">
                <div class="admin-panel-head">
                    <div>
                        <span class="eyebrow">Каталог</span>
                        <h2>Категорії та підкатегорії</h2>
                    </div>
                </div>

                <?php if (has_role(['admin', 'manager'])): ?>
                    <form class="admin-form admin-form-grid" method="post">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="admin_create_category">
                        <input type="hidden" name="return_to" value="/admin#categories">

                        <label>Батьківська категорія
                            <select name="parent_id">
                                <option value="0">Створити основну категорію</option>
                                <?php foreach ($adminCategories as $category): ?>
                                    <?php $categoryLevel = max(0, (int)($category['TREE_LEVEL'] ?? 0)); ?>
                                    <option value="<?= e($category['ID']) ?>"><?= e(str_repeat('— ', $categoryLevel) . ($category['TREE_PATH'] ?? $category['NAME'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>Назва категорії / підкатегорії
                            <input type="text" name="name" required placeholder="Сантехніка або Каналізація">
                        </label>

                        <label>Slug
                            <input type="text" name="slug" placeholder="pylomaterialy">
                        </label>

                        <label>Іконка
                            <input type="text" name="icon" value="▦">
                        </label>

                        <label>Порядок
                            <input type="number" name="sort_order" value="100">
                            <small class="muted">Менше число — вище в списку. Сортування працює всередині одного рівня категорій.</small>
                        </label>

                        <div class="span-2 form-actions">
                            <button class="btn btn-primary" type="submit">Зберегти категорію</button>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="admin-table-tools admin-table-tools--compact">
                    <input type="search" class="admin-table-search" data-admin-table-filter="categories-table" placeholder="Пошук категорії за назвою, slug або батьківською...">
                    <button class="btn btn-light" type="button" data-table-toggle="categories-table" data-collapsed-label="Показати всі" data-expanded-label="Згорнути">Показати всі</button>
                </div>
                <div class="admin-table-wrap admin-table-scroll-small">
                    <table class="admin-table compact-table categories-admin-table" data-admin-table="categories-table" data-row-limit="8">
                        <thead><tr><th>ID</th><th>Назва</th><th>Батьківська</th><th>Slug</th><th>Порядок</th><th>Дія</th></tr></thead>
                        <tbody>
                        <?php foreach ($adminCategories as $category): ?>
                            <?php $categoryLevel = max(0, (int)($category['TREE_LEVEL'] ?? 0)); ?>
                            <tr>
                                <td><?= e($category['ID']) ?></td>
                                <td><?= e(str_repeat('— ', $categoryLevel)) ?><?= e($category['ICON']) ?> <?= e($category['NAME']) ?></td>
                                <td><?= e($category['PARENT_NAME'] ?? '—') ?></td>
                                <td><?= e($category['SLUG']) ?></td>
                                <td><?= e($category['SORT_ORDER']) ?></td>
                                <td>
                                    <?php if (has_role(['admin', 'manager'])): ?>
                                        <form class="admin-delete-form admin-delete-form--inline" method="post" onsubmit="return confirm('Видалити категорію <?= e($category['NAME'] ?? '') ?>? Якщо це основна категорія, її порожні підкатегорії теж будуть приховані.');">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="admin_delete_category">
                                            <input type="hidden" name="category_id" value="<?= e($category['ID']) ?>">
                                            <input type="hidden" name="return_to" value="/admin#categories">
                                            <button class="btn btn-danger" type="submit">Видалити</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="tab-panel <?= $defaultAdminTab === 'warehouse' ? 'is-active' : '' ?>" data-tab-panel="warehouse">
                <link rel="stylesheet" href="/assets/css/warehouse.css">
                <?php $warehouseAdminData = $warehouseAdminData ?? (new WarehouseService())->adminData(current_user()); ?>

                <div class="admin-panel-head">
                    <div>
                        <span class="eyebrow">Складський модуль</span>
                        <h2>Склади, залишки, резерв і комплектація</h2>
                        <p>Модуль складу: облік залишків, резервування під замовлення, комплектація та передача в доставку.</p>
                    </div>
                    <form method="post">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="warehouse_sync_products">
                        <input type="hidden" name="warehouse_id" value="<?= e($warehouseAdminData['warehouses'][0]['ID'] ?? 0) ?>">
                        <input type="hidden" name="return_to" value="/admin#warehouse">
                        <button class="btn btn-light" type="submit">Синхронізувати товари зі складом</button>
                    </form>
                </div>

                <div class="warehouse-stats">
                    <div><span>Складів</span><b><?= e($warehouseAdminData['stats']['warehouses'] ?? 0) ?></b></div>
                    <div><span>Складських позицій</span><b><?= e($warehouseAdminData['stats']['stock_positions'] ?? 0) ?></b></div>
                    <div><span>Мало / немає</span><b><?= e($warehouseAdminData['stats']['low_stock'] ?? 0) ?></b></div>
                    <div><span>З резервом</span><b><?= e($warehouseAdminData['stats']['reserved'] ?? 0) ?></b></div>
                </div>

                <div class="warehouse-grid">
                    <div class="warehouse-card">
                        <h3>Додати склад</h3>
                        <form class="admin-form" method="post">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="warehouse_create">
                            <input type="hidden" name="return_to" value="/admin#warehouse">
                            <label>Код складу
                                <input type="text" name="code" placeholder="kyiv-main" required>
                            </label>
                            <label>Назва
                                <input type="text" name="name" placeholder="Склад-магазин Київ" required>
                            </label>
                            <label>Адреса
                                <input type="text" name="address_text" placeholder="м. Київ, вул. Куренівська, 15">
                            </label>
                            <button class="btn btn-primary" type="submit">Зберегти склад</button>
                        </form>
                    </div>

                    <div class="warehouse-card">
                        <h3>Операція із залишком</h3>
                        <form class="admin-form" method="post">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="warehouse_stock_adjust">
                            <input type="hidden" name="return_to" value="/admin#warehouse">
                            <label>Склад
                                <select name="warehouse_id" required>
                                    <?php foreach (($warehouseAdminData['warehouses'] ?? []) as $warehouse): ?>
                                        <option value="<?= e($warehouse['ID']) ?>"><?= e($warehouse['NAME']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Товар
                                <input type="search" class="admin-table-search" data-select-filter="warehouse-product-select" placeholder="Почніть вводити артикул або назву товару..." autocomplete="off">
                                <small class="muted">Після введення список нижче залишить тільки знайдені товари. Enter обирає перший знайдений.</small>
                                <select id="warehouse-product-select" name="product_id" required size="1">
                                    <option value="">Оберіть товар</option>
                                    <?php foreach (($warehouseAdminData['products'] ?? []) as $product): ?>
                                        <option value="<?= e($product['ID']) ?>"><?= e($product['SKU'] . ' — ' . $product['NAME']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Тип операції
                                <select name="movement_type">
                                    <option value="set">Встановити залишок</option>
                                    <option value="increase">Надходження</option>
                                    <option value="decrease">Списання</option>
                                </select>
                            </label>
                            <label>Кількість
                                <input type="number" name="quantity" min="0" step="0.001" required>
                            </label>
                            <label>Коментар
                                <input type="text" name="comment_text" placeholder="Інвентаризація, постачання, списання">
                            </label>
                            <button class="btn btn-primary" type="submit">Провести операцію</button>
                        </form>
                    </div>
                </div>

                <h3 class="admin-subtitle">Комплектація замовлень</h3>
                <?php if (empty($warehouseAdminData['orders'])): ?>
                    <div class="empty-card">Немає замовлень для комплектації.</div>
                <?php else: ?>
                    <div class="admin-table-tools admin-table-tools--split">
                        <input type="search" class="admin-table-search" data-admin-table-filter="warehouse-orders-table" placeholder="Пошук за ID, клієнтом, телефоном або статусом...">
                        <button class="btn btn-light" type="button" data-table-toggle="warehouse-orders-table" data-collapsed-label="Показати всі" data-expanded-label="Згорнути">Показати всі</button>
                    </div>
                    <div class="admin-table-wrap admin-table-scroll-small">
                        <table class="admin-table" data-admin-table="warehouse-orders-table" data-row-limit="5">
                            <thead>
                                <tr><th>ID</th><th>Клієнт</th><th>Статус</th><th>Сума</th><th>Резерв</th><th>Дії складу</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($warehouseAdminData['orders'] as $order): ?>
                                <tr id="order-<?= e($order['ID']) ?>" data-admin-row="order-<?= e($order['ID']) ?>">
                                    <td>#<?= e($order['ID']) ?></td>
                                    <td><?= e($order['CUSTOMER_NAME']) ?><br><small><?= e($order['CUSTOMER_PHONE']) ?></small></td>
                                    <td><span class="status-chip"><?= e(order_status_label($order['STATUS'] ?? '')) ?></span></td>
                                    <td><?= money($order['TOTAL_AMOUNT'] ?? 0) ?></td>
                                    <td><?= e($order['RESERVATION_ROWS'] ?? 0) ?> поз. / <?= e($order['RESERVED_QTY'] ?? 0) ?></td>
                                    <td>
                                        <form class="warehouse-inline-form" method="post">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="warehouse_reserve_order">
                                            <input type="hidden" name="order_id" value="<?= e($order['ID']) ?>">
                                            <input type="hidden" name="return_to" value="/admin#warehouse">
                                            <select name="warehouse_id">
                                                <?php foreach (($warehouseAdminData['warehouses'] ?? []) as $warehouse): ?>
                                                    <option value="<?= e($warehouse['ID']) ?>"><?= e($warehouse['NAME']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn btn-light" type="submit">Зарезервувати</button>
                                        </form>
                                        <form class="warehouse-inline-form" method="post">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="warehouse_update_order_status">
                                            <input type="hidden" name="order_id" value="<?= e($order['ID']) ?>">
                                            <input type="hidden" name="return_to" value="/admin#warehouse">
                                            <select name="status">
                                                <?php foreach (['processing','picking','packed','ready_for_delivery','completed','cancelled'] as $status): ?>
                                                    <option value="<?= e($status) ?>" <?= (($order['STATUS'] ?? '') === $status) ? 'selected' : '' ?>><?= e(order_status_label($status)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn btn-primary" type="submit">Оновити</button>
                                        </form>
                                        <form method="post" class="warehouse-inline-form">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="warehouse_release_order">
                                            <input type="hidden" name="order_id" value="<?= e($order['ID']) ?>">
                                            <input type="hidden" name="return_to" value="/admin#warehouse-reservations">
                                            <button class="btn btn-light" type="submit">Зняти резерв</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <h3 class="admin-subtitle">Залишки по складах</h3>
                <?php if (empty($warehouseAdminData['stock'])): ?>
                    <div class="empty-card">Складські позиції ще не створені. Натисніть “Синхронізувати товари зі складом”.</div>
                <?php else: ?>
                    <div class="admin-table-tools admin-table-tools--split">
                        <input type="search" class="admin-table-search" data-admin-table-filter="warehouse-stock-table" placeholder="Пошук за складом, артикулом, товаром, одиницею або кількістю...">
                        <button class="btn btn-light" type="button" data-table-toggle="warehouse-stock-table" data-collapsed-label="Показати всі" data-expanded-label="Згорнути">Показати всі</button>
                    </div>
                    <div class="admin-table-wrap admin-table-scroll-small">
                        <table class="admin-table compact-table" data-admin-table="warehouse-stock-table" data-row-limit="8">
                            <thead>
                                <tr><th>Склад</th><th>Товар</th><th>Всього</th><th>Резерв</th><th>Доступно</th><th>Оновлено</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($warehouseAdminData['stock'] as $stock): ?>
                                <tr>
                                    <td><?= e($stock['WAREHOUSE_NAME']) ?></td>
                                    <td><b><?= e($stock['SKU']) ?></b><br><?= e($stock['PRODUCT_NAME']) ?></td>
                                    <td><?= e($stock['QTY_TOTAL']) ?> <?= e($stock['UNIT']) ?></td>
                                    <td><?= e($stock['QTY_RESERVED']) ?> <?= e($stock['UNIT']) ?></td>
                                    <td><b><?= e($stock['QTY_AVAILABLE']) ?> <?= e($stock['UNIT']) ?></b></td>
                                    <td><?= e(format_db_datetime($stock['UPDATED_AT'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="warehouse-two-cols">
                    <div>
                        <h3 class="admin-subtitle">Останні рухи товарів</h3>
                        <?php if (empty($warehouseAdminData['movements'])): ?>
                            <div class="empty-card">Рухів товарів ще немає.</div>
                        <?php else: ?>
                            <div class="admin-table-tools admin-table-tools--compact">
                                <input type="search" class="admin-table-search" data-admin-table-filter="warehouse-movements-table" placeholder="Пошук за товаром, типом або коментарем...">
                                <button class="btn btn-light" type="button" data-table-toggle="warehouse-movements-table" data-collapsed-label="Показати всі" data-expanded-label="Згорнути">Показати всі</button>
                            </div>
                            <div class="admin-table-wrap admin-table-scroll-small">
                                <table class="admin-table compact-table" data-admin-table="warehouse-movements-table" data-row-limit="5">
                                    <thead><tr><th>Дата</th><th>Товар</th><th>Тип</th><th>К-сть</th><th>Коментар</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($warehouseAdminData['movements'] as $movement): ?>
                                        <tr>
                                            <td><?= e(format_db_datetime($movement['CREATED_AT'])) ?></td>
                                            <td><?= e($movement['SKU']) ?><br><small><?= e($movement['PRODUCT_NAME']) ?></small></td>
                                            <td><?= e($movement['MOVEMENT_TYPE']) ?></td>
                                            <td><?= e($movement['QUANTITY']) ?></td>
                                            <td><?= e($movement['COMMENT_TEXT'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <h3 class="admin-subtitle" id="warehouse-reservations">Резерви під замовлення</h3>
                        <?php if (empty($warehouseAdminData['reservations'])): ?>
                            <div class="empty-card">Активних резервів немає.</div>
                        <?php else: ?>
                            <div class="admin-table-tools admin-table-tools--compact">
                                <input type="search" class="admin-table-search" data-admin-table-filter="warehouse-reservations-table" placeholder="Пошук за замовленням, товаром або статусом...">
                                <button class="btn btn-light" type="button" data-table-toggle="warehouse-reservations-table" data-collapsed-label="Показати всі" data-expanded-label="Згорнути">Показати всі</button>
                            </div>
                            <div class="admin-table-wrap">
                                <table class="admin-table compact-table" data-admin-table="warehouse-reservations-table" data-row-limit="5">
                                    <thead><tr><th>Замовлення</th><th>Товар</th><th>К-сть</th><th>Статус</th><th>Дія</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($warehouseAdminData['reservations'] as $reservation): ?>
                                        <tr>
                                            <td>#<?= e($reservation['ORDER_ID']) ?></td>
                                            <td><?= e($reservation['SKU']) ?><br><small><?= e($reservation['PRODUCT_NAME']) ?></small></td>
                                            <td><?= e($reservation['QUANTITY']) ?></td>
                                            <td><span class="status-chip"><?= e($reservation['STATUS']) ?></span></td>
                                            <td>
                                                <?php if (($reservation['STATUS'] ?? '') === 'reserved'): ?>
                                                <form method="post" class="warehouse-inline-form">
                                                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="warehouse_release_order">
                                                    <input type="hidden" name="order_id" value="<?= e($reservation['ORDER_ID']) ?>">
                                                    <input type="hidden" name="return_to" value="/admin#warehouse-reservations">
                                                    <button class="btn btn-light" type="submit">Зняти резерв</button>
                                                </form>
                                                <?php else: ?>—<?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="tab-panel <?= $defaultAdminTab === 'analytics' ? 'is-active' : '' ?>" data-tab-panel="analytics">
                <link rel="stylesheet" href="/assets/css/analytics.css">
                <?php $analyticsAdminData = $analyticsAdminData ?? (new AnalyticsService())->adminData(); ?>

                <div class="admin-panel-head">
                    <div>
                        <span class="eyebrow">Аналітика</span>
                        <h2>Поведінка користувачів</h2>
                    </div>
                    <div class="admin-head-actions">
                        <?php if ($role === 'admin'): ?><a class="btn btn-primary" href="/admin-analytics-export">Скачати CSV</a><?php endif; ?>
                    </div>
                </div>

                <div class="analytics-grid">
                    <div class="analytics-card"><span>Подій</span><b><?= e($analyticsAdminData['summary']['events'] ?? 0) ?></b></div>
                    <div class="analytics-card"><span>Сесій</span><b><?= e($analyticsAdminData['summary']['sessions'] ?? 0) ?></b></div>
                    <div class="analytics-card"><span>Авторизованих користувачів</span><b><?= e($analyticsAdminData['summary']['users'] ?? 0) ?></b></div>
                    <div class="analytics-card"><span>Замовлень</span><b><?= e($analyticsAdminData['summary']['orders'] ?? 0) ?></b></div>
                    <div class="analytics-card"><span>Додавань у кошик</span><b><?= e($analyticsAdminData['summary']['cart_adds'] ?? 0) ?></b></div>
                    <div class="analytics-card"><span>Переглядів checkout</span><b><?= e($analyticsAdminData['summary']['checkout_views'] ?? 0) ?></b></div>
                </div>

                <div class="analytics-funnel">
                    <h3>Воронка клієнтського шляху</h3>
                    <?php
                        $funnelLabels = [
                            'page_view' => 'Перегляди сторінок',
                            'product_view' => 'Перегляди товарів',
                            'cart_add' => 'Додавання в кошик',
                            'checkout_view' => 'Перехід до оформлення',
                            'order_create' => 'Створені замовлення',
                        ];
                        $maxFunnel = max(array_values($analyticsAdminData['cart_funnel'] ?? [1])) ?: 1;
                    ?>
                    <?php foreach ($funnelLabels as $type => $label): ?>
                        <?php $value = (int)($analyticsAdminData['cart_funnel'][$type] ?? 0); ?>
                        <div class="funnel-row">
                            <span><?= e($label) ?></span>
                            <div><i style="width: <?= max(4, (int)round(($value / $maxFunnel) * 100)) ?>%"></i></div>
                            <b><?= e($value) ?></b>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="analytics-stack">
                    <div>
                        <h3 class="admin-subtitle">Типи подій</h3>
                        <div class="admin-table-wrap analytics-table-narrow">
                            <table class="admin-table">
                                <thead><tr><th>Подія</th><th>Кількість</th></tr></thead>
                                <tbody>
                                <?php foreach (($analyticsAdminData['events_by_type'] ?? []) as $event): ?>
                                    <tr>
                                        <td><?= e($event['EVENT_TYPE']) ?></td>
                                        <td><b><?= e($event['CNT']) ?></b></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div>
                        <h3 class="admin-subtitle">Пошукові запити</h3>
                        <div class="admin-table-wrap analytics-table-narrow">
                            <table class="admin-table">
                                <thead><tr><th>Запит</th><th>Кількість</th></tr></thead>
                                <tbody>
                                <?php if (empty($analyticsAdminData['search_queries'])): ?>
                                    <tr><td colspan="2">Пошукових запитів ще немає.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($analyticsAdminData['search_queries'] as $query): ?>
                                        <tr>
                                            <td><?= e($query['query']) ?></td>
                                            <td><b><?= e($query['count']) ?></b></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <details class="analytics-details" open><summary>Популярність товарів</summary>
                <div class="admin-table-wrap analytics-table-compact">
                    <table class="admin-table">
                        <thead><tr><th>ID</th><th>Артикул</th><th>Назва</th><th>Перегляди</th><th>Кошик</th><th>Бажане</th></tr></thead>
                        <tbody>
                        <?php foreach (($analyticsAdminData['popular_products'] ?? []) as $product): ?>
                            <tr>
                                <td><?= e($product['ID']) ?></td>
                                <td><?= e($product['SKU']) ?></td>
                                <td><?= e($product['NAME']) ?></td>
                                <td><?= e($product['VIEWS_COUNT']) ?></td>
                                <td><?= e($product['CART_COUNT']) ?></td>
                                <td><?= e($product['WISHLIST_COUNT']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                </details>

                <details class="analytics-details"><summary>Активні / потенційно покинуті кошики</summary>
                <div class="admin-table-wrap analytics-table-compact">
                    <table class="admin-table">
                        <thead><tr><th>ID</th><th>Сесія</th><th>Користувач</th><th>Позицій</th><th>Сума</th><th>Оновлено</th></tr></thead>
                        <tbody>
                        <?php if (empty($analyticsAdminData['abandoned_carts'])): ?>
                            <tr><td colspan="6">Активних кошиків немає.</td></tr>
                        <?php else: ?>
                            <?php foreach ($analyticsAdminData['abandoned_carts'] as $cart): ?>
                                <tr>
                                    <td>#<?= e($cart['ID']) ?></td>
                                    <td><?= e(mb_strimwidth((string)($cart['SESSION_ID'] ?? ''), 0, 18, '...')) ?></td>
                                    <td><?= e($cart['USER_ID'] ?? 'гість') ?></td>
                                    <td><?= e($cart['ITEMS_COUNT']) ?></td>
                                    <td><?= money($cart['TOTAL_AMOUNT'] ?? 0) ?></td>
                                    <td><?= e(format_db_datetime($cart['UPDATED_AT'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                </details>

                <details class="analytics-details"><summary>Останні 10 подій / розкрити повну таблицю</summary>
                <div class="admin-table-wrap analytics-table-compact">
                    <table class="admin-table">
                        <thead><tr><th>Подія</th><th>Обʼєкт</th><th>ID</th><th>Сторінка</th><th>Користувач</th><th>Дата</th></tr></thead>
                        <tbody>
                        <?php foreach (($analyticsAdminData['recent_events'] ?? []) as $eventIndex => $event): ?>
                            <tr class="<?= $eventIndex >= 10 ? 'analytics-extra-row' : '' ?>">
                                <td><?= e($event['EVENT_TYPE']) ?></td>
                                <td><?= e($event['ENTITY_TYPE'] ?? '—') ?></td>
                                <td><?= e($event['ENTITY_ID'] ?? '—') ?></td>
                                <td><?= e(mb_strimwidth((string)($event['PAGE_URL'] ?? ''), 0, 60, '...')) ?></td>
                                <td><?= e($event['FULL_NAME'] ?? 'гість') ?></td>
                                <td><?= e(format_db_datetime($event['CREATED_AT'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                </details>

                <details class="analytics-details"><summary>Журнал дій адміністратора</summary>
                <div class="admin-table-wrap analytics-table-compact">
                    <table class="admin-table">
                        <thead><tr><th>Дія</th><th>Обʼєкт</th><th>ID</th><th>Користувач</th><th>Дата</th></tr></thead>
                        <tbody>
                        <?php foreach ($adminLogs as $log): ?>
                            <tr>
                                <td><?= e($log['ACTION']) ?></td>
                                <td><?= e($log['ENTITY_TYPE']) ?></td>
                                <td><?= e($log['ENTITY_ID']) ?></td>
                                <td><?= e($log['FULL_NAME'] ?? '—') ?></td>
                                <td><?= e(format_db_datetime($log['CREATED_AT'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                </details>
            </div>

            <div class="tab-panel <?= $defaultAdminTab === 'accounting' ? 'is-active' : '' ?>" data-tab-panel="accounting">
                <div class="admin-panel-head">
                    <div><span class="eyebrow">Адміністратор</span><h2>Вигрузка для бухгалтера</h2><p>CSV з замовленнями та товарними позиціями для Excel/Google Sheets.</p></div>
                </div>
                <div class="accounting-export-box reveal">
                    <form class="form-grid three" method="get" action="/admin-accounting-export">
                        <label>Дата від<input type="date" name="date_from"></label>
                        <label>Дата до<input type="date" name="date_to"></label>
                        <label>&nbsp;<button class="btn btn-primary" type="submit">Завантажити CSV</button></label>
                    </form>
                </div>
            </div>

            <div class="tab-panel <?= $defaultAdminTab === 'staff_keys' ? 'is-active' : '' ?>" data-tab-panel="staff_keys">
                <div class="admin-panel-head">
                    <div><span class="eyebrow">Безпека</span><h2>Файлові ключі входу працівників</h2><p>Адміністратор створює персональний TXT-ключ. Після створення ключ стає обовʼязковим для входу цього працівника на /staff.</p></div>
                    <?php if (!empty($_SESSION['staff_key_download'])): ?><a class="btn btn-primary" href="/admin-staff-key-download">Завантажити створений ключ</a><?php endif; ?>
                </div>
                <form class="admin-form admin-form-grid" method="post" data-no-ajax="1">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="admin_generate_staff_key">
                    <input type="hidden" name="return_to" value="/admin#staff_keys">
                    <label>Працівник
                        <select name="staff_user_id" required>
                            <option value="">Оберіть працівника</option>
                            <?php foreach (($adminData['staff_users'] ?? []) as $staffUser): ?>
                                <option value="<?= e($staffUser['ID']) ?>"><?= e($staffUser['FULL_NAME'] . ' / ' . $staffUser['ROLE_CODE'] . ' / ' . ($staffUser['EMAIL'] ?? $staffUser['PHONE'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Назва ключа<input type="text" name="label" value="Основний ключ працівника"></label>
                    <div class="span-2 form-actions"><button class="btn btn-primary" type="submit">Створити файловий ключ</button></div>
                </form>
                <h3 class="admin-subtitle">Видані ключі</h3>
                <div class="admin-table-wrap">
                    <table class="admin-table compact-table">
                        <thead><tr><th>ID</th><th>Працівник</th><th>Роль</th><th>Назва</th><th>Активний</th><th>Створено</th><th>Дія</th></tr></thead>
                        <tbody>
                        <?php if (empty($adminData['staff_keys'])): ?>
                            <tr><td colspan="7">Ключів ще немає або міграція 033/034 не запущена.</td></tr>
                        <?php else: ?>
                            <?php foreach ($adminData['staff_keys'] as $keyRow): ?>
                                <tr>
                                    <td>#<?= e($keyRow['ID']) ?></td>
                                    <td><?= e($keyRow['FULL_NAME']) ?><br><small><?= e($keyRow['EMAIL'] ?? '') ?></small></td>
                                    <td><?= e($keyRow['ROLE_CODE']) ?></td>
                                    <td><?= e($keyRow['LABEL'] ?? '') ?></td>
                                    <td><?= ((int)($keyRow['IS_ACTIVE'] ?? 0) === 1) ? 'так' : 'ні' ?></td>
                                    <td><?= e(format_db_datetime($keyRow['CREATED_AT'] ?? '')) ?></td>
                                    <td class="inline-actions">
                                        <?php if ((int)($keyRow['HAS_FILE'] ?? 0) === 1): ?>
                                            <a class="btn btn-light" href="/admin-staff-key-download?id=<?= e($keyRow['ID']) ?>">Скачати</a>
                                        <?php else: ?>
                                            <small>файл не збережено</small>
                                        <?php endif; ?>
                                        <form method="post" class="inline-action-form">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="admin_toggle_staff_key">
                                            <input type="hidden" name="key_id" value="<?= e($keyRow['ID']) ?>">
                                            <input type="hidden" name="return_to" value="/admin#staff_keys">
                                            <button class="btn btn-light" type="submit"><?= ((int)($keyRow['IS_ACTIVE'] ?? 0) === 1) ? 'Відключити' : 'Увімкнути' ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="tab-panel <?= $defaultAdminTab === 'notifications' ? 'is-active' : '' ?>" data-tab-panel="notifications">
                <link rel="stylesheet" href="/assets/css/notifications.css">
                <?php $notificationAdminData = $notificationAdminData ?? (new NotificationService())->adminData(); ?>

                <div class="admin-panel-head">
                    <div>
                        <span class="eyebrow">Email</span>
                        <h2>Email-розсилки</h2>
                        <p>Telegram і ручні сповіщення вимкнені. У цьому розділі лишається рекламна email-розсилка.</p>
                    </div>
                    <a class="btn btn-light" href="/notifications">Відкрити окремо</a>
                </div>

                <div class="notification-grid">
                    <div class="notification-card"><span>Усього</span><b><?= e($notificationAdminData['summary']['total'] ?? 0) ?></b></div>
                    <div class="notification-card"><span>У черзі</span><b><?= e($notificationAdminData['summary']['queued'] ?? 0) ?></b></div>
                    <div class="notification-card"><span>Відправлено</span><b><?= e($notificationAdminData['summary']['sent'] ?? 0) ?></b></div>
                    <div class="notification-card"><span>Помилки</span><b><?= e($notificationAdminData['summary']['failed'] ?? 0) ?></b></div>
                    <div class="notification-card"><span>Email-підписників</span><b><?= e($notificationAdminData['email_subscriber_count'] ?? 0) ?></b></div>
                </div>

                <div class="notification-two-cols notification-one-col">
                    <div class="notification-box">
                        <h3>Email-розсилка через Brevo SMTP</h3>
                        <p class="hint-text">Отримувачі: усі користувачі з email, у яких увімкнено email-сповіщення та акції. Доступно: <b><?= e($notificationAdminData['email_subscriber_count'] ?? 0) ?></b>. Ліміт прибрано.</p>
                        <form class="admin-form" method="post" enctype="multipart/form-data" data-no-ajax="1">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="notification_send_email_campaign">
                            <input type="hidden" name="return_to" value="/admin#notifications">
                            <label>Тема листа<input type="text" name="subject" value="Акційна пропозиція RungoCraft" required></label>
                            <label>Текст розсилки<textarea name="message" rows="5" required>Вітаємо! У RungoCraft діє спеціальна пропозиція на будівельні матеріали.</textarea></label>
                            <label>Зображення для банера листа<input type="file" name="campaign_image" accept=".jpg,.jpeg,.png,.webp,.gif"></label>
                            <label>Тестовий email<input type="email" name="test_email" placeholder="Заповни тільки для тестового листа"></label>
                            <button class="btn btn-primary" type="submit">Надіслати розсилку</button>
                        </form>
                    </div>
                </div>

                <details class="admin-collapsible" open><summary>Останні email-сповіщення</summary>
                <div class="admin-table-tools"><input type="search" class="admin-table-search" data-admin-table-filter="notifications-table" placeholder="Пошук за email, темою або статусом..."></div>
                <div class="admin-table-wrap admin-table-scroll">
                    <table class="admin-table compact-table" data-admin-table="notifications-table">
                        <thead><tr><th>ID</th><th>Канал</th><th>Отримувач</th><th>Тема</th><th>Статус</th><th>Дата</th><th>Дія</th></tr></thead>
                        <tbody>
                        <?php if (empty($notificationAdminData['notifications'])): ?>
                            <tr><td colspan="7">Сповіщень ще немає.</td></tr>
                        <?php else: ?>
                            <?php foreach (($notificationAdminData['notifications'] ?? []) as $item): ?>
                                <tr>
                                    <td>#<?= e($item['ID']) ?></td><td><?= e($item['CHANNEL']) ?></td><td><?= e($item['RECIPIENT']) ?></td><td><?= e($item['SUBJECT']) ?></td><td><span class="status-chip"><?= e($item['STATUS']) ?></span></td><td><?= e(format_db_datetime($item['CREATED_AT'])) ?></td>
                                    <td><form method="post" class="inline-action-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="notification_mark_sent"><input type="hidden" name="notification_id" value="<?= e($item['ID']) ?>"><input type="hidden" name="return_to" value="/admin#notifications"><button class="btn btn-light" type="submit">Відправлено</button></form></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                </details>
            </div>

            <?php if ($role === 'admin'): ?>


            <div class="tab-panel <?= $defaultAdminTab === 'integrations' ? 'is-active' : '' ?>" data-tab-panel="integrations">
                <link rel="stylesheet" href="/assets/css/integrations.css">
                <?php $integrationAdminData = $integrationAdminData ?? (new IntegrationService())->adminData(current_user()); ?>
                <div class="admin-panel-head">
                    <div>
                        <span class="eyebrow">API</span>
                        <h2>Інтеграції сервісів</h2>
                    </div>
                    <a class="btn btn-light" href="/delivery">Сторінка доставки</a>
                </div>

                <div class="integration-grid">
                    <?php foreach (($integrationAdminData['config'] ?? []) as $key => $item): ?>
                        <div class="integration-card">
                            <b><?= e($item['name'] ?? $key) ?></b>
                            <span class="<?= !empty($item['enabled']) && !empty($item['configured']) ? 'ok' : 'warn' ?>">
                                <?= !empty($item['enabled']) && !empty($item['configured']) ? 'активно' : 'потрібні налаштування' ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <h3 class="admin-subtitle">Активні API та службові дані</h3>

                <details class="admin-collapsible" open><summary>Відправлення для API</summary>
                <?php if (empty($integrationAdminData['shipments'])): ?>
                    <div class="empty-card">Відправлень поки немає.</div>
                <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table compact-table">
                            <thead><tr><th>ID</th><th>Замовлення</th><th>Клієнт</th><th>Перевізник</th><th>Ціна доставки</th><th>ТТН</th><th>Статус</th><th>Дії</th></tr></thead>
                            <tbody>
                            <?php foreach ($integrationAdminData['shipments'] as $shipment): ?>
                                <tr>
                                    <td>#<?= e($shipment['ID']) ?></td>
                                    <td>#<?= e($shipment['ORDER_ID']) ?></td>
                                    <td><?= e($shipment['CUSTOMER_NAME'] ?? '') ?><br><small><?= e($shipment['CUSTOMER_PHONE'] ?? '') ?></small></td>
                                    <td><?= e($shipment['CARRIER_CODE'] ?? 'manual') ?></td>
                                    <td><?= ($shipment['ESTIMATED_PRICE'] ?? null) !== null && $shipment['ESTIMATED_PRICE'] !== '' ? money($shipment['ESTIMATED_PRICE']) : '—' ?></td>
                                    <td><?= e($shipment['TTN'] ?? '—') ?></td>
                                    <td><?= e($shipment['DELIVERY_STATUS'] ?? '') ?><br><small><?= e($shipment['PROVIDER_TRACKING_STATUS'] ?? '') ?></small></td>
                                    <td>
                                        <div class="integration-actions">
                                            <form method="post">
                                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="shipment_id" value="<?= e($shipment['ID']) ?>">
                                                <input type="hidden" name="return_to" value="/admin#integrations">
                                                <button class="btn btn-light" name="action" value="integration_np_create_ttn" type="submit">ТТН НП</button>
                                                <button class="btn btn-light" name="action" value="integration_np_track" type="submit">Трек НП</button>
                                                <button class="btn btn-light" name="action" value="integration_da_calculate" type="submit">Ціна Delivery</button>
                                            <button class="btn btn-light" name="action" value="integration_da_create_ttn" type="submit">ТТН Delivery</button>
                                                <button class="btn btn-light" name="action" value="integration_da_track" type="submit">Трек Delivery</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                </details>

                <details class="admin-collapsible"><summary>Платежі для перевірки статусу</summary>
                <?php if (empty($integrationAdminData['payments'])): ?>
                    <div class="empty-card">Платежів поки немає.</div>
                <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table compact-table">
                            <thead><tr><th>ID</th><th>Замовлення</th><th>Провайдер</th><th>Статус</th><th>Сума</th><th>Дія</th></tr></thead>
                            <tbody>
                            <?php foreach ($integrationAdminData['payments'] as $payment): ?>
                                <tr>
                                    <td>#<?= e($payment['ID']) ?></td>
                                    <td>#<?= e($payment['ORDER_ID']) ?></td>
                                    <td><?= e($payment['PROVIDER'] ?? '') ?></td>
                                    <td><?= e(payment_status_label($payment['STATUS'] ?? '')) ?><br><small><?= e(payment_status_label($payment['GATEWAY_STATUS'] ?? '')) ?></small></td>
                                    <td><?= money($payment['AMOUNT'] ?? 0) ?></td>
                                    <td>
                                        <form method="post">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="integration_payment_refresh">
                                            <input type="hidden" name="payment_id" value="<?= e($payment['ID']) ?>">
                                            <input type="hidden" name="return_to" value="/admin#integrations">
                                            <button class="btn btn-light" type="submit">Оновити статус</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                </details>

                <details class="admin-collapsible"><summary>Журнал API</summary>
                <?php if (empty($integrationAdminData['logs'])): ?>
                    <div class="empty-card">Запитів до зовнішніх API ще не було.</div>
                <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table compact-table">
                            <thead><tr><th>Сервіс</th><th>Дія</th><th>HTTP</th><th>Результат</th><th>Дата</th><th>Деталі</th></tr></thead>
                            <tbody>
                            <?php foreach ($integrationAdminData['logs'] as $log): ?>
                                <tr>
                                    <td><?= e($log['PROVIDER']) ?></td>
                                    <td><?= e($log['ACTION_NAME']) ?></td>
                                    <td><?= e($log['STATUS_CODE'] ?? '') ?></td>
                                    <td><?= ((int)($log['IS_SUCCESS'] ?? 0) === 1) ? 'OK' : 'Помилка' ?></td>
                                    <td><?= e(format_db_datetime($log['CREATED_AT'])) ?></td>
                                    <td>
                                        <?php $logPayload = trim((string)($log['PAYLOAD'] ?? '')); ?>
                                        <?php if ($logPayload !== ''): ?>
                                            <details class="api-log-details">
                                                <summary>payload/response</summary>
                                                <pre><?= e(mb_substr($logPayload, 0, 5000)) ?></pre>
                                            </details>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                </details>
            </div>

            <div class="tab-panel <?= $defaultAdminTab === 'settings' ? 'is-active' : '' ?>" data-tab-panel="settings">
                <div class="admin-panel-head">
                    <div>
                        <span class="eyebrow">Адміністратор</span>
                        <h2>Налаштування сайту</h2>
                    </div>
                </div>

                <form class="admin-form admin-form-grid" method="post">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="admin_update_settings">
                    <input type="hidden" name="return_to" value="/admin#settings">

                    <label>Телефон для показу
                        <input type="text" name="phone_label" value="<?= e($adminSettings['phone_label'] ?? '') ?>">
                    </label>
                    <label>Телефон tel:
                        <input type="text" name="phone" value="<?= e($adminSettings['phone'] ?? '') ?>">
                    </label>
                    <label>Email
                        <input type="email" name="email" value="<?= e($adminSettings['email'] ?? '') ?>">
                    </label>
                    <label>Графік
                        <input type="text" name="worktime" value="<?= e($adminSettings['worktime'] ?? '') ?>">
                    </label>
                    <label class="span-2">Адреса
                        <input type="text" name="address" value="<?= e($adminSettings['address'] ?? '') ?>">
                    </label>
                    <label>YouTube
                        <input type="text" name="youtube_url" value="<?= e($adminSettings['youtube_url'] ?? '') ?>">
                    </label>
                    <label>Telegram канал
                        <input type="text" name="telegram_url" value="<?= e($adminSettings['telegram_url'] ?? '') ?>" placeholder="https://t.me/...">
                    </label>
                    <label class="span-2">Google Maps URL
                        <input type="text" name="google_maps_url" value="<?= e($adminSettings['google_maps_url'] ?? '') ?>">
                    </label>
                    <div class="span-2 form-actions">
                        <button class="btn btn-primary" type="submit">Зберегти налаштування</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

<script>
(function () {
    function findStatusSelect(stockInput) {
        var formId = stockInput.getAttribute('form');
        if (formId && window.CSS && CSS.escape) {
            return document.querySelector('select[name="status"][form="' + CSS.escape(formId) + '"]');
        }
        if (formId) {
            return document.querySelector('select[name="status"][form="' + formId.replace(/"/g, '\\"') + '"]');
        }
        var form = stockInput.closest('form');
        return form ? form.querySelector('select[name="status"]') : null;
    }

    function applyAutoProductStatus(stockInput) {
        var raw = String(stockInput.value || '0').replace(',', '.');
        var stock = parseFloat(raw);
        var statusSelect = findStatusSelect(stockInput);
        if (!statusSelect || !isFinite(stock)) {
            return;
        }


        if (['preorder', 'expected', 'archived'].indexOf(statusSelect.value) !== -1) {
            return;
        }

        if (stock <= 0) {
            statusSelect.value = 'out_of_stock';
            return;
        }

        if (stock <= 2) {
            statusSelect.value = 'low_stock';
            return;
        }

        statusSelect.value = 'in_stock';
    }

    document.querySelectorAll('input[name="stock_qty"]').forEach(function (stockInput) {
        stockInput.addEventListener('input', function () { applyAutoProductStatus(stockInput); });
        stockInput.addEventListener('change', function () { applyAutoProductStatus(stockInput); });
    });
}());
</script>

</section>
