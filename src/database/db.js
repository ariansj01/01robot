const mysql = require('mysql2/promise');
const { database } = require('../config');

const pool = mysql.createPool({
  host: database.host,
  port: database.port,
  user: database.user,
  password: database.password,
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0,
});

async function ensureDatabase() {
  try {
    await pool.execute(`CREATE DATABASE IF NOT EXISTS \`${database.name}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
    console.log(`✅ دیتابیس ${database.name} بررسی شد`);
  } catch (err) {
    console.error('❌ خطا در ساخت دیتابیس:', err.message);
  }
}

const dbPool = mysql.createPool({
  host: database.host,
  port: database.port,
  user: database.user,
  password: database.password,
  database: database.name,
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0,
});

async function ensureTables() {
  const createUsers = `
    CREATE TABLE IF NOT EXISTS users (
      id INT AUTO_INCREMENT PRIMARY KEY,
      telegram_id BIGINT UNIQUE NOT NULL,
      first_name VARCHAR(255),
      username VARCHAR(255),
      is_colleague TINYINT(1) DEFAULT 0,
      colleague_code VARCHAR(100),
      last_active DATETIME,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  `;

  const createTrackedProducts = `
    CREATE TABLE IF NOT EXISTS tracked_products (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      product_id INT NOT NULL,
      product_name VARCHAR(500),
      initial_price DECIMAL(15,2),
      initial_stock_status VARCHAR(50),
      notified_price_change TINYINT(1) DEFAULT 0,
      notified_stock_change TINYINT(1) DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      UNIQUE KEY unique_user_product (user_id, product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  `;

  const createColleaguePrices = `
    CREATE TABLE IF NOT EXISTS colleague_prices (
      id INT AUTO_INCREMENT PRIMARY KEY,
      product_name VARCHAR(500),
      product_model VARCHAR(255),
      brand VARCHAR(255),
      colleague_price DECIMAL(15,2),
      original_price DECIMAL(15,2),
      product_code VARCHAR(100),
      uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  `;

  const createFavorites = `
    CREATE TABLE IF NOT EXISTS favorites (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      product_id INT NOT NULL,
      product_name VARCHAR(500),
      product_price DECIMAL(15,2),
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      UNIQUE KEY unique_user_fav (user_id, product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  `;

  const createUploadHistory = `
    CREATE TABLE IF NOT EXISTS upload_history (
      id INT AUTO_INCREMENT PRIMARY KEY,
      file_name VARCHAR(500),
      uploaded_by BIGINT,
      record_count INT,
      uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  `;

  try {
    await dbPool.execute(createUsers);
    await dbPool.execute(createTrackedProducts);
    await dbPool.execute(createColleaguePrices);
    await dbPool.execute(createFavorites);
    await dbPool.execute(createUploadHistory);
    console.log('✅ جداول دیتابیس آماده هستند');
  } catch (err) {
    console.error('❌ خطا در ساخت جداول:', err.message);
  }
}

async function initializeDatabase() {
  await ensureDatabase();
  await new Promise(resolve => setTimeout(resolve, 100));
  await ensureTables();
}

module.exports = {
  pool: dbPool,
  initializeDatabase,
};
