// Cashback Link Relay — service worker.
// Talks to the PHP backend (api/relay.php) on behalf of the content script,
// keeps settings/state, enforces the safety limits, and pauses itself when
// Shopee refuses requests (never retries around a block).
'use strict';

const DEFAULTS = {
  backendUrl: 'https://bot.dailyhotdeals.biz',
  relayKey: '',
  enabled: false,
  paused: false,
  pauseReason: '',
  dailyLimit: 300,      // max links per day created through Shopee
  minGapMs: 2500,       // min gap between two Shopee calls
};
const FETCH_TIMEOUT_MS = 10000;
const RETRIES = 3;

async function getCfg() {
  const s = await chrome.storage.local.get(Object.keys(DEFAULTS));
  return Object.assign({}, DEFAULTS, s);
}
async function setState(patch) { await chrome.storage.local.set(patch); await paintBadge(); }

async function paintBadge() {
  const c = await getCfg();
  let text = 'OFF', color = '#6b7280';
  if (c.enabled && c.paused) { text = '!'; color = '#be123c'; }
  else if (c.enabled) { text = 'ON'; color = '#047857'; }
  await chrome.action.setBadgeText({ text });
  await chrome.action.setBadgeBackgroundColor({ color });
}

function today() { return new Date().toISOString().slice(0, 10); }
async function usage() {
  const { usageDay, usageCount } = await chrome.storage.local.get(['usageDay', 'usageCount']);
  return usageDay === today() ? (usageCount || 0) : 0;
}
async function addUsage(n) {
  const cur = await usage();
  await chrome.storage.local.set({ usageDay: today(), usageCount: cur + n });
}

function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }

class HttpError extends Error {
  constructor(status, msg) { super(msg); this.status = status; }
}

// POST JSON to the backend with timeout + retry (network errors and 5xx only).
async function api(body) {
  const c = await getCfg();
  if (!c.relayKey || !/^[a-f0-9]{64}$/.test(c.relayKey)) throw new HttpError(0, 'Chưa nhập relay key (64 ký tự hex).');
  let url;
  try { url = new URL('/api/relay.php', c.backendUrl).toString(); }
  catch (e) { throw new HttpError(0, 'Backend URL không hợp lệ.'); }
  if (!url.startsWith('https://')) throw new HttpError(0, 'Backend phải dùng HTTPS.');
  let lastErr = null;
  for (let attempt = 0; attempt < RETRIES; attempt++) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), FETCH_TIMEOUT_MS);
    try {
      const r = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Relay-Key': c.relayKey },
        body: JSON.stringify(body),
        signal: ctrl.signal,
        cache: 'no-store',
        credentials: 'omit',
      });
      clearTimeout(timer);
      let j = null;
      try { j = await r.json(); } catch (e) { j = null; }
      if (r.status === 401) throw new HttpError(401, 'Backend từ chối relay key.');
      if (r.status >= 500) { lastErr = new HttpError(r.status, 'Backend lỗi ' + r.status); }
      else if (!r.ok || !j) throw new HttpError(r.status, 'Backend trả lỗi ' + r.status + (j && j.error ? ' (' + j.error + ')' : ''));
      else return j;
    } catch (e) {
      clearTimeout(timer);
      if (e instanceof HttpError && e.status && e.status < 500) throw e;
      lastErr = e.name === 'AbortError' ? new HttpError(0, 'Backend quá thời gian phản hồi.') : (e instanceof HttpError ? e : new HttpError(0, 'Mất kết nối backend: ' + (e.message || e)));
    }
    await sleep(1000 * Math.pow(2, attempt)); // 1s, 2s, 4s
  }
  throw lastErr || new HttpError(0, 'Backend không phản hồi.');
}

async function pause(reason) {
  await setState({ paused: true, pauseReason: reason, lastError: reason, lastErrorAt: Date.now() });
  try { await api({ action: 'ping', status: 'paused', note: reason }); } catch (e) { /* backend may be the problem */ }
}

// Only one affiliate tab works the queue (the leader); other tabs stay idle.
async function isLeader(tabId) {
  const { leaderTabId } = await chrome.storage.session.get('leaderTabId');
  if (leaderTabId === undefined || leaderTabId === null) {
    await chrome.storage.session.set({ leaderTabId: tabId });
    return true;
  }
  if (leaderTabId === tabId) return true;
  try { await chrome.tabs.get(leaderTabId); return false; }
  catch (e) { await chrome.storage.session.set({ leaderTabId: tabId }); return true; }
}
chrome.tabs.onRemoved.addListener(async (tabId) => {
  const { leaderTabId } = await chrome.storage.session.get('leaderTabId');
  if (leaderTabId === tabId) await chrome.storage.session.remove('leaderTabId');
});

async function handle(msg, sender) {
  const c = await getCfg();
  switch (msg && msg.type) {
    case 'pull': {
      if (!c.enabled || c.paused) return { jobs: [], idle: true };
      if (!sender.tab || !(await isLeader(sender.tab.id))) return { jobs: [], idle: true };
      const left = c.dailyLimit - (await usage());
      if (left <= 0) { await pause('Đạt giới hạn ' + c.dailyLimit + ' link/ngày.'); return { jobs: [], idle: true }; }
      const j = await api({ action: 'pull', limit: Math.min(3, left) });
      await chrome.storage.local.set({ lastPullAt: Date.now() });
      return { jobs: Array.isArray(j.jobs) ? j.jobs : [], minGapMs: c.minGapMs };
    }
    case 'push': {
      const results = Array.isArray(msg.results) ? msg.results.slice(0, 10) : [];
      if (!results.length) return { ok: true };
      await addUsage(results.filter((r) => r.ok).length);
      const j = await api({ action: 'push', results });
      const okN = results.filter((r) => r.ok).length;
      await chrome.storage.local.set({ lastPushAt: Date.now(), lastResult: okN + '/' + results.length + ' link OK' });
      return j;
    }
    case 'blocked': {
      // Shopee refused (403 / anti-fraud / logged out). Stop and wait for a human.
      await pause(String(msg.reason || 'Shopee từ chối yêu cầu.').slice(0, 300));
      return { ok: true };
    }
    case 'ping': {
      if (!c.enabled) return { ok: true };
      await api({ action: 'ping', status: c.paused ? 'paused' : 'ok', note: c.paused ? c.pauseReason : '' });
      return { ok: true };
    }
    case 'resume': {
      await setState({ paused: false, pauseReason: '' });
      return { ok: true };
    }
    case 'testBackend': {
      await api({ action: 'ping', status: 'ok', note: 'kiểm tra từ popup' });
      return { ok: true };
    }
    default:
      return { error: 'unknown message' };
  }
}

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  handle(msg, sender)
    .then((r) => sendResponse(r))
    .catch(async (e) => {
      const m = (e && e.message) ? e.message : String(e);
      await chrome.storage.local.set({ lastError: m, lastErrorAt: Date.now() });
      if (e instanceof HttpError && e.status === 401) await pause(m);
      sendResponse({ error: m });
    });
  return true; // async response
});

chrome.runtime.onInstalled.addListener(paintBadge);
chrome.runtime.onStartup.addListener(paintBadge);
