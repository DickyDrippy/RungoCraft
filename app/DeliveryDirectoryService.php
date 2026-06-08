<?php
declare(strict_types=1);

final class DeliveryDirectoryService
{
    private array $config;

    public function __construct()
    {
        $this->config = $this->loadConfig();
    }

    public function api(array $query): array
    {
        $carrier = strtolower(trim((string)($query['carrier'] ?? $query['provider'] ?? '')));
        $type = strtolower(trim((string)($query['type'] ?? $query['action'] ?? '')));
        $q = trim((string)($query['q'] ?? $query['query'] ?? ''));
        $cityRef = trim((string)($query['city_ref'] ?? $query['cityRef'] ?? ''));
        $limit = max(1, min((int)($query['limit'] ?? 30), 1000));

        if ($carrier === 'np') {
            $carrier = 'nova_poshta';
        }

        if (!in_array($carrier, ['nova_poshta', 'delivery_auto'], true)) {
            return $this->result(false, 'Невідомий перевізник.', []);
        }

        if ($type === 'cities') {
            $result = $carrier === 'nova_poshta'
                ? $this->novaPoshtaCities($q, $limit)
                : $this->deliveryAutoCities($q, $limit);

            return $this->normalizeResult($result);
        }

        if ($type === 'warehouses') {
            if ($cityRef === '') {
                return $this->result(false, 'Оберіть місто.', []);
            }

            $result = $carrier === 'nova_poshta'
                ? $this->novaPoshtaWarehouses($cityRef, $limit, $q)
                : $this->deliveryAutoWarehouses($cityRef, $limit, $q);

            return $this->normalizeResult($result);
        }

        return $this->result(false, 'Невідомий тип довідника.', []);
    }

    public function novaPoshtaCities(string $q, int $limit = 30): array
    {
        $items = $this->localNovaPoshtaCities($q, $limit);
        if (count($items) >= min(5, $limit) || $q === '') {
            return ['ok' => true, 'source' => 'local', 'items' => $items];
        }

        $response = $this->novaPoshtaRequest('Address', 'getCities', [
            'FindByString' => $q,
            'Limit' => (string)$limit,
            'Page' => '1',
        ]);

        if (!empty($response['ok'])) {
            foreach (($response['data']['data'] ?? []) as $city) {
                $this->upsertNovaCity($city);
            }
            $items = $this->localNovaPoshtaCities($q, $limit);
            return ['ok' => true, 'source' => 'api', 'items' => $items];
        }

        return ['ok' => !empty($items), 'message' => $response['message'] ?? 'Міста не знайдено.', 'source' => 'local', 'items' => $items];
    }

    public function novaPoshtaWarehouses(string $cityRef, int $limit = 2000, string $q = ''): array
    {
        $limit = max(50, min($limit, 3000));
        $q = trim($q);

        $items = $this->localNovaPoshtaWarehouses($cityRef, $limit, $q);

        
        
        $shouldRefreshFromApi = $q !== '' || count($items) < min(300, $limit);

        if (!$shouldRefreshFromApi) {
            return ['ok' => true, 'source' => 'local', 'items' => $items];
        }

        $pageLimit = 500;
        $loaded = 0;
        $maxPages = $q !== '' ? 3 : 8;

        for ($page = 1; $page <= $maxPages && $loaded < $limit; $page++) {
            $props = [
                'CityRef' => $cityRef,
                'Limit' => (string)$pageLimit,
                'Page' => (string)$page,
            ];

            if ($q !== '') {
                $props['FindByString'] = $q;
            }

            $response = $this->novaPoshtaRequest('Address', 'getWarehouses', $props);

            if (empty($response['ok'])) {
                return [
                    'ok' => !empty($items),
                    'message' => $response['message'] ?? 'Відділення не знайдено.',
                    'source' => 'local',
                    'items' => $items,
                ];
            }

            $rows = $response['data']['data'] ?? [];
            if (empty($rows) || !is_array($rows)) {
                break;
            }

            foreach ($rows as $warehouse) {
                if (is_array($warehouse)) {
                    $this->upsertNovaWarehouse($cityRef, $warehouse);
                    $loaded++;
                }
            }

            if (count($rows) < $pageLimit) {
                break;
            }
        }

        $items = $this->localNovaPoshtaWarehouses($cityRef, $limit, $q);
        return ['ok' => true, 'source' => 'api', 'items' => $items];
    }

    public function deliveryAutoCities(string $q, int $limit = 30): array
    {
        $q = trim($q);
        $items = $this->localDeliveryAutoCities($q, $limit);

        if (!empty($items)) {
            return ['ok' => true, 'source' => 'local', 'items' => $items];
        }

        $response = $this->deliveryAutoDirectoryRequest('cities', ['culture' => 'uk-UA']);
        if (!empty($response['ok'])) {
            foreach ($this->extractDirectoryItems($response['data']) as $city) {
                if (is_array($city)) {
                    $this->upsertDeliveryAutoCity($city);
                }
            }
            $items = $this->localDeliveryAutoCities($q, $limit);
            if (!empty($items)) {
                return ['ok' => true, 'source' => 'api', 'items' => $items];
            }
        }

        
        
        if ($q !== '') {
            return [
                'ok' => true,
                'source' => 'manual',
                'message' => ($response['message'] ?? 'Довідник Delivery Auto недоступний') . '. Місто буде збережено вручну, реальну квитанцію треба створювати після вибору ID з довідника.',
                'items' => [[
                    'kind' => 'city',
                    'ref' => 'manual:' . $q,
                    'name' => $q,
                    'area' => '',
                    'label' => $q . ' — ввести вручну',
                    'manual' => true,
                ]],
            ];
        }

        return ['ok' => true, 'message' => 'Почніть вводити місто.', 'source' => 'manual', 'items' => []];
    }

    public function deliveryAutoWarehouses(string $cityRef, int $limit = 500, string $q = ''): array
    {
        $q = trim($q);

        if (str_starts_with($cityRef, 'manual:')) {
            $manualLabel = $q !== '' ? $q : 'Введіть відділення або адресу Delivery вручну';
            return [
                'ok' => true,
                'source' => 'manual',
                'message' => 'Довідник Delivery Auto недоступний, відділення можна ввести вручну.',
                'items' => $q !== '' ? [[
                    'kind' => 'warehouse',
                    'ref' => 'manual:' . $q,
                    'name' => $manualLabel,
                    'address' => '',
                    'label' => $manualLabel . ' — ввести вручну',
                    'manual' => true,
                ]] : [],
            ];
        }

        $items = $this->localDeliveryAutoWarehouses($cityRef, $limit, $q);
        if (count($items) > 0 && $q !== '') {
            return ['ok' => true, 'source' => 'local', 'items' => $items];
        }

        if (count($items) >= 20 && $q === '') {
            return ['ok' => true, 'source' => 'local', 'items' => $items];
        }

        $response = $this->deliveryAutoDirectoryRequest('warehouses', [
            'culture' => 'uk-UA',
            'areasId' => $cityRef,
            'areaId' => $cityRef,
            'cityId' => $cityRef,
        ]);

        if (!empty($response['ok'])) {
            foreach ($this->extractDirectoryItems($response['data']) as $warehouse) {
                if (is_array($warehouse)) {
                    $this->upsertDeliveryAutoWarehouse($cityRef, $warehouse);
                }
            }
            $items = $this->localDeliveryAutoWarehouses($cityRef, $limit, $q);
            return ['ok' => true, 'source' => 'api', 'items' => $items];
        }

        $manualItems = [];
        if ($q !== '') {
            $manualItems[] = [
                'kind' => 'warehouse',
                'ref' => 'manual:' . $q,
                'name' => $q,
                'address' => '',
                'label' => $q . ' — ввести вручну',
                'manual' => true,
            ];
        }

        return [
            'ok' => true,
            'source' => 'manual',
            'message' => $response['message'] ?? 'Довідник відділень Delivery недоступний, введіть відділення вручну.',
            'items' => $manualItems,
        ];
    }

    private function normalizeResult(array $result): array
    {
        $ok = (bool)($result['ok'] ?? $result['success'] ?? false);
        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        return [
            'ok' => $ok,
            'success' => $ok,
            'source' => (string)($result['source'] ?? ''),
            'message' => (string)($result['message'] ?? ($ok ? 'OK' : 'Помилка довідника доставки.')),
            'items' => $items,
        ];
    }

    private function result(bool $ok, string $message, array $items): array
    {
        return [
            'ok' => $ok,
            'success' => $ok,
            'message' => $message,
            'items' => $items,
        ];
    }

    private function localNovaPoshtaCities(string $q, int $limit): array
    {
        $where = 'is_active = 1';
        $params = ['limit' => $limit];
        if ($q !== '') {
            $where .= ' AND LOWER(name) LIKE LOWER(:q)';
            $params['q'] = '%' . $q . '%';
        }

        $binds = $params + [
            'exact_q' => $q !== '' ? $q : '',
            'prefix_q' => $q !== '' ? $q . '%' : '%',
        ];

        $rows = Database::fetchAll(
            "SELECT ref, name, area_name
             FROM rc_np_cities
             WHERE {$where}
               AND LOWER(ref) NOT IN ('kyiv', 'test-kyiv')
             ORDER BY
               CASE
                 WHEN LOWER(name) = LOWER(:exact_q) THEN 0
                 WHEN LOWER(name) LIKE LOWER(:prefix_q) THEN 1
                 ELSE 2
               END,
               name
             FETCH FIRST {$limit} ROWS ONLY",
            $binds
        );

        return array_map(static fn(array $r): array => [
            'kind' => 'city',
            'ref' => (string)$r['REF'],
            'name' => (string)$r['NAME'],
            'area' => (string)($r['AREA_NAME'] ?? ''),
            'label' => trim((string)$r['NAME'] . (!empty($r['AREA_NAME']) ? ', ' . (string)$r['AREA_NAME'] : '')),
        ], $rows);
    }

    private function localNovaPoshtaWarehouses(string $cityRef, int $limit, string $q = ''): array
    {
        $where = 'is_active = 1 AND city_ref = :city_ref';
        $params = [
            'city_ref' => $cityRef,
            'exact_q' => $q,
            'prefix_q' => $q !== '' ? $q . '%' : '%',
        ];

        if ($q !== '') {
            $where .= " AND (LOWER(name) LIKE LOWER(:q) OR LOWER(NVL(address_text, '')) LIKE LOWER(:q))";
            $params['q'] = '%' . $q . '%';
        }

        $rows = Database::fetchAll(
            "SELECT ref, name, address_text
             FROM rc_np_warehouses
             WHERE {$where}
             ORDER BY
               CASE
                 WHEN :exact_q IS NOT NULL AND LOWER(name) = LOWER(:exact_q) THEN 0
                 WHEN :exact_q IS NOT NULL AND LOWER(name) LIKE LOWER(:prefix_q) THEN 1
                 WHEN :exact_q IS NOT NULL AND LOWER(NVL(address_text, '')) LIKE LOWER(:prefix_q) THEN 2
                 ELSE 3
               END,
               sort_order,
               name
             FETCH FIRST {$limit} ROWS ONLY",
            $params
        );

        return array_map(static fn(array $r): array => [
            'kind' => 'warehouse',
            'ref' => (string)$r['REF'],
            'name' => (string)$r['NAME'],
            'address' => (string)($r['ADDRESS_TEXT'] ?? ''),
            'label' => trim((string)$r['NAME'] . (!empty($r['ADDRESS_TEXT']) ? ' — ' . (string)$r['ADDRESS_TEXT'] : '')),
        ], $rows);
    }

    private function localDeliveryAutoCities(string $q, int $limit): array
    {
        if (!$this->tableExists('RC_DELIVERY_AUTO_CITIES')) {
            return [];
        }

        $where = 'is_active = 1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND LOWER(name) LIKE LOWER(:q)';
            $params['q'] = '%' . $q . '%';
        }

        $binds = $params + [
            'exact_q' => $q !== '' ? $q : '',
            'prefix_q' => $q !== '' ? $q . '%' : '%',
        ];

        $rows = Database::fetchAll(
            "SELECT ref, name, region_name
             FROM rc_delivery_auto_cities
             WHERE {$where}
             ORDER BY
               CASE
                 WHEN LOWER(name) = LOWER(:exact_q) THEN 0
                 WHEN LOWER(name) LIKE LOWER(:prefix_q) THEN 1
                 ELSE 2
               END,
               name
             FETCH FIRST {$limit} ROWS ONLY",
            $binds
        );

        return array_map(static fn(array $r): array => [
            'kind' => 'city',
            'ref' => (string)$r['REF'],
            'name' => (string)$r['NAME'],
            'area' => (string)($r['REGION_NAME'] ?? ''),
            'label' => trim((string)$r['NAME'] . (!empty($r['REGION_NAME']) ? ', ' . (string)$r['REGION_NAME'] : '')),
        ], $rows);
    }

    private function localDeliveryAutoWarehouses(string $cityRef, int $limit, string $q = ''): array
    {
        if (!$this->tableExists('RC_DELIVERY_AUTO_WAREHOUSES')) {
            return [];
        }

        $where = 'is_active = 1 AND city_ref = :city_ref';
        $params = ['city_ref' => $cityRef];

        if ($q !== '') {
            $where .= " AND (LOWER(name) LIKE LOWER(:q) OR LOWER(NVL(address_text, '')) LIKE LOWER(:q))";
            $params['q'] = '%' . $q . '%';
        }

        $rows = Database::fetchAll(
            "SELECT ref, name, address_text
             FROM rc_delivery_auto_warehouses
             WHERE {$where}
             ORDER BY name
             FETCH FIRST {$limit} ROWS ONLY",
            $params
        );

        return array_map(static fn(array $r): array => [
            'kind' => 'warehouse',
            'ref' => (string)$r['REF'],
            'name' => (string)$r['NAME'],
            'address' => (string)($r['ADDRESS_TEXT'] ?? ''),
            'label' => trim((string)$r['NAME'] . (!empty($r['ADDRESS_TEXT']) ? ' — ' . (string)$r['ADDRESS_TEXT'] : '')),
        ], $rows);
    }

    private function upsertNovaCity(array $city): void
    {
        $ref = (string)($city['Ref'] ?? '');
        $name = (string)($city['Description'] ?? $city['DescriptionRu'] ?? '');
        if ($ref === '' || $name === '') {
            return;
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
                'area_name' => (string)($city['AreaDescription'] ?? $city['AreaDescriptionRu'] ?? ''),
            ]
        );
    }

    private function upsertNovaWarehouse(string $cityRef, array $warehouse): void
    {
        $ref = (string)($warehouse['Ref'] ?? '');
        $name = (string)($warehouse['Description'] ?? $warehouse['DescriptionRu'] ?? '');
        if ($ref === '' || $name === '') {
            return;
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
    }

    private function upsertDeliveryAutoCity(array $city): void
    {
        if (!$this->tableExists('RC_DELIVERY_AUTO_CITIES')) {
            return;
        }

        $ref = $this->firstString($city, ['id', 'Id', 'ID', 'ref', 'Ref', 'areasId', 'AreasId', 'cityId', 'CityId']);
        $name = $this->firstString($city, ['name', 'Name', 'description', 'Description', 'cityName', 'CityName']);
        if ($ref === '' || $name === '') {
            return;
        }

        $region = $this->firstString($city, ['regionName', 'RegionName', 'areaName', 'AreaName', 'districtName', 'DistrictName']);

        Database::execute(
            "MERGE INTO rc_delivery_auto_cities c
             USING (SELECT :ref ref, :name name, :region_name region_name FROM dual) v
             ON (c.ref = v.ref)
             WHEN MATCHED THEN UPDATE SET c.name = v.name, c.region_name = v.region_name, c.is_active = 1
             WHEN NOT MATCHED THEN INSERT (ref, name, region_name, is_active) VALUES (v.ref, v.name, v.region_name, 1)",
            ['ref' => $ref, 'name' => $name, 'region_name' => $region !== '' ? $region : null]
        );
    }

    private function upsertDeliveryAutoWarehouse(string $cityRef, array $warehouse): void
    {
        if (!$this->tableExists('RC_DELIVERY_AUTO_WAREHOUSES')) {
            return;
        }

        $ref = $this->firstString($warehouse, ['id', 'Id', 'ID', 'ref', 'Ref', 'warehouseId', 'WarehouseId']);
        $name = $this->firstString($warehouse, ['name', 'Name', 'description', 'Description', 'warehouseName', 'WarehouseName']);
        if ($ref === '' || $name === '') {
            return;
        }

        $address = $this->firstString($warehouse, ['address', 'Address', 'addressText', 'AddressText', 'fullAddress', 'FullAddress']);

        Database::execute(
            "MERGE INTO rc_delivery_auto_warehouses w
             USING (SELECT :ref ref, :city_ref city_ref, :name name, :address_text address_text FROM dual) v
             ON (w.ref = v.ref)
             WHEN MATCHED THEN UPDATE SET w.city_ref = v.city_ref, w.name = v.name, w.address_text = v.address_text, w.is_active = 1
             WHEN NOT MATCHED THEN INSERT (ref, city_ref, name, address_text, is_active) VALUES (v.ref, v.city_ref, v.name, v.address_text, 1)",
            ['ref' => $ref, 'city_ref' => $cityRef, 'name' => $name, 'address_text' => $address !== '' ? $address : null]
        );
    }

    private function novaPoshtaRequest(string $model, string $method, array $props): array
    {
        $apiKey = (string)($this->config['nova_poshta']['api_key'] ?? '');
        $endpoint = (string)($this->config['nova_poshta']['endpoint'] ?? 'https://api.novaposhta.ua/v2.0/json/');
        return $this->httpJson($endpoint, [
            'apiKey' => $apiKey,
            'modelName' => $model,
            'calledMethod' => $method,
            'methodProperties' => $props,
        ], [], 'nova_poshta', $model . '.' . $method);
    }

    private function deliveryAutoDirectoryRequest(string $type, array $payload): array
    {
        $cfg = (array)($this->config['delivery_auto'] ?? []);
        $base = rtrim((string)($cfg['base_url'] ?? 'https://www.delivery-auto.com/api/v4/Public'), '/');

        if (str_ends_with(strtolower($base), '/public')) {
            $base = substr($base, 0, -7);
        }

        $endpoints = (array)($cfg['endpoints'] ?? []);

        if ($type === 'cities') {
            $params = [
                'culture' => (string)($payload['culture'] ?? 'uk-UA'),
            ];

            $candidates = $this->uniqueEndpoints([
                $this->normalizeDeliveryPublicEndpoint((string)($endpoints['cities'] ?? ''), '/Public/GetAreasList'),
                '/Public/GetAreasList',
            ]);

            return $this->tryDeliveryGetEndpoints($base, $candidates, $params, 'directory_' . $type);
        }

        if ($type === 'warehouses') {
            $cityId = trim((string)($payload['areasId'] ?? $payload['areaId'] ?? $payload['cityId'] ?? ''));
            if ($cityId === '') {
                return ['ok' => false, 'message' => 'CityId для Delivery Auto не задано.', 'data' => []];
            }

            $params = [
                'culture' => (string)($payload['culture'] ?? 'uk-UA'),
                'CityId' => $cityId,
                'onlyWarehouses' => 'true',
            ];

            $candidates = $this->uniqueEndpoints([
                $this->normalizeDeliveryPublicEndpoint((string)($endpoints['warehouses'] ?? ''), '/Public/GetWarehousesListInDetail'),
                '/Public/GetWarehousesListInDetail',
                '/Public/GetWarehousesListByCity',
                '/Public/GetWarehousesList',
            ]);

            return $this->tryDeliveryGetEndpoints($base, $candidates, $params, 'directory_' . $type);
        }

        $endpoint = $this->normalizeDeliveryPublicEndpoint('/Public/' . $type, '/Public/' . $type);
        return $this->httpGetJson($base . $endpoint, $payload, [], 'delivery_auto', 'directory_' . $type);
    }

    private function normalizeDeliveryPublicEndpoint(string $endpoint, string $fallback): string
    {
        $endpoint = trim($endpoint) !== '' ? trim($endpoint) : $fallback;

        if (!str_starts_with($endpoint, '/')) {
            $endpoint = '/' . $endpoint;
        }

        $lower = strtolower($endpoint);

        if (str_starts_with($lower, '/api/')) {
            return $endpoint;
        }

        if (!str_contains($lower, '/public/')) {
            $endpoint = '/Public' . $endpoint;
        }

        return $endpoint;
    }

    private function uniqueEndpoints(array $endpoints): array
    {
        $result = [];
        foreach ($endpoints as $endpoint) {
            $endpoint = trim((string)$endpoint);
            if ($endpoint !== '' && !in_array($endpoint, $result, true)) {
                $result[] = $endpoint;
            }
        }
        return $result;
    }

    private function tryDeliveryGetEndpoints(string $base, array $endpoints, array $params, string $action): array
    {
        $last = ['ok' => false, 'message' => 'Delivery Auto не відповів.', 'data' => []];

        foreach ($endpoints as $endpoint) {
            $response = $this->httpGetJson($base . $endpoint, $params, [], 'delivery_auto', $action . ':' . ltrim($endpoint, '/'));
            if (!empty($response['ok'])) {
                return $response;
            }
            $last = $response;
        }

        return $last;
    }



    private function httpGetJson(string $url, array $params, array $headers, string $provider, string $action): array
    {
        $headers[] = 'Accept: application/json';
        $query = http_build_query($params);
        $fullUrl = $url . ($query !== '' ? (str_contains($url, '?') ? '&' : '?') . $query : '');
        $started = microtime(true);

        $ch = curl_init($fullUrl);
        if (!$ch) {
            return ['ok' => false, 'message' => 'Не вдалося ініціалізувати curl.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $elapsed = (int)round((microtime(true) - $started) * 1000);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $ok = $errno === 0 && $status >= 200 && $status < 300 && is_array($decoded);

        $this->logExternal($provider, $action, $ok, [
            'url' => $fullUrl,
            'status' => $status,
            'elapsed_ms' => $elapsed,
            'curl_errno' => $errno,
            'curl_error' => $error,
            'request' => $this->maskSecrets($params),
            'response' => is_array($decoded) ? $decoded : $this->cut((string)$raw, 1500),
        ]);

        if (!$ok) {
            return ['ok' => false, 'message' => $error !== '' ? $error : 'API ' . $provider . ' не відповів коректно. HTTP ' . $status, 'data' => $decoded ?? []];
        }

        if (isset($decoded['status']) && $decoded['status'] === false) {
            return ['ok' => false, 'message' => (string)($decoded['message'] ?? 'API повернув помилку.'), 'data' => $decoded];
        }

        if (isset($decoded['success']) && $decoded['success'] === false) {
            $errors = implode(', ', array_map('strval', (array)($decoded['errors'] ?? [])));
            return ['ok' => false, 'message' => $errors !== '' ? $errors : 'API повернув помилку.', 'data' => $decoded];
        }

        return ['ok' => true, 'message' => 'OK', 'data' => $decoded];
    }

    private function httpJson(string $url, array $payload, array $headers, string $provider, string $action): array
    {
        $headers[] = 'Content-Type: application/json';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $started = microtime(true);

        $ch = curl_init($url);
        if (!$ch) {
            return ['ok' => false, 'message' => 'Не вдалося ініціалізувати curl.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $elapsed = (int)round((microtime(true) - $started) * 1000);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $ok = $errno === 0 && $status >= 200 && $status < 300 && is_array($decoded);

        $this->logExternal($provider, $action, $ok, [
            'url' => $url,
            'status' => $status,
            'elapsed_ms' => $elapsed,
            'curl_errno' => $errno,
            'curl_error' => $error,
            'request' => $this->maskSecrets($payload),
            'response' => is_array($decoded) ? $decoded : $this->cut((string)$raw, 1500),
        ]);

        if (!$ok) {
            return ['ok' => false, 'message' => $error !== '' ? $error : 'API ' . $provider . ' не відповів коректно. HTTP ' . $status];
        }

        if (isset($decoded['success']) && $decoded['success'] === false) {
            $errors = implode(', ', array_map('strval', (array)($decoded['errors'] ?? [])));
            return ['ok' => false, 'message' => $errors !== '' ? $errors : 'API повернув помилку.', 'data' => $decoded];
        }

        if (isset($decoded['status']) && $decoded['status'] === false) {
            return ['ok' => false, 'message' => (string)($decoded['message'] ?? 'API повернув помилку.'), 'data' => $decoded];
        }

        return ['ok' => true, 'message' => 'OK', 'data' => $decoded];
    }

    private function extractDirectoryItems(array $payload): array
    {
        $candidates = [
            $payload['data'] ?? null,
            $payload['Data'] ?? null,
            $payload['items'] ?? null,
            $payload['Items'] ?? null,
            $payload['areasList'] ?? null,
            $payload['AreasList'] ?? null,
            $payload['warehouseList'] ?? null,
            $payload['WarehouseList'] ?? null,
            $payload['result'] ?? null,
            $payload['Result'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                if (isset($candidate[0]) && is_array($candidate[0])) {
                    return $candidate;
                }
                foreach ($candidate as $nested) {
                    if (is_array($nested) && isset($nested[0]) && is_array($nested[0])) {
                        return $nested;
                    }
                }
            }
        }

        if (isset($payload[0]) && is_array($payload[0])) {
            return $payload;
        }

        return [];
    }

    private function firstString(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
                return trim((string)$row[$key]);
            }
        }
        return '';
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
                'message' => $success ? 'OK' : ((string)($payload['curl_error'] ?? 'API error')),
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    private function tableExists(string $table): bool
    {
        $row = Database::fetchOne('SELECT COUNT(*) AS cnt FROM user_tables WHERE table_name = :table_name', ['table_name' => strtoupper($table)]);
        return (int)($row['CNT'] ?? 0) > 0;
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

    private function cut(string $value, int $limit): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit, 'UTF-8');
        }
        return substr($value, 0, $limit);
    }
}
