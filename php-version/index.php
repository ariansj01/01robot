<?php

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

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (is_array($data) && !empty($data)) {
    $handler->handle($data);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

echo 'ربات آماده است. از طریق Webhook یا اسکریپت get_updates.php استفاده کنید.';
