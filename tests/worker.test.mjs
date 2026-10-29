import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(new URL('../worker/accmeta-hook.js', import.meta.url), 'utf8');
const executable = source.replace('export default {', 'globalThis.worker = {');

function loadWorker(fetchStub = async () => new Response('ok', { status: 200 })) {
  const context = {
    URL,
    Request,
    Response,
    crypto: { randomUUID: () => '12345678-1234-1234-1234-123456789abc' },
    fetch: fetchStub,
  };
  vm.runInNewContext(executable, context, { filename: 'accmeta-hook.js' });
  return context.worker;
}

function makeEnv() {
  const values = new Map();
  const calls = { put: [], get: [], delete: [] };
  return {
    values,
    calls,
    env: {
      TELEGRAM_SECRET: 'telegram-secret-test',
      BOT_SECRET: 'workflow-secret-test',
      GH_TOKEN: 'github-token-test',
      REPO: 'mortezahonar/accmeta-bot',
      UPDATES: {
        async put(key, value, options) {
          calls.put.push({ key, value, options });
          values.set(key, value);
        },
        async get(key) {
          calls.get.push(key);
          return values.get(key) ?? null;
        },
        async delete(key) {
          calls.delete.push(key);
          values.delete(key);
        },
      },
    },
  };
}

function telegramRequest(headers = {}, body = '{"update_id":42}') {
  return new Request('https://accmeta-hook.example.workers.dev/', {
    method: 'POST',
    headers,
    body,
  });
}

test('rejects an invalid Telegram secret without storing or dispatching', async () => {
  let dispatched = false;
  const worker = loadWorker(async () => { dispatched = true; return new Response('ok', { status: 200 }); });
  const { env, calls } = makeEnv();
  const response = await worker.fetch(telegramRequest(), env);

  assert.equal(response.status, 403);
  assert.deepEqual(calls.put, []);
  assert.equal(dispatched, false);
});

test('stores the update briefly and dispatches only its random key', async () => {
  let dispatchUrl = '';
  let dispatchOptions;
  const worker = loadWorker(async (url, options) => {
    dispatchUrl = url;
    dispatchOptions = options;
    return new Response('ok', { status: 200 });
  });
  const { env, calls } = makeEnv();
  const response = await worker.fetch(
    telegramRequest({ 'X-Telegram-Bot-Api-Secret-Token': 'telegram-secret-test' }),
    env,
  );

  assert.equal(response.status, 200);
  assert.equal(await response.text(), 'ok');
  assert.equal(dispatchUrl, 'https://api.github.com/repos/mortezahonar/accmeta-bot/dispatches');
  assert.deepEqual(JSON.parse(JSON.stringify(calls.put)), [{
    key: '12345678123412341234123456789abc',
    value: '{"update_id":42}',
    options: { expirationTtl: 3600 },
  }]);
  assert.equal(dispatchOptions.headers.Authorization, 'Bearer github-token-test');
  assert.deepEqual(JSON.parse(dispatchOptions.body), {
    event_type: 'telegram',
    client_payload: { key: '12345678123412341234123456789abc' },
  });
  assert.equal(dispatchOptions.body.includes('update_id'), false);
});

test('deletes the queued update and returns 502 when dispatch fails', async () => {
  const worker = loadWorker(async () => new Response('dispatch denied', { status: 403 }));
  const { env, calls, values } = makeEnv();
  const response = await worker.fetch(
    telegramRequest({ 'X-Telegram-Bot-Api-Secret-Token': 'telegram-secret-test' }),
    env,
  );

  assert.equal(response.status, 502);
  assert.match(await response.text(), /dispatch failed: 403/);
  assert.deepEqual(calls.delete, ['12345678123412341234123456789abc']);
  assert.equal(values.has('12345678123412341234123456789abc'), false);
});

test('protects the pending endpoint and consumes stored updates once', async () => {
  const worker = loadWorker();
  const { env, values, calls } = makeEnv();
  values.set('opaque-key', '{"update_id":99}');

  const denied = await worker.fetch(
    new Request('https://accmeta-hook.example.workers.dev/pending/opaque-key'),
    env,
  );
  assert.equal(denied.status, 403);
  assert.deepEqual(calls.get, []);

  const response = await worker.fetch(
    new Request('https://accmeta-hook.example.workers.dev/pending/opaque-key', {
      headers: { 'X-Bot-Secret': 'workflow-secret-test' },
    }),
    env,
  );
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('Content-Type'), 'application/json; charset=utf-8');
  assert.equal(await response.text(), '{"update_id":99}');
  assert.deepEqual(calls.delete, ['opaque-key']);

  const missing = await worker.fetch(
    new Request('https://accmeta-hook.example.workers.dev/pending/opaque-key', {
      headers: { 'X-Bot-Secret': 'workflow-secret-test' },
    }),
    env,
  );
  assert.equal(missing.status, 404);
});

test('answers health checks without processing them as updates', async () => {
  const worker = loadWorker();
  const response = await worker.fetch(new Request('https://accmeta-hook.example.workers.dev/'), makeEnv().env);
  assert.equal(response.status, 200);
  assert.equal(await response.text(), 'ok');
});
