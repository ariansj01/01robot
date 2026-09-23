<?php

namespace Bot;

use PDO;

class Repo
{
    public static function getUser(int $telegramId): ?array
    {
        $pdo = Database::connection();
        $stm = $pdo->prepare('SELECT * FROM users WHERE telegram_id = ?');
        $stm->execute([$telegramId]);
        $row = $stm->fetch();
        return $row ?: null;
    }

    public static function createOrUpdateUser(int $telegramId, ?string $firstName, ?string $username): array
    {
        $existing = self::getUser($telegramId);
        $pdo = Database::connection();
        if ($existing) {
            $stm = $pdo->prepare('UPDATE users SET first_name = ?, username = ?, last_active = NOW() WHERE telegram_id = ?');
            $stm->execute([$firstName, $username, $telegramId]);
            return $existing;
        }
        $stm = $pdo->prepare('INSERT INTO users (telegram_id, first_name, username, last_active) VALUES (?, ?, ?, NOW())');
        $stm->execute([$telegramId, $firstName, $username]);
        return self::getUser($telegramId);
    }

    public static function addTrackedProduct(int $userId, int $productId, string $name, float $price, string $stock): bool
    {
        $pdo = Database::connection();
        $sql = "INSERT INTO tracked_products (user_id, product_id, product_name, initial_price, initial_stock_status)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    initial_price = VALUES(initial_price),
                    initial_stock_status = VALUES(initial_stock_status),
                    notified_price_change = 0,
                    notified_stock_change = 0";
        $stm = $pdo->prepare($sql);
        return $stm->execute([$userId, $productId, $name, $price, $stock]);
    }

    public static function removeTrackedProduct(int $userId, int $productId): void
    {
        $pdo = Database::connection();
        $stm = $pdo->prepare('DELETE FROM tracked_products WHERE user_id = ? AND product_id = ?');
        $stm->execute([$userId, $productId]);
    }

    public static function getTrackedByUser(int $userId): array
    {
        $pdo = Database::connection();
        $stm = $pdo->prepare('SELECT * FROM tracked_products WHERE user_id = ? ORDER BY created_at DESC');
        $stm->execute([$userId]);
        return $stm->fetchAll();
    }

    public static function getAllTrackedWithUsers(): array
    {
        $pdo = Database::connection();
        $sql = "SELECT tp.*, u.telegram_id
                FROM tracked_products tp
                JOIN users u ON tp.user_id = u.id";
        return $pdo->query($sql)->fetchAll();
    }

    public static function updateNotified(int $trackId, string $field): void
    {
        $allowed = ['notified_price_change', 'notified_stock_change'];
        if (!in_array($field, $allowed, true)) return;
        $pdo = Database::connection();
        $stm = $pdo->prepare("UPDATE tracked_products SET {$field} = 1 WHERE id = ?");
        $stm->execute([$trackId]);
    }

    public static function clearColleaguePrices(): void
    {
        Database::connection()->exec('TRUNCATE TABLE colleague_prices');
    }

    public static function addColleaguePrice(array $item): void
    {
        $pdo = Database::connection();
        $stm = $pdo->prepare(
            'INSERT INTO colleague_prices (product_name, product_model, brand, colleague_price, original_price, product_code)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stm->execute([
            $item['productName'] ?? '',
            $item['productModel'] ?? '',
            $item['brand'] ?? '',
            $item['colleaguePrice'] ?? 0,
            $item['originalPrice'] ?? 0,
            $item['productCode'] ?? '',
        ]);
    }

    public static function getColleaguePrices(?string $brand = null): array
    {
        $pdo = Database::connection();
        if ($brand) {
            $stm = $pdo->prepare('SELECT * FROM colleague_prices WHERE brand = ? ORDER BY brand, product_name');
            $stm->execute([$brand]);
            return $stm->fetchAll();
        }
        return $pdo->query('SELECT * FROM colleague_prices ORDER BY brand, product_name')->fetchAll();
    }

    public static function getColleagueBrands(): array
    {
        $pdo = Database::connection();
        $rows = $pdo->query(
            "SELECT DISTINCT brand FROM colleague_prices WHERE brand IS NOT NULL AND brand != '' ORDER BY brand"
        )->fetchAll();
        return array_column($rows, 'brand');
    }

    public static function addFavorite(int $userId, int $productId, string $name, float $price): bool
    {
        $pdo = Database::connection();
        $sql = "INSERT INTO favorites (user_id, product_id, product_name, product_price)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE created_at = NOW()";
        $stm = $pdo->prepare($sql);
        return $stm->execute([$userId, $productId, $name, $price]);
    }

    public static function removeFavorite(int $userId, int $productId): void
    {
        $pdo = Database::connection();
        $stm = $pdo->prepare('DELETE FROM favorites WHERE user_id = ? AND product_id = ?');
        $stm->execute([$userId, $productId]);
    }

    public static function getFavorites(int $userId): array
    {
        $pdo = Database::connection();
        $stm = $pdo->prepare('SELECT * FROM favorites WHERE user_id = ? ORDER BY created_at DESC');
        $stm->execute([$userId]);
        return $stm->fetchAll();
    }

    public static function clearFavorites(int $userId): void
    {
        $pdo = Database::connection();
        $stm = $pdo->prepare('DELETE FROM favorites WHERE user_id = ?');
        $stm->execute([$userId]);
    }

    public static function recordUpload(string $fileName, int $uploadedBy, int $count): void
    {
        $pdo = Database::connection();
        $stm = $pdo->prepare('INSERT INTO upload_history (file_name, uploaded_by, record_count) VALUES (?, ?, ?)');
        $stm->execute([$fileName, $uploadedBy, $count]);
    }

    public static function getState(int $telegramId): ?array
    {
        $pdo = Database::connection();
        $stm = $pdo->prepare('SELECT state_json FROM user_state WHERE telegram_id = ?');
        $stm->execute([$telegramId]);
        $row = $stm->fetch();
        if (!$row || !$row['state_json']) return null;
        $decoded = json_decode($row['state_json'], true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function setState(int $telegramId, ?array $state): void
    {
        $pdo = Database::connection();
        $json = $state === null ? null : json_encode($state, JSON_UNESCAPED_UNICODE);
        $sql = "INSERT INTO user_state (telegram_id, state_json) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE state_json = VALUES(state_json)";
        $stm = $pdo->prepare($sql);
        $stm->execute([$telegramId, $json]);
    }

    public static function getCompareList(int $telegramId): array
    {
        $pdo = Database::connection();
        $stm = $pdo->prepare('SELECT product_json FROM compare_list WHERE telegram_id = ? ORDER BY added_at ASC');
        $stm->execute([$telegramId]);
        $items = [];
        foreach ($stm->fetchAll() as $r) {
            $d = json_decode($r['product_json'], true);
            if (is_array($d)) $items[] = $d;
        }
        return $items;
    }

    public static function addCompareItem(int $telegramId, int $productId, array $product): bool
    {
        $pdo = Database::connection();
        $stm = $pdo->prepare('SELECT COUNT(*) FROM compare_list WHERE telegram_id = ?');
        $stm->execute([$telegramId]);
        $count = (int)$stm->fetchColumn();
        if ($count >= 4) return false;
        $stm = $pdo->prepare(
            "INSERT IGNORE INTO compare_list (telegram_id, product_id, product_json) VALUES (?, ?, ?)"
        );
        return $stm->execute([$telegramId, $productId, json_encode($product, JSON_UNESCAPED_UNICODE)]);
    }

    public static function removeCompareItemByIndex(int $telegramId, int $index): void
    {
        $items = self::getCompareList($telegramId);
        if (!isset($items[$index])) return;
        $item = $items[$index];
        $pid = (int)($item['id'] ?? 0);
        $pdo = Database::connection();
        $stm = $pdo->prepare('DELETE FROM compare_list WHERE telegram_id = ? AND product_id = ?');
        $stm->execute([$telegramId, $pid]);
    }

    public static function clearCompareList(int $telegramId): void
    {
        $pdo = Database::connection();
        $stm = $pdo->prepare('DELETE FROM compare_list WHERE telegram_id = ?');
        $stm->execute([$telegramId]);
    }
}
