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

echo "=== تست سیستم ===\n";

echo "1. اتصال به دیتابیس: OK\n";

$wcTest = $wc->testConnection();
echo "2. اتصال به وردپرس: " . ($wcTest['success'] ? "OK ({$wcTest['count']} محصول)" : "FAIL: {$wcTest['error']}") . "\n";

try {
    $me = $bot->api('getMe');
    echo "3. اتصال به تلگرام (getMe): " . ($me['ok'] ? "OK (@" . ($me['result']['username'] ?? '?') . ")" : "FAIL") . "\n";
} catch (Throwable $e) {
    echo "3. اتصال به تلگرام: FAIL: " . $e->getMessage() . "\n";
}

echo "تست تمام شد.\n";
