<?php

namespace Bot;

class Keyboard
{
    public static function main(): array
    {
        return ['inline_keyboard' => [
            [['text' => '💻 محصولات', 'callback_data' => 'products']],
            [['text' => '🔎 جستجوی لپ‌تاپ', 'callback_data' => 'search']],
            [['text' => '⚖️ مقایسه لپ‌تاپ', 'callback_data' => 'compare']],
            [['text' => '📦 موجودی و قیمت', 'callback_data' => 'pricelist']],
            [['text' => '📊 لیست قیمت همکار', 'callback_data' => 'colleague_price']],
            [['text' => '🔔 پیگیری محصول', 'callback_data' => 'tracking_menu']],
            [['text' => '⭐ علاقه‌مندی‌ها', 'callback_data' => 'favorites']],
            [['text' => '💰 جستجو بر اساس بودجه', 'callback_data' => 'budget_search']],
            [['text' => '☎️ ارتباط با فروشنده', 'callback_data' => 'contact']],
        ]];
    }

    public static function brands(array $brands, string $prefix = 'brand'): array
    {
        $keys = [];
        foreach ($brands as $b) $keys[] = [['text' => "🏷️ {$b}", 'callback_data' => "{$prefix}:{$b}"]];
        $keys[] = [['text' => '🔙 بازگشت', 'callback_data' => 'main_menu']];
        return ['inline_keyboard' => $keys];
    }

    public static function products(array $products, string $prefix = 'product'): array
    {
        $keys = [];
        foreach ($products as $p) {
            $name = mb_substr($p['name'], 0, 40);
            if (mb_strlen($p['name']) > 40) $name .= '...';
            $icon = !empty($p['isInStock']) ? '✅' : '❌';
            $keys[] = [['text' => "{$icon} {$name}", 'callback_data' => "{$prefix}:{$p['id']}"]];
        }
        $keys[] = [['text' => '🔙 بازگشت به برندها', 'callback_data' => 'products']];
        $keys[] = [['text' => '🏠 منوی اصلی', 'callback_data' => 'main_menu']];
        return ['inline_keyboard' => $keys];
    }

    public static function productActions(int $id, bool $tracked = false, bool $fav = false, ?string $permalink = null): array
    {
        $keys = [
            [['text' => $tracked ? '🔔 پیگیری فعال است' : '🔔 فعال کردن پیگیری', 'callback_data' => "track:{$id}"]],
            [['text' => $fav ? '⭐ در علاقه‌مندی‌ها' : '⭐ افزودن به علاقه‌مندی‌ها', 'callback_data' => "fav:{$id}"]],
            [['text' => '➕ افزودن برای مقایسه', 'callback_data' => "add_compare:{$id}"]],
        ];
        if ($permalink) {
            $keys[] = [['text' => '🛒 مشاهده در سایت', 'url' => $permalink]];
        }
        $keys[] = [['text' => '🔙 بازگشت', 'callback_data' => 'products']];
        $keys[] = [['text' => '🏠 منوی اصلی', 'callback_data' => 'main_menu']];
        return ['inline_keyboard' => $keys];
    }

    public static function compareList(array $items = []): array
    {
        $keys = [];
        if (count($items) > 0) {
            $keys[] = [['text' => '🔬 مقایسه کن (' . count($items) . ' محصول)', 'callback_data' => 'do_compare']];
        }
        foreach ($items as $i => $p) {
            $name = mb_substr($p['name'], 0, 35);
            if (mb_strlen($p['name']) > 35) $name .= '...';
            $keys[] = [['text' => "❌ حذف: {$name}", 'callback_data' => "rm_compare:{$i}"]];
        }
        $keys[] = [['text' => '🗑️ خالی کردن لیست', 'callback_data' => 'clear_compare']];
        $keys[] = [['text' => '🔙 انتخاب محصول', 'callback_data' => 'compare']];
        $keys[] = [['text' => '🏠 منوی اصلی', 'callback_data' => 'main_menu']];
        return ['inline_keyboard' => $keys];
    }

    public static function trackingMenu(): array
    {
        return ['inline_keyboard' => [
            [['text' => '📋 لیست محصولات پیگیری شده', 'callback_data' => 'list_tracking']],
            [['text' => '➕ افزودن محصول برای پیگیری', 'callback_data' => 'products']],
            [['text' => '🏠 منوی اصلی', 'callback_data' => 'main_menu']],
        ]];
    }

    public static function trackedList(array $list): array
    {
        $keys = [];
        foreach ($list as $t) {
            $name = mb_substr($t['product_name'] ?? '', 0, 35);
            if (mb_strlen($t['product_name'] ?? '') > 35) $name .= '...';
            $keys[] = [['text' => "❌ حذف: {$name}", 'callback_data' => "untrack:{$t['id']}"]];
        }
        $keys[] = [['text' => '🔙 بازگشت', 'callback_data' => 'tracking_menu']];
        $keys[] = [['text' => '🏠 منوی اصلی', 'callback_data' => 'main_menu']];
        return ['inline_keyboard' => $keys];
    }

    public static function budgetRanges(): array
    {
        return ['inline_keyboard' => [
            [['text' => 'زیر ۳۰ میلیون', 'callback_data' => 'budget:0:30000000']],
            [['text' => '۳۰ تا ۵۰ میلیون', 'callback_data' => 'budget:30000000:50000000']],
            [['text' => '۵۰ تا ۷۰ میلیون', 'callback_data' => 'budget:50000000:70000000']],
            [['text' => '۷۰ تا ۱۰۰ میلیون', 'callback_data' => 'budget:70000000:100000000']],
            [['text' => 'بالای ۱۰۰ میلیون', 'callback_data' => 'budget:100000000:999999999999']],
            [['text' => '🔙 منوی اصلی', 'callback_data' => 'main_menu']],
        ]];
    }

    public static function contact(string $link): array
    {
        return ['inline_keyboard' => [
            [['text' => '🌐 صفحه ارتباط با ما', 'url' => $link]],
            [['text' => '🏠 منوی اصلی', 'callback_data' => 'main_menu']],
        ]];
    }

    public static function back(string $to = 'main_menu'): array
    {
        return ['inline_keyboard' => [
            [['text' => '🔙 بازگشت', 'callback_data' => $to]],
        ]];
    }

    public static function favorites(array $items): array
    {
        $keys = [];
        foreach ($items as $f) {
            $name = mb_substr($f['product_name'] ?? '', 0, 35);
            if (mb_strlen($f['product_name'] ?? '') > 35) $name .= '...';
            $keys[] = [['text' => "⭐ {$name}", 'callback_data' => "product:{$f['product_id']}"]];
        }
        $keys[] = [['text' => '🗑️ خالی کردن', 'callback_data' => 'clear_favs']];
        $keys[] = [['text' => '🏠 منوی اصلی', 'callback_data' => 'main_menu']];
        return ['inline_keyboard' => $keys];
    }
}
