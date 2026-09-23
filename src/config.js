require('dotenv').config();

module.exports = {
  bot: {
    token: process.env.BOT_TOKEN,
    channelUsername: process.env.CHANNEL_USERNAME,
  },
  woocommerce: {
    url: process.env.WEBSITE_URL,
    consumerKey: process.env.WC_CONSUMER_KEY,
    consumerSecret: process.env.WC_CONSUMER_SECRET,
  },
  admin: {
    uploadCode: process.env.ADMIN_UPLOAD_CODE || 'ADMIN_PRICE_1402',
  },
  database: {
    host: process.env.DB_HOST || 'localhost',
    port: parseInt(process.env.DB_PORT) || 3306,
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || '',
    name: process.env.DB_NAME || 'laptop_bot',
  },
  scheduler: {
    cronInterval: process.env.CRON_INTERVAL || '*/30 * * * *',
  },
  contact: {
    phones: ['05144244566', '05144244577', '09013711899'],
    zilLink: 'https://zil.ink/jalily_computer',
  },
};
