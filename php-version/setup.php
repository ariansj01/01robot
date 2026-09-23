<?php
require_once __DIR__ . '/app/Env.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/WooCommerce.php';
require_once __DIR__ . '/app/TelegramBot.php';

$config = require __DIR__ . '/config.php';

use Bot\Database;
use Bot\WooCommerce;
use Bot\TelegramBot;

foreach ($config['paths'] as $dir) {
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
}

Database::bootstrap($config);

$wc = new WooCommerce($config);
$bot = new TelegramBot($config, $wc);

$action = $argv[1] ?? '';

if ($action === 'status') {
    echo "=== وضعیت ربات ===\n";
    echo "توکن: " . substr($config['bot']['token'], 0, 10) . "...\n";
    echo "کانال: {$config['bot']['channel']}\n";
    echo "سایت: {$config['woocommerce']['url']}\n";
    $t = $wc->testConnection();
    echo "اتصال به وردپرس: " . ($t['success'] ? "OK ({$t['count']} محصول)" : "ERR: {$t['error']}") . "\n";
    try {
        $info = $bot->getWebhookInfo();
        if (!empty($info['ok'])) {
            echo "Webhook URL: " . ($info['result']['url'] ?? '(تنظیم نشده)') . "\n";
        }
    } catch (Throwable $e) {
        echo "Webhook error: " . $e->getMessage() . "\n";
    }
    exit;
}

if ($action === 'set-webhook') {
    $url = $argv[2] ?? ($config['bot']['webhook_url'] ?? '');
    if (!$url) {
        echo "شما باید آدرس Webhook را ارسال کنید:\n  php setup.php set-webhook https://yourdomain.com/php-version/index.php\n";
        exit(1);
    }
    print_r($bot->setWebhook($url));
    echo "\n";
    exit;
}

if ($action === 'get-webhook') {
    print_r($bot->getWebhookInfo());
    echo "\n";
    exit;
}

if ($action === 'test-api') {
    print_r($wc->testConnection());
    echo "\n";
    exit;
}

echo "استفاده:\n";
echo "  php setup.php status                          نمایش وضعیت\n";
echo "  php setup.php set-webhook <URL>               تنظیم Webhook\n";
echo "  php setup.php get-webhook                     اطلاعات Webhook\n";
echo "  php setup.php test-api                        تست اتصال ووکامرس\n";
