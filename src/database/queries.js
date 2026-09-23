const { pool } = require('./db');

async function getUserByTelegramId(telegramId) {
  const [rows] = await pool.execute(
    'SELECT * FROM users WHERE telegram_id = ?',
    [telegramId]
  );
  return rows[0] || null;
}

async function createOrUpdateUser(telegramId, firstName, username) {
  const existing = await getUserByTelegramId(telegramId);
  if (existing) {
    await pool.execute(
      'UPDATE users SET first_name = ?, username = ?, last_active = NOW() WHERE telegram_id = ?',
      [firstName || null, username || null, telegramId]
    );
    return existing;
  }
  await pool.execute(
    'INSERT INTO users (telegram_id, first_name, username, last_active) VALUES (?, ?, ?, NOW())',
    [telegramId, firstName || null, username || null]
  );
  return await getUserByTelegramId(telegramId);
}

async function setColleague(telegramId, colleagueCode) {
  await pool.execute(
    'UPDATE users SET is_colleague = 1, colleague_code = ?, last_active = NOW() WHERE telegram_id = ?',
    [colleagueCode, telegramId]
  );
}

async function addTrackedProduct(userId, productId, productName, initialPrice, initialStock) {
  try {
    await pool.execute(
      `INSERT INTO tracked_products 
       (user_id, product_id, product_name, initial_price, initial_stock_status) 
       VALUES (?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE 
         initial_price = VALUES(initial_price),
         initial_stock_status = VALUES(initial_stock_status),
         notified_price_change = 0,
         notified_stock_change = 0`,
      [userId, productId, productName, initialPrice, initialStock]
    );
    return true;
  } catch (err) {
    console.error('❌ خطا در افزودن محصول پیگیری:', err.message);
    return false;
  }
}

async function removeTrackedProduct(userId, productId) {
  await pool.execute(
    'DELETE FROM tracked_products WHERE user_id = ? AND product_id = ?',
    [userId, productId]
  );
}

async function getTrackedProductsByUser(userId) {
  const [rows] = await pool.execute(
    'SELECT * FROM tracked_products WHERE user_id = ? ORDER BY created_at DESC',
    [userId]
  );
  return rows;
}

async function getAllTrackedProducts() {
  const [rows] = await pool.execute(
    `SELECT tp.*, u.telegram_id 
     FROM tracked_products tp 
     JOIN users u ON tp.user_id = u.id`
  );
  return rows;
}

async function updateTrackedNotified(trackId, field) {
  await pool.execute(
    `UPDATE tracked_products SET ${field} = 1 WHERE id = ?`,
    [trackId]
  );
}

async function clearColleaguePrices() {
  await pool.execute('TRUNCATE TABLE colleague_prices');
}

async function addColleaguePrice(item) {
  await pool.execute(
    `INSERT INTO colleague_prices 
     (product_name, product_model, brand, colleague_price, original_price, product_code) 
     VALUES (?, ?, ?, ?, ?, ?)`,
    [
      item.productName || '',
      item.productModel || '',
      item.brand || '',
      item.colleaguePrice || 0,
      item.originalPrice || 0,
      item.productCode || '',
    ]
  );
}

async function getAllColleaguePrices(brand = null) {
  if (brand) {
    const [rows] = await pool.execute(
      'SELECT * FROM colleague_prices WHERE brand = ? ORDER BY brand, product_name',
      [brand]
    );
    return rows;
  }
  const [rows] = await pool.execute(
    'SELECT * FROM colleague_prices ORDER BY brand, product_name'
  );
  return rows;
}

async function getColleagueBrands() {
  const [rows] = await pool.execute(
    'SELECT DISTINCT brand FROM colleague_prices WHERE brand IS NOT NULL AND brand != "" ORDER BY brand'
  );
  return rows.map((r) => r.brand);
}

async function addFavorite(userId, productId, productName, productPrice) {
  try {
    await pool.execute(
      `INSERT INTO favorites (user_id, product_id, product_name, product_price) 
       VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE created_at = NOW()`,
      [userId, productId, productName, productPrice]
    );
    return true;
  } catch (err) {
    return false;
  }
}

async function removeFavorite(userId, productId) {
  await pool.execute(
    'DELETE FROM favorites WHERE user_id = ? AND product_id = ?',
    [userId, productId]
  );
}

async function getFavorites(userId) {
  const [rows] = await pool.execute(
    'SELECT * FROM favorites WHERE user_id = ? ORDER BY created_at DESC',
    [userId]
  );
  return rows;
}

async function recordUpload(fileName, uploadedBy, recordCount) {
  await pool.execute(
    'INSERT INTO upload_history (file_name, uploaded_by, record_count) VALUES (?, ?, ?)',
    [fileName, uploadedBy, recordCount]
  );
}

module.exports = {
  getUserByTelegramId,
  createOrUpdateUser,
  setColleague,
  addTrackedProduct,
  removeTrackedProduct,
  getTrackedProductsByUser,
  getAllTrackedProducts,
  updateTrackedNotified,
  clearColleaguePrices,
  addColleaguePrice,
  getAllColleaguePrices,
  getColleagueBrands,
  addFavorite,
  removeFavorite,
  getFavorites,
  recordUpload,
};
