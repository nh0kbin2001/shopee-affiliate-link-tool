// Cashback Link Relay — page-world script on affiliate.shopee.vn.
// Makes the same Custom Link request the "Custom Link" page makes, from the page
// itself, with the logged-in session. It does not fake any header or fingerprint:
// if Shopee refuses, the result is reported as blocked and the relay pauses.
(function () {
  'use strict';
  if (window.top !== window) return;

  const GQL = '/api/v3/gql?q=batchCustomLink';
  const QUERY = 'query batchGetCustomLink($linkParams: [CustomLinkParam!], $sourceCaller: SourceCaller){ batchCustomLink(linkParams: $linkParams, sourceCaller: $sourceCaller){ shortLink longLink failCode } }';
  const ALLOWED_ORIGIN = /^https:\/\/shopee\.vn\/product\/\d+\/\d+$/;
  const ALLOWED_SHORT = /^https:\/\/(s\.shopee\.vn|shope\.ee|vn\.shp\.ee|shopee\.vn)\//;

  function cookie(name) {
    const m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]+)'));
    return m ? decodeURIComponent(m[1]) : '';
  }

  function reply(reqId, data) {
    window.postMessage(Object.assign({ type: 'CB_RELAY_RES', reqId }, data), window.location.origin);
  }

  async function createLink(originUrl, subId) {
    if (!ALLOWED_ORIGIN.test(originUrl)) return { ok: false, error: 'origin_url không phải link sản phẩm Shopee chuẩn.' };
    if (!/^[a-f0-9]{20}$/.test(subId)) return { ok: false, error: 'sub_id không hợp lệ.' };
    const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json, text/plain, */*', 'Affiliate-Program-Type': '1' };
    const csrf = cookie('csrftoken');
    if (csrf) headers['X-CSRFToken'] = csrf;
    let r;
    try {
      r = await fetch(GQL, {
        method: 'POST',
        credentials: 'include',
        headers,
        body: JSON.stringify({
          operationName: 'batchGetCustomLink',
          query: QUERY,
          variables: { linkParams: [{ originalLink: originUrl, advancedLinkParams: { subId1: subId } }], sourceCaller: 'CUSTOM_LINK_CALLER' },
        }),
      });
    } catch (e) {
      return { ok: false, error: 'Lỗi mạng khi gọi Shopee: ' + (e && e.message || e) };
    }
    let j = null;
    try { j = await r.json(); } catch (e) { j = null; }
    if (j && j.is_login === false) return { ok: false, blocked: true, error: 'Phiên affiliate đã đăng xuất — đăng nhập lại affiliate.shopee.vn.' };
    if (r.status === 403 || r.status === 429 || (j && j.error === 90309999)) {
      return { ok: false, blocked: true, error: 'Shopee từ chối (HTTP ' + r.status + (j && j.error ? ', error ' + j.error : '') + '). Relay đã tạm dừng.' };
    }
    if (!r.ok) return { ok: false, error: 'Shopee trả HTTP ' + r.status };
    const row = j && j.data && Array.isArray(j.data.batchCustomLink) ? j.data.batchCustomLink[0] : null;
    if (!row) return { ok: false, error: 'Phản hồi Shopee không đúng định dạng.' };
    if (Number(row.failCode) !== 0) return { ok: false, error: 'Shopee failCode ' + row.failCode };
    const s = typeof row.shortLink === 'string' ? row.shortLink.trim() : '';
    if (!ALLOWED_SHORT.test(s)) return { ok: false, error: 'shortLink lạ: ' + s.slice(0, 120) };
    return { ok: true, shortLink: s };
  }

  window.addEventListener('message', async (ev) => {
    if (ev.source !== window || !ev.data || ev.data.type !== 'CB_RELAY_REQ') return;
    const { reqId, originUrl, subId } = ev.data;
    if (typeof reqId !== 'string') return;
    try { reply(reqId, await createLink(String(originUrl || ''), String(subId || ''))); }
    catch (e) { reply(reqId, { ok: false, error: 'Lỗi không mong đợi: ' + (e && e.message || e) }); }
  });
})();
