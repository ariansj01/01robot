const cron = require('node-cron');
const { scheduler } = require('../config');
const { getAllTrackedProducts, updateTrackedNotified } = require('../database/queries');
const { getProductById } = require('./woocommerce');
const { formatTooman } = require('../utils/format');

function startScheduler(bot) {
  console.log('⏰ زمان‌بند هشدارها فعال شد');

  cron.schedule(scheduler.cronInterval, async () => {
    console.log('🔄 چک کردن تغییرات قیمت و موجودی...');
    try {
      const trackedList = await getAllTrackedProducts();
      for (const t of trackedList) {
        try {
          const product = await getProductById(t.product_id);
          if (!product) continue;

          const curPrice = product.currentPriceRaw;
          const initPrice = Number(t.initial_price) || 0;
          const priceChanged = initPrice > 0 && curPrice > 0 && curPrice !== initPrice;

          if (priceChanged && !t.notified_price_change) {
            const diff = curPrice - initPrice;
            const emoji = diff > 0 ? '📈' : '📉';
            const txt = `${emoji} *تغییر قیمت*\n\nمحصول: ${product.name}\n\nقیمت قبلی: ${formatTooman(initPrice)}\nقیمت جدید: *${formatTooman(curPrice)}*\n${diff > 0 ? '+' : ''}${formatTooman(diff)}\n\n[مشاهده محصول](${product.permalink || '#'})`;
            await bot.telegram.sendMessage(t.telegram_id, txt, { parse_mode: 'Markdown' }).catch(() => {});
            await updateTrackedNotified(t.id, 'notified_price_change');
          }

          const curStock = product.isInStock ? 'instock' : 'outofstock';
          const initStock = t.initial_stock_status;
          const stockChanged = initStock && curStock && curStock !== initStock && curStock === 'instock';

          if (stockChanged && !t.notified_stock_change) {
            const txt = `🔔 *محصول موجود شد*\n\nمحصول: ${product.name}\n\n✅ حالا موجود است\nقیمت فعلی: *${formatTooman(curPrice)}*\n\n[مشاهده محصول](${product.permalink || '#'})`;
            await bot.telegram.sendMessage(t.telegram_id, txt, { parse_mode: 'Markdown' }).catch(() => {});
            await updateTrackedNotified(t.id, 'notified_stock_change');
          }
        } catch (e) {
          console.error(`❌ خطا در چک محصول ${t.product_id}:`, e.message);
        }
      }
    } catch (err) {
      console.error('❌ خطا در زمان‌بند:', err.message);
    }
  });
}

module.exports = { startScheduler };
