<?php

require_once __DIR__ . '/app/Env.php';
bot_env_load(__DIR__);

return [
    'bot' => [
        'token'          => bot_env('BOT_TOKEN', ''),
        'channel'        => bot_env('CHANNEL_USERNAME', '@com01'),
        'webhook_url'    => bot_env('WEBHOOK_URL', ''),
    ],
    'woocommerce' => [
        'url'             => bot_env('WEBSITE_URL', 'https://computer01.com'),
        'internal_url'    => bot_env('WC_INTERNAL_URL', ''),
        // مسیر مستقیم وردپرس روی همان هاست (فایروال HTTP را دور می‌زند)
        'wp_load'         => bot_env('WC_WP_LOAD', ''),
        'consumer_key'    => bot_env('WC_CONSUMER_KEY', ''),
        'consumer_secret' => bot_env('WC_CONSUMER_SECRET', ''),
    ],
    'admin' => [
        'upload_code' => bot_env('ADMIN_UPLOAD_CODE', 'ADMIN_PRICE_1402'),
    ],
    'database' => [
        'host'    => bot_env('DB_HOST', 'localhost'),
        'port'    => (int)bot_env('DB_PORT', '3306'),
        'user'    => bot_env('DB_USER', 'root'),
        'pass'    => bot_env('DB_PASSWORD', ''),
        'name'    => bot_env('DB_NAME', 'laptop_bot'),
        'charset' => 'utf8mb4',
    ],
    'contact' => [
        'phones'   => ['05144244566', '05144244577', '09013711899'],
        'zil_link' => 'https://zil.ink/jalily_computer',
    ],
    'paths' => [
        'uploads' => __DIR__ . '/uploads',
        'logs'    => __DIR__ . '/var/log',
        'cache'   => __DIR__ . '/var/cache',
    ],
];
