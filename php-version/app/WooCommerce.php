<?php

namespace Bot;

class WooCommerce
{
    private array $config;
    private string $publicHost;
    private string $baseUrl;
    private bool $localMode = false;
    private bool $wpLoaded = false;

    public function __construct(array $config)
    {
        $this->config = $config['woocommerce'];
        $public = rtrim($this->config['url'], '/');
        $this->publicHost = (string)(parse_url($public, PHP_URL_HOST) ?: 'computer01.com');

        $internal = trim((string)($this->config['internal_url'] ?? ''));
        if ($internal !== '') {
            $this->baseUrl = rtrim($internal, '/') . '/wp-json/wc/v3';
        } else {
            $this->baseUrl = $public . '/wp-json/wc/v3';
        }

        $wpLoad = trim((string)($this->config['wp_load'] ?? ''));
        if ($wpLoad === '') {
            // حدس مسیر رایج: public_html/telegram_bot -> public_html/wp-load.php
            $guesses = [
                dirname(__DIR__) . '/wp-load.php',
                dirname(__DIR__, 2) . '/wp-load.php',
                '/home/jknmqzao/public_html/wp-load.php',
            ];
            foreach ($guesses as $g) {
                if (is_file($g)) {
                    $wpLoad = $g;
                    break;
                }
            }
        }
        $this->config['wp_load'] = $wpLoad;
        $this->localMode = ($wpLoad !== '' && is_file($wpLoad));
    }

    private function bootWordPress(): void
    {
        if ($this->wpLoaded) return;
        $path = $this->config['wp_load'] ?? '';
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('فایل wp-load.php پیدا نشد. مسیر WC_WP_LOAD را در .env تنظیم کنید.');
        }
        if (!defined('ABSPATH')) {
            // جلوگیری از خروج زودهنگام بعضی قالب‌ها
            if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
            require_once $path;
        }
        if (!function_exists('wc_get_products') && !class_exists('WooCommerce')) {
            throw new \RuntimeException('ووکامرس روی وردپرس فعال نیست یا لود نشده.');
        }
        $this->wpLoaded = true;
    }

    /** خواندن محصولات مستقیم از وردپرس (بدون HTTP / فایروال) */
    private function localRequest(string $path, array $query = []): array
    {
        $this->bootWordPress();

        if (preg_match('#^/products/(\d+)$#', $path, $m)) {
            $product = wc_get_product((int)$m[1]);
            if (!$product) {
                throw new \RuntimeException('محصول پیدا نشد.');
            }
            return $this->wcProductToRestArray($product);
        }

        if ($path !== '/products') {
            throw new \RuntimeException('مسیر پشتیبانی‌نشده در حالت محلی: ' . $path);
        }

        $page = max(1, (int)($query['page'] ?? 1));
        $perPage = max(1, min(100, (int)($query['per_page'] ?? 100)));

        if (!empty($query['search'])) {
            $q = new \WP_Query([
                'post_type'      => 'product',
                'post_status'    => $query['status'] ?? 'publish',
                's'              => $query['search'],
                'posts_per_page' => $perPage,
                'paged'          => $page,
            ]);
            $out = [];
            foreach ($q->posts as $post) {
                $product = wc_get_product($post->ID);
                if ($product) $out[] = $this->wcProductToRestArray($product);
            }
            return $out;
        }

        $args = [
            'status'  => $query['status'] ?? 'publish',
            'limit'   => $perPage,
            'page'    => $page,
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'objects',
        ];

        $products = wc_get_products($args);
        $out = [];
        foreach ($products as $p) {
            $out[] = $this->wcProductToRestArray($p);
        }
        return $out;
    }

    private function wcProductToRestArray($product): array
    {
        $attrs = [];
        foreach ($product->get_attributes() as $attr) {
            $name = $attr->get_name();
            if (str_starts_with($name, 'pa_')) {
                $label = function_exists('wc_attribute_label') ? wc_attribute_label($name) : $name;
            } else {
                $label = $name;
            }
            $options = [];
            if ($attr->is_taxonomy()) {
                $terms = wc_get_product_terms($product->get_id(), $attr->get_name(), ['fields' => 'names']);
                $options = is_array($terms) ? $terms : [];
            } else {
                $options = $attr->get_options();
            }
            $attrs[] = [
                'name' => $label,
                'options' => array_values(array_map('strval', (array)$options)),
            ];
        }

        $cats = [];
        $termIds = $product->get_category_ids();
        foreach ($termIds as $tid) {
            $term = get_term($tid, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $cats[] = ['id' => (int)$term->term_id, 'name' => $term->name];
            }
        }

        $images = [];
        $imageId = $product->get_image_id();
        if ($imageId) {
            $src = wp_get_attachment_url($imageId);
            if ($src) $images[] = ['src' => $src];
        }
        foreach ($product->get_gallery_image_ids() as $gid) {
            $src = wp_get_attachment_url($gid);
            if ($src) $images[] = ['src' => $src];
        }

        return [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'status' => $product->get_status(),
            'permalink' => $product->get_permalink(),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'stock_status' => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity(),
            'short_description' => $product->get_short_description(),
            'description' => $product->get_description(),
            'categories' => $cats,
            'attributes' => $attrs,
            'images' => $images,
        ];
    }

    private function doCurl(string $url, string $method, bool $useBasicAuth): array
    {
        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (compatible; Computer01Bot/1.0; +https://computer01.com)',
        ];

        $host = (string)(parse_url($url, PHP_URL_HOST) ?: '');
        if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            $headers[] = 'Host: ' . $this->publicHost;
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ];
        if ($useBasicAuth) {
            $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $opts[CURLOPT_USERPWD] = $this->config['consumer_key'] . ':' . $this->config['consumer_secret'];
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return [
            'body' => $response === false ? '' : (string)$response,
            'code' => $httpCode,
            'error' => $error,
            'url' => $finalUrl !== '' ? $finalUrl : $url,
        ];
    }

    private function parseWcResponse(array $res): array
    {
        if ($res['error']) {
            throw new \RuntimeException('cURL: ' . $res['error']);
        }
        $data = json_decode($res['body'], true);
        $code = $res['code'];
        if ($code >= 400) {
            if (is_array($data)) {
                $msg = $data['message'] ?? ($data['code'] ?? json_encode($data, JSON_UNESCAPED_UNICODE));
            } else {
                $snippet = trim(preg_replace('/\s+/', ' ', strip_tags(mb_substr($res['body'], 0, 200))));
                $msg = 'HTTP ' . $code . ($snippet !== '' ? ' - ' . $snippet : '');
            }
            throw new \RuntimeException($msg);
        }
        if (!is_array($data)) {
            throw new \RuntimeException('پاسخ نامعتبر از ووکامرس.');
        }
        return $data;
    }

    private function request(string $method, string $path, array $query = []): array
    {
        // اولویت با اتصال مستقیم وردپرس (بدون HTTP) — فایروال را دور می‌زند
        if ($this->localMode) {
            try {
                return $this->localRequest($path, $query);
            } catch (\Throwable $e) {
                // اگر مسیر wp-load اشتباه بود، به REST برمی‌گردیم
                if (!str_contains($e->getMessage(), 'wp-load')) {
                    throw $e;
                }
            }
        }

        $key = $this->config['consumer_key'] ?? '';
        $secret = $this->config['consumer_secret'] ?? '';
        if ($key === '' || $secret === '') {
            throw new \RuntimeException('کلیدهای WC خالی‌اند و حالت محلی هم فعال نیست. WC_WP_LOAD را تنظیم کنید.');
        }

        $urlBasic = $this->baseUrl . $path;
        if (!empty($query)) {
            $urlBasic .= '?' . http_build_query($query);
        }
        $resBasic = $this->doCurl($urlBasic, $method, true);
        if ($resBasic['error'] === '' && $resBasic['code'] > 0 && $resBasic['code'] < 400) {
            return $this->parseWcResponse($resBasic);
        }

        $queryAuth = array_merge([
            'consumer_key'    => $key,
            'consumer_secret' => $secret,
        ], $query);
        $urlQuery = $this->baseUrl . $path . '?' . http_build_query($queryAuth);
        $resQuery = $this->doCurl($urlQuery, $method, false);
        if ($resQuery['error'] === '' && $resQuery['code'] > 0 && $resQuery['code'] < 400) {
            return $this->parseWcResponse($resQuery);
        }

        try {
            return $this->parseWcResponse($resBasic['code'] > 0 ? $resBasic : $resQuery);
        } catch (\Throwable $e1) {
            try {
                return $this->parseWcResponse($resQuery);
            } catch (\Throwable $e2) {
                throw new \RuntimeException(
                    $e1->getMessage() . ' | fallback: ' . $e2->getMessage() .
                    ' | راهنما: HTTP توسط فایروال بسته است. مسیر wp-load.php را در WC_WP_LOAD بگذارید.'
                );
            }
        }
    }

    private function getAttr(array $product, string $name): ?string
    {
        if (empty($product['attributes'])) return null;
        foreach ($product['attributes'] as $a) {
            if (mb_strtolower($a['name'] ?? '') === mb_strtolower($name)) {
                return is_array($a['options'] ?? null) ? implode('، ', $a['options']) : ($a['options'] ?? null);
            }
        }
        foreach ($product['attributes'] as $a) {
            $n = mb_strtolower($a['name'] ?? '');
            if (str_contains($n, mb_strtolower($name))) {
                return is_array($a['options'] ?? null) ? implode('، ', $a['options']) : ($a['options'] ?? null);
            }
        }
        return null;
    }

    private function getBrand(array $product): string
    {
        if (!empty($product['categories'])) {
            foreach ($product['categories'] as $c) {
                if (!empty($c['name'])) return $c['name'];
            }
        }
        $brands = ['ASUS','Lenovo','HP','Dell','MSI','Acer','Apple','Samsung','Razer','Huawei','LG'];
        foreach ($brands as $b) {
            if (stripos($product['name'] ?? '', $b) !== false) return $b;
        }
        return $this->getAttr($product, 'برند') ?: 'نامشخص';
    }

    public function formatProduct(array $product): array
    {
        $isInStock = ($product['stock_status'] ?? '') === 'instock';
        $stockQty = isset($product['stock_quantity']) && $product['stock_quantity'] !== null
            ? $product['stock_quantity']
            : 'نامشخص';

        $regularPrice = !empty($product['regular_price']) ? (float)$product['regular_price'] : 0;
        $salePrice = !empty($product['sale_price']) ? (float)$product['sale_price'] : 0;
        $price = !empty($product['price']) ? (float)$product['price'] : 0;

        return [
            'id' => (int)$product['id'],
            'name' => $product['name'] ?? 'بدون نام',
            'brand' => $this->getBrand($product),
            'regularPrice' => $regularPrice ? number_format($regularPrice, 0, '.', ',') : 'نامشخص',
            'regularPriceRaw' => $regularPrice,
            'salePrice' => $salePrice ? number_format($salePrice, 0, '.', ',') : null,
            'salePriceRaw' => $salePrice,
            'currentPrice' => $price ? number_format($price, 0, '.', ',') : 'نامشخص',
            'currentPriceRaw' => $price,
            'isInStock' => $isInStock,
            'stockQty' => $stockQty,
            'status' => $product['status'] ?? '',
            'permalink' => $product['permalink'] ?? '',
            'image' => $product['images'][0]['src'] ?? null,
            'images' => array_column($product['images'] ?? [], 'src'),
            'description' => trim(strip_tags($product['short_description'] ?? '')),
            'specs' => [
                'cpu'     => $this->getAttr($product, 'CPU') ?: $this->getAttr($product, 'پردازنده') ?: 'درج نشده',
                'gpu'     => $this->getAttr($product, 'GPU') ?: $this->getAttr($product, 'کارت گرافیک') ?: 'درج نشده',
                'ram'     => $this->getAttr($product, 'RAM') ?: $this->getAttr($product, 'حافظه رم') ?: 'درج نشده',
                'ssd'     => $this->getAttr($product, 'SSD') ?: $this->getAttr($product, 'حافظه داخلی') ?: 'درج نشده',
                'display' => $this->getAttr($product, 'Display') ?: $this->getAttr($product, 'صفحه نمایش') ?: 'درج نشده',
                'weight'  => $this->getAttr($product, 'Weight') ?: $this->getAttr($product, 'وزن') ?: 'درج نشده',
                'battery' => $this->getAttr($product, 'Battery') ?: $this->getAttr($product, 'باتری') ?: 'درج نشده',
                'color'   => $this->getAttr($product, 'Color') ?: $this->getAttr($product, 'رنگ') ?: 'درج نشده',
                'os'      => $this->getAttr($product, 'OS') ?: $this->getAttr($product, 'سیستم عامل') ?: 'درج نشده',
            ],
            'attributes' => $product['attributes'] ?? [],
        ];
    }

    public function getAllProducts(array $params = []): array
    {
        $page = (int)($params['page'] ?? 1);
        $perPage = (int)($params['per_page'] ?? 100);
        $query = array_merge(['status' => 'publish', 'page' => $page, 'per_page' => $perPage], $params);
        $data = $this->request('GET', '/products', $query);
        return array_map([$this, 'formatProduct'], $data);
    }

    public function searchProducts(string $q): array
    {
        $data = $this->request('GET', '/products', [
            'search'   => $q,
            'per_page' => 20,
            'status'   => 'publish',
        ]);
        $filtered = array_values(array_filter($data, fn($p) => ($p['stock_status'] ?? '') === 'instock'));
        return array_map([$this, 'formatProduct'], $filtered);
    }

    public function getProductById(int $id): ?array
    {
        try {
            $data = $this->request('GET', "/products/{$id}");
            return $this->formatProduct($data);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getProductsByBrand(string $brand): array
    {
        $all = $this->getAllProducts(['per_page' => 100]);
        return array_values(array_filter(
            $all,
            fn($p) => mb_strtolower($p['brand']) === mb_strtolower($brand)
        ));
    }

    public function getAllBrands(): array
    {
        $all = $this->getAllProducts(['per_page' => 100]);
        $brands = [];
        foreach ($all as $p) {
            if ($p['brand'] !== 'نامشخص') $brands[$p['brand']] = true;
        }
        $result = array_keys($brands);
        sort($result);
        return $result;
    }

    public function getProductsInRange(float $min, float $max): array
    {
        $all = $this->getAllProducts(['per_page' => 100]);
        return array_values(array_filter(
            $all,
            fn($p) => $p['isInStock'] && $p['currentPriceRaw'] >= $min && $p['currentPriceRaw'] <= $max
        ));
    }

    public function testConnection(): array
    {
        try {
            if ($this->localMode) {
                $data = $this->localRequest('/products', ['per_page' => 1, 'status' => 'publish']);
                return [
                    'success' => true,
                    'count' => is_array($data) ? count($data) : 0,
                    'mode' => 'local-wp-load',
                    'wp_load' => $this->config['wp_load'],
                ];
            }
            $data = $this->request('GET', '/products', ['per_page' => 1]);
            return [
                'success' => true,
                'count' => is_array($data) ? count($data) : 0,
                'mode' => 'http-rest',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'local_mode' => $this->localMode ? 'yes' : 'no',
                'wp_load' => $this->config['wp_load'] ?: '(خالی)',
            ];
        }
    }
}
