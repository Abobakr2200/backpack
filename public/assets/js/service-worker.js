const CACHE_NAME = 'chag-v5';
const STATIC_ASSETS = [
  '/',
  '/public/assets/css/main.css',
  '/public/assets/js/app.js',
  '/public/assets/js/chat.js',
  '/public/assets/img/default.png',
  '/public/assets/img/icon-192.png'
];

self.addEventListener('install', function(e) {
  e.waitUntil(
    caches.open(CACHE_NAME).then(function(cache) {
      return cache.addAll(STATIC_ASSETS).catch(function() {});
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', function(e) {
  e.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(
        keys.filter(function(k) { return k !== CACHE_NAME; })
            .map(function(k) { return caches.delete(k); })
      );
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', function(e) {
  if (e.request.method !== 'GET') return;
  var url = e.request.url;
  
  // لا تعترض API calls
  if (url.indexOf('/Api/') !== -1 || url.indexOf('.php') !== -1) {
    return;
  }
  
  // Cache first للـ assets
  e.respondWith(
    caches.match(e.request).then(function(cached) {
      if (cached) return cached;
      return fetch(e.request).then(function(response) {
        if (response.ok) {
          var clone = response.clone();
          caches.open(CACHE_NAME).then(function(cache) {
            cache.put(e.request, clone);
          });
        }
        return response;
      }).catch(function() {
        return caches.match('/');
      });
    })
  );
});

/* ══════════════════════════════════════
   PUSH NOTIFICATIONS
   الجزء ده هو اللي بيخلي الإشعار يبان حتى
   لو الموقع مقفول تمامًا على الهاتف/الكمبيوتر،
   لأن الـ service worker بيفضل شغال في الخلفية
   حتى بعد ما التاب/المتصفح يتقفل.
══════════════════════════════════════ */
self.addEventListener('push', function (e) {
  var data = {};
  try { data = e.data ? e.data.json() : {}; } catch (err) {}

  var title = data.title || 'Backpack';
  var options = {
    body: data.body || '',
    icon: data.icon || '/public/assets/img/icon-192.png',
    badge: '/public/assets/img/icon-192.png',
    tag: data.tag || 'chat-ag',
    requireInteraction: !!data.requireInteraction,
    renotify: !!data.renotify,
    vibrate: data.vibrate || [200, 100, 200],
    actions: data.actions || [],
    data: { url: data.url || '/' },
    dir: 'rtl',
    lang: 'ar',
  };

  e.waitUntil(
    self.registration.showNotification(title, options).then(updateAppBadge)
  );
});

// لما المستخدم يضغط على الإشعار (أو على زرار جوّاه)، افتح/ركّز على الصفحة المناسبة
self.addEventListener('notificationclick', function (e) {
  var action = e.action; // 'answer' | 'open' | 'dismiss' | '' (ضغط على الإشعار نفسه)
  e.notification.close();

  if (action === 'dismiss') {
    e.waitUntil(updateAppBadge());
    return;
  }

  var targetUrl = (e.notification.data && e.notification.data.url) || '/';
  if (action === 'answer') {
    targetUrl += (targetUrl.indexOf('?') > -1 ? '&' : '?') + 'autoanswer=1';
  }

  e.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then(function (clientList) {
        for (var i = 0; i < clientList.length; i++) {
          var client = clientList[i];
          if ('focus' in client) {
            client.navigate(targetUrl);
            return client.focus();
          }
        }
        if (self.clients.openWindow) return self.clients.openWindow(targetUrl);
      })
      .then(updateAppBadge)
  );
});

// يحدّث رقم/نقطة التنبيه على أيقونة التطبيق (لو المتصفح بيدعم Badging API)
// بعدد الإشعارات اللي لسه معروضة ومحدش فتحها
function updateAppBadge() {
  if (!self.registration.getNotifications || !('setAppBadge' in self.navigator || 'setAppBadge' in self.registration)) {
    return Promise.resolve();
  }
  return self.registration.getNotifications().then(function (list) {
    try {
      if (list.length > 0) {
        return self.navigator.setAppBadge(list.length);
      }
      return self.navigator.clearAppBadge();
    } catch (e) { /* المتصفح مش بيدعم Badging API */ }
  }).catch(function () {});
}
