/**
 * Backpack — Global App JS
 * Handles: theme, PWA install, bottom nav, lazy images,
 *          notifications, online users, global search
 */
(function () {
  'use strict';

  /* ─── Theme ─── */
  const THEME_KEY = 'chag_theme';
  const root = document.documentElement;

  function applyTheme(t) {
    root.setAttribute('data-theme', t);
    localStorage.setItem(THEME_KEY, t);
    document.querySelectorAll('.theme-toggle i').forEach(el => {
      el.className = t === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    });
  }

  function initTheme() {
    const saved = localStorage.getItem(THEME_KEY);
    const preferred = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    applyTheme(saved || preferred);
  }

  window.toggleTheme = function () {
    applyTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
  };

  /* ─── Lazy Images ─── */
  function initLazyImages() {
    const imgs = document.querySelectorAll('img[loading="lazy"]');
    if (!imgs.length) return;
    const io = new IntersectionObserver(entries => {
      entries.forEach(e => {
        if (e.isIntersecting) {
          const img = e.target;
          img.addEventListener('load', () => img.classList.add('loaded'));
          img.addEventListener('error', () => img.classList.add('loaded'));
          if (img.complete) img.classList.add('loaded');
          io.unobserve(img);
        }
      });
    }, { rootMargin: '100px' });
    imgs.forEach(i => io.observe(i));
  }

  /* ─── PWA Install ─── */
  let _deferredPrompt = null;

  window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    _deferredPrompt = e;
    const banner = document.getElementById('pwaBanner');
    if (banner) banner.classList.add('show');
  });

  window.installPWA = async function () {
    if (!_deferredPrompt) return;
    _deferredPrompt.prompt();
    const { outcome } = await _deferredPrompt.userChoice;
    if (outcome === 'accepted') {
      const banner = document.getElementById('pwaBanner');
      if (banner) banner.classList.remove('show');
    }
    _deferredPrompt = null;
  };

  window.dismissPWA = function () {
    const banner = document.getElementById('pwaBanner');
    if (banner) banner.classList.remove('show');
    _deferredPrompt = null;
  };

  /* ─── Service Worker ─── */
  function registerSW() {
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('/service-worker.js', { scope: '/' })
        .then(function (reg) {
          initPushNotifications(reg);
        })
        .catch(() => {});
    }
  }

  // يمسح نقطة/رقم التنبيه من فوق أيقونة التطبيق (Badging API) — بيتنفذ
  // لما المستخدم يفتح الموقع تاني بعد ما وصله إشعار
  function clearAppBadge() {
    try { if ('clearAppBadge' in navigator) navigator.clearAppBadge(); } catch (e) {}
  }
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') clearAppBadge();
  });
  window.addEventListener('focus', clearAppBadge);

  /* ─── Push Notifications (تصل حتى لو الموقع مقفول تمامًا) ─── */
  function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var rawData = window.atob(base64);
    var outputArray = new Uint8Array(rawData.length);
    for (var i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
    return outputArray;
  }

  function pushSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && window.isSecureContext;
  }

  async function subscribeToPush(reg) {
    if (!window.CSRF_TOKEN) return; // مش على صفحة فيها CSRF token (زي صفحات تسجيل الدخول)
    try {
      const keyRes  = await fetch('/app/Modules/Notifications/Api/vapid_public_key.php');
      const keyData = await keyRes.json();
      if (!keyData.success || !keyData.key) return; // السيرفر لسه ماظبطش مفاتيح VAPID

      let sub = await reg.pushManager.getSubscription();
      if (!sub) {
        sub = await reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(keyData.key),
        });
      }

      await fetch('/app/Modules/Notifications/Api/push_subscribe.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ subscription: sub.toJSON(), csrf_token: window.CSRF_TOKEN }),
      });
    } catch (_) {}
  }

  function showPushBanner(reg) {
    if (document.getElementById('pushBanner') || localStorage.getItem('chag_push_dismissed')) return;
    const bar = document.createElement('div');
    bar.id = 'pushBanner';
    bar.style.cssText = 'position:fixed;bottom:14px;left:14px;right:14px;max-width:420px;margin:0 auto;'
      + 'background:var(--bg-surface,#fff);border:1px solid var(--border,#e5e7eb);border-radius:12px;'
      + 'box-shadow:0 8px 24px rgba(0,0,0,.18);padding:14px 16px;display:flex;align-items:center;gap:10px;z-index:9400';
    bar.innerHTML =
      '<i class="fas fa-bell" style="color:var(--brand,#4f6ef7);font-size:1.2rem"></i>'
      + '<span style="flex:1;font-size:.85rem">فعّل التنبيهات عشان توصلك الرسائل والمكالمات حتى لو الموقع مقفول</span>'
      + '<button id="pushEnableBtn" style="background:var(--brand,#4f6ef7);color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:.8rem;font-weight:600;cursor:pointer;white-space:nowrap">تفعيل</button>'
      + '<button id="pushDismissBtn" style="background:none;border:none;color:var(--text-muted,#888);cursor:pointer;font-size:1rem">&times;</button>';
    document.body.appendChild(bar);

    document.getElementById('pushEnableBtn').addEventListener('click', async () => {
      bar.remove();
      const perm = await Notification.requestPermission();
      if (perm === 'granted') subscribeToPush(reg);
    });
    document.getElementById('pushDismissBtn').addEventListener('click', () => {
      bar.remove();
      localStorage.setItem('chag_push_dismissed', '1');
    });
  }

  function initPushNotifications(reg) {
    if (!pushSupported()) return;
    // فقط في صفحات المستخدم المسجّل دخوله (فيها زرار الإشعارات)
    if (!document.getElementById('notifBtn') && !document.getElementById('voiceCallBtn')) return;

    if (Notification.permission === 'granted') {
      subscribeToPush(reg);
    } else if (Notification.permission === 'default') {
      showPushBanner(reg);
    }
  }

  /* ─── Notification Dropdown ─── */
  function initNotifDropdown() {
    const btn      = document.getElementById('notifBtn');
    const dropdown = document.getElementById('notifDropdown');
    if (!btn || !dropdown) return;

    btn.addEventListener('click', e => {
      e.stopPropagation();
      const isOpen = dropdown.classList.toggle('active');
      if (isOpen) fetchNotifications();
    });

    document.addEventListener('click', e => {
      if (!btn.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.classList.remove('active');
      }
    });
  }

  async function fetchNotifications() {
    const list = document.getElementById('notifList');
    if (!list) return;
    try {
      const res  = await fetch('/app/Modules/Notifications/Api/get_notifications.php');
      const data = await res.json();
      const badge = document.getElementById('notifBadge');

      if (data.unread_count > 0) {
        if (badge) { badge.textContent = data.unread_count; badge.style.display = 'flex'; }
      } else {
        if (badge) badge.style.display = 'none';
      }

      if (!data.notifications || data.notifications.length === 0) {
        list.innerHTML = '<div class="notif-empty"><i class="far fa-bell" style="font-size:2rem;display:block;margin-bottom:8px;"></i>لا توجد إشعارات</div>';
        return;
      }

      list.innerHTML = data.notifications.map(n => {
        const icons = { like: 'fas fa-heart', comment: 'fas fa-comment', follow: 'fas fa-user-plus', message: 'fas fa-envelope' };
        const labels = { like: 'أعجب بمنشورك', comment: 'علّق على منشورك', follow: 'بدأ متابعتك', message: 'أرسل رسالة' };
        return `
          <div class="notif-item ${n.is_read ? '' : 'unread'}" data-id="${n.id}"
               data-type="${n.type}" data-post-id="${n.post_id || ''}" data-sender-id="${n.sender_id}">
            <img src="/public/assets/img/${n.profile_photo}" alt="${esc(n.username)}" class="avatar" width="44" height="44" loading="lazy">
            <div class="notif-content">
              <p><strong>${esc(n.username)}</strong> ${labels[n.type] || ''}</p>
              <span class="notif-time">${formatTime(n.created_at)}</span>
            </div>
            <i class="${icons[n.type] || 'fas fa-bell'}" style="color:var(--brand);font-size:.85rem;"></i>
          </div>`;
      }).join('');

      list.querySelectorAll('.notif-item:not(.read-marked)').forEach(el => {
        el.addEventListener('click', async () => {
          if (el.classList.contains('unread')) {
            el.classList.remove('unread');
            el.classList.add('read-marked');
            await fetch('/app/Modules/Notifications/Api/mark_notifications_read.php', { method: 'POST' });
            clearAppBadge();
          }
          // نوجّه المستخدم لمكان الإشعار (المنشور نفسه أو بروفايل اللي عمل متابعة)
          const type     = el.dataset.type;
          const postId   = el.dataset.postId;
          const senderId = el.dataset.senderId;
          if ((type === 'like' || type === 'comment') && postId) {
            location.href = '/app/Modules/Posts/Views/feed.php#post-' + postId;
          } else if (type === 'follow' && senderId) {
            location.href = '/app/Modules/Profile/Views/profile.php?id=' + senderId;
          }
        });
      });
    } catch (_) {}
  }

  /* ─── Online Users (Sidebar) ─── */
  async function fetchOnlineUsers() {
    const el = document.getElementById('onlineUsers');
    if (!el) return;
    try {
      const res  = await fetch('/app/Modules/Chat/Api/get_online_users.php');
      const data = await res.json();
      const users = data.users || [];

      if (!users.length) {
        el.innerHTML = '<p style="font-size:.8rem;color:var(--text-muted);padding:4px 0;">لا أحد متصل الآن</p>';
        return;
      }

      el.innerHTML = users.slice(0, 12).map(u => `
        <div class="contact-item">
          <div class="avatar" style="width:38px;height:38px;position:relative;">
            <img src="/public/assets/img/${u.profile_photo}" alt="${esc(u.username)}"
                 class="avatar" width="38" height="38" loading="lazy"
                 style="width:38px;height:38px;">
            ${u.status === 'online' ? '<span class="online-dot"></span>' : ''}
          </div>
          <span>${esc(u.username)}</span>
        </div>`).join('');
    } catch (_) {}
  }

  /* ─── Utilities ─── */
  function esc(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
  }

  window.formatTime = function(dateStr) {
    const diff = Date.now() - new Date(dateStr);
    if (diff < 60000)      return 'الآن';
    if (diff < 3600000)    return Math.floor(diff / 60000) + 'د';
    if (diff < 86400000)   return Math.floor(diff / 3600000) + 'س';
    if (diff < 604800000)  return Math.floor(diff / 86400000) + 'ي';
    return new Date(dateStr).toLocaleDateString('ar-SA');
  };

  window.escHtml = esc;

  /* ─── Bottom Nav active state ─── */
  function initBottomNav() {
    const path = location.pathname;
    document.querySelectorAll('.bottom-nav-btn[data-path]').forEach(btn => {
      if (path.includes(btn.dataset.path)) btn.classList.add('active');
    });
  }

  /* ─── Chat sidebar toggle (mobile) ─── */
  window.toggleChatSidebar = function () {
    document.querySelector('.chat-sidebar')?.classList.toggle('open');
  };

  /* ─── Global Search (header) ─── */
  function initGlobalSearch() {
    const input = document.getElementById('globalSearch');
    if (!input) return;
    let timer;
    input.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => {
        const q = input.value.trim();
        if (q.length < 2) return;
        // redirect to search (future feature)
      }, 400);
    });
  }

  /* ─── Init ─── */
  document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initLazyImages();
    initNotifDropdown();
    initBottomNav();
    initGlobalSearch();
    registerSW();
    fetchOnlineUsers();

    // Periodic refresh
    setInterval(fetchNotifications, 30000);
    setInterval(fetchOnlineUsers, 60000);
  });
})();

/* ─── Global Header Search (shared across all pages) ─── */
(function initHeaderSearch() {
  document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('globalSearch');
    if (!input) return;

    let timer;
    let resultsBox = null;

    function closeResults() {
      if (resultsBox) { resultsBox.remove(); resultsBox = null; }
    }

    function escStr(s) {
      return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function truncate(s, n) {
      s = s || '';
      return s.length > n ? s.slice(0, n) + '…' : s;
    }

    function showResults(users, posts) {
      closeResults();
      const wrap = input.closest('.header-search');
      wrap.style.position = 'relative';

      resultsBox = document.createElement('div');
      resultsBox.style.cssText = [
        'position:absolute','top:calc(100% + 6px)','right:0','left:0',
        'background:var(--bg-surface)','border:1px solid var(--border)',
        'border-radius:var(--radius-md)','box-shadow:var(--shadow-lg)',
        'z-index:9999','overflow:hidden','max-height:420px','overflow-y:auto'
      ].join(';');

      if (!users.length && !posts.length) {
        resultsBox.innerHTML = '<div style="padding:18px;text-align:center;color:var(--text-muted);font-size:.875rem"><i class="fas fa-search-minus" style="display:block;font-size:1.5rem;margin-bottom:6px"></i>لا توجد نتائج</div>';
        wrap.appendChild(resultsBox);
        return;
      }

      let html = '';

      if (users.length) {
        html += '<div style="padding:8px 14px;font-size:.72rem;font-weight:700;color:var(--text-muted);background:var(--bg-base)">الأشخاص</div>';
        html += users.map(u => `
          <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border);transition:background .15s"
               onmouseover="this.style.background='var(--bg-base)'" onmouseout="this.style.background=''">
            <a href="/app/Modules/Profile/Views/profile.php?id=${u.id}"
               style="display:flex;align-items:center;gap:10px;flex:1;text-decoration:none;color:inherit;min-width:0" onclick="closeResultsNow()">
              <img src="/public/assets/img/${escStr(u.profile_photo)}" width="38" height="38"
                   style="width:38px;height:38px;border-radius:50%;object-fit:cover;flex-shrink:0"
                   onerror="this.src='/public/assets/img/default.png'">
              <div style="min-width:0">
                <div style="font-weight:600;font-size:.9rem">${escStr(u.username)}</div>
                <div style="font-size:.72rem;color:${u.status==='online'?'var(--success)':'var(--text-muted)'}">
                  ${u.status==='online'?'● متصل الآن':'غير متصل'}
                </div>
              </div>
            </a>
            <button onclick="goToChat(${u.id},'${escStr(u.username)}','${escStr(u.profile_photo)}')"
                    style="padding:5px 12px;border:none;border-radius:var(--radius-sm);background:var(--brand);color:#fff;font-size:.75rem;font-weight:600;white-space:nowrap;cursor:pointer;flex-shrink:0">
              <i class="fas fa-comment"></i> راسل
            </button>
          </div>`).join('');
      }

      if (posts.length) {
        html += '<div style="padding:8px 14px;font-size:.72rem;font-weight:700;color:var(--text-muted);background:var(--bg-base)">المنشورات</div>';
        html += posts.map(p => `
          <a href="/app/Modules/Posts/Views/feed.php#post-${p.id}"
             style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border);text-decoration:none;color:inherit;transition:background .15s"
             onmouseover="this.style.background='var(--bg-base)'" onmouseout="this.style.background=''" onclick="closeResultsNow()">
            <img src="/public/assets/img/${escStr(p.profile_photo)}" width="34" height="34"
                 style="width:34px;height:34px;border-radius:50%;object-fit:cover;flex-shrink:0"
                 onerror="this.src='/public/assets/img/default.png'">
            <div style="min-width:0">
              <div style="font-weight:600;font-size:.85rem">${escStr(p.username)}</div>
              <div style="font-size:.78rem;color:var(--text-secondary)">${escStr(truncate(p.content, 60))}</div>
            </div>
          </a>`).join('');
      }

      resultsBox.innerHTML = html;
      wrap.appendChild(resultsBox);
    }

    window.closeResultsNow = closeResults;

  /* Navigate to chat — works from any page */
  window.goToChat = function(id, username, photo) {
    // لو على صفحة الشات، افتح مباشرة
    if (typeof window.startChat === 'function') {
      if (typeof closeResults === 'function') closeResults();
      if (typeof window.closeResultsNow === 'function') window.closeResultsNow();
      window.startChat(id, username, photo);
    } else {
      // انتقل لصفحة الشات
      window.location.href = '/?chat=' + id;
    }
  };


    async function doSearch(q) {
      try {
        const [usersRes, postsRes] = await Promise.all([
          fetch('/app/Modules/Profile/Api/search_users.php?q=' + encodeURIComponent(q)),
          fetch('/app/Modules/Posts/Api/search_posts.php?q=' + encodeURIComponent(q))
        ]);
        const usersData = await usersRes.json();
        const postsData = await postsRes.json();
        showResults(usersData.users || [], postsData.posts || []);
      } catch (_) {
        closeResults();
      }
    }

    input.addEventListener('input', function () {
      clearTimeout(timer);
      const q = this.value.trim();
      if (q.length < 2) { closeResults(); return; }
      timer = setTimeout(() => doSearch(q), 300);
    });

    input.addEventListener('focus', function () {
      const q = this.value.trim();
      if (q.length >= 2) doSearch(q);
    });

    input.addEventListener('keydown', e => {
      if (e.key === 'Escape') { closeResults(); input.blur(); }
    });

    document.addEventListener('click', e => {
      if (!input.contains(e.target) && (!resultsBox || !resultsBox.contains(e.target))) {
        closeResults();
      }
    });
  });
})();
