const fs = require('fs'), vm = require('vm');
const src = fs.readFileSync(process.argv[2], 'utf8');
function harness(resp) {
  const listeners = []; const out = [];
  const win = { location: { origin: 'https://affiliate.shopee.vn' }, addEventListener: (t, f) => listeners.push(f),
    postMessage: (d) => { if (d.type === 'CB_RELAY_RES') out.push(d); } };
  win.top = win;
  const ctx = { window: win, document: { cookie: 'csrftoken=abc' }, fetch: async (u, o) => { ctx.lastReq = { u, o }; return resp; }, console };
  vm.createContext(ctx); vm.runInContext(src, ctx);
  return { ctx, ask: async (originUrl, subId) => { for (const f of listeners) await f({ source: win, data: { type: 'CB_RELAY_REQ', reqId: 'r1', originUrl, subId } }); return out[0]; } };
}
const mk = (status, body) => ({ status, ok: status >= 200 && status < 300, json: async () => body });
const O = 'https://shopee.vn/product/1/2', SID = 'a'.repeat(20);
let fail = 0; const t = (c, n) => { console.log((c ? 'PASS ' : 'FAIL ') + n); if (!c) fail++; };
(async () => {
  let h = harness(mk(200, { data: { batchCustomLink: [{ shortLink: 'https://s.shopee.vn/AbC', failCode: 0 }] } }));
  let r = await h.ask(O, SID); t(r.ok && r.shortLink === 'https://s.shopee.vn/AbC', 'success');
  const body = JSON.parse(h.ctx.lastReq.o.body); t(body.variables.linkParams[0].advancedLinkParams.subId1 === SID && h.ctx.lastReq.o.headers['X-CSRFToken'] === 'abc', 'subId1 + csrf sent');
  r = await harness(mk(403, { is_login: true, error: 90309999 })).ask(O, SID); t(!r.ok && r.blocked, '403 anti-fraud -> blocked');
  r = await harness(mk(200, { is_login: false })).ask(O, SID); t(!r.ok && r.blocked, 'logged out -> blocked');
  r = await harness(mk(200, { data: { batchCustomLink: [{ shortLink: '', failCode: 3 }] } })).ask(O, SID); t(!r.ok && !r.blocked && /failCode 3/.test(r.error), 'failCode -> error, not blocked');
  r = await harness(mk(200, { data: { batchCustomLink: [{ shortLink: 'https://evil.com/x', failCode: 0 }] } })).ask(O, SID); t(!r.ok && /lạ/.test(r.error), 'foreign short link rejected');
  r = await harness(mk(200, {})).ask('https://evil.com/p', SID); t(!r.ok, 'bad origin rejected before fetch');
  process.exit(fail ? 1 : 0);
})();
