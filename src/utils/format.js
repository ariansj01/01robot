function formatTooman(number) {
  if (!number || isNaN(Number(number))) return 'نامشخص';
  return Number(number).toLocaleString('fa-IR') + ' تومان';
}

function formatNumber(num) {
  if (!num || isNaN(Number(num))) return 'نامشخص';
  return Number(num).toLocaleString('fa-IR');
}

function productShortText(p) {
  const stockIcon = p.isInStock ? '✅ موجود' : '❌ ناموجود';
  const priceText = p.salePrice
    ? `💰 فروش: ${formatTooman(p.currentPrice)}  (${formatTooman(p.regularPrice)})`
    : `💰 قیمت: ${formatTooman(p.currentPrice)}`;
  return `*${p.name}*\n${stockIcon}\n${priceText}`;
}

function productFullText(p) {
  const lines = [];
  lines.push(`🛒 *${p.name}*`);
  lines.push('');
  lines.push(`🏷️ برند: ${p.brand}`);

  if (p.isInStock) {
    lines.push(`✅ موجودی: موجود در انبار`);
    if (p.stockQty !== 'نامشخص') {
      lines.push(`📦 تعداد موجود: ${formatNumber(p.stockQty)} عدد`);
    }
  } else {
    lines.push(`❌ موجودی: ناموجود`);
  }

  lines.push('');
  if (p.salePrice) {
    lines.push(`💥 قیمت ویژه: *${formatTooman(p.currentPrice)}*`);
    lines.push(`💰 قیمت اصلی: ${formatTooman(p.regularPrice)}`);
  } else {
    lines.push(`💰 قیمت: *${formatTooman(p.currentPrice)}*`);
  }

  lines.push('');
  lines.push('⚙️ مشخصات فنی:');
  lines.push(`├─ پردازنده (CPU): ${p.specs.cpu}`);
  lines.push(`├─ کارت گرافیک (GPU): ${p.specs.gpu}`);
  lines.push(`├─ حافظه رم: ${p.specs.ram}`);
  lines.push(`├─ حافظه داخلی (SSD): ${p.specs.ssd}`);
  lines.push(`├─ صفحه نمایش: ${p.specs.display}`);
  lines.push(`├─ باتری: ${p.specs.battery}`);
  lines.push(`├─ وزن: ${p.specs.weight}`);
  lines.push(`├─ رنگ: ${p.specs.color}`);
  lines.push(`└─ سیستم عامل: ${p.specs.os}`);

  if (p.description) {
    lines.push('');
    lines.push(`📝 توضیحات: ${p.description.substring(0, 200)}`);
  }

  return lines.join('\n');
}

function compareProductsText(products) {
  if (products.length === 0) return 'هیچ محصولی برای مقایسه وجود ندارد.';
  if (products.length === 1) return 'برای مقایسه حداقل ۲ محصول نیاز دارید.';

  const rows = [];
  rows.push(`*🔬 مقایسه ${products.length} لپ‌تاپ*`);
  rows.push('');
  rows.push('━━━━━━━━━━━━━━━━━━━━');

  const specsLabel = [
    { key: 'brand', label: 'برند' },
    { key: 'currentPrice', label: 'قیمت' },
    { key: 'isInStock', label: 'موجودی', fmt: (v) => (v ? '✅ موجود' : '❌ ناموجود') },
    { key: ['specs', 'cpu'], label: 'CPU / پردازنده' },
    { key: ['specs', 'gpu'], label: 'GPU / گرافیک' },
    { key: ['specs', 'ram'], label: 'رم (RAM)' },
    { key: ['specs', 'ssd'], label: 'SSD / حافظه' },
    { key: ['specs', 'display'], label: 'صفحه نمایش' },
    { key: ['specs', 'battery'], label: 'باتری' },
    { key: ['specs', 'weight'], label: 'وزن' },
    { key: ['specs', 'color'], label: 'رنگ' },
    { key: ['specs', 'os'], label: 'سیستم عامل' },
  ];

  const getVal = (p, key) => {
    if (Array.isArray(key)) {
      return key.reduce((obj, k) => (obj ? obj[k] : null), p);
    }
    return p[key];
  };

  for (const spec of specsLabel) {
    const label = spec.label.padEnd(16, ' ');
    rows.push(`*${label}*`);
    products.forEach((p, i) => {
      const rawVal = getVal(p, spec.key);
      const val = spec.fmt ? spec.fmt(rawVal) : rawVal;
      const header = i === 0 ? '├─ ' : i === products.length - 1 ? '└─ ' : '├─ ';
      const shortName = p.name.length > 14 ? p.name.substring(0, 11) + '...' : p.name;
      rows.push(`${header}[${shortName}]  ${val}`);
    });
    rows.push('');
  }

  rows.push('━━━━━━━━━━━━━━━━━━━━');
  rows.push('');
  rows.push('*💡 خلاصه تفاوت‌ها:*');
  rows.push(summarizeDifferences(products));

  return rows.join('\n');
}

function summarizeDifferences(products) {
  const diffs = [];
  const prices = products.map((p) => p.currentPriceRaw).filter((x) => x > 0);

  if (prices.length >= 2) {
    const min = Math.min(...prices);
    const max = Math.max(...prices);
    const diff = max - min;
    if (diff > 0) {
      const cheap = products.find((p) => p.currentPriceRaw === min);
      const expsv = products.find((p) => p.currentPriceRaw === max);
      diffs.push(
        `💰 ارزان‌ترین: ${cheap.name.substring(0, 20)} (${formatTooman(min)}) گران‌ترین: ${expsv.name.substring(0, 20)} (${formatTooman(max)}) اختلاف: ${formatTooman(diff)}`
      );
    }
  }

  const bestCpu = findBest(products, ['specs', 'cpu']);
  if (bestCpu) diffs.push(`🚀 قوی‌ترین CPU: ${bestCpu.name.substring(0, 25)}`);

  const bestGpu = findBest(products, ['specs', 'gpu']);
  if (bestGpu) diffs.push(`🎮 قوی‌ترین GPU: ${bestGpu.name.substring(0, 25)}`);

  const inStock = products.filter((p) => p.isInStock).length;
  if (inStock < products.length) {
    diffs.push(`📦 فقط ${inStock} از ${products.length} محصول موجود هستند.`);
  } else {
    diffs.push(`✅ همه محصولات موجود هستند.`);
  }

  return diffs.join('\n').substring(0, 1500);
}

function findBest(products, specKey) {
  const getVal = (p) => {
    const v = specKey.reduce((o, k) => (o ? o[k] : null), p);
    return v ? v.toString() : '';
  };
  const scoreMap = { '9': 9, '8': 8, '7': 7, '6': 6, '5': 5, 'rtx': 10, 'rx': 8, 'gtx': 7, 'i9': 9, 'i7': 8, 'i5': 7, 'i3': 6, 'ryzen 9': 9, 'ryzen 7': 8, 'ryzen 5': 7, 'ryzen 3': 6, 'ultra 9': 9, 'ultra 7': 8, 'ultra 5': 7 };
  let best = null;
  let bestScore = -1;
  products.forEach((p) => {
    const val = getVal(p).toLowerCase();
    let score = 0;
    for (const k in scoreMap) {
      if (val.includes(k)) score = Math.max(score, scoreMap[k]);
    }
    if (score > bestScore) {
      bestScore = score;
      best = p;
    }
  });
  return bestScore > 0 ? best : null;
}

function splitLongMessage(text, maxLen = 4000) {
  if (text.length <= maxLen) return [text];
  const chunks = [];
  let current = '';
  const lines = text.split('\n');
  for (const line of lines) {
    if ((current + '\n' + line).length > maxLen) {
      chunks.push(current);
      current = line;
    } else {
      current += (current ? '\n' : '') + line;
    }
  }
  if (current) chunks.push(current);
  return chunks;
}

function formatColleaguePriceList(items) {
  const lines = [];
  lines.push('📊 *لیست قیمت همکاران*');
  lines.push('');

  let currentBrand = '';
  items.forEach((item) => {
    if (item.brand && item.brand !== currentBrand) {
      lines.push(`\n━━━━ *${item.brand}* ━━━━`);
      currentBrand = item.brand;
    }
    const name = item.product_name || item.product_model || 'نامشخص';
    const price = formatTooman(item.colleague_price);
    const code = item.product_code ? ` [${item.product_code}]` : '';
    lines.push(`• ${name}${code} → *${price}*`);
  });

  if (items.length === 0) {
    lines.push('⚠️ لیست قیمت همکاران خالی است.');
  }

  return lines.join('\n');
}

module.exports = {
  formatTooman,
  formatNumber,
  productShortText,
  productFullText,
  compareProductsText,
  splitLongMessage,
  formatColleaguePriceList,
};
