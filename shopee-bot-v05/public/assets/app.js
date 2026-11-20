(function () {
  'use strict';
  var root = document.documentElement;

  function current() {
    var t = root.getAttribute('data-theme');
    return (t === 'light' || t === 'dark') ? t : '';
  }
  function setMode(m) {
    if (m === 'light' || m === 'dark') root.setAttribute('data-theme', m);
    else root.removeAttribute('data-theme');
    try { if (m) localStorage.setItem('theme', m); else localStorage.removeItem('theme'); } catch (e) {}
  }
  function toast(msg) {
    var el = document.getElementById('toast');
    if (!el) {
      el = document.createElement('div'); el.id = 'toast'; el.className = 'toast';
      el.setAttribute('role', 'status'); el.setAttribute('aria-live', 'polite');
      document.body.appendChild(el);
    }
    el.textContent = msg; el.classList.add('show');
    clearTimeout(el._t); el._t = setTimeout(function () { el.classList.remove('show'); }, 1900);
  }
  function copyText(t) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(t).then(function () { toast('Đã chép'); }, function () { toast('Không chép được'); });
    } else { toast('Trình duyệt không hỗ trợ sao chép'); }
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!t || !t.closest) return;
    var b = t.closest('[data-theme-toggle]');
    if (b) {
      var c = current();
      var n = (c === '') ? 'light' : (c === 'light' ? 'dark' : '');
      setMode(n);
      var tg = document.querySelectorAll('[data-theme-toggle]');
      for (var j = 0; j < tg.length; j++) tg[j].setAttribute('aria-pressed', n === 'dark' ? 'true' : 'false');
      toast(n === 'dark' ? 'Giao diện tối' : (n === 'light' ? 'Giao diện sáng' : 'Theo hệ thống'));
      return;
    }
    var cp = t.closest('[data-copy]');
    if (cp) { copyText(cp.getAttribute('data-copy') || ''); return; }
    var fl = t.closest('[data-fill]');
    if (fl) {
      var inp = document.querySelector('input[name="amount"]');
      if (inp) { inp.value = fl.getAttribute('data-fill'); inp.focus(); }
    }
  });

  document.addEventListener('focusin', function (ev) {
    var el = ev.target;
    if (el && el.tagName === 'INPUT' && el.readOnly && el.hasAttribute('data-select')) { try { el.select(); } catch (e) {} }
  });

  document.addEventListener('change', function (ev) {
    var el = ev.target;
    if (el && el.matches && el.matches('[data-autosubmit]') && el.form) el.form.submit();
  });

  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (!f || !f.getAttribute) return;
    var sub = ev.submitter || null;
    // Confirm may sit on the form or on the clicked button (e.g. "Trừ tiền").
    var ask = (sub && sub.getAttribute('data-confirm')) || f.getAttribute('data-confirm');
    if (ask && !window.confirm(ask)) { ev.preventDefault(); return; }
    if (f.getAttribute('data-once') !== null) {
      if (f._busy) { ev.preventDefault(); return; }
      f._busy = true;
      // Keep the clicked button's name/value: disabled buttons are not submitted.
      if (sub && sub.name) {
        var h = document.createElement('input');
        h.type = 'hidden'; h.name = sub.name; h.value = sub.value; f.appendChild(h);
      }
      var shown = sub || f.querySelector('button[type="submit"]');
      setTimeout(function () {
        var all = f.querySelectorAll('button[type="submit"]');
        for (var i = 0; i < all.length; i++) all[i].disabled = true;
        if (shown) { shown.setAttribute('aria-busy', 'true'); shown.textContent = 'Đang xử lý…'; }
      }, 0);
    }
  }, true);
  // Back/forward cache: re-enable buttons if the page is shown again.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    var fs = document.querySelectorAll('form[data-once]');
    for (var i = 0; i < fs.length; i++) { fs[i]._busy = false; var b = fs[i].querySelectorAll('button[type="submit"]'); for (var k = 0; k < b.length; k++) b[k].disabled = false; }
  });

  // Level 2 notifications: poll while the tab is open.
  var bell = document.querySelector('[data-bell]');
  if (bell) {
    var lastId = 0;
    try { lastId = parseInt(localStorage.getItem('notifLast') || '0', 10) || 0; } catch (e) {}
    var poll = function () {
      if (document.hidden) return;
      fetch('api/notifications.php?after=' + lastId, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
          if (!j) return;
          var cEl = document.querySelector('[data-bell-count]');
          if (cEl && j.unread != null) { cEl.textContent = j.unread; cEl.classList.toggle('hide', j.unread <= 0); }
          if (j.items && j.items.length) {
            for (var i = 0; i < j.items.length; i++) {
              var it = j.items[i];
              if (it.id > lastId) lastId = it.id;
              toast(it.title);
              if (window.Notification && Notification.permission === 'granted') {
                try { new Notification(it.title, { body: it.body || '', tag: 'cb' + it.id }); } catch (e) {}
              }
            }
            try { localStorage.setItem('notifLast', String(lastId)); } catch (e) {}
          }
        })
        .catch(function () {});
    };
    setInterval(poll, 45000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
  }

  var nb = document.querySelector('[data-enable-notif]');
  if (nb) {
    nb.addEventListener('click', function () {
      if (window.Notification) {
        Notification.requestPermission().then(function (p) {
          toast(p === 'granted' ? 'Đã bật thông báo trên trình duyệt' : 'Bạn chưa cho phép thông báo');
        });
      } else { toast('Trình duyệt không hỗ trợ thông báo'); }
    });
  }
})();
