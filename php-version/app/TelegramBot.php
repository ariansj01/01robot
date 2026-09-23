<?php

namespace Bot;

class TelegramBot
{
    private string $token;
    private string $apiBase;
    private array $config;
    private WooCommerce $wc;

    public function __construct(array $config, WooCommerce $wc)
    {
        $this->config = $config;
        $this->token = $config['bot']['token'];
        $base = trim((string)bot_env('TG_API_BASE', ''));
        if ($base === '') {
            $base = 'https://api.telegram.org';
        }
        $this->apiBase = rtrim($base, '/') . "/bot{$this->token}";
        $this->wc = $wc;
    }

    private function applyProxy($ch): void
    {
        $httpProxy = trim((string)bot_env('TG_PROXY', ''));
        if ($httpProxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $httpProxy);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            return;
        }

        $socksHost = trim((string)bot_env('TG_SOCKS_HOST', ''));
        $socksPort = (int)bot_env('TG_SOCKS_PORT', '0');
        if ($socksHost === '' || $socksPort <= 0) {
            return;
        }
        if (preg_match('#^https?://#i', $socksHost)) {
            return;
        }
        curl_setopt($ch, CURLOPT_PROXY, $socksHost);
        curl_setopt($ch, CURLOPT_PROXYPORT, $socksPort);
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        $socksUser = bot_env('TG_SOCKS_USER');
        $socksPass = bot_env('TG_SOCKS_PASS');
        if ($socksUser !== '' && $socksUser !== null) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $socksUser . ':' . $socksPass);
            curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
        }
    }

    public function api(string $method, array $params = [], bool $multipart = false)
    {
        $url = "{$this->apiBase}/{$method}";
        $ch = curl_init($url);
        if ($multipart) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $this->applyProxy($ch);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) throw new \RuntimeException('Telegram cURL: ' . $err);
        $data = json_decode($resp, true);
        if (!is_array($data)) throw new \RuntimeException('Telegram invalid JSON: ' . $resp);
        return $data;
    }

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, string $parseMode = 'Markdown')
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
            'disable_web_page_preview' => true,
        ];
        if ($replyMarkup) $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        return $this->api('sendMessage', $params);
    }

    public function answerCallbackQuery(string $id, string $text = '', bool $alert = false)
    {
        return $this->api('answerCallbackQuery', [
            'callback_query_id' => $id,
            'text' => $text,
            'show_alert' => $alert,
        ]);
    }

    public function editMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null, string $parseMode = 'Markdown')
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => $parseMode,
            'disable_web_page_preview' => true,
        ];
        if ($replyMarkup) $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        return $this->api('editMessageText', $params);
    }

    public function sendPhoto(int $chatId, string $photo, ?string $caption = null, ?array $replyMarkup = null, string $parseMode = 'Markdown')
    {
        $params = [
            'chat_id' => $chatId,
            'photo' => $photo,
            'parse_mode' => $parseMode,
        ];
        if ($caption !== null) $params['caption'] = $caption;
        if ($replyMarkup) $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        return $this->api('sendPhoto', $params);
    }

    public function getFile(string $fileId): array
    {
        return $this->api('getFile', ['file_id' => $fileId]);
    }

    public function downloadFile(string $filePath, string $saveTo): void
    {
        $fileBase = preg_replace('#/bot' . preg_quote($this->token, '#') . '$#', '', $this->apiBase);
        $url = rtrim($fileBase, '/') . "/file/bot{$this->token}/{$filePath}";
        $ch = curl_init($url);
        $fp = fopen($saveTo, 'w');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 60,
        ]);
        $this->applyProxy($ch);
        curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if ($err) throw new \RuntimeException($err);
    }

    public function checkChannelMembership(int $userId, string $channel): bool
    {
        try {
            $resp = $this->api('getChatMember', [
                'chat_id' => $channel,
                'user_id' => $userId,
            ]);
            if (empty($resp['ok'])) return false;
            $status = $resp['result']['status'] ?? '';
            return in_array($status, ['member', 'administrator', 'creator'], true);
        } catch (\Throwable $e) {
            return true;
        }
    }

    public function getUpdateLink(): string
    {
        return "https://api.telegram.org/bot{$this->token}";
    }

    public function getWC(): WooCommerce
    {
        return $this->wc;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setWebhook(string $url): array
    {
        return $this->api('setWebhook', ['url' => $url]);
    }

    public function getWebhookInfo(): array
    {
        return $this->api('getWebhookInfo');
    }

    public function getUpdates(int $offset = 0, int $limit = 100, int $timeout = 0): array
    {
        return $this->api('getUpdates', [
            'offset' => $offset,
            'limit' => $limit,
            'timeout' => $timeout,
        ]);
    }
}
