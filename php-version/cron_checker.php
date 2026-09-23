<?php
require_once __DIR__ . '/app/Env.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/Repo.php';
require_once __DIR__ . '/app/WooCommerce.php';
require_once __DIR__ . '/app/TelegramBot.php';
require_once __DIR__ . '/app/Format.php';

$config = require __DIR__ . '/config.php';

use Bot\Database;
use Bot\Repo;
use Bot\WooCommerce;
use Bot\TelegramBot;
use Bot\Format;

foreach ($config['paths'] as $dir) {
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
}

Database::bootstrap($config);

$wc = new WooCommerce($config);
$bot = new TelegramBot($config, $wc);

$items = Repo::getAllTrackedWithUsers();
echo 'Tracked products count: ' . count($items) . PHP_EOL;

foreach ($items as $t) {
    try {
        $product = $wc->getProductById((int)$t['product_id']);
        if (!$product) continue;

        $curPrice = (float)$product['currentPriceRaw'];
        $initPrice = (float)($t['initial_price'] ?? 0);
        $priceChanged = $initPrice > 0 && $curPrice > 0 && $curPrice !== $initPrice;
        $notifiedPrice = (int)($t['notified_price_change'] ?? 0);

        if ($priceChanged && !$notifiedPrice) {
            $diff = $curPrice - $initPrice;
            $emoji = $diff > 0 ? '📈' : '📉';
            $text = "{$emoji} *تغییر قیمت*\n\nمحصول: {$product['name']}\n\nقیمت قبلی: " . Format::toman($initPrice) .
                "\nقیمت جدید: *" . Format::toman($curPrice) . "*\n" .
                ($diff > 0 ? '+' : '') . Format::toman($diff) . "\n\n[مشاهده محصول]({$product['permalink']})";
            @$bot->sendMessage((int)$t['telegram_id'], $text);
            Repo::updateNotified((int)$t['id'], 'notified_price_change');
            echo "  [PRICE] notify user {$t['telegram_id']} for product {$t['product_id']}\n";
        }

        $curStock = !empty($product['isInStock']) ? 'instock' : 'outofstock';
        $initStock = $t['initial_stock_status'] ?? '';
        $stockChanged = $initStock && $curStock !== $initStock && $curStock === 'instock';
        $notifiedStock = (int)($t['notified_stock_change'] ?? 0);

        if ($stockChanged && !$notifiedStock) {
            $text = "🔔 *محصول موجود شد*\n\nمحصول: {$product['name']}\n\n✅ حالا موجود است\nقیمت فعلی: *" . Format::toman($curPrice) .
                "*\n\n[مشاهده محصول]({$product['permalink']})";
            @$bot->sendMessage((int)$t['telegram_id'], $text);
            Repo::updateNotified((int)$t['id'], 'notified_stock_change');
            echo "  [STOCK] notify user {$t['telegram_id']} for product {$t['product_id']}\n";
        }
    } catch (Throwable $e) {
        echo '  [ERR] ' . $e->getMessage() . "\n";
    }
}

echo 'Done.' . PHP_EOL;
