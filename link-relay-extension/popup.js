'use strict';
const $ = (id) => document.getElementById(id);
const KEYS = ['backendUrl', 'relayKey', 'enabled', 'paused', 'pauseReason', 'dailyLimit', 'lastError', 'lastErrorAt', 'lastPullAt', 'lastResult', 'usageDay', 'usageCount'];

function ago(ts) {
  if (!ts) return 'chưa có';
  const s = Math.round((Date.now() - ts) / 1000);
  return s < 60 ? s + ' giây trước' : Math.round(s / 60) + ' phút trước';
}

function send(msg) {
  return new Promise((resolve) => chrome.runtime.sendMessage(msg, (r) => resolve(chrome.runtime.lastError ? { error: chrome.runtime.lastError.message } : (r || {}))));
}

async function render() {
  const s = await chrome.storage.local.get(KEYS);
  $('backendUrl').value = s.backendUrl || 'https://bot.dailyhotdeals.biz';
  $('relayKey').value = s.relayKey || '';
  $('enabled').checked = !!s.enabled;
  $('dailyLimit').value = s.dailyLimit || 300;
  const used = s.usageDay === new Date().toISOString().slice(0, 10) ? (s.usageCount || 0) : 0;
  const st = $('status');
  if (!s.enabled) { st.className = 'status'; st.textContent = 'Đang tắt.'; }
  else if (s.paused) { st.className = 'status err'; st.textContent = 'Tạm dừng: ' + (s.pauseReason || ''); }
  else {
    st.className = 'status ok';
    st.textContent = 'Đang chạy · lấy việc ' + ago(s.lastPullAt) + ' · hôm nay ' + used + ' link' + (s.lastResult ? ' · ' + s.lastResult : '');
  }
  $('resume').disabled = !s.paused;
  if (s.lastError && (!s.lastErrorAt || Date.now() - s.lastErrorAt < 3600e3)) $('msg').textContent = 'Lỗi gần nhất (' + ago(s.lastErrorAt) + '): ' + s.lastError;
}

$('save').addEventListener('click', async () => {
  const key = $('relayKey').value.trim().toLowerCase();
  if (key && !/^[a-f0-9]{64}$/.test(key)) { $('msg').textContent = 'Relay key phải là 64 ký tự hex.'; return; }
  let url = $('backendUrl').value.trim();
  try { const u = new URL(url); if (u.protocol !== 'https:') throw new Error(); url = u.origin; }
  catch (e) { $('msg').textContent = 'Backend phải là địa chỉ https:// hợp lệ.'; return; }
  const limit = Math.max(1, Math.min(2000, parseInt($('dailyLimit').value, 10) || 300));
  await chrome.storage.local.set({ backendUrl: url, relayKey: key, enabled: $('enabled').checked, dailyLimit: limit });
  await send({ type: 'resume' }); // also repaints badge
  $('msg').textContent = 'Đã lưu.';
  render();
});

$('test').addEventListener('click', async () => {
  $('msg').textContent = 'Đang thử…';
  const r = await send({ type: 'testBackend' });
  $('msg').textContent = r.error ? 'Lỗi: ' + r.error : 'Backend OK, relay key đúng.';
});

$('resume').addEventListener('click', async () => {
  await send({ type: 'resume' });
  $('msg').textContent = 'Đã tiếp tục. Nếu Shopee vẫn từ chối, relay sẽ dừng lại.';
  render();
});

render();
