# Delivery Auto v60

Основна причина попередніх помилок: для Delivery були переплутані схеми доставки.

За документацією Delivery:
- `0` = склад-склад;
- `1` = двері-двері;
- `2` = склад-двері;
- `3` = двері-склад.

Для RungoCraft відправник — склад магазину, тому:
- Delivery у відділення використовує `deliveryScheme = 0`;
- Delivery кур'єром до дверей використовує `deliveryScheme = 2`.

Також PostCreateReceipts тепер формується як офіційний `receiptsList[]` з `category[]` всередині, а не через багато різних payload-варіантів.

У живому `config/integrations.php` бажано мати:

```php
'delivery_auto' => [
    'scheme_branch' => 0,
    'scheme_courier' => 2,
    'cargo_category_id' => '0307d03b-9e36-e311-8b0d-00155d037960',
    'hmac_algorithm' => 'sha256',
    'hmac_algorithms' => ['sha256', 'sha1'],
]
```

Якщо в живому конфігу залишилися старі значення `scheme_branch = 2` або `scheme_courier = 3`, код v60 автоматично виправляє їх під час запиту, але краще замінити їх у конфігу вручну.
