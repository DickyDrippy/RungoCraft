<?php
declare(strict_types=1);

final class IntegrationService
{
    private array $config;

    public function __construct()
    {
        $this->config = $this->loadConfig();
    }

    public function handle(string $action, array $data, ?array $user): array
    {
        return match ($action) {
            'integration_np_sync_cities' => $this->syncNovaPoshtaCities($data, $user),
            'integration_np_sync_warehouses' => $this->syncNovaPoshtaWarehouses($data, $user),
            'integration_np_create_ttn' => $this->createNovaPoshtaTtn((int)($data['shipment_id'] ?? 0), $user),
            'integration_np_track' => $this->trackNovaPoshtaShipment((int)($data['shipment_id'] ?? 0), $user),
            'integration_da_calculate' => $this->calculateDeliveryAuto((int)($data['shipment_id'] ?? 0), $user),
            'integration_da_create_ttn' => $this->createDeliveryAutoTtn((int)($data['shipment_id'] ?? 0), $user),
            'integration_da_track' => $this->trackDeliveryAutoShipment((int)($data['shipment_id'] ?? 0), $user),
            'integration_payment_refresh' => $this->refreshPaymentStatus((int)($data['payment_id'] ?? 0), $user),
            'integration_test_np' => $this->testNovaPoshta($user),
            'integration_test_delivery_auto' => $this->testDeliveryAuto($user),
            default => ['ok' => false, 'message' => 'Невідома дія інтеграції.'],
        };
    }

    public function adminData(?array $user): array
    {
        if (!$this->canManage($user)) {
            return [
                'config' => [],
                'shipments' => [],
                'payments' => [],
                'logs' => [],
            ];
        }

        return [
            'config' => $this->integrationStatus(),
            'shipments' => $this->shipmentsForIntegration(),
            'payments' => $this->paymentsForIntegration(),
            'logs' => $this->lastLogs(),
        ];
    }

    public function preparePaymentForOrder(int $orderId): ?array
    {
        if ($orderId <= 0 || !$this->tableExists('RC_PAYMENTS')) {
            return null;
        }

        $payment = Database::fetchOne(
            "SELECT * FROM rc_payments WHERE order_id = :order_id ORDER BY id DESC FETCH FIRST 1 ROWS ONLY",
            ['order_id' => $orderId]
        );

        if (!$payment) {
            return null;
        }

        $provider = strtolower((string)($payment['PROVIDER'] ?? ''));
        if ($provider === 'online_card') {
            $provider = strtolower((string)($this->config['payments']['default_provider'] ?? 'liqpay'));
        }

        if ($provider === 'liqpay' && $this->isEnabled('payments.liqpay.enabled')) {
            return $this->prepareLiqPayPayment($payment);
        }

        if ($provider === 'wayforpay' && $this->isEnabled('payments.wayforpay.enabled')) {
            return $this->prepareWayForPayPayment($payment);
        }

        return $payment;
    }

    public function gatewayPayload(int $paymentId, string $provider): ?array
    {
        $payment = Database::fetchOne(
            "SELECT p.*, o.customer_name, o.customer_email, o.customer_phone
             FROM rc_payments p
             JOIN rc_orders o ON o.id = p.order_id
             WHERE p.id = :id",
            ['id' => $paymentId]
        );

        if (!$payment) {
            return null;
        }

        $provider = strtolower($provider);
        if ($provider === 'liqpay') {
            return $this->liqPayFormPayload($payment);
        }

        if ($provider === 'wayforpay') {
            return $this->wayForPayFormPayload($payment);
        }

        return null;
    }

    public function handleLiqPayCallback(array $post): array
    {
        $data = (string)($post['data'] ?? '');
        $signature = (string)($post['signature'] ?? '');
        $privateKey = (string)($this->config['payments']['liqpay']['private_key'] ?? '');

        if ($data === '' || $signature === '' || $privateKey === '') {
            return ['ok' => false, 'message' => 'Некоректний callback LiqPay.'];
        }

        $expected = base64_encode(sha1($privateKey . $data . $privateKey, true));
        if (!hash_equals($expected, $signature)) {
            return ['ok' => false, 'message' => 'Підпис LiqPay не пройшов перевірку.'];
        }

        $payload = json_decode(base64_decode($data), true);
        if (!is_array($payload)) {
            return ['ok' => false, 'message' => 'Не вдалося прочитати callback LiqPay.'];
        }

        $orderId = (int)($payload['order_id'] ?? 0);
        $status = $this->mapLiqPayStatus((string)($payload['status'] ?? ''));
        $transactionRef = (string)($payload['payment_id'] ?? ($payload['transaction_id'] ?? ''));

        $this->updatePaymentByOrder($orderId, 'liqpay', $status, $transactionRef, $payload);
        return ['ok' => true, 'message' => 'Callback LiqPay оброблено.'];
    }

    public function handleWayForPayCallback(array $post): array
    {
        $payload = $post;
        if (isset($post['json']) && is_string($post['json'])) {
            $decoded = json_decode($post['json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        } elseif (count($post) === 0) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $orderId = (int)($payload['orderReference'] ?? $payload['order_id'] ?? 0);
        $status = $this->mapWayForPayStatus((string)($payload['transactionStatus'] ?? $payload['status'] ?? ''));
        $transactionRef = (string)($payload['transactionId'] ?? $payload['authCode'] ?? '');

        $this->updatePaymentByOrder($orderId, 'wayforpay', $status, $transactionRef, $payload);

        return [
            'orderReference' => (string)$orderId,
            'status' => 'accept',
            'time' => time(),
            'signature' => $this->wayForPayCallbackSignature((string)$orderId, 'accept', time()),
        ];
    }

    private function syncNovaPoshtaCities(array $data, ?array $user): array
    {
        if (!$this->canManage($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав.'];
        }

        $limit = max(1, min((int)($data['limit'] ?? 100), 500));
        $response = $this->novaPoshtaRequest('Address', 'getCities', ['Limit' => $limit]);
        if (!$response['ok']) {
            return $response;
        }

        $count = 0;
        foreach (($response['data']['data'] ?? []) as $city) {
            $ref = (string)($city['Ref'] ?? '');
            $name = (string)($city['Description'] ?? $city['DescriptionRu'] ?? '');
            if ($ref === '' || $name === '') {
                continue;
            }

            Database::execute(
                "MERGE INTO rc_np_cities c
                 USING (SELECT :ref ref, :name name, :area_name area_name FROM dual) v
                 ON (c.ref = v.ref)
                 WHEN MATCHED THEN UPDATE SET c.name = v.name, c.area_name = v.area_name, c.is_active = 1
                 WHEN NOT MATCHED THEN INSERT (ref, name, area_name, is_active) VALUES (v.ref, v.name, v.area_name, 1)",
                [
                    'ref' => $ref,
                    'name' => $name,
                    'area_name' => (string)($city['AreaDescription'] ?? ''),
                ]
            );
            $count++;
        }

        return ['ok' => true, 'message' => 'Міста Нової пошти оновлено: ' . $count];
    }

    private function syncNovaPoshtaWarehouses(array $data, ?array $user): array
    {
        if (!$this->canManage($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав.'];
        }

        $cityRef = trim((string)($data['city_ref'] ?? ''));
        if ($cityRef === '') {
            $row = Database::fetchOne("SELECT ref FROM rc_np_cities ORDER BY name FETCH FIRST 1 ROWS ONLY");
            $cityRef = (string)($row['REF'] ?? '');
        }

        if ($cityRef === '') {
            return ['ok' => false, 'message' => 'Спочатку синхронізуйте міста Нової пошти.'];
        }

        $response = $this->novaPoshtaRequest('Address', 'getWarehouses', ['CityRef' => $cityRef, 'Limit' => 500]);
        if (!$response['ok']) {
            return $response;
        }

        $count = 0;
        foreach (($response['data']['data'] ?? []) as $warehouse) {
            $ref = (string)($warehouse['Ref'] ?? '');
            $name = (string)($warehouse['Description'] ?? $warehouse['DescriptionRu'] ?? '');
            if ($ref === '' || $name === '') {
                continue;
            }

            Database::execute(
                "MERGE INTO rc_np_warehouses w
                 USING (SELECT :ref ref, :city_ref city_ref, :name name, :address_text address_text FROM dual) v
                 ON (w.ref = v.ref)
                 WHEN MATCHED THEN UPDATE SET w.city_ref = v.city_ref, w.name = v.name, w.address_text = v.address_text, w.is_active = 1
                 WHEN NOT MATCHED THEN INSERT (ref, city_ref, name, address_text, is_active) VALUES (v.ref, v.city_ref, v.name, v.address_text, 1)",
                [
                    'ref' => $ref,
                    'city_ref' => $cityRef,
                    'name' => $name,
                    'address_text' => (string)($warehouse['ShortAddress'] ?? $warehouse['Description'] ?? ''),
                ]
            );
            $count++;
        }

        return ['ok' => true, 'message' => 'Відділення Нової пошти оновлено: ' . $count];
    }

    private function createNovaPoshtaTtn(int $shipmentId, ?array $user): array
    {
        if (!$this->canManage($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав.'];
        }

        $shipment = $this->shipment($shipmentId);
        if (!$shipment) {
            return ['ok' => false, 'message' => 'Відправлення не знайдено.'];
        }

        if (!in_array((string)($shipment['ORDER_STATUS'] ?? ''), ['packed','ready_for_delivery','sent','delivering'], true)) {
            return ['ok' => false, 'message' => 'ТТН можна створити тільки після резерву та комплектації замовлення на складі.'];
        }

        $deliveryType = (string)($shipment['DELIVERY_TYPE'] ?? '');
        if (!str_starts_with($deliveryType, 'nova_poshta')) {
            return ['ok' => false, 'message' => 'Реальна ТТН НП створюється тільки для замовлень із доставкою Новою поштою.'];
        }

        $np = $this->config['nova_poshta'] ?? [];
        $sender = (array)($np['sender'] ?? []);
        $required = ['sender_ref', 'contact_sender_ref', 'sender_city_ref', 'sender_warehouse_ref'];
        $missingSender = [];
        foreach ($required as $key) {
            if (empty($sender[$key])) {
                $missingSender[] = $key;
            }
        }
        if ($missingSender !== []) {
            $demoTtn = 'NP-DEMO-' . date('ymd') . '-' . str_pad((string)$shipmentId, 6, '0', STR_PAD_LEFT);
            $this->updateShipmentProvider($shipmentId, 'nova_poshta_demo', $demoTtn, 'ttn_created', [
                'demo' => true,
                'reason' => 'Не заповнені sender refs Нової пошти',
                'missing' => $missingSender,
            ]);
            return ['ok' => true, 'message' => 'Створено демо-ТТН Нової пошти: ' . $demoTtn . '. Для реальної ТТН заповніть sender_ref/contact_sender_ref/sender_city_ref/sender_warehouse_ref у config/integrations.php.'];
        }

        $cityRecipient = (string)($shipment['CITY_REF'] ?? '');
        $recipientAddress = (string)($shipment['WAREHOUSE_REF'] ?? '');
        if ($cityRecipient === '' || str_starts_with($cityRecipient, 'manual:')) {
            return ['ok' => false, 'message' => 'Для реальної ТТН НП потрібно вибрати місто з довідника Нової пошти, а не вводити вручну.'];
        }

        if ($deliveryType === 'nova_poshta_courier') {
            return ['ok' => false, 'message' => 'Адресна ТТН Нової пошти потребує Ref адреси отримувача. У цій версії реальне створення ТТН підтримане для “Нова пошта — у відділення”.'];
        }

        if ($recipientAddress === '' || str_starts_with($recipientAddress, 'manual:')) {
            return ['ok' => false, 'message' => 'Для реальної ТТН НП потрібно вибрати відділення/поштомат з довідника Нової пошти.'];
        }

        $recipientPhone = $this->normalizePhone((string)($shipment['RECIPIENT_PHONE'] ?? $shipment['CUSTOMER_PHONE'] ?? ''));
        if ($recipientPhone === '' || strlen($recipientPhone) < 10) {
            return ['ok' => false, 'message' => 'Для ТТН НП потрібен коректний телефон отримувача.'];
        }

        $recipient = $this->createNovaPoshtaRecipient($shipment, $cityRecipient, $recipientPhone);
        if (empty($recipient['ok'])) {
            return $recipient;
        }

        $methodProperties = [
            'PayerType' => 'Recipient',
            'PaymentMethod' => 'Cash',
            'DateTime' => date('d.m.Y'),
            'CargoType' => 'Cargo',
            'VolumeGeneral' => (string)max(0.001, (float)($shipment['DECLARED_VOLUME'] ?? 0.01)),
            'Weight' => (string)max(1, (float)($shipment['DECLARED_WEIGHT'] ?? 1)),
            'ServiceType' => 'WarehouseWarehouse',
            'SeatsAmount' => '1',
            'Description' => 'Будівельні матеріали',
            'Cost' => (string)max(1, (float)($shipment['TOTAL_AMOUNT'] ?? 1)),
            'CitySender' => (string)$sender['sender_city_ref'],
            'Sender' => (string)$sender['sender_ref'],
            'SenderAddress' => (string)$sender['sender_warehouse_ref'],
            'ContactSender' => (string)$sender['contact_sender_ref'],
            'SendersPhone' => (string)($sender['sender_phone'] ?? '380937278561'),
            'CityRecipient' => $cityRecipient,
            'RecipientAddress' => $recipientAddress,
            'Recipient' => (string)$recipient['recipient_ref'],
            'ContactRecipient' => (string)$recipient['contact_ref'],
            'RecipientsPhone' => $recipientPhone,
        ];

        $response = $this->novaPoshtaRequest('InternetDocument', 'save', $methodProperties);
        if (!$response['ok']) {
            return $response;
        }

        $first = $response['data']['data'][0] ?? [];
        $ttn = (string)($first['IntDocNumber'] ?? $first['Number'] ?? '');
        if ($ttn === '') {
            return ['ok' => false, 'message' => 'Нова пошта не повернула номер ТТН. Перевірте журнал інтеграцій та відповідь API.'];
        }

        $this->updateShipmentProvider($shipmentId, 'nova_poshta', $ttn, 'ttn_created', $first);
        return ['ok' => true, 'message' => 'ТТН Нової пошти створено: ' . $ttn];
    }

    private function createNovaPoshtaRecipient(array $shipment, string $cityRef, string $phone): array
    {
        $fullName = trim((string)($shipment['RECIPIENT_NAME'] ?? $shipment['CUSTOMER_NAME'] ?? ''));
        if ($fullName === '') {
            return ['ok' => false, 'message' => 'Для створення ТТН НП потрібно ПІБ отримувача.'];
        }

        $parts = $this->splitPersonName($fullName);
        $props = [
            'CounterpartyProperty' => 'Recipient',
            'CounterpartyType' => 'PrivatePerson',
            'FirstName' => $parts['first_name'],
            'MiddleName' => $parts['middle_name'],
            'LastName' => $parts['last_name'],
            'Phone' => $phone,
            'CityRef' => $cityRef,
        ];
        $email = trim((string)($shipment['CUSTOMER_EMAIL'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $props['Email'] = $email;
        }

        $response = $this->novaPoshtaRequest('Counterparty', 'save', $props);
        if (!$response['ok']) {
            return $response + ['message' => 'НП не створила отримувача: ' . ($response['message'] ?? 'невідома помилка')];
        }

        $first = $response['data']['data'][0] ?? [];
        $recipientRef = (string)($first['Ref'] ?? '');
        $contactRef = $this->extractNovaPoshtaContactRef($first);

        if ($recipientRef !== '' && $contactRef === '') {
            $contacts = $this->novaPoshtaRequest('Counterparty', 'getCounterpartyContactPersons', [
                'Ref' => $recipientRef,
                'Page' => '1',
            ]);
            if ($contacts['ok']) {
                $contactRef = (string)($contacts['data']['data'][0]['Ref'] ?? '');
            }
        }

        if ($recipientRef === '' || $contactRef === '') {
            return [
                'ok' => false,
                'message' => 'НП створила/знайшла отримувача, але не повернула Recipient або ContactRecipient. Перевірте відповідь API у журналі інтеграцій.',
                'data' => $response['data'] ?? [],
            ];
        }

        return [
            'ok' => true,
            'recipient_ref' => $recipientRef,
            'contact_ref' => $contactRef,
        ];
    }

    private function extractNovaPoshtaContactRef(array $counterparty): string
    {
        $paths = [
            ['ContactPerson', 'data', 0, 'Ref'],
            ['ContactPersons', 'data', 0, 'Ref'],
            ['ContactPerson', 0, 'Ref'],
            ['ContactPersons', 0, 'Ref'],
            ['ContactPerson', 'Ref'],
            ['ContactRecipient'],
        ];

        foreach ($paths as $path) {
            $value = $counterparty;
            foreach ($path as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$part];
            }
            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function splitPersonName(string $fullName): array
    {
        $parts = preg_split('/\s+/u', trim($fullName)) ?: [];
        $lastName = trim((string)($parts[0] ?? ''));
        $firstName = trim((string)($parts[1] ?? ''));
        $middleName = trim(implode(' ', array_slice($parts, 2)));

        if ($firstName === '' && $lastName !== '') {
            $firstName = $lastName;
            $lastName = 'Клієнт';
        }
        if ($lastName === '') {
            $lastName = 'Клієнт';
        }
        if ($firstName === '') {
            $firstName = 'RungoCraft';
        }

        return [
            'last_name' => mb_substr($lastName, 0, 40, 'UTF-8'),
            'first_name' => mb_substr($firstName, 0, 40, 'UTF-8'),
            'middle_name' => mb_substr($middleName, 0, 40, 'UTF-8'),
        ];
    }

    private function trackNovaPoshtaShipment(int $shipmentId, ?array $user): array
    {
        if (!$this->canManage($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав.'];
        }

        $shipment = $this->shipment($shipmentId);
        $ttn = trim((string)($shipment['TTN'] ?? ''));
        if (!$shipment || $ttn === '') {
            return ['ok' => false, 'message' => 'Для відстеження потрібна ТТН.'];
        }

        $response = $this->novaPoshtaRequest('TrackingDocument', 'getStatusDocuments', [
            'Documents' => [['DocumentNumber' => $ttn]],
        ]);
        if (!$response['ok']) {
            return $response;
        }

        $first = $response['data']['data'][0] ?? [];
        $status = (string)($first['Status'] ?? $first['StatusCode'] ?? 'tracked');
        $this->updateShipmentTracking($shipmentId, 'nova_poshta', $status, $first);
        return ['ok' => true, 'message' => 'Статус Нової пошти оновлено: ' . $status];
    }

    private function calculateDeliveryAuto(int $shipmentId, ?array $user): array
    {
        if (!$this->canManage($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав.'];
        }

        $shipment = $this->shipment($shipmentId);
        if (!$shipment) {
            return ['ok' => false, 'message' => 'Відправлення не знайдено.'];
        }

        $deliveryType = (string)($shipment['DELIVERY_TYPE'] ?? '');
        if (!str_starts_with($deliveryType, 'delivery_auto')) {
            return ['ok' => false, 'message' => 'Розрахунок Delivery доступний тільки для замовлень Delivery Auto.'];
        }

        $cfg = (array)($this->config['delivery_auto'] ?? []);
        $shipment = $this->enrichDeliveryAutoShipmentRefs($shipment);
        $validation = $this->validateDeliveryAutoShipment($shipment, $cfg, false);
        if (!$validation['ok']) {
            return $validation;
        }

        $payload = $this->deliveryAutoPayload($shipment, false);
        $response = $this->deliveryAutoRequest('calculate', $payload);
        if (!$response['ok']) {
            return ['ok' => false, 'message' => 'Delivery API не розрахував доставку: ' . ($response['message'] ?? 'невідома помилка') . '. Подивіться останній payload у журналі інтеграцій.'];
        }

        $price = $this->extractDeliveryAutoPrice($response['data']);
        $priceIsApi = true;
        if ($price === null) {
            $price = $this->deliveryMethodBasePrice($deliveryType);
            $priceIsApi = false;
        }
        if ($price !== null) {
            $this->updateShipmentEstimatedPrice($shipmentId, $price, $response['data']);
        }

        $this->logExternal('delivery_auto', 'calculate_result', true, $response['data']);
        if ($price !== null) {
            $sourceText = $priceIsApi ? '' : ' API не повернув суму, тому збережено базову ціну методу доставки.';
            return ['ok' => true, 'message' => 'Розрахунок Delivery виконано: ' . number_format($price, 2, '.', ' ') . ' грн. Ціна збережена у колонці “Ціна доставки”.' . $sourceText];
        }
        return ['ok' => true, 'message' => 'Розрахунок Delivery виконано, але API не повернув суму і базову ціну методу не знайдено. Перевірте payload/відповідь у журналі інтеграцій.'];
    }

    private function createDeliveryAutoTtn(int $shipmentId, ?array $user): array
    {
        if (!$this->canManage($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав.'];
        }

        $shipment = $this->shipment($shipmentId);
        if (!$shipment) {
            return ['ok' => false, 'message' => 'Відправлення не знайдено.'];
        }

        $deliveryType = (string)($shipment['DELIVERY_TYPE'] ?? '');
        if (!str_starts_with($deliveryType, 'delivery_auto')) {
            return ['ok' => false, 'message' => 'Квитанція Delivery створюється тільки для замовлень із доставкою Delivery Auto.'];
        }

        $cfg = (array)($this->config['delivery_auto'] ?? []);
        if (empty($cfg['api_key']) || empty($cfg['secret_key']) || !empty($cfg['test_mode'])) {
            $demoTtn = 'DA-DEMO-' . date('ymd') . '-' . str_pad((string)$shipmentId, 6, '0', STR_PAD_LEFT);
            $this->updateShipmentProvider($shipmentId, 'delivery_auto_demo', $demoTtn, 'ttn_created', ['demo' => true, 'reason' => 'Delivery API credentials missing or test mode']);
            return ['ok' => true, 'message' => 'Створено демо-квитанцію Delivery: ' . $demoTtn . '. Для реальної квитанції заповніть api_key, secret_key, sender.city_id, sender.warehouse_id і поставте test_mode=false.'];
        }

        $shipment = $this->enrichDeliveryAutoShipmentRefs($shipment);
        $validation = $this->validateDeliveryAutoShipment($shipment, $cfg);
        if (!$validation['ok']) {
            return $validation;
        }

        $payloads = $this->deliveryAutoCreatePayloads($shipment);
        $response = ['ok' => false, 'message' => 'Payload Delivery не сформовано.', 'data' => []];
        $usedPayload = [];
        $attemptMessages = [];
        foreach ($payloads as $index => $payload) {
            $usedPayload = $payload;
            $response = $this->deliveryAutoCreateRequest($payload);
            if (!empty($response['ok'])) {
                break;
            }
            $attemptMessages[] = '#' . ((int)$index + 1) . ': ' . (string)($response['message'] ?? 'невідома помилка');
        }
        if (empty($response['ok'])) {
            $this->logExternal('delivery_auto', 'create_receipt_all_payloads_failed', false, [
                'status' => 0,
                'attempts' => $attemptMessages,
                'last_request' => $this->maskSecrets($usedPayload),
                'last_response' => $response['data'] ?? [],
            ]);

            if ($this->deliveryAutoLocalFallbackEnabled($cfg)) {
                $localTtn = 'DA-PENDING-' . date('ymd') . '-' . str_pad((string)$shipmentId, 6, '0', STR_PAD_LEFT);
                $fallbackPayload = [
                    'local_fallback' => true,
                    'reason' => 'Delivery API did not create a real receipt.',
                    'api_message' => (string)($response['message'] ?? 'невідома помилка'),
                    'attempts' => $attemptMessages,
                    'last_request' => $this->maskSecrets($usedPayload),
                    'last_response' => $response['data'] ?? [],
                    'note' => 'Це службовий локальний номер, не реальна квитанція Delivery. Реальну квитанцію треба створити у кабінеті Delivery або отримати після коректного sender_id/маршруту API.',
                ];
                $this->updateShipmentProvider($shipmentId, 'delivery_auto_pending', $localTtn, 'api_pending', $fallbackPayload);
                return ['ok' => true, 'message' => 'Delivery API не створив реальну квитанцію (' . ($response['message'] ?? 'невідома помилка') . '). Збережено службовий номер для дипломної демонстрації: ' . $localTtn . '. Увага: це не реальна ТТН Delivery. Для реальної потрібен коректний sender_id/маршрут у кабінеті Delivery.'];
            }

            return ['ok' => false, 'message' => 'Delivery API не створив квитанцію: ' . ($response['message'] ?? 'невідома помилка') . '. Спробовано ' . count($payloads) . ' варіант(и) payload. Подивіться останній payload/відповідь у журналі інтеграцій.'];
        }

        $createdPrice = $this->extractDeliveryAutoPrice($response['data']);
        if ($createdPrice !== null) {
            $this->updateShipmentEstimatedPrice($shipmentId, $createdPrice, $response['data']);
        }

        $ttn = $this->extractDeliveryAutoTtn($response['data']);
        if ($ttn === '') {
            $receiptId = $this->extractDeliveryAutoReceiptId($response['data']);
            if ($receiptId !== '') {
                $this->updateShipmentProvider($shipmentId, 'delivery_auto', $receiptId, 'ttn_created', $response['data']);
                return ['ok' => true, 'message' => 'Delivery створив квитанцію, збережено ID квитанції: ' . $receiptId . '. Якщо у кабінеті Delivery є окремий номер ТТН, він підтягнеться після оновлення/відстеження.'];
            }

            $this->logExternal('delivery_auto', 'create_receipt_no_number', false, [
                'status' => 0,
                'request' => $this->maskSecrets($payload),
                'response' => $response['data'],
            ]);
            return ['ok' => false, 'message' => 'Delivery API відповів, але не повернув номер або ID квитанції. Перевірте payload/особистий кабінет API та журнал інтеграцій.'];
        }

        $this->updateShipmentProvider($shipmentId, 'delivery_auto', $ttn, 'ttn_created', $response['data']);
        return ['ok' => true, 'message' => 'Квитанцію Delivery створено: ' . $ttn];
    }

    private function deliveryAutoLocalFallbackEnabled(array $cfg): bool
    {
        
        
        
        if (array_key_exists('local_fallback_on_api_error', $cfg)) {
            return (bool)$cfg['local_fallback_on_api_error'];
        }
        return true;
    }

    private function validateDeliveryAutoShipment(array $shipment, array $cfg, bool $forCreate = true): array
    {
        $sender = (array)($cfg['sender'] ?? []);
        $missing = [];
        foreach (['api_key', 'secret_key'] as $key) {
            $value = trim((string)($cfg[$key] ?? ''));
            if ($value === '') {
                $missing[] = 'delivery_auto.' . $key;
            }
        }
        foreach (['city_id', 'warehouse_id'] as $key) {
            $value = trim((string)($sender[$key] ?? ''));
            if ($value === '' || str_starts_with($value, 'manual:')) {
                $missing[] = 'delivery_auto.sender.' . $key;
            }
        }

        $deliveryType = (string)($shipment['DELIVERY_TYPE'] ?? '');
        $isDoor = $this->isDeliveryAutoDoor($deliveryType);
        $cityRef = $this->deliveryAutoCityRef($shipment);
        $warehouseRef = $this->deliveryAutoWarehouseRef($shipment, $cityRef);
        $address = trim((string)($shipment['ADDRESS_TEXT'] ?? $shipment['ORDER_DELIVERY_ADDRESS'] ?? ''));

        if ($cityRef === '' || str_starts_with($cityRef, 'manual:')) {
            $missing[] = 'recipient CITY_REF Delivery';
        }
        if (!$isDoor && ($warehouseRef === '' || str_starts_with($warehouseRef, 'manual:'))) {
            $missing[] = 'recipient WAREHOUSE_REF Delivery';
        }
        if ($isDoor && $address === '') {
            $missing[] = 'recipient address Delivery';
        }

        $phone = $this->deliveryAutoPhone((string)($shipment['RECIPIENT_PHONE'] ?? $shipment['CUSTOMER_PHONE'] ?? ''));
        if ($phone === '' || !preg_match('/^0\d{9}$/', $phone)) {
            $missing[] = 'recipient phone у форматі 0XXXXXXXXX';
        }

        if ($missing !== []) {
            $hint = $isDoor
                ? 'Для курʼєрської Delivery-доставки потрібно вибрати місто саме з довідника Delivery і вказати адресу. Відділення отримувача не потрібне.'
                : 'Для доставки у відділення Delivery потрібно вибрати місто і відділення саме з довідника Delivery.';
            return [
                'ok' => false,
                'message' => 'Для реальної квитанції/розрахунку Delivery не вистачає: ' . implode(', ', $missing) . '. ' . $hint,
            ];
        }

        return ['ok' => true, 'message' => 'OK'];
    }

    private function trackDeliveryAutoShipment(int $shipmentId, ?array $user): array
    {
        if (!$this->canManage($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав.'];
        }

        $shipment = $this->shipment($shipmentId);
        $ttn = trim((string)($shipment['TTN'] ?? ''));
        if (!$shipment || $ttn === '') {
            return ['ok' => false, 'message' => 'Для відстеження потрібна квитанція Delivery.'];
        }

        $response = $this->deliveryAutoRequest('tracking', ['culture' => 'uk-UA', 'number' => $ttn, 'receiptNumber' => $ttn]);
        if (!$response['ok']) {
            return $response;
        }

        $status = $this->extractDeliveryAutoStatus($response['data']) ?: 'tracked';
        $this->updateShipmentTracking($shipmentId, 'delivery_auto', $status, $response['data']);
        return ['ok' => true, 'message' => 'Статус Delivery оновлено: ' . $status];
    }

    private function refreshPaymentStatus(int $paymentId, ?array $user): array
    {
        if (!$this->canManage($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав.'];
        }

        $payment = Database::fetchOne('SELECT * FROM rc_payments WHERE id = :id', ['id' => $paymentId]);
        if (!$payment) {
            return ['ok' => false, 'message' => 'Платіж не знайдено.'];
        }

        $provider = strtolower((string)($payment['PROVIDER'] ?? ''));
        if ($provider === 'liqpay' && $this->isEnabled('payments.liqpay.enabled')) {
            $result = $this->liqPayStatus($payment);
            if ($result['ok']) {
                $payload = $result['data'];
                $status = $this->mapLiqPayStatus((string)($payload['status'] ?? ''));
                $this->updatePaymentByOrder((int)$payment['ORDER_ID'], 'liqpay', $status, (string)($payload['payment_id'] ?? ''), $payload);
                return ['ok' => true, 'message' => 'Статус LiqPay оновлено: ' . $status];
            }
            return $result;
        }

        if ($provider === 'wayforpay' && $this->isEnabled('payments.wayforpay.enabled')) {
            $result = $this->wayForPayStatus($payment);
            if ($result['ok']) {
                $payload = $result['data'];
                $status = $this->mapWayForPayStatus((string)($payload['transactionStatus'] ?? $payload['reason'] ?? ''));
                $this->updatePaymentByOrder((int)$payment['ORDER_ID'], 'wayforpay', $status, (string)($payload['transactionId'] ?? ''), $payload);
                return ['ok' => true, 'message' => 'Статус WayForPay оновлено: ' . $status];
            }
            return $result;
        }

        return ['ok' => false, 'message' => 'Для цього платежу немає активної API-інтеграції.'];
    }

    private function testNovaPoshta(?array $user): array
    {
        if (!$this->canManage($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав.'];
        }
        $result = $this->novaPoshtaRequest('Address', 'getCities', ['Limit' => 1]);
        return ['ok' => $result['ok'], 'message' => $result['ok'] ? 'Нова пошта API відповідає.' : $result['message']];
    }

    private function testDeliveryAuto(?array $user): array
    {
        if (!$this->canManage($user)) {
            return ['ok' => false, 'message' => 'Недостатньо прав.'];
        }
        $result = $this->deliveryAutoRequest('calculate', ['culture' => 'uk-UA']);
        return ['ok' => $result['ok'], 'message' => $result['ok'] ? 'Delivery API відповідає.' : $result['message']];
    }

    private function prepareLiqPayPayment(array $payment): array
    {
        $payload = $this->liqPayFormPayload($payment);
        if (!$payload) {
            return $payment;
        }

        $checkoutUrl = '/payment-gateway?provider=liqpay&payment_id=' . (int)$payment['ID'];
        Database::execute(
            "UPDATE rc_payments
             SET provider = 'liqpay', checkout_url = :checkout_url, gateway_payload = :payload, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            [
                'checkout_url' => $checkoutUrl,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'id' => (int)$payment['ID'],
            ]
        );

        return Database::fetchOne('SELECT * FROM rc_payments WHERE id = :id', ['id' => (int)$payment['ID']]) ?? $payment;
    }

    private function prepareWayForPayPayment(array $payment): array
    {
        $payload = $this->wayForPayFormPayload($payment);
        if (!$payload) {
            return $payment;
        }

        $checkoutUrl = '/payment-gateway?provider=wayforpay&payment_id=' . (int)$payment['ID'];
        Database::execute(
            "UPDATE rc_payments
             SET provider = 'wayforpay', checkout_url = :checkout_url, gateway_payload = :payload, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            [
                'checkout_url' => $checkoutUrl,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'id' => (int)$payment['ID'],
            ]
        );

        return Database::fetchOne('SELECT * FROM rc_payments WHERE id = :id', ['id' => (int)$payment['ID']]) ?? $payment;
    }

    private function liqPayFormPayload(array $payment): ?array
    {
        $publicKey = (string)($this->config['payments']['liqpay']['public_key'] ?? '');
        $privateKey = (string)($this->config['payments']['liqpay']['private_key'] ?? '');
        if ($publicKey === '' || $privateKey === '') {
            return null;
        }

        $orderId = (int)$payment['ORDER_ID'];
        $params = [
            'version' => 3,
            'public_key' => $publicKey,
            'action' => 'pay',
            'amount' => number_format((float)$payment['AMOUNT'], 2, '.', ''),
            'currency' => (string)($payment['CURRENCY'] ?? 'UAH'),
            'description' => 'Оплата замовлення RungoCraft #' . $orderId,
            'order_id' => (string)$orderId,
            'language' => 'uk',
            'result_url' => $this->publicUrl('/payment-result?provider=liqpay&return_ok=1&order_id=' . $orderId . '&payment_id=' . (int)$payment['ID']),
            'server_url' => $this->publicUrl('/payment-callback-liqpay'),
        ];

        $data = base64_encode(json_encode($params, JSON_UNESCAPED_UNICODE));
        $signature = base64_encode(sha1($privateKey . $data . $privateKey, true));

        return [
            'action' => 'https://www.liqpay.ua/api/3/checkout',
            'method' => 'POST',
            'fields' => [
                'data' => $data,
                'signature' => $signature,
            ],
        ];
    }

    private function wayForPayFormPayload(array $payment): ?array
    {
        $cfg = (array)($this->config['payments']['wayforpay'] ?? []);
        $merchantAccount = (string)($cfg['merchant_account'] ?? '');
        $secret = (string)($cfg['merchant_secret_key'] ?? '');
        $domain = (string)($cfg['merchant_domain'] ?? ($_SERVER['HTTP_HOST'] ?? 'rungocraft.local'));
        if ($merchantAccount === '' || $secret === '') {
            return null;
        }

        $orderId = (string)(int)$payment['ORDER_ID'];
        $amount = number_format((float)$payment['AMOUNT'], 2, '.', '');
        $currency = (string)($payment['CURRENCY'] ?? 'UAH');
        $orderDate = time();
        $productName = 'Замовлення RungoCraft #' . $orderId;
        $productCount = '1';
        $productPrice = $amount;

        $signatureString = implode(';', [
            $merchantAccount,
            $domain,
            $orderId,
            $orderDate,
            $amount,
            $currency,
            $productName,
            $productCount,
            $productPrice,
        ]);
        $signature = hash_hmac('md5', $signatureString, $secret);

        return [
            'action' => 'https://secure.wayforpay.com/pay',
            'method' => 'POST',
            'fields' => [
                'merchantAccount' => $merchantAccount,
                'merchantAuthType' => 'SimpleSignature',
                'merchantDomainName' => $domain,
                'merchantSignature' => $signature,
                'orderReference' => $orderId,
                'orderDate' => (string)$orderDate,
                'amount' => $amount,
                'currency' => $currency,
                'productName[]' => $productName,
                'productCount[]' => $productCount,
                'productPrice[]' => $productPrice,
                'clientFirstName' => 'RungoCraft',
                'clientLastName' => 'Customer',
                'clientEmail' => 'client@rungocraft.ua',
                'serviceUrl' => $this->publicUrl('/payment-callback-wayforpay'),
                'returnUrl' => $this->publicUrl('/payment-result?provider=wayforpay&return_ok=1&order_id=' . $orderId . '&payment_id=' . (int)$payment['ID']),
            ],
        ];
    }

    private function liqPayStatus(array $payment): array
    {
        $publicKey = (string)($this->config['payments']['liqpay']['public_key'] ?? '');
        $privateKey = (string)($this->config['payments']['liqpay']['private_key'] ?? '');
        if ($publicKey === '' || $privateKey === '') {
            return ['ok' => false, 'message' => 'Не заповнені ключі LiqPay.'];
        }

        $params = [
            'version' => 3,
            'action' => 'status',
            'public_key' => $publicKey,
            'order_id' => (string)(int)$payment['ORDER_ID'],
        ];
        $data = base64_encode(json_encode($params));
        $signature = base64_encode(sha1($privateKey . $data . $privateKey, true));

        return $this->httpJson('https://www.liqpay.ua/api/request', ['data' => $data, 'signature' => $signature], [], 'liqpay', 'status');
    }

    private function wayForPayStatus(array $payment): array
    {
        $cfg = (array)($this->config['payments']['wayforpay'] ?? []);
        $merchantAccount = (string)($cfg['merchant_account'] ?? '');
        $secret = (string)($cfg['merchant_secret_key'] ?? '');
        $orderReference = (string)(int)$payment['ORDER_ID'];
        if ($merchantAccount === '' || $secret === '') {
            return ['ok' => false, 'message' => 'Не заповнені ключі WayForPay.'];
        }

        $signature = hash_hmac('md5', implode(';', [$merchantAccount, $orderReference]), $secret);
        return $this->httpJson('https://api.wayforpay.com/api', [
            'transactionType' => 'CHECK_STATUS',
            'merchantAccount' => $merchantAccount,
            'orderReference' => $orderReference,
            'merchantSignature' => $signature,
            'apiVersion' => 1,
        ], [], 'wayforpay', 'status');
    }

    private function novaPoshtaRequest(string $model, string $method, array $props = []): array
    {
        $apiKey = (string)($this->config['nova_poshta']['api_key'] ?? '');
        if ($apiKey === '') {
            return ['ok' => false, 'message' => 'Не заповнений API-ключ Нової пошти у config/integrations.php.'];
        }

        $endpoint = (string)($this->config['nova_poshta']['endpoint'] ?? 'https://api.novaposhta.ua/v2.0/json/');
        return $this->httpJson($endpoint, [
            'apiKey' => $apiKey,
            'modelName' => $model,
            'calledMethod' => $method,
            'methodProperties' => $props,
        ], [], 'nova_poshta', $model . '.' . $method);
    }

    private function deliveryAutoRequest(string $type, array $payload, ?string $hmacAlgorithm = null): array
    {
        $cfg = (array)($this->config['delivery_auto'] ?? []);
        $base = rtrim((string)($cfg['base_url'] ?? 'https://www.delivery-auto.com/api/v4/Public'), '/');
        $endpoints = (array)($cfg['endpoints'] ?? []);
        $endpoint = match ($type) {
            'calculate' => (string)($endpoints['calculate'] ?? '/PostReceiptCalculate'),
            'create_receipt' => (string)($endpoints['create_receipt'] ?? '/PostCreateReceipts'),
            'tracking' => (string)($endpoints['tracking'] ?? '/GetReceiptDetails'),
            default => '/' . $type,
        };

        $headers = $this->deliveryAutoAuthHeaders($cfg, $hmacAlgorithm);
        return $this->httpJson($base . $endpoint, $payload, $headers, 'delivery_auto', $type);
    }

    private function deliveryAutoCreateRequest(array $payload): array
    {
        $last = ['ok' => false, 'message' => 'Delivery Auto payload не виконано.', 'data' => []];

        
        
        foreach ($this->deliveryAutoHmacAlgorithms() as $algo) {
            foreach ($this->deliveryAutoFormPayloadVariants($payload) as $variant) {
                $response = $this->deliveryAutoRequestForm('create_receipt', $variant, $algo);
                $last = $response;
                if (!empty($response['ok'])) {
                    return $response;
                }
            }
        }

        
        
        foreach ($this->deliveryAutoHmacAlgorithms() as $algo) {
            $response = $this->deliveryAutoRequest('create_receipt', $payload, $algo);
            $last = $response;
            if (!empty($response['ok'])) {
                return $response;
            }
        }

        return $last;
    }

    private function deliveryAutoRequestForm(string $type, array $formPayload, ?string $hmacAlgorithm = null): array
    {
        $cfg = (array)($this->config['delivery_auto'] ?? []);
        $base = rtrim((string)($cfg['base_url'] ?? 'https://www.delivery-auto.com/api/v4/Public'), '/');
        $endpoints = (array)($cfg['endpoints'] ?? []);
        $endpoint = match ($type) {
            'calculate' => (string)($endpoints['calculate'] ?? '/PostReceiptCalculate'),
            'create_receipt' => (string)($endpoints['create_receipt'] ?? '/PostCreateReceipts'),
            'tracking' => (string)($endpoints['tracking'] ?? '/GetReceiptDetails'),
            default => '/' . $type,
        };

        $headers = $this->deliveryAutoAuthHeaders($cfg, $hmacAlgorithm);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8';
        $headers[] = 'Accept: application/json, text/json, */*';

        $url = $base . $endpoint;
        $body = http_build_query($formPayload);
        $started = microtime(true);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        $status = $this->httpStatus($http_response_header ?? []);
        $elapsed = (int)round((microtime(true) - $started) * 1000);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $ok = $raw !== false && $status >= 200 && $status < 300 && is_array($decoded);

        $this->logExternal('delivery_auto', $type . '_form', $ok, [
            'url' => $url,
            'status' => $status,
            'hmac_algorithm' => $hmacAlgorithm ?: $this->deliveryAutoPrimaryHmacAlgorithm(),
            'elapsed_ms' => $elapsed,
            'request' => $this->maskSecrets($formPayload),
            'response' => is_array($decoded) ? $decoded : mb_substr((string)$raw, 0, 1500),
        ]);

        if (!$ok) {
            return [
                'ok' => false,
                'message' => 'API delivery_auto form payload не відповів коректно. HTTP ' . $status,
                'data' => [
                    'status' => $status,
                    'url' => $url,
                    'raw' => mb_substr((string)$raw, 0, 3000),
                    'request' => $this->maskSecrets($formPayload),
                ],
            ];
        }

        $apiFailed = (isset($decoded['success']) && $decoded['success'] === false)
            || (isset($decoded['Success']) && $decoded['Success'] === false)
            || (isset($decoded['status']) && $decoded['status'] === false)
            || (isset($decoded['Status']) && $decoded['Status'] === false)
            || (isset($decoded['isError']) && (bool)$decoded['isError']);

        if ($apiFailed) {
            $errors = $this->extractApiErrors($decoded);
            return ['ok' => false, 'message' => $errors !== '' ? $errors : 'API повернув помилку.', 'data' => $decoded];
        }

        return ['ok' => true, 'message' => 'OK', 'data' => $decoded];
    }

    private function deliveryAutoAuthHeaders(array $cfg, ?string $hmacAlgorithm = null): array
    {
        $headers = [];
        $apiKey = (string)($cfg['api_key'] ?? '');
        $secret = (string)($cfg['secret_key'] ?? '');
        if ($apiKey === '' || $secret === '') {
            return $headers;
        }

        $algo = strtolower((string)($hmacAlgorithm ?: $this->deliveryAutoPrimaryHmacAlgorithm()));
        if (!in_array($algo, hash_hmac_algos(), true)) {
            $algo = 'sha256';
        }
        $timestamp = (string)round(microtime(true) * 1000);
        $hmac = hash_hmac($algo, $apiKey . $timestamp, $secret);
        $headers[] = 'HMACAuthorization: amx ' . $apiKey . ':' . $timestamp . ':' . $hmac;
        return $headers;
    }

    private function deliveryAutoPrimaryHmacAlgorithm(): string
    {
        $cfg = (array)($this->config['delivery_auto'] ?? []);
        $algo = strtolower(trim((string)($cfg['hmac_algorithm'] ?? 'sha256')));
        return $algo !== '' ? $algo : 'sha256';
    }

    private function deliveryAutoHmacAlgorithms(): array
    {
        $cfg = (array)($this->config['delivery_auto'] ?? []);
        $configured = $cfg['hmac_algorithms'] ?? null;
        $algos = [];
        if (is_array($configured)) {
            foreach ($configured as $algo) {
                $algo = strtolower(trim((string)$algo));
                if ($algo !== '') {
                    $algos[] = $algo;
                }
            }
        } else {
            $algos[] = $this->deliveryAutoPrimaryHmacAlgorithm();
        }
        
        
        foreach (['sha256', 'sha1'] as $fallback) {
            if (!in_array($fallback, $algos, true)) {
                $algos[] = $fallback;
            }
        }
        return array_values(array_unique($algos));
    }

    private function deliveryAutoFormPayloadVariants(array $payload): array
    {
        $variants = [];

        
        $variants[] = $this->deliveryAutoNormalizeFormBooleans($payload);

        
        $root = [
            'culture' => (string)($payload['culture'] ?? 'uk-UA'),
            'flSave' => !empty($payload['flSave']) ? 'true' : 'false',
            'debugMode' => !empty($payload['debugMode']) ? 'true' : 'false',
        ];
        $receiptList = is_array($payload['receiptsList'] ?? null) ? $payload['receiptsList'] : [];
        $json = json_encode($receiptList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json) && $receiptList !== []) {
            $variants[] = $root + ['receiptsList' => $json];
        }

        $unique = [];
        $seen = [];
        foreach ($variants as $variant) {
            $key = json_encode($variant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($key) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $variant;
        }
        return $unique;
    }

    private function deliveryAutoNormalizeFormBooleans(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if ($item === null) {
                    continue;
                }
                $out[$key] = $this->deliveryAutoNormalizeFormBooleans($item);
            }
            return $out;
        }
        return $value;
    }

    private function flattenAspNet(array $value, string $prefix, array &$out, string $style): void
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $isList = array_keys($item) === range(0, count($item) - 1);
                if ($isList) {
                    foreach ($item as $index => $child) {
                        $childPrefix = $style === 'bracket'
                            ? $prefix . '[' . $key . '][' . $index . ']'
                            : $prefix . '.' . $key . '[' . $index . ']';
                        if (is_array($child)) {
                            $this->flattenAspNet($child, $childPrefix, $out, $style);
                        } else {
                            $out[$childPrefix] = (string)$child;
                        }
                    }
                } else {
                    $childPrefix = $style === 'bracket' ? $prefix . '[' . $key . ']' : $prefix . '.' . $key;
                    $this->flattenAspNet($item, $childPrefix, $out, $style);
                }
                continue;
            }
            $field = $style === 'bracket' ? $prefix . '[' . $key . ']' : $prefix . '.' . $key;
            if (is_bool($item)) {
                $out[$field] = $item ? 'true' : 'false';
            } elseif ($item !== null) {
                $out[$field] = (string)$item;
            }
        }
    }

    private function flattenFormValue(mixed $value, string $prefix, array &$out, string $style): void
    {
        if (is_array($value)) {
            $isList = array_keys($value) === range(0, count($value) - 1);
            foreach ($value as $key => $child) {
                if ($isList) {
                    $childPrefix = $prefix . '[' . $key . ']';
                } else {
                    $childPrefix = $style === 'bracket'
                        ? $prefix . '[' . $key . ']'
                        : ($prefix === '' ? (string)$key : $prefix . '.' . $key);
                }
                $this->flattenFormValue($child, $childPrefix, $out, $style);
            }
            return;
        }
        if (is_bool($value)) {
            $out[$prefix] = $value ? 'true' : 'false';
        } elseif ($value !== null) {
            $out[$prefix] = (string)$value;
        }
    }


    private function httpJson(string $url, array $payload, array $headers, string $provider, string $action): array
    {
        $headers[] = 'Content-Type: application/json';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $started = microtime(true);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $json,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        $status = $this->httpStatus($http_response_header ?? []);
        $elapsed = (int)round((microtime(true) - $started) * 1000);

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $ok = $raw !== false && $status >= 200 && $status < 300 && is_array($decoded);

        $this->logExternal($provider, $action, $ok, [
            'url' => $url,
            'status' => $status,
            'elapsed_ms' => $elapsed,
            'request' => $this->maskSecrets($payload),
            'response' => is_array($decoded) ? $decoded : mb_substr((string)$raw, 0, 1500),
        ]);

        if (!$ok) {
            return [
                'ok' => false,
                'message' => 'API ' . $provider . ' не відповів коректно. HTTP ' . $status,
                'data' => [
                    'status' => $status,
                    'url' => $url,
                    'raw' => mb_substr((string)$raw, 0, 3000),
                    'request' => $this->maskSecrets($payload),
                ],
            ];
        }

        $apiFailed = (isset($decoded['success']) && $decoded['success'] === false)
            || (isset($decoded['Success']) && $decoded['Success'] === false)
            || (isset($decoded['status']) && $decoded['status'] === false)
            || (isset($decoded['Status']) && $decoded['Status'] === false)
            || (isset($decoded['isError']) && (bool)$decoded['isError']);

        if ($apiFailed) {
            $errors = $this->extractApiErrors($decoded);
            return ['ok' => false, 'message' => $errors !== '' ? $errors : 'API повернув помилку.', 'data' => $decoded];
        }

        return ['ok' => true, 'message' => 'OK', 'data' => $decoded];
    }

    private function deliveryAutoPayload(array $shipment, bool $create): array
    {
        $cfg = (array)($this->config['delivery_auto'] ?? []);
        $sender = (array)($cfg['sender'] ?? []);
        $deliveryType = (string)($shipment['DELIVERY_TYPE'] ?? '');
        $isDoor = $this->isDeliveryAutoDoor($deliveryType);
        $recipientPhone = $this->deliveryAutoPhone((string)($shipment['RECIPIENT_PHONE'] ?? $shipment['CUSTOMER_PHONE'] ?? ''));
        $recipientAddress = trim((string)($shipment['ADDRESS_TEXT'] ?? $shipment['ORDER_DELIVERY_ADDRESS'] ?? ''));
        $recipientCityRef = $this->deliveryAutoCityRef($shipment);
        $recipientWarehouseRef = $this->deliveryAutoWarehouseRef($shipment, $recipientCityRef);

        
        
        
        
        
        $scheme = $isDoor ? (int)($cfg['scheme_courier'] ?? 2) : (int)($cfg['scheme_branch'] ?? 0);
        if (!$isDoor && $scheme === 2) {
            $scheme = 0; 
        }
        if ($isDoor && $scheme === 3) {
            $scheme = 2; 
        }

        $common = [
            'culture' => 'uk-UA',
            'debugMode' => !empty($cfg['test_mode']),
            'areasSendId' => (string)($sender['city_id'] ?? ''),
            'areasResiveId' => $recipientCityRef,
            'warehouseSendId' => (string)($sender['warehouse_id'] ?? ''),
            'dateSend' => date('Y-m-d\T00:00:00'),
            'deliveryScheme' => $scheme,
            'InsuranceValue' => max(1, (float)($shipment['TOTAL_AMOUNT'] ?? 1000)),
            'currency' => 100000000,
            'payerType' => 1,
            'paymentType' => 0,
            'paymentTypeInsuranse' => 0,
            'DeliveryComment' => (string)($shipment['MANAGER_COMMENT'] ?? ''),
        ];

        if (!$isDoor) {
            $common['warehouseResiveId'] = $recipientWarehouseRef;
        } else {
            $common['deliveryAddress'] = $recipientAddress;
            $common['deliveryContactName'] = trim((string)($shipment['RECIPIENT_NAME'] ?? $shipment['CUSTOMER_NAME'] ?? 'Одержувач RungoCraft'));
            $common['deliveryContactPhone'] = $recipientPhone;
        }

        $category = $this->deliveryAutoCargoCategory($shipment, $cfg, false);
        $common['category'] = [$category];

        if (!$create) {
            return $common;
        }

        return [
            'culture' => 'uk-UA',
            'flSave' => 'true',
            'debugMode' => !empty($cfg['test_mode']),
            'receiptsList' => [$this->deliveryAutoReceiptModel($shipment)],
        ];
    }

    




    private function deliveryAutoCreatePayloads(array $shipment): array
    {
        $root = [
            'culture' => 'uk-UA',
            'flSave' => 'true',
            'debugMode' => !empty(($this->config['delivery_auto'] ?? [])['test_mode']),
            'receiptsList' => [$this->deliveryAutoReceiptModel($shipment)],
        ];

        
        return [$root];
    }

    private function deliveryAutoReceiptModel(array $shipment): array
    {
        $cfg = (array)($this->config['delivery_auto'] ?? []);
        $sender = (array)($cfg['sender'] ?? []);
        $deliveryType = (string)($shipment['DELIVERY_TYPE'] ?? '');
        $isDoor = $this->isDeliveryAutoDoor($deliveryType);
        $recipientCityRef = $this->deliveryAutoCityRef($shipment);
        $recipientWarehouseRef = $this->deliveryAutoWarehouseRef($shipment, $recipientCityRef);
        $recipientPhone = $this->deliveryAutoPhone((string)($shipment['RECIPIENT_PHONE'] ?? $shipment['CUSTOMER_PHONE'] ?? ''));
        $senderPhone = $this->deliveryAutoPhone((string)($sender['contact_phone'] ?? '380937278561'));
        $recipientName = trim((string)($shipment['RECIPIENT_NAME'] ?? $shipment['CUSTOMER_NAME'] ?? ''));
        if ($recipientName === '') {
            $recipientName = 'Одержувач RungoCraft';
        }
        $recipientAddress = trim((string)($shipment['ADDRESS_TEXT'] ?? $shipment['ORDER_DELIVERY_ADDRESS'] ?? ''));
        $senderId = trim((string)($sender['sender_id'] ?? ''));
        if ($senderId === '') {
            
            
            $senderId = trim((string)($cfg['api_key'] ?? ''));
        }

        $scheme = $isDoor ? (int)($cfg['scheme_courier'] ?? 2) : (int)($cfg['scheme_branch'] ?? 0);
        if (!$isDoor && $scheme === 2) {
            $scheme = 0; 
        }
        if ($isDoor && $scheme === 3) {
            $scheme = 2; 
        }

        $receipt = [
            'senderid' => $senderId,
            'SenderId' => $senderId,
            'areasSendId' => (string)($sender['city_id'] ?? ''),
            'areasResiveId' => $recipientCityRef,
            'warehouseSendId' => (string)($sender['warehouse_id'] ?? ''),
            'dateSend' => date('Y-m-d\T00:00:00'),
            'deliveryScheme' => $scheme,
            'receiverName' => $recipientName,
            'receiverType' => false,
            'receiverPhone' => $recipientPhone,
            'currency' => 100000000,
            'InsuranceValue' => max(1, (float)($shipment['TOTAL_AMOUNT'] ?? 1000)),
            'payerType' => 1,
            'paymentType' => 0,
            'paymentTypeInsuranse' => 0,
            'payerInsuranceId' => $senderId,
            'DeliveryComment' => (string)($shipment['MANAGER_COMMENT'] ?? ''),
            'ReturnDocuments' => false,
            'climbingToFloor' => 0,
            'descentFromFloor' => 0,
            'EconomDelivery' => false,
            'EconomPickUp' => false,
            'ExpressPickUp' => false,
            'IsOverSize' => false,
            'IsGidrobort' => false,
            'cashOnDeliveryType' => 0,
            'CashOnDeliveryValuta' => 100000000,
            'CashOnDeliveryValue' => 0,
            'category' => [$this->deliveryAutoCargoCategory($shipment, $cfg, true)],
        ];

        if (!$isDoor) {
            $receipt['warehouseResiveId'] = $recipientWarehouseRef;
        } else {
            $receipt['deliveryAddress'] = $recipientAddress;
            $receipt['deliveryContactName'] = $recipientName;
            $receipt['deliveryContactPhone'] = $recipientPhone;
        }

        
        if (in_array($scheme, [1, 3], true)) {
            $receipt['pickUpDate'] = date('Y-m-d\T00:00:00');
            $receipt['pickUpContactName'] = (string)($sender['contact_name'] ?? 'RungoCraft');
            $receipt['pickUpContactPhone'] = $senderPhone;
            $receipt['pickUpAddress'] = (string)($sender['pickup_address'] ?? '');
        }

        return $receipt;
    }

    private function deliveryAutoCargoCategory(array $shipment, array $cfg, bool $forCreate): array
    {
        $weight = max(1, (float)($shipment['DECLARED_WEIGHT'] ?? 1));
        $volume = max(0.1, (float)($shipment['DECLARED_VOLUME'] ?? 0.1));
        $categoryId = trim((string)($cfg['default_category_id'] ?? '00000000-0000-0000-0000-000000000000'));
        if ($categoryId === '') {
            $categoryId = '00000000-0000-0000-0000-000000000000';
        }
        $cargoCategoryId = trim((string)($cfg['cargo_category_id'] ?? ''));
        if ($cargoCategoryId === '') {
            
            
            
            $cargoCategoryId = '0307d03b-9e36-e311-8b0d-00155d037960';
        }

        $category = [
            'categoryId' => $categoryId,
            'countPlace' => 1,
            'helf' => $weight,
            'size' => $volume,
        ];

        if ($forCreate) {
            $category['cargoCategoryId'] = $cargoCategoryId;
            $category['isEconom'] = false;
            $category['PartnerNumber'] = '';
        }

        return $category;
    }

    private function normalizeDeliveryAutoCategoryForCreate(array $category, array $cfg): array
    {
        $categoryId = trim((string)($cfg['default_category_id'] ?? ''));
        if ($categoryId === '' || $categoryId === '00000000-0000-0000-0000-000000000000') {
            unset($category['categoryId'], $category['CategoryId']);
        }

        $weight = (float)($category['helf'] ?? $category['weight'] ?? $category['Weight'] ?? 1);
        $volume = (float)($category['size'] ?? $category['volume'] ?? $category['Volume'] ?? 0.1);

        $clean = [
            'countPlace' => max(1, (int)($category['countPlace'] ?? 1)),
            'helf' => max(1, $weight),
            'size' => max(0.1, $volume),
        ];

        if ($categoryId !== '' && $categoryId !== '00000000-0000-0000-0000-000000000000') {
            $clean['categoryId'] = $categoryId;
            $clean['CategoryId'] = $categoryId;
        }

        return $clean;
    }

    private function shipment(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return Database::fetchOne(
            "SELECT ds.*, o.customer_name, o.customer_phone, o.customer_email, o.total_amount,
                    o.delivery_type AS order_delivery_type, o.delivery_address AS order_delivery_address,
                    o.status AS order_status
             FROM rc_delivery_shipments ds
             JOIN rc_orders o ON o.id = ds.order_id
             WHERE ds.id = :id",
            ['id' => $id]
        );
    }

    private function enrichDeliveryAutoShipmentRefs(array $shipment): array
    {
        $shipmentId = (int)($shipment['ID'] ?? 0);
        $cityRef = $this->deliveryAutoCityRef($shipment);
        $warehouseRef = $this->deliveryAutoWarehouseRef($shipment, $cityRef);

        $updates = [];
        $params = ['id' => $shipmentId];
        $columns = $this->columns('RC_DELIVERY_SHIPMENTS');

        if ($shipmentId > 0 && isset($columns['CITY_REF'])) {
            $current = trim((string)($shipment['CITY_REF'] ?? ''));
            if ($cityRef !== '' && ($current === '' || str_starts_with($current, 'manual:'))) {
                $updates[] = 'city_ref = :city_ref';
                $params['city_ref'] = $cityRef;
                $shipment['CITY_REF'] = $cityRef;
            }
        }

        if ($shipmentId > 0 && isset($columns['WAREHOUSE_REF'])) {
            $current = trim((string)($shipment['WAREHOUSE_REF'] ?? ''));
            if ($warehouseRef !== '' && ($current === '' || str_starts_with($current, 'manual:'))) {
                $updates[] = 'warehouse_ref = :warehouse_ref';
                $params['warehouse_ref'] = $warehouseRef;
                $shipment['WAREHOUSE_REF'] = $warehouseRef;
            }
        }

        if ($updates !== []) {
            $updates[] = 'updated_at = CURRENT_TIMESTAMP';
            Database::execute('UPDATE rc_delivery_shipments SET ' . implode(', ', $updates) . ' WHERE id = :id', $params);
        }

        return $shipment;
    }

    private function deliveryAutoCityRef(array $shipment): string
    {
        $current = trim((string)($shipment['CITY_REF'] ?? ''));
        if ($current !== '' && !str_starts_with($current, 'manual:')) {
            return $current;
        }

        $cityName = trim((string)($shipment['CITY'] ?? $shipment['DELIVERY_CITY'] ?? ''));
        if ($cityName === '') {
            return '';
        }

        $normalized = $this->normalizeText($cityName);
        if ($normalized === '') {
            return '';
        }

        
        
        
        $cfg = (array)($this->config['delivery_auto'] ?? []);
        $sender = (array)($cfg['sender'] ?? []);
        $senderCityId = trim((string)($sender['city_id'] ?? ''));
        if ($senderCityId !== '' && !str_starts_with($senderCityId, 'manual:') && str_contains($normalized, 'киив')) {
            return $senderCityId;
        }

        if ($this->tableExists('RC_DELIVERY_AUTO_CITIES')) {
            $row = Database::fetchOne(
                "SELECT ref
                 FROM rc_delivery_auto_cities
                 WHERE is_active = 1
                   AND (LOWER(name) = LOWER(:name) OR LOWER(name) LIKE LOWER(:prefix))
                 ORDER BY CASE WHEN LOWER(name) = LOWER(:name) THEN 0 ELSE 1 END, name
                 FETCH FIRST 1 ROWS ONLY",
                ['name' => $cityName, 'prefix' => $cityName . '%']
            );
            $ref = trim((string)($row['REF'] ?? ''));
            if ($ref !== '') {
                return $ref;
            }

            $rows = Database::fetchAll(
                "SELECT ref, name
                 FROM rc_delivery_auto_cities
                 WHERE is_active = 1
                 FETCH FIRST 1000 ROWS ONLY"
            );
            foreach ($rows as $row) {
                $name = trim((string)($row['NAME'] ?? ''));
                if ($name !== '' && $this->normalizeText($name) === $normalized) {
                    return trim((string)($row['REF'] ?? ''));
                }
            }
        }

        if (class_exists('DeliveryDirectoryService')) {
            try {
                $directory = new DeliveryDirectoryService();
                $result = $directory->deliveryAutoCities($cityName, 20);
                foreach ((array)($result['items'] ?? []) as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $ref = trim((string)($item['ref'] ?? ''));
                    $name = trim((string)($item['name'] ?? ''));
                    if ($ref !== '' && !str_starts_with($ref, 'manual:') && ($this->normalizeText($name) === $normalized || $name === '')) {
                        return $ref;
                    }
                }
                foreach ((array)($result['items'] ?? []) as $item) {
                    if (is_array($item)) {
                        $ref = trim((string)($item['ref'] ?? ''));
                        if ($ref !== '' && !str_starts_with($ref, 'manual:')) {
                            return $ref;
                        }
                    }
                }
            } catch (Throwable) {
                return '';
            }
        }

        return '';
    }

    private function deliveryAutoWarehouseRef(array $shipment, string $cityRef = ''): string
    {
        $current = trim((string)($shipment['WAREHOUSE_REF'] ?? ''));
        if ($current !== '' && !str_starts_with($current, 'manual:')) {
            return $current;
        }

        if ($cityRef === '') {
            $cityRef = $this->deliveryAutoCityRef($shipment);
        }
        if ($cityRef === '' || str_starts_with($cityRef, 'manual:')) {
            return '';
        }

        $warehouseName = trim((string)($shipment['WAREHOUSE'] ?? ''));
        if ($warehouseName === '') {
            return '';
        }

        $normalized = $this->normalizeText($warehouseName);

        if ($this->tableExists('RC_DELIVERY_AUTO_WAREHOUSES')) {
            $row = Database::fetchOne(
                "SELECT ref
                 FROM rc_delivery_auto_warehouses
                 WHERE is_active = 1
                   AND city_ref = :city_ref
                   AND (LOWER(name) = LOWER(:name) OR LOWER(NVL(address_text, '')) = LOWER(:name)
                        OR LOWER(name) LIKE LOWER(:like_name) OR LOWER(NVL(address_text, '')) LIKE LOWER(:like_name))
                 ORDER BY CASE WHEN LOWER(name) = LOWER(:name) THEN 0 ELSE 1 END, name
                 FETCH FIRST 1 ROWS ONLY",
                ['city_ref' => $cityRef, 'name' => $warehouseName, 'like_name' => '%' . $warehouseName . '%']
            );
            $ref = trim((string)($row['REF'] ?? ''));
            if ($ref !== '') {
                return $ref;
            }

            $rows = Database::fetchAll(
                "SELECT ref, name, address_text
                 FROM rc_delivery_auto_warehouses
                 WHERE is_active = 1 AND city_ref = :city_ref
                 FETCH FIRST 1000 ROWS ONLY",
                ['city_ref' => $cityRef]
            );
            foreach ($rows as $row) {
                $haystack = $this->normalizeText((string)($row['NAME'] ?? '') . ' ' . (string)($row['ADDRESS_TEXT'] ?? ''));
                if ($haystack !== '' && ($haystack === $normalized || str_contains($haystack, $normalized) || str_contains($normalized, $haystack))) {
                    return trim((string)($row['REF'] ?? ''));
                }
            }
        }

        if (class_exists('DeliveryDirectoryService')) {
            try {
                $directory = new DeliveryDirectoryService();
                $result = $directory->deliveryAutoWarehouses($cityRef, 200, $warehouseName);
                foreach ((array)($result['items'] ?? []) as $item) {
                    if (is_array($item)) {
                        $ref = trim((string)($item['ref'] ?? ''));
                        if ($ref !== '' && !str_starts_with($ref, 'manual:')) {
                            return $ref;
                        }
                    }
                }
            } catch (Throwable) {
                return '';
            }
        }

        return '';
    }

    private function normalizeText(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        $value = str_replace(['’', "'", '`', 'ʼ'], '', $value);
        $value = str_replace(['ё', 'і', 'ї', 'є'], ['е', 'и', 'и', 'е'], $value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function isDeliveryAutoDoor(string $deliveryType): bool
    {
        return in_array($deliveryType, ['delivery_auto_courier', 'delivery_auto_door'], true);
    }

    private function deliveryAutoPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 12 && str_starts_with($digits, '380')) {
            return '0' . substr($digits, 3);
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '80')) {
            return '0' . substr($digits, 2);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            return $digits;
        }
        return $digits;
    }

    private function deliveryMethodBasePrice(string $deliveryType): ?float
    {
        if ($deliveryType === '') {
            return null;
        }
        if ($this->tableExists('RC_DELIVERY_METHODS')) {
            $row = Database::fetchOne(
                "SELECT base_price FROM rc_delivery_methods WHERE code = :code FETCH FIRST 1 ROWS ONLY",
                ['code' => $deliveryType]
            );
            if ($row && $row['BASE_PRICE'] !== null && $row['BASE_PRICE'] !== '') {
                return (float)$row['BASE_PRICE'];
            }
        }
        return match ($deliveryType) {
            'delivery_auto_branch', 'delivery_auto_warehouse' => 120.0,
            'delivery_auto_courier', 'delivery_auto_door' => 180.0,
            default => null,
        };
    }

    private function updateShipmentEstimatedPrice(int $shipmentId, float $price, array $payload = []): void
    {
        $columns = $this->columns('RC_DELIVERY_SHIPMENTS');
        $sets = [];
        $params = ['id' => $shipmentId];

        if (isset($columns['ESTIMATED_PRICE'])) {
            $sets[] = 'estimated_price = :price';
            $params['price'] = $price;
        }
        if ($payload !== [] && isset($columns['PROVIDER_PAYLOAD'])) {
            $sets[] = 'provider_payload = :payload';
            $params['payload'] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        if (isset($columns['UPDATED_AT'])) {
            $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        }
        if ($sets === []) {
            return;
        }

        Database::execute('UPDATE rc_delivery_shipments SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
    }

    private function updateShipmentProvider(int $shipmentId, string $provider, string $ttn, string $status, array $payload): void
    {
        Database::execute(
            "UPDATE rc_delivery_shipments
             SET carrier_code = :provider,
                 provider_ref = :provider_ref,
                 ttn = :ttn,
                 delivery_status = :status,
                 provider_payload = :payload,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            [
                'provider' => $provider,
                'provider_ref' => $ttn,
                'ttn' => $ttn,
                'status' => $status,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'id' => $shipmentId,
            ]
        );
        Database::execute(
            "UPDATE rc_orders
             SET delivery_ttn = :ttn,
                 delivery_status = :status,
                 status = CASE WHEN status IN ('packed','ready_for_delivery') THEN 'sent' ELSE status END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = (SELECT order_id FROM rc_delivery_shipments WHERE id = :id)",
            ['ttn' => $ttn, 'status' => $status, 'id' => $shipmentId]
        );
    }

    private function updateShipmentTracking(int $shipmentId, string $provider, string $status, array $payload): void
    {
        Database::execute(
            "UPDATE rc_delivery_shipments
             SET carrier_code = :provider,
                 provider_tracking_status = :tracking_status,
                 delivery_status = CASE WHEN delivery_status = 'pending' THEN 'in_transit' ELSE delivery_status END,
                 provider_payload = :payload,
                 last_tracking_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            [
                'provider' => $provider,
                'tracking_status' => $status,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'id' => $shipmentId,
            ]
        );
    }

    private function updatePaymentByOrder(int $orderId, string $provider, string $status, string $transactionRef, array $payload): void
    {
        if ($orderId <= 0) {
            return;
        }

        $paymentColumns = $this->columns('RC_PAYMENTS');
        $sets = [];
        $params = ['order_id' => $orderId];

        if (isset($paymentColumns['PROVIDER'])) {
            $sets[] = 'provider = :provider';
            $params['provider'] = $provider;
        }
        if (isset($paymentColumns['STATUS'])) {
            $sets[] = 'status = :status';
            $params['status'] = $status;
        }
        if (isset($paymentColumns['TRANSACTION_REF'])) {
            $sets[] = 'transaction_ref = COALESCE(:transaction_ref, transaction_ref)';
            $params['transaction_ref'] = $transactionRef !== '' ? $transactionRef : null;
        }
        if (isset($paymentColumns['GATEWAY_STATUS'])) {
            $sets[] = 'gateway_status = :gateway_status';
            $params['gateway_status'] = $status;
        }
        if (isset($paymentColumns['GATEWAY_RESPONSE'])) {
            $sets[] = 'gateway_response = :gateway_response';
            $params['gateway_response'] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        if (isset($paymentColumns['GATEWAY_CALLBACK_AT'])) {
            $sets[] = 'gateway_callback_at = CURRENT_TIMESTAMP';
        }
        if (isset($paymentColumns['PAID_AT'])) {
            $sets[] = "paid_at = CASE WHEN :paid_status = 'paid' THEN COALESCE(paid_at, CURRENT_TIMESTAMP) ELSE paid_at END";
            $params['paid_status'] = $status;
        }
        if (isset($paymentColumns['UPDATED_AT'])) {
            $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        }
        if ($sets !== []) {
            Database::execute('UPDATE rc_payments SET ' . implode(', ', $sets) . ' WHERE order_id = :order_id', $params);
        }

        $orderColumns = $this->columns('RC_ORDERS');
        $orderSets = [];
        $orderParams = ['id' => $orderId, 'status_for_order' => $status];

        if (isset($orderColumns['PAYMENT_STATUS'])) {
            $orderSets[] = 'payment_status = :payment_status';
            $orderParams['payment_status'] = $status;
        }
        if (isset($orderColumns['PAYMENT_PROVIDER'])) {
            $orderSets[] = 'payment_provider = :payment_provider';
            $orderParams['payment_provider'] = $provider;
        }
        if (isset($orderColumns['PAYMENT_REFERENCE'])) {
            $orderSets[] = 'payment_reference = :payment_reference';
            $orderParams['payment_reference'] = $transactionRef !== '' ? $transactionRef : null;
        }
        if (isset($orderColumns['STATUS'])) {
            $orderSets[] = "status = CASE WHEN :status_for_order = 'paid' AND status IN ('created','waiting_payment','waiting_confirmation','confirmed') THEN 'paid' ELSE status END";
        }
        if (isset($orderColumns['UPDATED_AT'])) {
            $orderSets[] = 'updated_at = CURRENT_TIMESTAMP';
        }
        if ($orderSets !== []) {
            Database::execute('UPDATE rc_orders SET ' . implode(', ', $orderSets) . ' WHERE id = :id', $orderParams);
        }
    }

    private function shipmentsForIntegration(): array
    {
        if (!$this->tableExists('RC_DELIVERY_SHIPMENTS')) {
            return [];
        }
        return Database::fetchAll(
            "SELECT ds.*, o.customer_name, o.customer_phone, o.total_amount
             FROM rc_delivery_shipments ds
             JOIN rc_orders o ON o.id = ds.order_id
             ORDER BY ds.created_at DESC, ds.id DESC
             FETCH FIRST 60 ROWS ONLY"
        );
    }

    private function paymentsForIntegration(): array
    {
        if (!$this->tableExists('RC_PAYMENTS')) {
            return [];
        }
        return Database::fetchAll(
            "SELECT p.*, o.customer_name, o.customer_phone
             FROM rc_payments p
             LEFT JOIN rc_orders o ON o.id = p.order_id
             ORDER BY p.created_at DESC, p.id DESC
             FETCH FIRST 60 ROWS ONLY"
        );
    }

    private function integrationStatus(): array
    {
        return [
            'nova_poshta' => [
                'name' => 'Нова пошта',
                'enabled' => $this->isEnabled('nova_poshta.enabled'),
                'configured' => $this->isNovaPoshtaConfigured(),
            ],
            'delivery_auto' => [
                'name' => 'Delivery Auto',
                'enabled' => $this->isEnabled('delivery_auto.enabled'),
                'configured' => $this->isDeliveryAutoConfigured(),
            ],
            'liqpay' => [
                'name' => 'LiqPay',
                'enabled' => $this->isEnabled('payments.liqpay.enabled'),
                'configured' => (string)($this->config['payments']['liqpay']['public_key'] ?? '') !== '' && (string)($this->config['payments']['liqpay']['private_key'] ?? '') !== '',
            ],
            'wayforpay' => [
                'name' => 'WayForPay',
                'enabled' => $this->isEnabled('payments.wayforpay.enabled'),
                'configured' => (string)($this->config['payments']['wayforpay']['merchant_account'] ?? '') !== '' && (string)($this->config['payments']['wayforpay']['merchant_secret_key'] ?? '') !== '',
            ],
        ];
    }

    private function isNovaPoshtaConfigured(): bool
    {
        $np = (array)($this->config['nova_poshta'] ?? []);
        $sender = (array)($np['sender'] ?? []);
        foreach (['api_key'] as $key) {
            if (trim((string)($np[$key] ?? '')) === '') {
                return false;
            }
        }
        foreach (['sender_ref', 'contact_sender_ref', 'sender_city_ref', 'sender_warehouse_ref'] as $key) {
            if (trim((string)($sender[$key] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    private function isDeliveryAutoConfigured(): bool
    {
        $cfg = (array)($this->config['delivery_auto'] ?? []);
        $sender = (array)($cfg['sender'] ?? []);
        foreach (['api_key', 'secret_key'] as $key) {
            if (trim((string)($cfg[$key] ?? '')) === '') {
                return false;
            }
        }
        foreach (['city_id', 'warehouse_id'] as $key) {
            if (trim((string)($sender[$key] ?? '')) === '') {
                return false;
            }
        }
        return empty($cfg['test_mode']);
    }

    private function lastLogs(): array
    {
        if (!$this->tableExists('RC_EXTERNAL_API_LOGS')) {
            return [];
        }
        return Database::fetchAll(
            "SELECT provider, action_name, is_success, status_code, message, payload, created_at
             FROM rc_external_api_logs
             ORDER BY created_at DESC, id DESC
             FETCH FIRST 40 ROWS ONLY"
        );
    }

    private function logExternal(string $provider, string $action, bool $success, array $payload): void
    {
        if (!$this->tableExists('RC_EXTERNAL_API_LOGS')) {
            return;
        }
        Database::execute(
            "INSERT INTO rc_external_api_logs (provider, action_name, is_success, status_code, message, payload)
             VALUES (:provider, :action_name, :is_success, :status_code, :message, :payload)",
            [
                'provider' => $provider,
                'action_name' => $action,
                'is_success' => $success ? 1 : 0,
                'status_code' => (int)($payload['status'] ?? 0),
                'message' => $success ? 'OK' : 'API error',
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    private function loadConfig(): array
    {
        $example = __DIR__ . '/../config/integrations.example.php';
        $local = __DIR__ . '/../config/integrations.php';
        $config = file_exists($example) ? require $example : [];
        if (file_exists($local)) {
            $config = array_replace_recursive($config, require $local);
        }
        return is_array($config) ? $config : [];
    }

    private function isEnabled(string $path): bool
    {
        $value = $this->configValue($path);
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private function configValue(string $path): mixed
    {
        $value = $this->config;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }

    private function columns(string $table): array
    {
        $rows = Database::fetchAll(
            'SELECT column_name FROM user_tab_columns WHERE table_name = :table_name',
            ['table_name' => strtoupper($table)]
        );
        $columns = [];
        foreach ($rows as $row) {
            $columns[(string)$row['COLUMN_NAME']] = true;
        }
        return $columns;
    }

    private function tableExists(string $table): bool
    {
        $row = Database::fetchOne('SELECT COUNT(*) AS cnt FROM user_tables WHERE table_name = :table_name', ['table_name' => strtoupper($table)]);
        return (int)($row['CNT'] ?? 0) > 0;
    }

    private function canManage(?array $user): bool
    {
        return in_array((string)($user['role'] ?? ''), ['admin', 'manager'], true);
    }

    private function publicUrl(string $path): string
    {
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        $env = trim((string)getenv('APP_PUBLIC_URL'));
        $base = $env !== '' ? rtrim($env, '/') : rtrim((string)($this->config['app']['public_url'] ?? ''), '/');
        $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $requestScheme = $forwardedProto !== '' ? $forwardedProto : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
        $host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000')) ?: '127.0.0.1';

        
        
        if ($base === '' || ($requestScheme === 'http' && str_starts_with($base, 'https://'))) {
            $base = $requestScheme . '://' . $host;
        }
        return $base . $path;
    }

    private function httpStatus(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) {
                return (int)$m[1];
            }
        }
        return 0;
    }

    private function maskSecrets(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string)$key), ['apikey', 'api_key', 'private_key', 'secret_key', 'merchant_secret_key'], true)) {
                $payload[$key] = '***';
            } elseif (is_array($value)) {
                $payload[$key] = $this->maskSecrets($value);
            }
        }
        return $payload;
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '0')) {
            $digits = '38' . $digits;
        }
        return $digits;
    }

    private function mapLiqPayStatus(string $status): string
    {
        return match ($status) {
            'success', 'sandbox' => 'paid',
            'failure', 'error', 'reversed' => 'failed',
            'subscribed', 'wait_accept', 'wait_secure', 'wait_lc' => 'pending',
            default => $status !== '' ? $status : 'pending',
        };
    }

    private function mapWayForPayStatus(string $status): string
    {
        return match ($status) {
            'Approved' => 'paid',
            'Declined', 'Expired', 'Refunded', 'Voided' => 'failed',
            'InProcessing', 'Pending' => 'pending',
            default => $status !== '' ? strtolower($status) : 'pending',
        };
    }

    private function wayForPayCallbackSignature(string $orderReference, string $status, int $time): string
    {
        $secret = (string)($this->config['payments']['wayforpay']['merchant_secret_key'] ?? '');
        return hash_hmac('md5', implode(';', [$orderReference, $status, $time]), $secret);
    }

    private function extractApiErrors(array $decoded): string
    {
        $candidates = [];
        foreach (['errors', 'Errors', 'error', 'Error', 'message', 'Message', 'messages', 'Messages', 'errorDescription', 'ErrorDescription'] as $key) {
            if (isset($decoded[$key])) {
                $candidates[] = $decoded[$key];
            }
        }
        $flat = [];
        $walk = static function ($value) use (&$walk, &$flat): void {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $walk($item);
                }
                return;
            }
            $text = trim((string)$value);
            if ($text !== '') {
                $flat[] = $text;
            }
        };
        foreach ($candidates as $candidate) {
            $walk($candidate);
        }
        $flat = array_values(array_unique($flat));
        return implode(', ', array_slice($flat, 0, 8));
    }

    private function extractDeliveryAutoPrice(array $data): ?float
    {
        $value = $this->findFirstRecursive($data, [
            'Cost', 'cost', 'price', 'Price', 'totalCost', 'TotalCost', 'deliveryCost', 'DeliveryCost',
            'deliveryPrice', 'DeliveryPrice', 'costDelivery', 'CostDelivery', 'amount', 'Amount',
            'sum', 'Sum', 'total', 'Total', 'tariff', 'Tariff', 'tariffCost', 'TariffCost',
        ]);
        if (is_string($value)) {
            $value = str_replace([' ', ','], ['', '.'], $value);
        }
        return is_numeric($value) ? (float)$value : null;
    }

    private function extractDeliveryAutoTtn(array $data): string
    {
        $value = $this->findFirstRecursive($data, [
            'receiptNumber', 'ReceiptNumber', 'receipt_number', 'receiptNum', 'ReceiptNum',
            'declarationNumber', 'DeclarationNumber', 'declaration_number',
            'ttn', 'TTN', 'waybill', 'Waybill', 'waybillNumber', 'WaybillNumber',
            'trackingNumber', 'TrackingNumber', 'documentNumber', 'DocumentNumber',
            'deliveryNumber', 'DeliveryNumber', 'publicNumber', 'PublicNumber',
            'number', 'Number',
        ]);
        $text = trim((string)$value);
        if ($text !== '') {
            return $text;
        }

        return $this->findDeliveryAutoNumberLikeValue($data);
    }

    private function extractDeliveryAutoReceiptId(array $data): string
    {
        $value = $this->findFirstRecursive($data, [
            'receiptId', 'ReceiptId', 'receipt_id', 'declarationId', 'DeclarationId',
            'documentId', 'DocumentId', 'idReceipt', 'IdReceipt', 'id', 'Id', 'ID',
        ]);
        return trim((string)$value);
    }

    private function findDeliveryAutoNumberLikeValue(array $data): string
    {
        $found = '';
        $walk = static function (mixed $value) use (&$walk, &$found): void {
            if ($found !== '') {
                return;
            }
            if (is_array($value)) {
                foreach ($value as $nested) {
                    $walk($nested);
                    if ($found !== '') {
                        return;
                    }
                }
                return;
            }
            $text = trim((string)$value);
            if ($text === '') {
                return;
            }
            if (preg_match('/\bDA[-A-Z0-9]{6,}\b/u', $text, $m)) {
                $found = $m[0];
                return;
            }
            if (preg_match('/\b\d{10,18}\b/u', $text, $m)) {
                $found = $m[0];
            }
        };
        $walk($data);
        return $found;
    }

    private function extractDeliveryAutoStatus(array $data): string
    {
        $value = $this->findFirstRecursive($data, ['status', 'Status', 'state', 'State', 'receiptStatus', 'ReceiptStatus', 'description', 'Description']);
        return trim((string)$value);
    }

    private function findFirstRecursive(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && !is_array($data[$key]) && trim((string)$data[$key]) !== '') {
                return $data[$key];
            }
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->findFirstRecursive($value, $keys);
                if (!is_array($found) && trim((string)$found) !== '') {
                    return $found;
                }
            }
        }
        return null;
    }
}
