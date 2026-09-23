const { Markup } = require('telegraf');

function mainMenu() {
  return Markup.inlineKeyboard(
    [
      [Markup.button.callback('💻 محصولات', 'products')],
      [Markup.button.callback('🔎 جستجوی لپ‌تاپ', 'search')],
      [Markup.button.callback('⚖️ مقایسه لپ‌تاپ', 'compare')],
      [Markup.button.callback('📦 موجودی و قیمت', 'pricelist')],
      [Markup.button.callback('📊 لیست قیمت همکار', 'colleague_price')],
      [Markup.button.callback('🔔 پیگیری محصول', 'tracking_menu')],
      [Markup.button.callback('⭐ علاقه‌مندی‌ها', 'favorites')],
      [Markup.button.callback('💰 جستجو بر اساس بودجه', 'budget_search')],
      [Markup.button.callback('☎️ ارتباط با فروشنده', 'contact')],
    ],
    { columns: 1 }
  );
}

function brandsMenu(brands, actionPrefix = 'brand') {
  const buttons = brands.map((brand) => [
    Markup.button.callback(`🏷️ ${brand}`, `${actionPrefix}:${brand}`),
  ]);
  buttons.push([Markup.button.callback('🔙 بازگشت', 'main_menu')]);
  return Markup.inlineKeyboard(buttons);
}

function productsMenu(products, actionPrefix = 'product') {
  const buttons = [];
  products.forEach((p) => {
    const shortName = p.name.length > 40 ? p.name.substring(0, 37) + '...' : p.name;
    const icon = p.isInStock ? '✅' : '❌';
    buttons.push([
      Markup.button.callback(`${icon} ${shortName}`, `${actionPrefix}:${p.id}`),
    ]);
  });
  buttons.push([Markup.button.callback('🔙 بازگشت به برندها', 'products')]);
  buttons.push([Markup.button.callback('🏠 منوی اصلی', 'main_menu')]);
  return Markup.inlineKeyboard(buttons);
}

function productActionMenu(productId, isTracked = false, isFavorited = false, permalink = null) {
  const buttons = [
    [
      Markup.button.callback(
        isTracked ? '🔔 پیگیری فعال است' : '🔔 فعال کردن پیگیری',
        `track:${productId}`
      ),
    ],
    [
      Markup.button.callback(
        isFavorited ? '⭐ در علاقه‌مندی‌ها' : '⭐ افزودن به علاقه‌مندی‌ها',
        `fav:${productId}`
      ),
    ],
    [Markup.button.callback('➕ افزودن برای مقایسه', `add_compare:${productId}`)],
  ];
  if (permalink && permalink !== '') {
    buttons.push([Markup.button.url('🛒 مشاهده در سایت', permalink)]);
  }
  buttons.push([Markup.button.callback('🔙 بازگشت', 'products')]);
  buttons.push([Markup.button.callback('🏠 منوی اصلی', 'main_menu')]);
  return Markup.inlineKeyboard(buttons, { columns: 1 });
}

function compareListMenu(items = []) {
  const buttons = [];
  if (items.length > 0) {
    buttons.push([
      Markup.button.callback(`🔬 مقایسه کن (${items.length} محصول)`, 'do_compare'),
    ]);
  }
  items.forEach((p, idx) => {
    const name = p.name.length > 35 ? p.name.substring(0, 32) + '...' : p.name;
    buttons.push([
      Markup.button.callback(`❌ حذف: ${name}`, `rm_compare:${idx}`),
    ]);
  });
  buttons.push([Markup.button.callback('🗑️ خالی کردن لیست', 'clear_compare')]);
  buttons.push([Markup.button.callback('🔙 انتخاب محصول', 'compare')]);
  buttons.push([Markup.button.callback('🏠 منوی اصلی', 'main_menu')]);
  return Markup.inlineKeyboard(buttons, { columns: 1 });
}

function trackingMenu() {
  return Markup.inlineKeyboard(
    [
      [Markup.button.callback('📋 لیست محصولات پیگیری شده', 'list_tracking')],
      [Markup.button.callback('➕ افزودن محصول برای پیگیری', 'products')],
      [Markup.button.callback('🏠 منوی اصلی', 'main_menu')],
    ],
    { columns: 1 }
  );
}

function trackedListMenu(trackedItems) {
  const buttons = [];
  trackedItems.forEach((t) => {
    const name = (t.product_name || '').length > 35
      ? t.product_name.substring(0, 32) + '...'
      : t.product_name;
    buttons.push([
      Markup.button.callback(`❌ حذف: ${name}`, `untrack:${t.id}`),
    ]);
  });
  buttons.push([Markup.button.callback('🔙 بازگشت', 'tracking_menu')]);
  buttons.push([Markup.button.callback('🏠 منوی اصلی', 'main_menu')]);
  return Markup.inlineKeyboard(buttons, { columns: 1 });
}

function budgetRanges() {
  return Markup.inlineKeyboard(
    [
      [Markup.button.callback('زیر ۳۰ میلیون', 'budget:0:30000000')],
      [Markup.button.callback('۳۰ تا ۵۰ میلیون', 'budget:30000000:50000000')],
      [Markup.button.callback('۵۰ تا ۷۰ میلیون', 'budget:50000000:70000000')],
      [Markup.button.callback('۷۰ تا ۱۰۰ میلیون', 'budget:70000000:100000000')],
      [Markup.button.callback('بالای ۱۰۰ میلیون', 'budget:100000000:999999999999')],
      [Markup.button.callback('🔙 منوی اصلی', 'main_menu')],
    ],
    { columns: 1 }
  );
}

function contactMenu() {
  return Markup.inlineKeyboard(
    [
      [Markup.button.url('🌐 صفحه ارتباط با ما', 'https://zil.ink/jalily_computer')],
      [Markup.button.callback('🏠 منوی اصلی', 'main_menu')],
    ],
    { columns: 1 }
  );
}

function backButton(action) {
  return Markup.inlineKeyboard([
    [Markup.button.callback('🔙 بازگشت', action || 'main_menu')],
  ]);
}

module.exports = {
  mainMenu,
  brandsMenu,
  productsMenu,
  productActionMenu,
  compareListMenu,
  trackingMenu,
  trackedListMenu,
  budgetRanges,
  contactMenu,
  backButton,
};
