<?php
$host = (string)(getenv('APP_DOMAIN') ?: ($_SERVER['HTTP_HOST'] ?? 'rungocraft.local'));
$host = preg_replace('/:\d+$/', '', $host) ?: 'rungocraft.local';
$forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
$scheme = $forwardedProto !== '' ? $forwardedProto : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
$publicUrl = rtrim((string)(getenv('APP_PUBLIC_URL') ?: ($scheme . '://' . $host)), '/');

return [
    'app' => [
        
        'public_url' => $publicUrl,
    ],

    'nova_poshta' => [
        'enabled' => false,
        'api_key' => '',
        'endpoint' => 'https://api.novaposhta.ua/v2.0/json/',
        'sender' => [
            
            'sender_ref' => '',
            'contact_sender_ref' => '',
            'sender_city_ref' => '',
            'sender_warehouse_ref' => '',
            'sender_phone' => '380937278561',
        ],
    ],

    'delivery_auto' => [
        'enabled' => false,
        'api_key' => '',
        'secret_key' => '',
        
        'base_url' => 'https://www.delivery-auto.com/api/v4/Public',
        
        'hmac_algorithm' => 'sha256',
        'hmac_algorithms' => ['sha256', 'sha1'],
        'test_mode' => true,
        
        
        
        'local_fallback_on_api_error' => true,
        
        'scheme_branch' => 0, 
        'scheme_courier' => 2, 
        
        
        'default_category_id' => '00000000-0000-0000-0000-000000000000',
        
        'cargo_category_id' => '0307d03b-9e36-e311-8b0d-00155d037960',
        'endpoints' => [
            'calculate' => '/PostReceiptCalculate',
            'create_receipt' => '/PostCreateReceipts',
            'tracking' => '/GetReceiptDetails',
            'cities' => '/Public/GetAreasList',
            'warehouses' => '/Public/GetWarehousesListInDetail',
        ],
        'sender' => [
            
            'sender_id' => '',
            'city_id' => '',
            'warehouse_id' => '',
            'contact_name' => 'RungoCraft',
            'contact_phone' => '380937278561',
        ],
    ],

    'payments' => [
        
        'default_provider' => 'liqpay',

        'liqpay' => [
            'enabled' => false,
            'public_key' => '',
            'private_key' => '',
            'sandbox' => true,
        ],

        'wayforpay' => [
            'enabled' => false,
            'merchant_account' => '',
            'merchant_secret_key' => '',
            'merchant_domain' => $host,
            'sandbox' => true,
        ],
    ],

    'telegram' => [
        'enabled' => false,
        'bot_token' => '',
        'bot_username' => 'RungoCraftBot',
        'manager_chat_id' => '',
        'webhook_secret' => '',
    ],

    'sms' => [
        'enabled' => false,
        'provider' => 'turbosms',
        'sender' => 'RungoCraft',
        'turbosms' => [
            'token' => '',
            'endpoint' => 'https://api.turbosms.ua/message/send.json',
        ],
    ],

    'email' => [
        'enabled' => false,
        'from_email' => 'no-reply@rungocraft.ua',
        'from_name' => 'RungoCraft',
        'smtp' => [
            'host' => 'smtp-relay.brevo.com', 
            'port' => 587,
            'username' => '', 
            'password' => '', 
            'encryption' => 'tls',
        ],
    ],

    'recaptcha' => [
        
        
        'enabled' => false,
        'provider' => 'classic',
        'site_key' => '',
        'secret_key' => '',
        'verify_url' => 'https://www.google.com/recaptcha/api/siteverify',
        'min_score' => 0.5,
        
        'project_id' => '',
        'api_key' => '',
    ],


    'auth' => [
        
        
        'dev_mode' => true,
        'code_ttl_minutes' => 15,
    ],

    'notifications_queue_mode' => true,
];
