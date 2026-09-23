const XLSX = require('xlsx');
const fs = require('fs');
const csv = require('csv-parser');
const { clearColleaguePrices, addColleaguePrice, recordUpload } = require('../database/queries');

async function parseExcelOrCsv(filePath, originalName) {
  const ext = (originalName || filePath).split('.').pop().toLowerCase();
  const rows = [];

  if (ext === 'xlsx' || ext === 'xls') {
    const workbook = XLSX.readFile(filePath);
    const sheetName = workbook.SheetNames[0];
    const sheet = workbook.Sheets[sheetName];
    const jsonData = XLSX.utils.sheet_to_json(sheet, { defval: '' });
    rows.push(...jsonData);
  } else if (ext === 'csv') {
    await new Promise((resolve, reject) => {
      const stream = fs.createReadStream(filePath)
        .pipe(csv())
        .on('data', (row) => rows.push(row))
        .on('end', resolve)
        .on('error', reject);
    });
  } else {
    throw new Error('فرمت فایل پشتیبانی نمی‌شود. فقط CSV و اکسل (xlsx/xls)');
  }

  return rows;
}

function normalizeRow(row) {
  const keys = Object.keys(row);
  const findKey = (patterns) => {
    for (const pat of patterns) {
      for (const k of keys) {
        if (k.toLowerCase().includes(pat.toLowerCase())) return k;
      }
    }
    return null;
  };

  const kName = findKey(['نام محصول', 'نام', 'اسم', 'product', 'name', 'title']) || keys[0];
  const kModel = findKey(['مدل', 'model']) || keys[0];
  const kBrand = findKey(['برند', 'brand']) || keys[0];
  const kColleague = findKey(['قیمت همکار', 'همکار', 'قیمت خرید', 'colleague', 'dealer', 'buy_price']) || keys[0];
  const kOriginal = findKey(['قیمت فروش', 'قیمت اصلی', 'قیمت', 'price', 'sale']) || keys[0];
  const kCode = findKey(['کد محصول', 'کد', 'sku', 'code']) || null;

  const parsePrice = (v) => {
    if (!v) return 0;
    const s = v.toString().replace(/[,،\s]/g, '');
    const n = parseFloat(s);
    return isNaN(n) ? 0 : n;
  };

  return {
    productName: (row[kName] || '').toString().trim(),
    productModel: (row[kModel] || '').toString().trim(),
    brand: (row[kBrand] || '').toString().trim(),
    colleaguePrice: parsePrice(row[kColleague]),
    originalPrice: parsePrice(row[kOriginal]),
    productCode: kCode ? (row[kCode] || '').toString().trim() : '',
  };
}

async function importColleaguePrices(filePath, originalName, uploadedBy) {
  const rawRows = await parseExcelOrCsv(filePath, originalName);
  if (rawRows.length === 0) {
    throw new Error('فایل خالی است یا خطا در خواندن.');
  }

  const items = rawRows
    .map(normalizeRow)
    .filter((r) => r.productName || r.productModel);

  if (items.length === 0) {
    throw new Error('هیچ رکورد معتبری در فایل پیدا نشد.');
  }

  await clearColleaguePrices();

  for (const item of items) {
    await addColleaguePrice(item);
  }

  await recordUpload(originalName, uploadedBy, items.length);

  return {
    count: items.length,
    brands: [...new Set(items.map((i) => i.brand).filter(Boolean))],
  };
}

module.exports = {
  importColleaguePrices,
  parseExcelOrCsv,
};
