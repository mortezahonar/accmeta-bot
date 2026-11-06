const TARGET_REPO = 'mortezahonar/accmeta-bot';
const SECRET_HEADER = 'X-Telegram-Bot-Api-Secret-Token';
const BOT_SECRET_HEADER = 'X-Bot-Secret';
const PENDING_PREFIX = '/pending/';
const LAW_SOURCE_PROBE_PATH = '/law-source-probe';
const DIRECT_TAX_SOURCE_URL = 'https://qavanin.ir/Law/TreeText/?IDS=892583840653829785';

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    if (env.REPO !== TARGET_REPO) {
      return new Response('wrong target', { status: 500 });
    }

    if (url.pathname === LAW_SOURCE_PROBE_PATH) {
      return probeDirectTaxSource(request, env);
    }

    if (request.method === 'GET' && url.pathname.startsWith(PENDING_PREFIX)) {
      return fetchPending(request, env, url);
    }

    if (request.method !== 'POST') {
      return new Response('ok');
    }

    if (request.headers.get(SECRET_HEADER) !== env.TELEGRAM_SECRET) {
      return new Response('forbidden', { status: 403 });
    }

    const update = await request.text();
    const key = crypto.randomUUID().replace(/-/g, '');

    await env.UPDATES.put(key, update, { expirationTtl: 3600 });

    const response = await fetch(`https://api.github.com/repos/${env.REPO}/dispatches`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${env.GH_TOKEN}`,
        Accept: 'application/vnd.github+json',
        'User-Agent': 'accmeta-bot-hook',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ event_type: 'telegram', client_payload: { key } }),
    });

    if (!response.ok) {
      const detail = await response.text();
      await env.UPDATES.delete(key);

      // A non-2xx answer makes Telegram retry, so the update is not lost.
      return new Response(`dispatch failed: ${response.status} ${detail}`, { status: 502 });
    }

    return new Response('ok', { status: 200 });
  },
};

async function probeDirectTaxSource(request, env) {
  if (request.method !== 'GET') {
    return new Response('method not allowed', { status: 405, headers: { Allow: 'GET' } });
  }

  if (request.headers.get(BOT_SECRET_HEADER) !== env.BOT_SECRET) {
    return new Response('forbidden', { status: 403 });
  }

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 20000);

  try {
    const upstream = await fetch(DIRECT_TAX_SOURCE_URL, {
      method: 'GET',
      headers: {
        Accept: 'text/html,application/xhtml+xml',
        'User-Agent': 'Mozilla/5.0 (compatible; AccmetaBot/1.0)',
      },
      redirect: 'follow',
      signal: controller.signal,
    });
    const body = await upstream.text();
    const normalizedText = body
      .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, ' ')
      .replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, ' ')
      .replace(/<[^>]*>/g, ' ')
      .replace(/&nbsp;|&#160;|&#xA0;|&zwnj;|&#8204;|&#x200c;/gi, ' ')
      .replace(/[۰-۹]/g, (digit) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)))
      .replace(/[٠-٩]/g, (digit) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)))
      .replace(/\s+/g, ' ');
    const lawTitleFound = /قانون\s*مال(?:یات|يات)[\s‌]*(?:های|هاي)?[\s‌]*مستقیم/i.test(normalizedText);
    const articleOneFound = /ماده[\s‌]*1(?:[\s‌]|ـ|-|:|—|$)/i.test(normalizedText);
    const challenge = /captcha|cf-chl-|challenge-platform|verify you are|access denied|security check/i.test(body);
    const reachable = upstream.ok && lawTitleFound && articleOneFound && !challenge;

    return json({
      reachable,
      upstreamStatus: upstream.status,
      bytes: body.length,
      lawTitleFound,
      articleOneFound,
      challenge,
    });
  } catch {
    return json({
      reachable: false,
      upstreamStatus: null,
      bytes: 0,
      lawTitleFound: false,
      articleOneFound: false,
      challenge: false,
    });
  } finally {
    clearTimeout(timeout);
  }
}

function json(value, status = 200) {
  return new Response(JSON.stringify(value), {
    status,
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'no-store',
    },
  });
}

async function fetchPending(request, env, url) {
  if (request.headers.get(BOT_SECRET_HEADER) !== env.BOT_SECRET) {
    return new Response('forbidden', { status: 403 });
  }

  const key = url.pathname.slice(PENDING_PREFIX.length);
  const stored = await env.UPDATES.get(key);

  if (stored === null) {
    return new Response('not found', { status: 404 });
  }

  await env.UPDATES.delete(key);

  return new Response(stored, {
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
  });
}
