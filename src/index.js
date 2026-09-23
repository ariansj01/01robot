const { Telegraf, session, Scenes } = require('telegraf');
const fs = require('fs');
const path = require('path');
const os = require('os');

const { bot: botCfg, admin, contact } = require('./config');
const { initializeDatabase } = require('./database/db');
const db = require('./database/queries');
const wc = require('./services/woocommerce');
const { importColleaguePrices } = require('./services/fileParser');
const { startScheduler } = require('./services/scheduler');

const kb = require('./keyboards');
const fmt = require('./utils/format');

const bot = new Telegraf(botCfg.token);
const uploadsDir = path.join(__dirname, '..', 'uploads');
if (!fs.existsSync(uploadsDir)) fs.mkdirSync(uploadsDir, { recursive: true });

const sessionStore = {};
const compareStore = {};
const stateStore = {};

bot.use(session());

const CHANNEL_USERNAME = botCfg.channelUsername || '@com01';

async function checkChannelMembership(ctx) {
  try {
    const userId = ctx.from.id;
    const chatId = CHANNEL_USERNAME;
    const member = await ctx.telegram.getChatMember(chatId, userId);
    const allowed = ['member', 'administrator', 'creator'];
    return allowed.includes(member.status);
  } catch (err) {
    console.error('❌ خطا در چک عضویت:', err.message);
    return true;
  }
}

function requireChannel(func) {
  return async (ctx, next) => {
    const isMember = await checkChannelMembership(ctx);
    if (!isMember) {
      const channelLink = CHANNEL_USERNAME.startsWith('@')
        ? `https://t.me/${CHANNEL_USERNAME.slice(1)}`
        : CHANNEL_USERNAME;
      await ctx.replyWithMarkdown(
        `⚠️ برای استفاده از ربات ابتدا باید عضو کانال شوید:\n\n[${CHANNEL_USERNAME}](${channelLink})\n\nبعد از عضو شدن دوباره روی /start بزنید.`
      );
      return;
    }
    return func(ctx, next);
  };
}

async function ensureUser(ctx) {
  const from = ctx.from || {};
  return db.createOrUpdateUser(from.id, from.first_name, from.username);
}

function setState(ctx, state) {
  const id = ctx.from.id;
  stateStore[id] = state;
}
function getState(ctx) {
  const id = ctx.from.id;
  return stateStore[id] || null;
}
function clearState(ctx) {
  const id = ctx.from.id;
  delete stateStore[id];
}

function getCompareList(ctx) {
  return compareStore[ctx.from.id] || [];
}
function setCompareList(ctx, list) {
  compareStore[ctx.from.id] = list;
}
function clearCompare(ctx) {
  delete compareStore[ctx.from.id];
}

bot.start(requireChannel(async (ctx) => {
  const user = await ensureUser(ctx);
  const name = ctx.from.first_name || 'کاربر گرامی';
  await ctx.replyWithMarkdown(
    `👋 سلام *${name}* به ربات لپ‌تاپ کامپیوتر اول خوش آمدید.\n\nلطفاً یکی از گزینه‌های زیر را انتخاب کنید:`,
    kb.mainMenu()
  );
}));

bot.help(requireChannel(async (ctx) => {
  await ctx.replyWithMarkdown(
    `*راهنما:*\n\n/start - نمایش منوی اصلی\n/menu - منو\n/contact - تماس با پشتیبانی\n\n*نکته:* قبل از استفاده حتماً عضو کانال ${CHANNEL_USERNAME} شوید.`
  );
}));

bot.command('menu', requireChannel(async (ctx) => {
  await ctx.replyWithMarkdown('*منوی اصلی:*', kb.mainMenu());
}));

bot.command('contact', requireChannel(async (ctx) => {
  const lines = ['☎️ *راه‌های ارتباط با ما*', '',
    '📞 شماره‌های تماس:',
    ...contact.phones.map((p) => `  • 📱 ${p}`),
    '',
    `🔗 لینک ارتباطی: [${contact.zilLink}](${contact.zilLink})`
  ];
  await ctx.replyWithMarkdown(lines.join('\n'), kb.contactMenu());
}));

async function handleMainMenu(ctx) {
  await ctx.answerCbQuery().catch(() => {});
  await ctx.editMessageText('*🏠 منوی اصلی:*', {
    parse_mode: 'Markdown',
    ...kb.mainMenu(),
  }).catch(() => {
    ctx.replyWithMarkdown('*🏠 منوی اصلی:*', kb.mainMenu());
  });
}
bot.action('main_menu', requireChannel(handleMainMenu));

bot.action('products', requireChannel(async (ctx) => {
  await ctx.answerCbQuery('در حال دریافت برندها...').catch(() => {});
  try {
    const brands = await wc.getAllBrands();
    if (brands.length === 0) {
      await ctx.editMessageText('⚠️ فعلاً محصولی در سایت ثبت نشده است.', kb.backButton('main_menu'));
      return;
    }
    await ctx.editMessageText('🏷️ لطفاً برند مورد نظر را انتخاب کنید:', {
      parse_mode: 'Markdown',
      ...kb.brandsMenu(brands, 'brand'),
    }).catch(() => {
      ctx.replyWithMarkdown('🏷️ لطفاً برند مورد نظر را انتخاب کنید:', kb.brandsMenu(brands, 'brand'));
    });
  } catch (err) {
    await ctx.replyWithMarkdown('❌ خطا در دریافت اطلاعات. بعداً تلاش کنید.', kb.backButton('main_menu'));
  }
}));

bot.action(/^brand:(.+)$/, requireChannel(async (ctx) => {
  const brand = ctx.match[1];
  await ctx.answerCbQuery(`دریافت محصولات ${brand}...`).catch(() => {});
  try {
    const products = await wc.getProductsByBrand(brand);
    if (products.length === 0) {
      await ctx.editMessageText(`⚠️ فعلاً محصولی از برند *${brand}* موجود نیست.`, {
        parse_mode: 'Markdown',
        ...kb.backButton('products'),
      });
      return;
    }
    await ctx.editMessageText(`💻 محصولات *${brand}* (${products.length} محصول):\nبرای مشاهده مشخصات روی محصول کلیک کنید:`, {
      parse_mode: 'Markdown',
      ...kb.productsMenu(products, 'product'),
    });
  } catch (err) {
    await ctx.replyWithMarkdown('❌ خطا در دریافت محصولات.', kb.backButton('products'));
  }
}));

bot.action(/^product:(\d+)$/, requireChannel(async (ctx) => {
  const productId = Number(ctx.match[1]);
  await ctx.answerCbQuery('در حال دریافت مشخصات...').catch(() => {});
  try {
    const user = await ensureUser(ctx);
    const p = await wc.getProductById(productId);
    if (!p) {
      await ctx.answerCbQuery('محصول پیدا نشد');
      return;
    }

    const trackedList = await db.getTrackedProductsByUser(user.id);
    const isTracked = trackedList.some((t) => t.product_id === productId);
    const favs = await db.getFavorites(user.id);
    const isFav = favs.some((f) => f.product_id === productId);

    const text = fmt.productFullText(p);
    const chunks = fmt.splitLongMessage(text, 3500);

    const perm = p.permalink || null;
    if (p.image) {
      try {
        await ctx.replyWithPhoto(p.image, {
          caption: chunks[0],
          parse_mode: 'Markdown',
          ...kb.productActionMenu(productId, isTracked, isFav, perm),
        }).catch(async () => {
          await ctx.replyWithMarkdown(chunks[0], kb.productActionMenu(productId, isTracked, isFav, perm));
        });
      } catch (e) {
        await ctx.replyWithMarkdown(chunks[0], kb.productActionMenu(productId, isTracked, isFav, perm));
      }
    } else {
      await ctx.replyWithMarkdown(chunks[0], kb.productActionMenu(productId, isTracked, isFav, perm));
    }

    for (let i = 1; i < chunks.length; i++) {
      await ctx.replyWithMarkdown(chunks[i]);
    }

    for (let i = 1; i < Math.min(p.images.length, 4); i++) {
      try { await ctx.replyWithPhoto(p.images[i]); } catch (e) {}
    }
  } catch (err) {
    console.error(err);
    await ctx.answerCbQuery('خطا در دریافت اطلاعات').catch(() => {});
  }
}));

bot.action(/^track:(\d+)$/, requireChannel(async (ctx) => {
  const productId = Number(ctx.match[1]);
  try {
    const user = await ensureUser(ctx);
    const p = await wc.getProductById(productId);
    if (!p) {
      await ctx.answerCbQuery('محصول یافت نشد');
      return;
    }
    const stock = p.isInStock ? 'instock' : 'outofstock';
    const ok = await db.addTrackedProduct(user.id, productId, p.name, p.currentPriceRaw, stock);
    if (ok) {
      await ctx.answerCbQuery('✅ پیگیری فعال شد');
      await ctx.replyWithMarkdown(
        `🔔 *پیگیری فعال شد*\n\nمحصول: *${p.name}*\n\nدر صورت تغییر قیمت یا موجود شدن، پیام دریافت خواهید کرد.`,
        kb.backButton('main_menu')
      );
    } else {
      await ctx.answerCbQuery('در حال حاضر پیگیری فعال است');
    }
  } catch (err) {
    await ctx.answerCbQuery('خطا').catch(() => {});
  }
}));

bot.action(/^untrack:(\d+)$/, requireChannel(async (ctx) => {
  const trackId = Number(ctx.match[1]);
  try {
    const user = await ensureUser(ctx);
    const all = await db.getTrackedProductsByUser(user.id);
    const t = all.find((x) => x.id === trackId);
    if (t) {
      await db.removeTrackedProduct(user.id, t.product_id);
    }
    await ctx.answerCbQuery('حذف شد');
    const list = await db.getTrackedProductsByUser(user.id);
    if (list.length === 0) {
      await ctx.editMessageText('📋 لیست پیگیری خالی است.', kb.trackingMenu()).catch(() => {});
    } else {
      await ctx.editMessageText('📋 لیست پیگیری شما:', {
        parse_mode: 'Markdown',
        ...kb.trackedListMenu(list),
      }).catch(() => {});
    }
  } catch (err) {
    await ctx.answerCbQuery('خطا').catch(() => {});
  }
}));

bot.action(/^fav:(\d+)$/, requireChannel(async (ctx) => {
  const productId = Number(ctx.match[1]);
  try {
    const user = await ensureUser(ctx);
    const p = await wc.getProductById(productId);
    if (!p) return;
    const currentFavs = await db.getFavorites(user.id);
    if (currentFavs.some((f) => f.product_id === productId)) {
      await db.removeFavorite(user.id, productId);
      await ctx.answerCbQuery('⭐ از علاقه‌مندی‌ها حذف شد');
    } else {
      await db.addFavorite(user.id, productId, p.name, p.currentPriceRaw);
      await ctx.answerCbQuery('⭐ به علاقه‌مندی‌ها اضافه شد');
    }
  } catch (err) {
    await ctx.answerCbQuery('خطا').catch(() => {});
  }
}));

bot.action('favorites', requireChannel(async (ctx) => {
  try {
    const user = await ensureUser(ctx);
    const favs = await db.getFavorites(user.id);
    if (favs.length === 0) {
      await ctx.editMessageText('⭐ هنوز محصولی به علاقه‌مندی‌ها اضافه نکرده‌اید.', {
        parse_mode: 'Markdown',
        ...kb.backButton('main_menu'),
      });
      return;
    }
    const buttons = favs.map((f) => [{
      text: `⭐ ${(f.product_name || '').substring(0, 35)}`,
      callback_data: `product:${f.product_id}`,
    }]);
    buttons.push([{ text: '🗑️ خالی کردن', callback_data: 'clear_favs' }]);
    buttons.push([{ text: '🏠 منوی اصلی', callback_data: 'main_menu' }]);
    await ctx.editMessageText(`⭐ لیست علاقه‌مندی‌های شما (${favs.length} مورد):`, {
      parse_mode: 'Markdown',
      ...({ reply_markup: { inline_keyboard: buttons } }),
    });
  } catch (err) {
    await ctx.answerCbQuery('خطا').catch(() => {});
  }
}));

bot.action('clear_favs', requireChannel(async (ctx) => {
  try {
    const user = await ensureUser(ctx);
    const favs = await db.getFavorites(user.id);
    for (const f of favs) await db.removeFavorite(user.id, f.product_id);
    await ctx.answerCbQuery('تکمیل شد');
    await ctx.editMessageText('⭐ لیست علاقه‌مندی‌ها خالی شد.', kb.mainMenu());
  } catch (e) {}
}));

bot.action('tracking_menu', requireChannel(async (ctx) => {
  await ctx.editMessageText('🔔 *منوی پیگیری محصولات:*', {
    parse_mode: 'Markdown',
    ...kb.trackingMenu(),
  });
}));

bot.action('list_tracking', requireChannel(async (ctx) => {
  try {
    const user = await ensureUser(ctx);
    const list = await db.getTrackedProductsByUser(user.id);
    if (list.length === 0) {
      await ctx.editMessageText('📋 هیچ محصولی برای پیگیری ثبت نکرده‌اید.\nبرای افزودن، روی یک محصول کلیک کنید و گزینه پیگیری را بزنید.', {
        parse_mode: 'Markdown',
        ...kb.backButton('tracking_menu'),
      });
      return;
    }
    const lines = ['📋 *محصولات پیگیری شده:*', ''];
    list.forEach((t, i) => {
      const price = fmt.formatTooman(t.initial_price);
      const stock = t.initial_stock_status === 'instock' ? '✅' : '❌';
      lines.push(`${i + 1}. ${t.product_name}`);
      lines.push(`   ${stock} قیمت: ${price}`);
    });
    await ctx.editMessageText(lines.join('\n'), {
      parse_mode: 'Markdown',
      ...kb.trackedListMenu(list),
    });
  } catch (err) {
    await ctx.answerCbQuery('خطا').catch(() => {});
  }
}));

bot.action('search', requireChannel(async (ctx) => {
  setState(ctx, { action: 'search' });
  await ctx.editMessageText('🔎 لطفاً نام یا مدل لپ‌تاپ مورد نظر را ارسال کنید:\nمثال: *Lenovo LOQ 15* یا *ASUS TUF*', {
    parse_mode: 'Markdown',
    ...kb.backButton('main_menu'),
  });
}));

bot.action(/^add_compare:(\d+)$/, requireChannel(async (ctx) => {
  const pid = Number(ctx.match[1]);
  try {
    const list = getCompareList(ctx);
    if (list.some((p) => p.id === pid)) {
      await ctx.answerCbQuery('از قبل در لیست است');
      return;
    }
    if (list.length >= 4) {
      await ctx.answerCbQuery('حداکثر ۴ محصول');
      return;
    }
    const p = await wc.getProductById(pid);
    list.push(p);
    setCompareList(ctx, list);
    await ctx.answerCbQuery(`✅ اضافه شد (${list.length}/4)`);
  } catch (err) {
    await ctx.answerCbQuery('خطا').catch(() => {});
  }
}));

bot.action(/^rm_compare:(\d+)$/, requireChannel(async (ctx) => {
  const idx = Number(ctx.match[1]);
  const list = getCompareList(ctx);
  if (list[idx]) {
    list.splice(idx, 1);
    setCompareList(ctx, list);
  }
  const l = getCompareList(ctx);
  const msg = l.length
    ? `⚖️ لیست مقایسه شما (${l.length} محصول):\nبرای مقایسه روی دکمه زیر بزنید.`
    : '⚖️ لیست مقایسه خالی است. محصولات را از بخش محصولات انتخاب کنید.';
  await ctx.editMessageText(msg, {
    parse_mode: 'Markdown',
    ...kb.compareListMenu(l),
  });
}));

bot.action('clear_compare', requireChannel(async (ctx) => {
  clearCompare(ctx);
  await ctx.editMessageText('🗑️ لیست مقایسه خالی شد.\nمحصولات را از بخش محصولات انتخاب کنید.', {
    parse_mode: 'Markdown',
    ...kb.backButton('main_menu'),
  });
}));

bot.action('compare', requireChannel(async (ctx) => {
  const list = getCompareList(ctx);
  const msg = list.length
    ? `⚖️ لیست مقایسه شما (${list.length} محصول):\n\nبرای افزودن محصول: محصولات → انتخاب → + افزودن برای مقایسه`
    : '⚖️ *مقایسه لپ‌تاپ*\n\nبرای افزودن محصول:\n💻 محصولات → انتخاب محصول → *➕ افزودن برای مقایسه*\n\nحداکثر ۴ محصول قابل مقایسه هستند.';
  await ctx.editMessageText(msg, {
    parse_mode: 'Markdown',
    ...kb.compareListMenu(list),
  });
}));

bot.action('do_compare', requireChannel(async (ctx) => {
  const list = getCompareList(ctx);
  if (list.length < 2) {
    await ctx.answerCbQuery('حداقل ۲ محصول نیاز است');
    return;
  }
  await ctx.answerCbQuery('در حال مقایسه...').catch(() => {});
  const text = fmt.compareProductsText(list);
  const chunks = fmt.splitLongMessage(text, 3500);
  for (const ch of chunks) {
    await ctx.replyWithMarkdown(ch);
  }
}));

bot.action('pricelist', requireChannel(async (ctx) => {
  await ctx.answerCbQuery('در حال دریافت لیست قیمت...').catch(() => {});
  try {
    const products = await wc.getAllProducts({ per_page: 100 });
    const inStock = products.filter((p) => p.isInStock);
    const lines = ['📦 *لیست قیمت لحظه‌ای (موجود)*', '',
      `تعداد محصول موجود: *${inStock.length}*\n`,
    ];

    const byBrand = {};
    inStock.forEach((p) => {
      if (!byBrand[p.brand]) byBrand[p.brand] = [];
      byBrand[p.brand].push(p);
    });

    for (const brand in byBrand) {
      lines.push(`\n━━━━ *${brand}* ━━━━`);
      byBrand[brand].forEach((p) => {
        const n = p.name.length > 42 ? p.name.substring(0, 39) + '...' : p.name;
        lines.push(`• ${n}  →  *${fmt.formatTooman(p.currentPrice)}*`);
      });
    }

    const chunks = fmt.splitLongMessage(lines.join('\n'), 3500);
    for (let i = 0; i < chunks.length; i++) {
      const opts = i === chunks.length - 1 ? kb.backButton('main_menu') : undefined;
      if (opts) {
        await ctx.replyWithMarkdown(chunks[i], opts).catch(() => ctx.reply(chunks[i]));
      } else {
        await ctx.replyWithMarkdown(chunks[i]).catch(() => ctx.reply(chunks[i]));
      }
    }
  } catch (err) {
    console.error(err);
    await ctx.replyWithMarkdown('❌ خطا در دریافت لیست قیمت.', kb.backButton('main_menu'));
  }
}));

bot.action('colleague_price', requireChannel(async (ctx) => {
  await ctx.answerCbQuery('در حال دریافت لیست قیمت همکار...').catch(() => {});
  try {
    const all = await db.getAllColleaguePrices();
    if (all.length === 0) {
      await ctx.editMessageText('⚠️ لیست قیمت همکاران فعلاً خالی است. بعداً بررسی کنید.', {
        parse_mode: 'Markdown',
        ...kb.backButton('main_menu'),
      });
      return;
    }
    const text = fmt.formatColleaguePriceList(all);
    const chunks = fmt.splitLongMessage(text, 3500);
    for (let i = 0; i < chunks.length; i++) {
      const opts = i === chunks.length - 1 ? kb.backButton('main_menu') : undefined;
      if (opts) {
        if (i === 0) {
          await ctx.editMessageText(chunks[i], { parse_mode: 'Markdown', ...opts }).catch(async () => {
            await ctx.replyWithMarkdown(chunks[i], opts);
          });
        } else {
          await ctx.replyWithMarkdown(chunks[i], opts);
        }
      } else {
        await ctx.replyWithMarkdown(chunks[i]);
      }
    }
  } catch (err) {
    console.error(err);
    await ctx.reply('خطا در دریافت لیست.');
  }
}));

bot.action('budget_search', requireChannel(async (ctx) => {
  await ctx.editMessageText('💰 لطفاً بازه بودجه خود را انتخاب کنید:', {
    parse_mode: 'Markdown',
    ...kb.budgetRanges(),
  });
}));

bot.action(/^budget:(\d+):(\d+)$/, requireChannel(async (ctx) => {
  const min = Number(ctx.match[1]);
  const max = Number(ctx.match[2]);
  await ctx.answerCbQuery('در حال جستجو...').catch(() => {});
  try {
    const products = await wc.getProductsInRange(min, max);
    if (products.length === 0) {
      await ctx.editMessageText(`⚠️ محصولی در بازه قیمت ${fmt.formatTooman(min)} تا ${fmt.formatTooman(max)} پیدا نشد.`, {
        parse_mode: 'Markdown',
        ...kb.backButton('budget_search'),
      });
      return;
    }
    const text = products.map((p) => {
      const n = p.name.length > 42 ? p.name.substring(0, 39) + '...' : p.name;
      return `• ${n}\n  💰 ${fmt.formatTooman(p.currentPrice)}\n`;
    }).join('\n');

    const header = `💰 نتایج بودجه ${fmt.formatTooman(min)} تا ${fmt.formatTooman(max)}:\nتعداد: ${products.length} محصول\n\n`;
    const chunks = fmt.splitLongMessage(header + text, 3500);
    for (let i = 0; i < chunks.length; i++) {
      const opts = i === chunks.length - 1 ? kb.backButton('budget_search') : undefined;
      if (opts) {
        if (i === 0) {
          await ctx.editMessageText(chunks[i], { parse_mode: 'Markdown', ...opts }).catch(async () => {
            await ctx.replyWithMarkdown(chunks[i], opts);
          });
        } else {
          await ctx.replyWithMarkdown(chunks[i], opts);
        }
      } else {
        await ctx.replyWithMarkdown(chunks[i]);
      }
    }
  } catch (err) {
    await ctx.reply('خطا در جستجو.');
  }
}));

bot.action('contact', requireChannel(async (ctx) => {
  const lines = ['☎️ *راه‌های ارتباط با فروشگاه*', '',
    '📞 شماره‌های تماس:',
    ...contact.phones.map((p) => `  • 📱 ${p}`),
    '',
    `🔗 صفحه ارتباطی: [${contact.zilLink}](${contact.zilLink})`
  ];
  await ctx.editMessageText(lines.join('\n'), {
    parse_mode: 'Markdown',
    ...kb.contactMenu(),
  });
}));

bot.on('text', requireChannel(async (ctx) => {
  const text = ctx.message.text.trim();
  const user = await ensureUser(ctx);
  const state = getState(ctx);

  if (text === admin.uploadCode) {
    setState(ctx, { action: 'upload_admin_file' });
    await ctx.replyWithMarkdown(
      `✅ *احراز هویت ادمین موفق*\n\nلطفاً فایل لیست قیمت همکار را ارسال کنید.\nفرمت‌های پشتیبانی شده: *CSV*, *XLSX*, *XLS*\n\nساختار فایل (ستون‌ها):\n• نام محصول / مدل / برند\n• قیمت همکار / قیمت اصلی / کد محصول\n\n⚠️ آپلود شدن فایل جدید، لیست قبلی حذف می‌شود.`,
      kb.backButton('main_menu')
    );
    return;
  }

  if (state && state.action === 'enter_colleague_code') {
    clearState(ctx);
    if (text === admin.uploadCode) {
      setState(ctx, { action: 'upload_admin_file' });
      await ctx.replyWithMarkdown('✅ ادمین تایید شد. فایل لیست قیمت را ارسال کنید.');
      return;
    }
  }

  if (state && state.action === 'search') {
    clearState(ctx);
    await ctx.replyWithMarkdown(`🔎 در حال جستجو برای: *${text}*`);
    try {
      const results = await wc.searchProducts(text);
      if (results.length === 0) {
        await ctx.replyWithMarkdown('⚠️ محصولی یافت نشد. لطفاً کلمات دیگری تست کنید.', kb.backButton('main_menu'));
        return;
      }
      await ctx.replyWithMarkdown(
        `✅ ${results.length} محصول پیدا شد.\nیکی را انتخاب کنید:`,
        kb.productsMenu(results, 'product')
      );
    } catch (err) {
      await ctx.replyWithMarkdown('❌ خطا در جستجو.', kb.backButton('main_menu'));
    }
    return;
  }

  if (text === '/start' || text === '/menu') return;
  await ctx.replyWithMarkdown('لطفاً یکی از گزینه‌های منو را انتخاب کنید:', kb.mainMenu());
}));

bot.on('document', requireChannel(async (ctx) => {
  const state = getState(ctx);
  if (!state || state.action !== 'upload_admin_file') {
    await ctx.replyWithMarkdown('⚠️ برای آپلود فایل ابتدا کد ادمین را ارسال کنید.', kb.backButton('main_menu'));
    return;
  }
  const doc = ctx.message.document;
  const name = doc.file_name || '';
  const extOk = /\.(xlsx|xls|csv)$/i.test(name);
  if (!extOk) {
    await ctx.replyWithMarkdown('❌ فرمت فایل نامعتبر است. فقط XLSX, XLS, CSV');
    return;
  }
  await ctx.replyWithMarkdown('⏳ در حال پردازش فایل...');
  try {
    const fileId = doc.file_id;
    const fileLink = await ctx.telegram.getFileLink(fileId);
    const localPath = path.join(uploadsDir, `${Date.now()}_${name}`);

    const fetch = await import('node-fetch').then((m) => m.default);
    const resp = await fetch(fileLink);
    const arrayBuffer = await resp.arrayBuffer();
    fs.writeFileSync(localPath, Buffer.from(arrayBuffer));

    const result = await importColleaguePrices(localPath, name, ctx.from.id);

    clearState(ctx);
    await ctx.replyWithMarkdown(
      `✅ *آپلود موفق*\n\nتعداد رکورد: *${result.count}*\nبرندها: ${result.brands.join('، ') || '---'}\n\nلیست قیمت همکاران به‌روز شد.`,
      kb.mainMenu()
    );
  } catch (err) {
    console.error(err);
    await ctx.replyWithMarkdown(`❌ خطا در پردازش فایل:\n${err.message}`, kb.backButton('main_menu'));
  }
}));

bot.catch((err, ctx) => {
  console.error('❌ خطای ربات:', err.message);
  ctx.reply('خطایی رخ داد. بعداً تلاش کنید.').catch(() => {});
});

async function start() {
  console.log('🚀 ربات در حال راه‌اندازی...');
  await initializeDatabase();
  const test = await wc.testConnection();
  if (test.success) {
    console.log(`✅ اتصال به وردپرس موفق (${test.productsCount || 'چندین'} محصول)`);
  } else {
    console.log('⚠️ اتصال به وردپرس ناموفق بود:', test.error);
  }
  startScheduler(bot);
  await bot.launch({ dropPendingUpdates: true });
  console.log('✅ ربات فعال است.');
}

process.once('SIGINT', () => bot.stop('SIGINT'));
process.once('SIGTERM', () => bot.stop('SIGTERM'));

start().catch((e) => {
  console.error('❌ خطای راه‌اندازی:', e.message);
  process.exit(1);
});
