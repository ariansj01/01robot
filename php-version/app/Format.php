<?php

namespace Bot;

class Format
{
    public static function toman($n): string
    {
        if ($n === null || $n === '' || !is_numeric($n)) return 'نامشخص';
        return number_format((float)$n, 0, '.', ',') . ' تومان';
    }

    public static function number($n): string
    {
        if ($n === null || $n === '' || !is_numeric($n)) return 'نامشخص';
        return number_format((float)$n, 0, '.', ',');
    }

    public static function productFull(array $p): string
    {
        $lines = [];
        $lines[] = "🛒 *{$p['name']}*";
        $lines[] = '';
        $lines[] = "🏷️ برند: {$p['brand']}";

        if ($p['isInStock']) {
            $lines[] = '✅ موجودی: موجود در انبار';
            if ($p['stockQty'] !== 'نامشخص') {
                $lines[] = '📦 تعداد موجود: ' . self::number($p['stockQty']) . ' عدد';
            }
        } else {
            $lines[] = '❌ موجودی: ناموجود';
        }

        $lines[] = '';
        if ($p['salePrice']) {
            $lines[] = "💥 قیمت ویژه: *{$p['currentPrice']} تومان*";
            $lines[] = "💰 قیمت اصلی: {$p['regularPrice']} تومان";
        } else {
            $lines[] = "💰 قیمت: *{$p['currentPrice']} تومان*";
        }

        $lines[] = '';
        $lines[] = '⚙️ مشخصات فنی:';
        $lines[] = "├─ پردازنده (CPU): {$p['specs']['cpu']}";
        $lines[] = "├─ کارت گرافیک (GPU): {$p['specs']['gpu']}";
        $lines[] = "├─ حافظه رم: {$p['specs']['ram']}";
        $lines[] = "├─ حافظه داخلی (SSD): {$p['specs']['ssd']}";
        $lines[] = "├─ صفحه نمایش: {$p['specs']['display']}";
        $lines[] = "├─ باتری: {$p['specs']['battery']}";
        $lines[] = "├─ وزن: {$p['specs']['weight']}";
        $lines[] = "├─ رنگ: {$p['specs']['color']}";
        $lines[] = "└─ سیستم عامل: {$p['specs']['os']}";

        if (!empty($p['description'])) {
            $lines[] = '';
            $lines[] = '📝 توضیحات: ' . mb_substr($p['description'], 0, 200);
        }

        return implode("\n", $lines);
    }

    public static function compare(array $products): string
    {
        if (count($products) < 2) return 'برای مقایسه حداقل ۲ محصول لازم است.';

        $rows = [];
        $rows[] = '*🔬 مقایسه ' . count($products) . ' لپ‌تاپ*';
        $rows[] = '';
        $rows[] = '━━━━━━━━━━━━━━━━━━━━';

        $specs = [
            ['key' => 'brand', 'label' => 'برند'],
            ['key' => 'currentPrice', 'label' => 'قیمت', 'suffix' => ' تومان'],
            ['key' => 'isInStock', 'label' => 'موجودی', 'fmt' => fn($v) => $v ? '✅ موجود' : '❌ ناموجود'],
            ['key' => ['specs','cpu'], 'label' => 'CPU'],
            ['key' => ['specs','gpu'], 'label' => 'GPU'],
            ['key' => ['specs','ram'], 'label' => 'رم'],
            ['key' => ['specs','ssd'], 'label' => 'SSD'],
            ['key' => ['specs','display'], 'label' => 'صفحه نمایش'],
            ['key' => ['specs','battery'], 'label' => 'باتری'],
            ['key' => ['specs','weight'], 'label' => 'وزن'],
            ['key' => ['specs','color'], 'label' => 'رنگ'],
            ['key' => ['specs','os'], 'label' => 'سیستم عامل'],
        ];

        $getVal = function (array $p, $key) {
            if (is_array($key)) {
                $val = $p;
                foreach ($key as $k) {
                    if (!is_array($val) || !isset($val[$k])) return null;
                    $val = $val[$k];
                }
                return $val;
            }
            return $p[$key] ?? null;
        };

        foreach ($specs as $spec) {
            $label = str_pad($spec['label'], 16, ' ', STR_PAD_RIGHT);
            $rows[] = "*{$label}*";
            foreach ($products as $i => $p) {
                $raw = $getVal($p, $spec['key']);
                if (isset($spec['fmt']) && is_callable($spec['fmt'])) {
                    $val = call_user_func($spec['fmt'], $raw);
                } else {
                    $val = $raw;
                    if (!empty($spec['suffix']) && is_scalar($val)) {
                        $val = $val . $spec['suffix'];
                    }
                }
                $prefix = ($i === count($products) - 1) ? '└─ ' : '├─ ';
                $name = mb_substr($p['name'], 0, 14);
                if (mb_strlen($p['name']) > 14) $name .= '...';
                $rows[] = "{$prefix}[{$name}]  {$val}";
            }
            $rows[] = '';
        }

        $rows[] = '━━━━━━━━━━━━━━━━━━━━';
        $rows[] = '';
        $rows[] = '*💡 خلاصه تفاوت‌ها:*';
        $rows[] = self::summarizeDifferences($products);

        return implode("\n", $rows);
    }

    private static function summarizeDifferences(array $products): string
    {
        $diffs = [];

        $prices = [];
        foreach ($products as $p) {
            if (!empty($p['currentPriceRaw']) && $p['currentPriceRaw'] > 0) {
                $prices[] = (float)$p['currentPriceRaw'];
            }
        }
        if (count($prices) >= 2) {
            $min = min($prices);
            $max = max($prices);
            if ($min !== $max) {
                $cheap = $products[0];
                $expsv = $products[0];
                foreach ($products as $p) {
                    if (($p['currentPriceRaw'] ?? 0) === $min) $cheap = $p;
                    if (($p['currentPriceRaw'] ?? 0) === $max) $expsv = $p;
                }
                $diffs[] = '💰 ارزان‌ترین: ' . mb_substr($cheap['name'], 0, 20) .
                    ' (' . self::toman($min) . ') | گران‌ترین: ' . mb_substr($expsv['name'], 0, 20) .
                    ' (' . self::toman($max) . ') | اختلاف: ' . self::toman($max - $min);
            }
        }

        $bestCpu = self::findBest($products, ['specs','cpu']);
        $bestGpu = self::findBest($products, ['specs','gpu']);
        if ($bestCpu) $diffs[] = '🚀 قوی‌ترین CPU: ' . mb_substr($bestCpu['name'], 0, 25);
        if ($bestGpu) $diffs[] = '🎮 قوی‌ترین GPU: ' . mb_substr($bestGpu['name'], 0, 25);

        $inStock = count(array_filter($products, fn($p) => !empty($p['isInStock'])));
        if ($inStock < count($products)) {
            $diffs[] = "📦 فقط {$inStock} از " . count($products) . ' محصول موجود هستند.';
        } else {
            $diffs[] = '✅ همه محصولات موجود هستند.';
        }

        return mb_substr(implode("\n", $diffs), 0, 1500);
    }

    private static function findBest(array $products, array $keyPath): ?array
    {
        $map = [
            '9' => 9, '8' => 8, '7' => 7, '6' => 6, '5' => 5,
            'rtx' => 10, 'rx' => 8, 'gtx' => 7,
            'i9' => 9, 'i7' => 8, 'i5' => 7, 'i3' => 6,
            'ryzen 9' => 9, 'ryzen 7' => 8, 'ryzen 5' => 7, 'ryzen 3' => 6,
            'ultra 9' => 9, 'ultra 7' => 8, 'ultra 5' => 7,
        ];
        $best = null;
        $bestScore = -1;
        foreach ($products as $p) {
            $val = $p;
            foreach ($keyPath as $k) {
                if (!is_array($val) || !isset($val[$k])) { $val = ''; break; }
                $val = $val[$k];
            }
            $s = mb_strtolower((string)$val);
            $score = 0;
            foreach ($map as $k => $v) {
                if (str_contains($s, $k)) $score = max($score, $v);
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $p;
            }
        }
        return $bestScore > 0 ? $best : null;
    }

    public static function splitLong(string $text, int $max = 3800): array
    {
        if (mb_strlen($text) <= $max) return [$text];
        $chunks = [];
        $current = '';
        foreach (explode("\n", $text) as $line) {
            if (mb_strlen($current . "\n" . $line) > $max) {
                $chunks[] = $current;
                $current = $line;
            } else {
                $current .= ($current === '' ? '' : "\n") . $line;
            }
        }
        if ($current !== '') $chunks[] = $current;
        return $chunks;
    }

    public static function colleagueList(array $items): string
    {
        $lines = [];
        $lines[] = '📊 *لیست قیمت همکاران*';
        $lines[] = '';
        $curBrand = '';
        foreach ($items as $item) {
            $brand = $item['brand'] ?? '';
            if ($brand !== '' && $brand !== $curBrand) {
                $lines[] = "\n━━━━ *{$brand}* ━━━━";
                $curBrand = $brand;
            }
            $name = $item['product_name'] ?? ($item['product_model'] ?? 'نامشخص');
            $price = self::toman($item['colleague_price'] ?? 0);
            $code = !empty($item['product_code']) ? " [{$item['product_code']}]" : '';
            $lines[] = "• {$name}{$code} → *{$price}*";
        }
        if (count($items) === 0) $lines[] = '⚠️ لیست قیمت همکاران خالی است.';
        return implode("\n", $lines);
    }
}
