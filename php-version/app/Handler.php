<?php

namespace Bot;

class Handler
{
    private TelegramBot $bot;
    private array $config;
    private WooCommerce $wc;

    public function __construct(TelegramBot $bot)
    {
        $this->bot = $bot;
        $this->config = $bot->getConfig();
        $this->wc = $bot->getWC();
    }

    public function handle(array $update): void
    {
        try {
            if (isset($update['callback_query'])) {
                $this->handleCallback($update['callback_query']);
                return;
            }
            if (isset($update['message'])) {
                $this->handleMessage($update['message']);
                return;
            }
        } catch (\Throwable $e) {
            error_log('[BOT ERROR] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }

    private function memberGate(int $chatId, int $userId, string $fromName = null): bool
    {
        return true;
        // $channel = $this->config['bot']['channel'];
        // $ok = $this->bot->checkChannelMembership($userId, $channel);
        // if (!$ok) {
        //     $link = str_starts_with($channel, '@')
        //         ? 'https://t.me/' . substr($channel, 1)
        //         : $channel;
        //     $this->bot->sendMessage(
        //         $chatId,
        //         "⚠️ برای استفاده از ربات ابتدا عضو کانال شوید:\n\n[{$channel}]({$link})\n\nبعد از عضو شدن دوباره /start را بفرستید.",
        //         null
        //     );
        //     return false;
        // }
        // return true;
    }

    private function handleMessage(array $msg): void
    {
        $chatId = (int)$msg['chat']['id'];
        $from = $msg['from'] ?? [];
        $userId = (int)($from['id'] ?? 0);
        $name = $from['first_name'] ?? null;
        $username = $from['username'] ?? null;

        if (isset($msg['document'])) {
            if (!$this->memberGate($chatId, $userId, $name)) return;
            Repo::createOrUpdateUser($userId, $name, $username);
            $this->handleDocument($chatId, $userId, $msg['document']);
            return;
        }

        if (!isset($msg['text'])) return;
        $text = trim($msg['text']);

        if ($text === '/start' || $text === '/menu') {
            if (!$this->memberGate($chatId, $userId, $name)) return;
            Repo::createOrUpdateUser($userId, $name, $username);
            $hi = $name ? "👋 سلام *{$name}* به ربات لپ‌تاپ کامپیوتر اول خوش آمدید." : '👋 به ربات لپ‌تاپ کامپیوتر اول خوش آمدید.';
            $this->bot->sendMessage($chatId, "{$hi}\n\nلطفاً یکی از گزینه‌های زیر را انتخاب کنید:", Keyboard::main());
            return;
        }

        if ($text === '/contact' || $text === '/help') {
            if (!$this->memberGate($chatId, $userId, $name)) return;
            $this->sendContact($chatId);
            return;
        }

        if (!$this->memberGate($chatId, $userId, $name)) return;
        Repo::createOrUpdateUser($userId, $name, $username);

        if ($text === $this->config['admin']['upload_code']) {
            Repo::setState($userId, ['action' => 'upload_admin_file']);
            $this->bot->sendMessage(
                $chatId,
                "✅ *احراز هویت ادمین موفق*\n\nلطفاً فایل لیست قیمت همکار را ارسال کنید.\nفرمت‌های پشتیبانی شده: *CSV*, *XLSX*, *XLS*\n\nستون‌های پیشنهادی:\n• نام محصول / مدل / برند\n• قیمت همکار / قیمت اصلی / کد محصول\n\n⚠️ آپلود فایل جدید، لیست قبلی حذف می‌شود.",
                Keyboard::back('main_menu')
            );
            return;
        }

        $state = Repo::getState($userId);

        if ($state && ($state['action'] ?? '') === 'search') {
            Repo::setState($userId, null);
            $this->doSearch($chatId, $text);
            return;
        }

        if ($state && ($state['action'] ?? '') === 'enter_colleague_code') {
            Repo::setState($userId, null);
        }

        $this->bot->sendMessage($chatId, 'لطفاً یکی از گزینه‌های منو را انتخاب کنید:', Keyboard::main());
    }

    private function handleCallback(array $cb): void
    {
        $from = $cb['from'] ?? [];
        $userId = (int)($from['id'] ?? 0);
        $name = $from['first_name'] ?? null;
        $username = $from['username'] ?? null;
        $message = $cb['message'] ?? null;
        if (!$message) return;
        $chatId = (int)$message['chat']['id'];
        $messageId = (int)$message['message_id'];
        $data = $cb['data'] ?? '';
        $cbId = $cb['id'];

        if (!$this->memberGate($chatId, $userId, $name)) {
            $this->bot->answerCallbackQuery($cbId, 'ابتدا عضو کانال شوید');
            return;
        }

        Repo::createOrUpdateUser($userId, $name, $username);

        try {
            $this->routeCallback($chatId, $messageId, $cbId, $userId, $data);
        } catch (\Throwable $e) {
            error_log('[CB ERR] ' . $e->getMessage());
            try { $this->bot->answerCallbackQuery($cbId, 'خطا'); } catch (\Throwable $e2) {}
        }
    }

    private function notifyCb(string $cbId, string $text = ''): void
    {
        try { $this->bot->answerCallbackQuery($cbId, $text); } catch (\Throwable $e) {}
    }

    private function editOrSend(int $chatId, int $messageId, string $text, ?array $kb = null): void
    {
        try {
            $this->bot->editMessageText($chatId, $messageId, $text, $kb);
        } catch (\Throwable $e) {
            $this->bot->sendMessage($chatId, $text, $kb);
        }
    }

    private function routeCallback(int $chatId, int $mid, string $cbId, int $userId, string $data): void
    {
        switch (true) {
            case $data === 'main_menu':
                $this->notifyCb($cbId);
                $this->editOrSend($chatId, $mid, '*🏠 منوی اصلی:*', Keyboard::main());
                break;

            case $data === 'products':
                $this->notifyCb($cbId, 'در حال دریافت برندها...');
                try {
                    $brands = $this->wc->getAllBrands();
                    if (count($brands) === 0) {
                        $this->editOrSend($chatId, $mid, '⚠️ فعلاً محصولی در سایت ثبت نشده است.', Keyboard::back('main_menu'));
                        return;
                    }
                    $this->editOrSend($chatId, $mid, '🏷️ لطفاً برند مورد نظر را انتخاب کنید:', Keyboard::brands($brands));
                } catch (\Throwable $e) {
                    $this->editOrSend($chatId, $mid, '❌ خطا در دریافت اطلاعات سایت:\n`' . $e->getMessage() . '`', Keyboard::back('main_menu'));
                }
                break;

            case str_starts_with($data, 'brand:'):
                $brand = substr($data, 6);
                $this->notifyCb($cbId, "دریافت محصولات {$brand}...");
                try {
                    $products = $this->wc->getProductsByBrand($brand);
                    if (count($products) === 0) {
                        $this->editOrSend($chatId, $mid, "⚠️ فعلاً محصولی از برند *{$brand}* موجود نیست.", Keyboard::back('products'));
                        return;
                    }
                    $this->editOrSend(
                        $chatId, $mid,
                        "💻 محصولات *{$brand}* (" . count($products) . " محصول):\nبرای مشاهده مشخصات روی محصول کلیک کنید:",
                        Keyboard::products($products)
                    );
                } catch (\Throwable $e) {
                    $this->editOrSend($chatId, $mid, '❌ خطا در دریافت محصولات:\n`' . $e->getMessage() . '`', Keyboard::back('products'));
                }
                break;

            case str_starts_with($data, 'product:'):
                $productId = (int)substr($data, 8);
                $this->notifyCb($cbId, 'در حال دریافت مشخصات...');
                $this->showProduct($chatId, $mid, $userId, $productId);
                break;

            case str_starts_with($data, 'track:'):
                $productId = (int)substr($data, 6);
                $this->trackProduct($chatId, $cbId, $userId, $productId);
                break;

            case str_starts_with($data, 'untrack:'):
                $trackId = (int)substr($data, 8);
                $this->untrackTrackId($chatId, $mid, $cbId, $userId, $trackId);
                break;

            case str_starts_with($data, 'fav:'):
                $productId = (int)substr($data, 4);
                $this->toggleFavorite($chatId, $cbId, $userId, $productId);
                break;

            case str_starts_with($data, 'add_compare:'):
                $productId = (int)substr($data, 12);
                $this->addToCompare($chatId, $cbId, $userId, $productId);
                break;

            case str_starts_with($data, 'rm_compare:'):
                $idx = (int)substr($data, 11);
                Repo::removeCompareItemByIndex($userId, $idx);
                $this->notifyCb($cbId);
                $this->renderCompareList($chatId, $mid);
                break;

            case $data === 'clear_compare':
                Repo::clearCompareList($userId);
                $this->notifyCb($cbId);
                $this->renderCompareList($chatId, $mid);
                break;

            case $data === 'compare':
                $this->notifyCb($cbId);
                $this->renderCompareList($chatId, $mid);
                break;

            case $data === 'do_compare':
                $this->doCompare($chatId, $cbId, $userId);
                break;

            case $data === 'tracking_menu':
                $this->notifyCb($cbId);
                $this->editOrSend($chatId, $mid, '🔔 *منوی پیگیری محصولات:*', Keyboard::trackingMenu());
                break;

            case $data === 'list_tracking':
                $this->notifyCb($cbId);
                $this->showTrackedList($chatId, $mid, $userId);
                break;

            case $data === 'favorites':
                $this->notifyCb($cbId);
                $this->showFavorites($chatId, $mid, $userId);
                break;

            case $data === 'clear_favs':
                Repo::clearFavorites($userId);
                $this->notifyCb($cbId);
                $this->editOrSend($chatId, $mid, '⭐ لیست علاقه‌مندی‌ها خالی شد.', Keyboard::main());
                break;

            case $data === 'pricelist':
                $this->notifyCb($cbId, 'در حال دریافت لیست قیمت...');
                $this->sendPriceList($chatId, $mid);
                break;

            case $data === 'colleague_price':
                $this->notifyCb($cbId, 'در حال دریافت لیست قیمت همکار...');
                $this->sendColleaguePrices($chatId, $mid);
                break;

            case $data === 'budget_search':
                $this->notifyCb($cbId);
                $this->editOrSend($chatId, $mid, '💰 لطفاً بازه بودجه خود را انتخاب کنید:', Keyboard::budgetRanges());
                break;

            case str_starts_with($data, 'budget:'):
                $parts = explode(':', substr($data, 7));
                $min = (float)($parts[0] ?? 0);
                $max = (float)($parts[1] ?? 0);
                $this->notifyCb($cbId, 'در حال جستجو...');
                $this->sendBudgetResults($chatId, $mid, $min, $max);
                break;

            case $data === 'search':
                Repo::setState($userId, ['action' => 'search']);
                $this->notifyCb($cbId);
                $this->editOrSend(
                    $chatId, $mid,
                    "🔎 لطفاً نام یا مدل لپ‌تاپ مورد نظر را ارسال کنید:\nمثال: *Lenovo LOQ 15* یا *ASUS TUF*",
                    Keyboard::back('main_menu')
                );
                break;

            case $data === 'contact':
                $this->notifyCb($cbId);
                $this->sendContact($chatId, $mid);
                break;

            default:
                $this->notifyCb($cbId, 'دستور نامعتبر');
                break;
        }
    }

    private function showProduct(int $chatId, int $mid, int $userId, int $productId): void
    {
        $p = $this->wc->getProductById($productId);
        if (!$p) {
            $this->editOrSend($chatId, $mid, 'محصول پیدا نشد.', Keyboard::back('products'));
            return;
        }

        $user = Repo::getUser($userId);
        $tracked = Repo::getTrackedByUser($user['id']);
        $isTracked = count(array_filter($tracked, fn($t) => (int)$t['product_id'] === $productId)) > 0;
        $favs = Repo::getFavorites($user['id']);
        $isFav = count(array_filter($favs, fn($f) => (int)$f['product_id'] === $productId)) > 0;

        $kb = Keyboard::productActions($productId, $isTracked, $isFav, $p['permalink'] ?: null);
        $text = Format::productFull($p);
        $chunks = Format::splitLong($text, 800);

        if (!empty($p['image'])) {
            try {
                $this->bot->sendPhoto($chatId, $p['image'], $chunks[0], $kb);
            } catch (\Throwable $e) {
                $this->bot->sendMessage($chatId, $chunks[0], $kb);
            }
        } else {
            $this->bot->sendMessage($chatId, $chunks[0], $kb);
        }

        for ($i = 1; $i < count($chunks); $i++) {
            $this->bot->sendMessage($chatId, $chunks[$i]);
        }

        $imgs = $p['images'] ?? [];
        for ($i = 1; $i < min(count($imgs), 4); $i++) {
            try { $this->bot->sendPhoto($chatId, $imgs[$i]); } catch (\Throwable $e) {}
        }
    }

    private function trackProduct(int $chatId, string $cbId, int $userId, int $productId): void
    {
        $p = $this->wc->getProductById($productId);
        if (!$p) {
            $this->notifyCb($cbId, 'محصول یافت نشد');
            return;
        }
        $user = Repo::getUser($userId);
        $stock = $p['isInStock'] ? 'instock' : 'outofstock';
        Repo::addTrackedProduct($user['id'], $productId, $p['name'], (float)$p['currentPriceRaw'], $stock);
        $this->notifyCb($cbId, '✅ پیگیری فعال شد');
        $this->bot->sendMessage(
            $chatId,
            "🔔 *پیگیری فعال شد*\n\nمحصول: *{$p['name']}*\n\nدر صورت تغییر قیمت یا موجود شدن، پیام دریافت خواهید کرد.",
            Keyboard::back('main_menu')
        );
    }

    private function untrackTrackId(int $chatId, int $mid, string $cbId, int $userId, int $trackId): void
    {
        $user = Repo::getUser($userId);
        $all = Repo::getTrackedByUser($user['id']);
        foreach ($all as $t) {
            if ((int)$t['id'] === $trackId) {
                Repo::removeTrackedProduct($user['id'], (int)$t['product_id']);
                break;
            }
        }
        $this->notifyCb($cbId, 'حذف شد');
        $this->showTrackedList($chatId, $mid, $userId);
    }

    private function toggleFavorite(int $chatId, string $cbId, int $userId, int $productId): void
    {
        $user = Repo::getUser($userId);
        $current = Repo::getFavorites($user['id']);
        $exists = count(array_filter($current, fn($f) => (int)$f['product_id'] === $productId)) > 0;
        if ($exists) {
            Repo::removeFavorite($user['id'], $productId);
            $this->notifyCb($cbId, '⭐ از علاقه‌مندی‌ها حذف شد');
        } else {
            $p = $this->wc->getProductById($productId);
            if ($p) {
                Repo::addFavorite($user['id'], $productId, $p['name'], (float)$p['currentPriceRaw']);
                $this->notifyCb($cbId, '⭐ به علاقه‌مندی‌ها اضافه شد');
            } else {
                $this->notifyCb($cbId, 'خطا');
            }
        }
    }

    private function addToCompare(int $chatId, string $cbId, int $userId, int $productId): void
    {
        $current = Repo::getCompareList($userId);
        if (count($current) >= 4) {
            $this->notifyCb($cbId, 'حداکثر ۴ محصول');
            return;
        }
        foreach ($current as $it) {
            if ((int)($it['id'] ?? 0) === $productId) {
                $this->notifyCb($cbId, 'از قبل در لیست است');
                return;
            }
        }
        $p = $this->wc->getProductById($productId);
        if (!$p) {
            $this->notifyCb($cbId, 'محصول یافت نشد');
            return;
        }
        Repo::addCompareItem($userId, $productId, $p);
        $list = Repo::getCompareList($userId);
        $this->notifyCb($cbId, '✅ اضافه شد (' . count($list) . '/4)');
    }

    private function renderCompareList(int $chatId, int $mid): void
    {
        $list = Repo::getCompareList($chatId);
        if (count($list) === 0) {
            $this->editOrSend(
                $chatId, $mid,
                "⚖️ *مقایسه لپ‌تاپ*\n\nبرای افزودن محصول:\n💻 محصولات → انتخاب محصول → *➕ افزودن برای مقایسه*\n\nحداکثر ۴ محصول قابل مقایسه هستند.",
                Keyboard::compareList([])
            );
            return;
        }
        $this->editOrSend(
            $chatId, $mid,
            "⚖️ لیست مقایسه شما (" . count($list) . " محصول):\nبرای افزودن محصول: محصولات → انتخاب → + افزودن برای مقایسه",
            Keyboard::compareList($list)
        );
    }

    private function doCompare(int $chatId, string $cbId, int $userId): void
    {
        $list = Repo::getCompareList($userId);
        if (count($list) < 2) {
            $this->notifyCb($cbId, 'حداقل ۲ محصول نیاز است');
            return;
        }
        $this->notifyCb($cbId, 'در حال مقایسه...');
        $text = Format::compare($list);
        foreach (Format::splitLong($text, 3500) as $chunk) {
            $this->bot->sendMessage($chatId, $chunk);
        }
    }

    private function showTrackedList(int $chatId, int $mid, int $userId): void
    {
        $user = Repo::getUser($userId);
        $list = Repo::getTrackedByUser($user['id']);
        if (count($list) === 0) {
            $this->editOrSend(
                $chatId, $mid,
                "📋 هیچ محصولی برای پیگیری ثبت نکرده‌اید.\nبرای افزودن، روی یک محصول کلیک کنید و گزینه پیگیری را بزنید.",
                Keyboard::back('tracking_menu')
            );
            return;
        }
        $lines = ["📋 *محصولات پیگیری شده:*", ""];
        foreach ($list as $i => $t) {
            $price = Format::toman($t['initial_price'] ?? 0);
            $stock = ($t['initial_stock_status'] ?? '') === 'instock' ? '✅' : '❌';
            $lines[] = ($i + 1) . ". {$t['product_name']}";
            $lines[] = "   {$stock} قیمت: {$price}";
        }
        $this->editOrSend($chatId, $mid, implode("\n", $lines), Keyboard::trackedList($list));
    }

    private function showFavorites(int $chatId, int $mid, int $userId): void
    {
        $user = Repo::getUser($userId);
        $items = Repo::getFavorites($user['id']);
        if (count($items) === 0) {
            $this->editOrSend($chatId, $mid, '⭐ هنوز محصولی به علاقه‌مندی‌ها اضافه نکرده‌اید.', Keyboard::back('main_menu'));
            return;
        }
        $this->editOrSend(
            $chatId, $mid,
            "⭐ لیست علاقه‌مندی‌های شما (" . count($items) . " مورد):",
            Keyboard::favorites($items)
        );
    }

    private function sendPriceList(int $chatId, int $mid): void
    {
        try {
            $products = $this->wc->getAllProducts(['per_page' => 100]);
            $inStock = array_values(array_filter($products, fn($p) => !empty($p['isInStock'])));
            $lines = [
                '📦 *لیست قیمت لحظه‌ای (موجود)*',
                '',
                'تعداد محصول موجود: *' . count($inStock) . "*\n",
            ];
            $byBrand = [];
            foreach ($inStock as $p) {
                $byBrand[$p['brand']][] = $p;
            }
            foreach ($byBrand as $brand => $ps) {
                $lines[] = "\n━━━━ *{$brand}* ━━━━";
                foreach ($ps as $p) {
                    $n = $p['name'];
                    if (mb_strlen($n) > 42) $n = mb_substr($n, 0, 39) . '...';
                    $lines[] = "• {$n}  →  *{$p['currentPrice']} تومان*";
                }
            }
            $chunks = Format::splitLong(implode("\n", $lines), 3500);
            $lastIdx = count($chunks) - 1;
            foreach ($chunks as $i => $chunk) {
                $kb = $i === $lastIdx ? Keyboard::back('main_menu') : null;
                $this->bot->sendMessage($chatId, $chunk, $kb);
            }
        } catch (\Throwable $e) {
            $this->bot->sendMessage($chatId, '❌ خطا در دریافت لیست قیمت:\n`' . $e->getMessage() . '`', Keyboard::back('main_menu'));
        }
    }

    private function sendColleaguePrices(int $chatId, int $mid): void
    {
        try {
            $all = Repo::getColleaguePrices();
            if (count($all) === 0) {
                $this->editOrSend(
                    $chatId,
                    $mid,
                    "⚠️ لیست قیمت همکاران فعلاً خالی است.\n\nبرای آپلود (فقط ادمین):\n۱) کد ادمین را در چت بفرستید\n۲) فایل CSV را ارسال کنید",
                    Keyboard::back('main_menu')
                );
                return;
            }
            $text = Format::colleagueList($all);
            $chunks = Format::splitLong($text, 3500);
            $lastIdx = count($chunks) - 1;
            foreach ($chunks as $i => $chunk) {
                $kb = $i === $lastIdx ? Keyboard::back('main_menu') : null;
                if ($i === 0) {
                    try {
                        $this->bot->editMessageText($chatId, $mid, $chunk, $kb);
                    } catch (\Throwable $e) {
                        $this->bot->sendMessage($chatId, $chunk, $kb);
                    }
                } else {
                    $this->bot->sendMessage($chatId, $chunk, $kb);
                }
            }
        } catch (\Throwable $e) {
            $this->bot->sendMessage($chatId, 'خطا در دریافت لیست.', Keyboard::back('main_menu'));
        }
    }

    private function sendBudgetResults(int $chatId, int $mid, float $min, float $max): void
    {
        try {
            $products = $this->wc->getProductsInRange($min, $max);
            if (count($products) === 0) {
                $this->editOrSend(
                    $chatId, $mid,
                    "⚠️ محصولی در بازه قیمت " . Format::toman($min) . " تا " . Format::toman($max) . " پیدا نشد.",
                    Keyboard::back('budget_search')
                );
                return;
            }
            $header = "💰 نتایج بودجه " . Format::toman($min) . " تا " . Format::toman($max) . ":\nتعداد: " . count($products) . " محصول\n\n";
            $items = [];
            foreach ($products as $p) {
                $n = $p['name'];
                if (mb_strlen($n) > 42) $n = mb_substr($n, 0, 39) . '...';
                $items[] = "• {$n}\n  💰 {$p['currentPrice']} تومان\n";
            }
            $chunks = Format::splitLong($header . implode("\n", $items), 3500);
            $lastIdx = count($chunks) - 1;
            foreach ($chunks as $i => $chunk) {
                $kb = $i === $lastIdx ? Keyboard::back('budget_search') : null;
                if ($i === 0) {
                    try {
                        $this->bot->editMessageText($chatId, $mid, $chunk, $kb);
                    } catch (\Throwable $e) {
                        $this->bot->sendMessage($chatId, $chunk, $kb);
                    }
                } else {
                    $this->bot->sendMessage($chatId, $chunk, $kb);
                }
            }
        } catch (\Throwable $e) {
            $this->bot->sendMessage($chatId, '❌ خطا در جستجوی بودجه:\n`' . $e->getMessage() . '`', Keyboard::back('budget_search'));
        }
    }

    private function doSearch(int $chatId, string $q): void
    {
        $this->bot->sendMessage($chatId, "🔎 در حال جستجو برای: *{$q}*");
        try {
            $results = $this->wc->searchProducts($q);
            if (count($results) === 0) {
                $this->bot->sendMessage($chatId, '⚠️ محصولی یافت نشد. لطفاً کلمات دیگری تست کنید.', Keyboard::back('main_menu'));
                return;
            }
            $this->bot->sendMessage(
                $chatId,
                "✅ " . count($results) . " محصول پیدا شد.\nیکی را انتخاب کنید:",
                Keyboard::products($results)
            );
        } catch (\Throwable $e) {
            $this->bot->sendMessage($chatId, '❌ خطا در جستجو:\n`' . $e->getMessage() . '`', Keyboard::back('main_menu'));
        }
    }

    private function sendContact(int $chatId, ?int $mid = null): void
    {
        $cfg = $this->config['contact'];
        $lines = [
            '☎️ *راه‌های ارتباط با فروشگاه*',
            '',
            '📞 شماره‌های تماس:',
        ];
        foreach ($cfg['phones'] as $ph) $lines[] = "  • 📱 {$ph}";
        $lines[] = '';
        $lines[] = "🔗 صفحه ارتباطی: [{$cfg['zil_link']}]({$cfg['zil_link']})";
        $text = implode("\n", $lines);
        $kb = Keyboard::contact($cfg['zil_link']);
        if ($mid !== null) {
            try {
                $this->bot->editMessageText($chatId, $mid, $text, $kb);
                return;
            } catch (\Throwable $e) {}
        }
        $this->bot->sendMessage($chatId, $text, $kb);
    }

    private function handleDocument(int $chatId, int $userId, array $document): void
    {
        $state = Repo::getState($userId);
        if (!$state || ($state['action'] ?? '') !== 'upload_admin_file') {
            $this->bot->sendMessage($chatId, '⚠️ برای آپلود فایل ابتدا کد ادمین را ارسال کنید.', Keyboard::back('main_menu'));
            return;
        }
        $name = $document['file_name'] ?? '';
        if (!preg_match('/\.(xlsx|xls|csv)$/i', $name)) {
            $this->bot->sendMessage($chatId, '❌ فرمت فایل نامعتبر است. فقط XLSX, XLS, CSV');
            return;
        }
        $this->bot->sendMessage($chatId, '⏳ در حال پردازش فایل...');

        try {
            $fileId = $document['file_id'];
            $gf = $this->bot->getFile($fileId);
            if (empty($gf['ok']) || empty($gf['result']['file_path'])) {
                throw new \RuntimeException('دریافت اطلاعات فایل از تلگرام ناموفق بود.');
            }
            $tgPath = $gf['result']['file_path'];
            $uploadsDir = $this->config['paths']['uploads'];
            if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);
            $savePath = $uploadsDir . DIRECTORY_SEPARATOR . time() . '_' . basename($name);
            $this->bot->downloadFile($tgPath, $savePath);

            $result = FileParser::importColleaguePrices($savePath, $name, $userId, $uploadsDir);
            Repo::setState($userId, null);
            $this->bot->sendMessage(
                $chatId,
                "✅ *آپلود موفق*\n\nتعداد رکورد: *{$result['count']}*\nبرندها: " . (count($result['brands']) ? implode('، ', $result['brands']) : '---') . "\n\nلیست قیمت همکاران به‌روز شد.",
                Keyboard::main()
            );
        } catch (\Throwable $e) {
            $this->bot->sendMessage($chatId, "❌ خطا در پردازش فایل:\n{$e->getMessage()}", Keyboard::back('main_menu'));
        }
    }
}
