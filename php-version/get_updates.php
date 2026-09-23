<?php
set_time_limit(0);
ini_set('memory_limit', '512M');
@ini_set('display_errors', '1');
@error_reporting(E_ALL);

require_once __DIR__ . '/app/Env.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/Repo.php';
require_once __DIR__ . '/app/WooCommerce.php';
require_once __DIR__ . '/app/TelegramBot.php';
require_once __DIR__ . '/app/Format.php';
require_once __DIR__ . '/app/Keyboard.php';
require_once __DIR__ . '/app/FileParser.php';
require_once __DIR__ . '/app/Handler.php';

$config = require __DIR__ . '/config.php';

use Bot\Database;
use Bot\WooCommerce;
use Bot\TelegramBot;
use Bot\Handler;

foreach ($config['paths'] as $dir) {
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
}

Database::bootstrap($config);

$wc = new WooCommerce($config);
$bot = new TelegramBot($config, $wc);
$handler = new Handler($bot);

$lastOffsetFile = $config['paths']['cache'] . '/last_offset.txt';
@mkdir(dirname($lastOffsetFile), 0755, true);

$test = $wc->testConnection();
echo '[BOOT] اتصال به وردپرس: ' . ($test['success'] ? "OK ({$test['count']} محصول)" : "FAILED: {$test['error']}") . PHP_EOL;
echo '[BOOT] شروع دریافت آپدیت‌ها (Long Polling). برای خروج Ctrl+C' . PHP_EOL;

while (true) {
    try {
        $offset = (int)(@file_get_contents($lastOffsetFile) ?: 0);
        $resp = $bot->getUpdates($offset, 100, 30);
        if (empty($resp['ok']) || !is_array($resp['result'] ?? null)) {
            usleep(500000);
            continue;
        }
        foreach ($resp['result'] as $update) {
            try {
                $handler->handle($update);
            } catch (Throwable $e) {
                echo '[ERR] ' . $e->getMessage() . "\n";
            }
            $nextOffset = (int)$update['update_id'] + 1;
            file_put_contents($lastOffsetFile, $nextOffset);
        }
    } catch (Throwable $e) {
        echo '[FATAL] ' . $e->getMessage() . "\n";
        sleep(3);
    }
}
