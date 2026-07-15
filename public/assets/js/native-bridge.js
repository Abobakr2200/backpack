/**
 * native-bridge.js
 * ------------------------------------------------------------------
 * ده مش جزء من مشروع Capacitor نفسه - ده ملف بيتحط جوه مشروع موقع
 * Backpack (public/assets/js/native-bridge.js) عشان يوصل الموقع بالتطبيق.
 *
 * الكود بيشتغل بس لو الموقع بيتفتح جوه تطبيق Backpack (Capacitor)، ومفيش
 * أي تأثير عليه لو المستخدم فاتح نفس الموقع من متصفح عادي أو كـ PWA -
 * فمفيش داعي لأي نسخة "ويب" منفصلة من الموقع.
 *
 * التركيب:
 * 1) انسخي الملف ده في: public/assets/js/native-bridge.js
 * 2) ضيفي السطر ده قبل غلق </body> في الصفحات الرئيسية (أو في فوتر مشترك
 *    لو عندك واحد لكل الصفحات):
 *      <script src="/public/assets/js/native-bridge.js"></script>
 * ------------------------------------------------------------------
 */
(function () {
  function isNativeApp() {
    return !!(window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform());
  }

  if (!isNativeApp()) {
    return; // الموقع بيتفتح من متصفح عادي -> سيبيه زي ما هو من غير أي تعديل
  }

  var Capacitor = window.Capacitor;
  var Plugins = Capacitor.Plugins || {};

  /* ================= 1) شاشة تحميل (Loading) + Splash Screen ================= */
  function injectLoadingOverlay() {
    if (document.getElementById('bp-loading-overlay')) return;
    var style = document.createElement('style');
    style.textContent =
      '#bp-loading-overlay{position:fixed;inset:0;background:#ffffff;z-index:999999;' +
      'display:flex;align-items:center;justify-content:center;transition:opacity .25s ease;}' +
      '#bp-loading-overlay.bp-hide{opacity:0;pointer-events:none;}' +
      '.bp-spinner{width:42px;height:42px;border-radius:50%;' +
      'border:4px solid #e3e8ff;border-top-color:#4f6ef7;animation:bp-spin .8s linear infinite;}' +
      '@keyframes bp-spin{to{transform:rotate(360deg);}}';
    document.head.appendChild(style);

    var overlay = document.createElement('div');
    overlay.id = 'bp-loading-overlay';
    overlay.innerHTML = '<div class="bp-spinner"></div>';
    document.body.appendChild(overlay);
  }

  function hideLoadingOverlay() {
    var overlay = document.getElementById('bp-loading-overlay');
    if (overlay) {
      overlay.classList.add('bp-hide');
      setTimeout(function () { overlay.remove(); }, 300);
    }
  }

  injectLoadingOverlay();

  function hideSplashWhenReady() {
    function reallyHide() {
      if (Plugins.SplashScreen) Plugins.SplashScreen.hide();
      hideLoadingOverlay();
    }
    if (document.readyState === 'complete') {
      setTimeout(reallyHide, 250);
    } else {
      window.addEventListener('load', function () { setTimeout(reallyHide, 250); });
    }
  }
  hideSplashWhenReady();

  /* ================= 2) صفحة "لا يوجد اتصال بالإنترنت" ================= */
  function injectOfflineOverlay() {
    if (document.getElementById('bp-offline-overlay')) return;
    var style = document.createElement('style');
    style.textContent =
      '#bp-offline-overlay{position:fixed;inset:0;background:#ffffff;z-index:999998;' +
      'display:none;flex-direction:column;align-items:center;justify-content:center;padding:24px;' +
      'text-align:center;font-family:-apple-system,Segoe UI,Roboto,sans-serif;}' +
      '#bp-offline-overlay.bp-show{display:flex;}' +
      '#bp-offline-overlay h2{color:#333;margin:16px 0 8px;font-size:20px;}' +
      '#bp-offline-overlay p{color:#777;font-size:14px;margin-bottom:20px;}' +
      '#bp-offline-overlay button{background:#4f6ef7;color:#fff;border:none;padding:12px 28px;' +
      'border-radius:24px;font-size:15px;}';
    document.head.appendChild(style);

    var overlay = document.createElement('div');
    overlay.id = 'bp-offline-overlay';
    overlay.innerHTML =
      '<svg width="72" height="72" viewBox="0 0 24 24" fill="none">' +
      '<path d="M12 18h.01M8.5 14.5a5 5 0 0 1 7 0M5.5 11.5a9 9 0 0 1 13 0M2.5 8.5a13 13 0 0 1 19 0" ' +
      'stroke="#c7cbe0" stroke-width="1.8" stroke-linecap="round"/></svg>' +
      '<h2>لا يوجد اتصال بالإنترنت</h2>' +
      '<p>تأكد من اتصالك بالشبكة وحاول مرة أخرى</p>' +
      '<button id="bp-offline-retry">إعادة المحاولة</button>';
    document.body.appendChild(overlay);

    document.getElementById('bp-offline-retry').addEventListener('click', function () {
      window.location.reload();
    });
  }
  injectOfflineOverlay();

  function setOffline(isOffline) {
    var overlay = document.getElementById('bp-offline-overlay');
    if (!overlay) return;
    overlay.classList.toggle('bp-show', !!isOffline);
  }

  if (Plugins.Network) {
    Plugins.Network.getStatus().then(function (status) { setOffline(!status.connected); });
    Plugins.Network.addListener('networkStatusChange', function (status) { setOffline(!status.connected); });
  } else {
    setOffline(!navigator.onLine);
    window.addEventListener('online', function () { setOffline(false); });
    window.addEventListener('offline', function () { setOffline(true); });
  }

  /* ================= 3) Pull to Refresh ================= */
  (function setupPullToRefresh() {
    var startY = 0, pulling = false;
    var threshold = 80;

    var indicator = document.createElement('div');
    indicator.id = 'bp-ptr-indicator';
    indicator.style.cssText =
      'position:fixed;top:-50px;left:0;right:0;height:50px;display:flex;' +
      'align-items:center;justify-content:center;z-index:999997;transition:top .15s ease;';
    indicator.innerHTML =
      '<div class="bp-spinner" style="width:26px;height:26px;"></div>';
    document.body.appendChild(indicator);

    document.addEventListener('touchstart', function (e) {
      if (window.scrollY === 0 && e.touches.length === 1) {
        startY = e.touches[0].clientY;
        pulling = true;
      }
    }, { passive: true });

    document.addEventListener('touchmove', function (e) {
      if (!pulling) return;
      var diff = e.touches[0].clientY - startY;
      if (diff > 0 && window.scrollY === 0) {
        indicator.style.top = (Math.min(diff, threshold + 30) - 50) + 'px';
      }
    }, { passive: true });

    document.addEventListener('touchend', function () {
      if (!pulling) return;
      pulling = false;
      var top = parseInt(indicator.style.top || '-50', 10);
      if (top + 50 >= threshold) {
        indicator.style.top = '10px';
        window.location.reload();
      } else {
        indicator.style.top = '-50px';
      }
    }, { passive: true });
  })();

  /* ================= 4) زر الرجوع الذكي (Smart Back Button) =================
     لو في مودال/نافذة مفتوحة في الصفحة (زي معاينة صورة، أو قائمة خيارات
     الشات) عرّفي الدالة دي في صفحتك عشان الزرار يقفلها الأول بدل ما يخرج
     من التطبيق أو يرجع صفحة:
       window.__bpCloseTopModal = function () {
         if (myModalIsOpen) { closeMyModal(); return true; }
         return false;
       };
  ================================================================= */
  var lastBackPressTime = 0;

  function showToast(msg) {
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText =
      'position:fixed;bottom:40px;left:50%;transform:translateX(-50%);' +
      'background:rgba(0,0,0,.8);color:#fff;padding:10px 18px;border-radius:20px;' +
      'font-size:13px;z-index:999999;';
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 1800);
  }

  if (Plugins.App) {
    Plugins.App.addListener('backButton', function () {
      if (typeof window.__bpCloseTopModal === 'function' && window.__bpCloseTopModal()) {
        return;
      }

      var isHome = window.location.pathname === '/' || window.location.pathname === '/index.php';

      if (!isHome && window.history.length > 1) {
        window.history.back();
        return;
      }

      var now = Date.now();
      if (now - lastBackPressTime < 2000) {
        Plugins.App.exitApp();
      } else {
        lastBackPressTime = now;
        showToast('اضغط مرة أخرى للخروج');
      }
    });
  }

  /* ================= 5) فتح الروابط الخارجية جوه التطبيق ================= */
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a) return;

    var href = a.getAttribute('href') || '';
    if (!href || href.indexOf('#') === 0 || href.indexOf('javascript:') === 0 || href.indexOf('tel:') === 0 || href.indexOf('mailto:') === 0) {
      return;
    }

    var url;
    try {
      url = new URL(href, window.location.href);
    } catch (err) {
      return;
    }

    if (url.origin === window.location.origin) return; // رابط داخلي -> تنقّل عادي

    if (Plugins.Browser) {
      e.preventDefault();
      Plugins.Browser.open({ url: url.href });
    }
  }, true);

  /* ================= 6) تنزيل الملفات على جهاز المستخدم ================= */
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[download], a.bp-download-file');
    if (!a) return;

    var href = a.getAttribute('href');
    if (!href || !Plugins.Filesystem) return;

    e.preventDefault();
    var fileName = a.getAttribute('download') || href.split('/').pop().split('?')[0] || ('file_' + Date.now());

    fetch(href)
      .then(function (res) { return res.blob(); })
      .then(function (blob) {
        var reader = new FileReader();
        reader.onloadend = function () {
          var base64 = String(reader.result).split(',')[1];
          Plugins.Filesystem.writeFile({
            path: fileName,
            data: base64,
            directory: 'DOCUMENTS',
          }).then(function (result) {
            showToast('تم حفظ الملف: ' + fileName);
            if (Plugins.Share) {
              Plugins.Share.share({ title: fileName, url: result.uri }).catch(function () {});
            }
          }).catch(function () {
            showToast('تعذّر حفظ الملف على الجهاز');
          });
        };
        reader.readAsDataURL(blob);
      })
      .catch(function () {
        showToast('تعذّر تنزيل الملف');
      });
  }, true);

  /* ================= 7) رفع الصور من الكاميرا/الجاليري =================
     أي <input type="file" accept="image/*" capture> موجود بالفعل في صفحات
     الشات (رفع صورة بروفايل، إرسال صورة في المحادثة...) بيشتغل تلقائيًا مع
     Capacitor WebView وبيفتح اختيار "الكاميرا / معرض الصور" أصليًا من غير
     أي كود إضافي، بشرط إضافة صلاحيات الكاميرا والتخزين في AndroidManifest
     (موجودة في ملف android-manifest-additions.xml المرفق).
  ================================================================= */

  /* ================= 8) حفظ تسجيل الدخول (Cookies/Session) =================
     WebView الأصلي بيحتفظ بالكوكيز والـ localStorage تلقائيًا بين مرات فتح
     التطبيق طول ما بيانات التطبيق مش بتتمسح (Clear storage) أو يتم حذف
     التطبيق. مفيش كود إضافي مطلوب هنا، بس تأكدي إن كوكي الجلسة راجع من
     السيرفر بصيغة Secure + SameSite=None (لو الموقع شغال HTTPS) عشان
     الـ WebView يقبلها من غير مشاكل.
  ================================================================= */

})();
