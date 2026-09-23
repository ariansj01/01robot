/**
 * Cloudflare Worker — پروکسی API تلگرام
 *
 * 1) در cloudflare.com یک Worker بسازید و همین کد را Paste کنید.
 * 2) Deploy کنید تا آدرس بگیرید مثل:
 *    https://telegram-proxy.YOUR_SUBDOMAIN.workers.dev
 * 3) در .env هاست بگذارید:
 *    TG_API_BASE=https://telegram-proxy.YOUR_SUBDOMAIN.workers.dev
 */
export default {
  async fetch(request) {
    const url = new URL(request.url);

    if (url.pathname === '/' || url.pathname === '') {
      return new Response('ok', { status: 200 });
    }

    url.hostname = 'api.telegram.org';
    url.protocol = 'https:';

    const headers = new Headers(request.headers);
    headers.delete('host');

    return fetch(url.toString(), {
      method: request.method,
      headers,
      body: request.method === 'GET' || request.method === 'HEAD' ? null : request.body,
      redirect: 'follow',
    });
  },
};
