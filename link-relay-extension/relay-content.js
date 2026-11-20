// Cashback Link Relay — content script (isolated world) on affiliate.shopee.vn.
// Loop: ask the service worker for jobs -> hand each to relay-main.js (page world,
// same session as the Custom Link page) -> collect results -> push back.
(function () {
  'use strict';
  if (window.top !== window) return;

  const POLL_MS = 1500;
  const JOB_TIMEOUT_MS = 15000;
  const pending = new Map();
  let busy = false;

  function send(msg) {
    return new Promise((resolve) => {
      try {
        chrome.runtime.sendMessage(msg, (r) => {
          if (chrome.runtime.lastError) resolve({ error: chrome.runtime.lastError.message });
          else resolve(r || {});
        });
      } catch (e) { resolve({ error: String(e && e.message || e) }); } // extension reloaded
    });
  }

  window.addEventListener('message', (ev) => {
    if (ev.source !== window || !ev.data || ev.data.type !== 'CB_RELAY_RES') return;
    const p = pending.get(ev.data.reqId);
    if (!p) return;
    pending.delete(ev.data.reqId);
    clearTimeout(p.timer);
    p.resolve(ev.data);
  });

  function runInPage(job) {
    return new Promise((resolve) => {
      const reqId = Math.random().toString(36).slice(2) + Date.now().toString(36);
      const timer = setTimeout(() => {
        pending.delete(reqId);
        resolve({ ok: false, error: 'Hết thời gian chờ Shopee (' + (JOB_TIMEOUT_MS / 1000) + 's).' });
      }, JOB_TIMEOUT_MS);
      pending.set(reqId, { resolve, timer });
      window.postMessage({ type: 'CB_RELAY_REQ', reqId, originUrl: job.origin_url, subId: job.sub_id }, window.location.origin);
    });
  }

  function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }

  async function tick() {
    if (busy) return;
    busy = true;
    try {
      const r = await send({ type: 'pull' });
      if (r.error || !Array.isArray(r.jobs) || !r.jobs.length) return;
      const gap = Math.max(1500, r.minGapMs || 2500);
      const results = [];
      for (const job of r.jobs) {
        const res = await runInPage(job);
        if (res.blocked) {
          // Report this job as failed, stop the loop, let a human look.
          results.push({ id: job.id, ok: false, error: res.error || 'Shopee từ chối' });
          await send({ type: 'push', results });
          await send({ type: 'blocked', reason: res.error });
          return;
        }
        results.push(res.ok ? { id: job.id, ok: true, short_link: res.shortLink } : { id: job.id, ok: false, error: res.error || 'lỗi không rõ' });
        await sleep(gap);
      }
      await send({ type: 'push', results });
    } finally {
      busy = false;
    }
  }

  setInterval(tick, POLL_MS);
  setInterval(() => { send({ type: 'ping' }); }, 60000);
  tick();
})();
