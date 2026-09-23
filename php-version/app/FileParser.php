<?php

namespace Bot;

class FileParser
{
    public static function importColleaguePrices(string $filePath, string $originalName, int $uploadedBy, string $uploadsDir): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $rows = [];

        if ($ext === 'csv') {
            $rows = self::parseCsv($filePath);
        } elseif (in_array($ext, ['xlsx','xls'])) {
            $rows = self::parseExcel($filePath);
        } else {
            throw new \RuntimeException('فرمت فایل پشتیبانی نمی‌شود (CSV, XLSX, XLS).');
        }

        if (count($rows) === 0) {
            throw new \RuntimeException('فایل خالی است یا خطا در خواندن.');
        }

        $items = [];
        foreach ($rows as $row) {
            $item = self::normalize($row);
            if (!empty($item['productName']) || !empty($item['productModel'])) {
                $items[] = $item;
            }
        }

        if (count($items) === 0) {
            throw new \RuntimeException('هیچ رکورد معتبری در فایل پیدا نشد.');
        }

        Repo::clearColleaguePrices();
        foreach ($items as $it) Repo::addColleaguePrice($it);
        Repo::recordUpload($originalName, $uploadedBy, count($items));

        $brands = array_values(array_unique(array_filter(array_column($items, 'brand'))));
        return ['count' => count($items), 'brands' => $brands];
    }

    private static function parseCsv(string $path): array
    {
        $rows = [];
        if (!file_exists($path)) return [];
        $handle = fopen($path, 'r');
        $headers = fgetcsv($handle);
        if (!$headers) { fclose($handle); return []; }
        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) < count($headers)) $data = array_pad($data, count($headers), null);
            $rows[] = array_combine($headers, array_slice($data, 0, count($headers)));
        }
        fclose($handle);
        return $rows;
    }

    private static function parseExcel(string $path): array
    {
        $ioFactoryClass = 'PhpOffice\\PhpSpreadsheet\\IOFactory';
        if (!class_exists($ioFactoryClass)) {
            throw new \RuntimeException('پشتیبانی از فایل اکسل (xlsx/xls) فعال نیست. لطفاً فایل CSV آپلود کنید یا پکیج phpoffice/phpspreadsheet را نصب کنید.');
        }
        $spreadsheet = $ioFactoryClass::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = [];
        $header = [];
        foreach ($sheet->getRowIterator() as $rowIdx => $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            $values = [];
            foreach ($cellIterator as $cell) $values[] = $cell->getCalculatedValue();
            if ($rowIdx === 1) {
                $header = array_map(fn($v) => trim((string)$v), $values);
                continue;
            }
            if (count($values) < count($header)) $values = array_pad($values, count($header), null);
            $rows[] = array_combine($header, array_slice($values, 0, count($header)));
        }
        return $rows;
    }

    private static function findKey(array $row, array $patterns): ?string
    {
        $keys = array_keys($row);
        foreach ($patterns as $pat) {
            foreach ($keys as $k) {
                if (stripos($k, $pat) !== false) return $k;
            }
        }
        return null;
    }

    private static function parsePrice($v): float
    {
        if ($v === null || $v === '') return 0;
        $s = preg_replace('/[,،\s]/u', '', (string)$v);
        $n = (float)$s;
        return is_numeric($n) ? $n : 0;
    }

    private static function normalize(array $row): array
    {
        $kName    = self::findKey($row, ['نام محصول','نام','اسم','product','name','title']) ?: array_key_first($row);
        $kModel   = self::findKey($row, ['مدل','model']);
        $kBrand   = self::findKey($row, ['برند','brand']) ?: array_key_first($row);
        $kColl    = self::findKey($row, ['قیمت همکار','همکار','قیمت خرید','colleague','dealer','buy_price']) ?: array_key_first($row);
        $kOrigin  = self::findKey($row, ['قیمت فروش','قیمت اصلی','قیمت','price','sale']) ?: array_key_first($row);
        $kCode    = self::findKey($row, ['کد محصول','کد','sku','code']);

        return [
            'productName'    => trim((string)($row[$kName] ?? '')),
            'productModel'   => $kModel ? trim((string)($row[$kModel] ?? '')) : '',
            'brand'          => trim((string)($row[$kBrand] ?? '')),
            'colleaguePrice' => self::parsePrice($row[$kColl] ?? 0),
            'originalPrice'  => self::parsePrice($row[$kOrigin] ?? 0),
            'productCode'    => $kCode ? trim((string)($row[$kCode] ?? '')) : '',
        ];
    }
}
