<?php

namespace Bot;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $pdo = null;
    private static array $config;

    public static function init(array $config): void
    {
        self::$config = $config['database'];
    }

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = self::$config;
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['charset']
        );

        try {
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('خطا در اتصال به دیتابیس: ' . $e->getMessage());
        }

        return self::$pdo;
    }

    public static function ensureDatabase(): void
    {
        $cfg = self::$config;
        $name = $cfg['name'];
        $pdo = self::connection();
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$name}`");
    }

    public static function ensureTables(): void
    {
        $pdo = self::connection();

        $tables = [
            "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                telegram_id BIGINT UNIQUE NOT NULL,
                first_name VARCHAR(255) NULL,
                username VARCHAR(255) NULL,
                last_active DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS tracked_products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                product_id INT NOT NULL,
                product_name VARCHAR(500),
                initial_price DECIMAL(15,2),
                initial_stock_status VARCHAR(50),
                notified_price_change TINYINT(1) DEFAULT 0,
                notified_stock_change TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_product (user_id, product_id),
                CONSTRAINT fk_tp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS colleague_prices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_name VARCHAR(500),
                product_model VARCHAR(255),
                brand VARCHAR(255),
                colleague_price DECIMAL(15,2),
                original_price DECIMAL(15,2),
                product_code VARCHAR(100),
                uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS favorites (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                product_id INT NOT NULL,
                product_name VARCHAR(500),
                product_price DECIMAL(15,2),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_fav (user_id, product_id),
                CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS upload_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                file_name VARCHAR(500),
                uploaded_by BIGINT,
                record_count INT,
                uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS user_state (
                telegram_id BIGINT PRIMARY KEY,
                state_json TEXT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS compare_list (
                telegram_id BIGINT NOT NULL,
                product_id INT NOT NULL,
                product_json TEXT,
                added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (telegram_id, product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($tables as $sql) {
            $pdo->exec($sql);
        }
    }

    public static function bootstrap(array $config): void
    {
        self::init($config);
        self::ensureDatabase();
        self::ensureTables();
    }
}
