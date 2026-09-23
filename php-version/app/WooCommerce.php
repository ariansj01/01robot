<?php

namespace Bot;

class WooCommerce
{
    private array $config;
    private string $baseUrl;

    public function __construct(array $config)
    {
        $this->config = $config['woocommerce'];
        $this->baseUrl = rtrim($this->config['url'], '/') . '/wp-json/wc/v3';
    }

    private function doCurl(string $url, string $method, bool $useBasicAuth): array
    {
        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
            'User-Agent: Computer01-TelegramBot/1.0',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_FOLLOWLOCATION => true,
        ];
        if ($useBasicAuth) {
            $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $opts[CURLOPT_USERPWD] = $this->config['consumer_key'] . ':' . $this->config['consumer_secret'];
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return [
            'body' => $response === false ? '' : (string)$response,
            'code' => $httpCode,
            'error' => $error,
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
                $snippet = trim(strip_tags(mb_substr($res['body'], 0, 180)));
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
        $key = $this->config['consumer_key'] ?? '';
        $secret = $this->config['consumer_secret'] ?? '';
        if ($key === '' || $secret === '') {
            throw new \RuntimeException('کلیدهای WC_CONSUMER_KEY / WC_CONSUMER_SECRET در .env خالی هستند.');
        }

        // 1) Basic Auth (مثل نسخه Node) — برای HTTPS معمولاً درست کار می‌کند
        $urlBasic = $this->baseUrl . $path;
        if (!empty($query)) {
            $urlBasic .= '?' . http_build_query($query);
        }
        $resBasic = $this->doCurl($urlBasic, $method, true);
        if ($resBasic['error'] === '' && $resBasic['code'] > 0 && $resBasic['code'] < 400) {
            return $this->parseWcResponse($resBasic);
        }

        // 2) Query Auth — اگر هاست هدر Authorization را حذف کند
        $queryAuth = array_merge([
            'consumer_key'    => $key,
            'consumer_secret' => $secret,
        ], $query);
        $urlQuery = $this->baseUrl . $path . '?' . http_build_query($queryAuth);
        $resQuery = $this->doCurl($urlQuery, $method, false);
        if ($resQuery['error'] === '' && $resQuery['code'] > 0 && $resQuery['code'] < 400) {
            return $this->parseWcResponse($resQuery);
        }

        // هر دو شکست خوردند — خطای واضح‌تر را برگردان
        try {
            return $this->parseWcResponse($resBasic['code'] > 0 ? $resBasic : $resQuery);
        } catch (\Throwable $e1) {
            try {
                return $this->parseWcResponse($resQuery);
            } catch (\Throwable $e2) {
                throw new \RuntimeException($e1->getMessage() . ' | fallback: ' . $e2->getMessage());
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
            $data = $this->request('GET', '/products', ['per_page' => 1]);
            return ['success' => true, 'count' => is_array($data) ? count($data) : 0];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
