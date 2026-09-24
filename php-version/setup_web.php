<?php
require_once __DIR__ . '/app/Env.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/WooCommerce.php';
require_once __DIR__ . '/app/TelegramBot.php';

$config = require __DIR__ . '/config.php';

use Bot\Database;
use Bot\WooCommerce;
use Bot\TelegramBot;

foreach ($config['paths'] as $dir) {
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
}

$action = $_POST['action'] ?? ($_GET['action'] ?? 'menu');
$webhookUrl = $_POST['url'] ?? ($_GET['url'] ?? null);
if ($webhookUrl === null || $webhookUrl === '') {
    $webhookUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') .
        ($_SERVER['HTTP_HOST'] ?? '') . str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')) . '/index.php');
}

$msg = '';
$msgType = 'info';

function pageHead(string $title): void {
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    echo "<!DOCTYPE html><html dir=\"rtl\" lang=\"fa\"><head><meta charset=\"UTF-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
    echo "<title>{$t}</title>";
    echo "<style>
        *{box-sizing:border-box}
        body{margin:0;font-family:tahoma,arial;background:#f5f7fb;color:#1e293b;direction:rtl;text-align:right}
        .wrap{max-width:760px;margin:0 auto;padding:32px 16px}
        .card{background:#fff;border-radius:14px;padding:20px 24px;box-shadow:0 6px 22px rgba(0,0,0,.06);margin-bottom:16px}
        h1{font-size:20px;margin:0 0 14px;color:#0661ff}
        h2{font-size:16px;margin:14px 0 8px;color:#0661ff}
        a.btn,button.btn{display:inline-block;margin:8px 8px 8px 0;padding:10px 16px;border-radius:10px;text-decoration:none;font-size:14px;cursor:pointer;border:none;color:#fff;background:#0661ff}
        a.btn:hover,button.btn:hover{opacity:.9}
        .btn.orange{background:#ff6600}.btn.gray{background:#64748b}
        pre{background:#0f172a;color:#e2e8f0;padding:12px 14px;border-radius:10px;overflow:auto;font-size:12px;line-height:1.8;direction:ltr;text-align:left}
        .msg{padding:10px 12px;border-radius:10px;margin:10px 0;font-size:14px}
        .msg.ok{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
        .msg.err{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
        .msg.info{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe}
        input[type=text]{width:100%;padding:10px 12px;border-radius:10px;border:1px solid #cbd5e1;font-size:14px;margin:4px 0 10px 0;direction:ltr}
        label{font-size:13px;color:#475569}
        table{width:100%;border-collapse:collapse;margin-top:8px}
        table td,table th{border-bottom:1px solid #e2e8f0;padding:8px 6px;font-size:13px}
    </style></head><body><div class=\"wrap\">";
}

function pageFoot(): void { echo "</div></body></html>"; }

pageHead('تنظیمات ربات تلگرام');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbPass = trim((string)($_POST['setup_key'] ?? ''));
    $dbPass = ltrim($dbPass, '/');
    $correctPass = trim((string)$config['admin']['upload_code']);
    if ($correctPass === '') {
        $msg = 'ADMIN_UPLOAD_CODE در فایل .env خالی است یا خوانده نشد. فایل .env را در همان پوشه ربات چک کنید.';
        $msgType = 'err';
    } elseif ($dbPass !== $correctPass) {
        $msg = 'کد دسترسی اشتباه است. همان مقدار ADMIN_UPLOAD_CODE داخل .env را بدون فاصله وارد کنید (مثلاً ADMIN_PRICE_1402).';
        $msgType = 'err';
    } else {
        Database::bootstrap($config);
        $wc = new WooCommerce($config);
        $bot = new TelegramBot($config, $wc);

        if ($action === 'set-webhook') {
            try {
                $r = $bot->setWebhook($webhookUrl);
                if (!empty($r['ok'])) { $msg = 'Webhook با موفقیت تنظیم شد ✅'; $msgType = 'ok'; }
                else { $msg = 'خطا: ' . htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE)); $msgType = 'err'; }
            } catch (Throwable $e) { $msg = 'ERR: ' . htmlspecialchars($e->getMessage()); $msgType = 'err'; }
        } elseif ($action === 'get-webhook') {
            try { $r = $bot->getWebhookInfo(); $msg = 'اطلاعات Webhook: ' . htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE)); $msgType = 'info'; }
            catch (Throwable $e) { $msg = 'ERR: ' . htmlspecialchars($e->getMessage()); $msgType = 'err'; }
        } elseif ($action === 'delete-webhook') {
            try { $r = $bot->api('deleteWebhook'); if (!empty($r['ok'])) { $msg = 'Webhook حذف شد.'; $msgType = 'ok'; } else { $msg = json_encode($r, JSON_UNESCAPED_UNICODE); $msgType = 'err'; } }
            catch (Throwable $e) { $msg = 'ERR: ' . htmlspecialchars($e->getMessage()); $msgType = 'err'; }
        } elseif ($action === 'test-api') {
            $r = $wc->testConnection();
            if ($r['success']) {
                $mode = $r['mode'] ?? '';
                $msg = "✅ اتصال به وردپرس برقرار است. تعداد: {$r['count']}" . ($mode ? " (حالت: {$mode})" : '');
                $msgType = 'ok';
            }
            else {
                $extra = '';
                if (isset($r['wp_load'])) $extra .= ' | wp_load=' . htmlspecialchars((string)$r['wp_load']);
                if (isset($r['local_mode'])) $extra .= ' | local=' . htmlspecialchars((string)$r['local_mode']);
                $msg = '❌ خطا در اتصال وردپرس: ' . htmlspecialchars($r['error']) . $extra;
                $msgType = 'err';
            }
        } elseif ($action === 'test-all') {
            ob_start();
            echo "<div dir=\"ltr\"><pre>";
            try {
                Database::bootstrap($config);
                echo "1. MySQL: OK\n";
            } catch (Throwable $e) { echo "1. MySQL: FAIL - " . htmlspecialchars($e->getMessage()) . "\n"; }
            try {
                $r = $wc->testConnection();
                if ($r['success']) echo "2. WooCommerce: OK ({$r['count']} محصول)\n";
                else echo "2. WooCommerce: FAIL - " . htmlspecialchars($r['error']) . "\n";
            } catch (Throwable $e) { echo "2. WooCommerce: FAIL - " . htmlspecialchars($e->getMessage()) . "\n"; }
            try {
                $r = $bot->api('getMe');
                if (!empty($r['ok'])) echo "3. Telegram: OK (@" . htmlspecialchars($r['result']['username']) . ")\n";
                else echo "3. Telegram: FAIL\n";
            } catch (Throwable $e) { echo "3. Telegram: FAIL - " . htmlspecialchars($e->getMessage()) . "\n"; }
            try {
                $r = $bot->getWebhookInfo();
                if (!empty($r['ok'])) echo "4. Webhook URL: " . htmlspecialchars($r['result']['url'] ?? '(تنظیم نشده)') . "\n";
            } catch (Throwable $e) { echo "4. Webhook: FAIL - " . htmlspecialchars($e->getMessage()) . "\n"; }
            echo "</pre></div>";
            $msg = ob_get_clean();
            $msgType = 'info';
        }
    }
}
?>

<div class="card">
  <h1>🧰 پنل تنظیمات ربات لپ‌تاپ کامپیوتر اول</h1>
  <?php if ($msg): ?>
    <div class="msg <?php echo $msgType ?>"><?php echo $msg ?></div>
  <?php endif ?>
  <form method="post" action="?action=test-all">
    <label for="setup_key">کد دسترسی ادمین (کد آپلود):</label>
    <input type="text" id="setup_key" name="setup_key" required placeholder="ADMIN_PRICE_1402" dir="ltr">
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <button class="btn" type="submit" name="action" value="set-webhook" formaction="?action=set-webhook">🎯 تنظیم Webhook</button>
      <button class="btn orange" type="submit" name="action" value="test-all" formaction="?action=test-all">🧪 تست کامل سیستم</button>
      <button class="btn gray" type="submit" name="action" value="test-api" formaction="?action=test-api">🛍 تست وردپرس</button>
      <button class="btn gray" type="submit" name="action" value="get-webhook" formaction="?action=get-webhook">ℹ️ اطلاعات Webhook</button>
      <button class="btn gray" type="submit" name="action" value="delete-webhook" formaction="?action=delete-webhook">❌ حذف Webhook</button>
    </div>
  </form>
</div>

<div class="card">
  <h2>📋 آدرس پیشنهادی Webhook شما</h2>
  <div dir="ltr"><pre><?php echo htmlspecialchars($webhookUrl) ?></pre></div>
  <p style="font-size:13px;color:#475569;">آدرس بالا در صورت تمایل در فیلد پایینی تغییر دهید و دکمه تنظیم Webhook را بزنید.</p>
  <form method="post" action="">
    <input type="hidden" name="action" value="set-webhook">
    <label for="url">آدرس Webhook:</label>
    <input type="text" id="url" name="url" value="<?php echo htmlspecialchars($webhookUrl) ?>" dir="ltr">
    <label for="setup_key2">کد دسترسی:</label>
    <input type="text" id="setup_key2" name="setup_key" required placeholder="ADMIN_PRICE_1402" dir="ltr">
    <button class="btn orange" type="submit">ذخیره آدرس سفارشی و تنظیم</button>
  </form>
</div>

<div class="card">
  <h2>📌 نکات مهم</h2>
  <ul style="line-height:2;font-size:14px;color:#334155;">
    <li>پس از اینکه Webhook را تنظیم کردید، <b>چند دقیقه</b> صبر کنید و در تلگرام دستور <code dir="ltr">/start</code> را ارسال کنید.</li>
    <li>برای فعال شدن هشدارهای قیمت و موجودی، یک <b>Cron Job</b> در پنل هاست بسازید که هر ۳۰ دقیقه اجرا شود:<br>
      <code dir="ltr">/usr/local/bin/php /home/jknmqzao/public_html/telegram_bot/cron_checker.php >> /home/jknmqzao/public_html/telegram_bot/var/log/cron.log 2>&1</code>
    </li>
    <li>آدرس کانال مورد نیاز برای عضویت در فایل <code>.env</code> قابل تغییر است.</li>
  </ul>
</div>

<?php pageFoot(); ?>
