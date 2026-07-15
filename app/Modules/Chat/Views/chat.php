<?php
require_once __DIR__ . '/../../../../config/session.php';
require_once __DIR__ . '/../../Auth/Models/User.php';
require_once __DIR__ . '/../Models/Message.php';
require_once __DIR__ . '/../../Notifications/Models/Notification.php';

if (!isLoggedIn()) { header("Location: /app/Modules/Auth/Views/login.php"); exit(); }

$userModel   = new User();
$notifModel  = new Notification();
$user_id     = getUserId();
$currentUser = $userModel->getUserData($user_id);
try { $userModel->updateStatus($user_id, 'online'); } catch(Exception $e) {}
$unread_notif_count = $notifModel->getUnreadCount($user_id);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <link rel="icon" type="image/png" href="/public/assets/img/favicon.png">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#4f6ef7">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="Backpack">
  <title>Backpack</title>
  <link rel="manifest" href="/manifest.json">
  <link rel="stylesheet" href="/public/assets/css/main.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    /* ══ RESET للصفحة ══ */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { height: 100%; }
    body { height: 100%; overflow: hidden; display: flex; flex-direction: column; }

    /*
      ملاحظة: كل الأنماط العامة لصفحة الشات (.app-shell, .chat-shell,
      .chat-sidebar, .chat-form, #emojiPicker, ...إلخ) موجودة في main.css
      عشان تبقى مصدر واحد للحقيقة ومايحصلش تعارض بين نسختين مختلفتين.
      هنا بس العناصر اللي معرّفة بـ id واللي خاصة بصفحة الشات فقط.
    */

    #chatMain {
      flex: 1;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      min-width: 0;
      background: var(--bg-base);
    }

    #chatEmpty {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: var(--text-muted);
      text-align: center;
      gap: 12px;
      padding: 24px;
    }

    #chatContainer {
      display: none;
      flex-direction: column;
      flex: 1;
      overflow: hidden;
      min-height: 0;
      position: relative;
    }

    #messagesArea {
      flex: 1;
      overflow-y: auto;
      overflow-x: hidden;
      padding: 12px 16px;
      display: flex;
      flex-direction: column;
      gap: 4px;
      min-height: 0;
      -webkit-overflow-scrolling: touch;
    }

    #messageInput {
      flex: 1;
      border: none;
      background: none;
      outline: none;
      font-size: 1rem;
      color: var(--text-primary);
      min-width: 0;
      font-family: inherit;
    }
    #messageInput::placeholder { color: var(--text-muted); }
  </style>
</head>
<body class="chat-page">
<div class="app-shell">

  <!-- Header -->
  <header class="top-header">
    <a href="/app/Modules/Posts/Views/feed.php" class="logo"><img src="/public/assets/img/icon-512.png" alt="Backpack"></a>
    <div class="header-search">
      <i class="fas fa-search search-icon"></i>
      <input type="text" id="globalSearch" placeholder="ابحث...">
    </div>
    <nav class="header-nav">
      <a href="/app/Modules/Posts/Views/feed.php" class="nav-btn"><i class="fas fa-home"></i></a>
      <a href="/" class="nav-btn active"><i class="fas fa-comment-dots"></i></a>
      <a href="/app/Modules/Profile/Views/profile.php" class="nav-btn"><i class="fas fa-user"></i></a>
    </nav>
    <div class="header-actions">
      <div class="notif-wrapper">
        <button class="icon-btn" id="notifBtn">
          <i class="fas fa-bell"></i>
          <span class="badge" id="notifBadge" style="display:<?= $unread_notif_count > 0 ? 'flex' : 'none' ?>">
            <?= (int)$unread_notif_count ?>
          </span>
        </button>
        <div class="notif-dropdown" id="notifDropdown">
          <div class="notif-head">الإشعارات</div>
          <div class="notif-list" id="notifList"><div class="notif-empty">جاري التحميل...</div></div>
        </div>
      </div>
      <button class="icon-btn theme-toggle" onclick="toggleTheme()"><i class="fas fa-moon"></i></button>
      <button class="icon-btn" onclick="openNewChatModal()" title="محادثة جديدة"><i class="fas fa-edit"></i></button>
      <a href="/app/Modules/Profile/Views/profile.php">
        <img src="/public/assets/img/<?= htmlspecialchars($currentUser['profile_photo'] ?? 'default.png') ?>"
             class="avatar" width="34" height="34" loading="lazy"
             onerror="this.src='/public/assets/img/default.png'"
             alt="<?= htmlspecialchars($currentUser['username'] ?? '') ?>">
      </a>
      <a href="/app/Modules/Auth/Views/logout.php" class="icon-btn danger"><i class="fas fa-sign-out-alt"></i></a>
    </div>
  </header>

  <!-- Chat Shell -->
  <div class="chat-shell">

    <!-- Overlay for mobile sidebar -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

    <!-- Sidebar -->
    <aside class="chat-sidebar" id="chatSidebar">
      <div class="sidebar-head">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
          <h2 style="margin:0;font-size:1.1rem">الرسائل</h2>
          <div style="display:flex;gap:4px">
            <button class="icon-btn" onclick="window.openNewGroupModal && window.openNewGroupModal()" title="مجموعة جديدة"><i class="fas fa-users"></i></button>
            <button class="icon-btn" onclick="openNewChatModal()" title="محادثة جديدة"><i class="fas fa-plus"></i></button>
          </div>
        </div>
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input type="text" id="convSearch" placeholder="ابحث في المحادثات...">
        </div>
      </div>
      <div class="conv-list" id="convList">
        <div style="padding:12px">
          <?php for($i=0;$i<4;$i++): ?>
          <div style="display:flex;gap:10px;align-items:center;padding:8px 0">
            <div class="skeleton" style="width:50px;height:50px;border-radius:50%;flex-shrink:0"></div>
            <div style="flex:1">
              <div class="skeleton" style="height:13px;margin-bottom:6px;border-radius:4px"></div>
              <div class="skeleton" style="height:11px;width:65%;border-radius:4px"></div>
            </div>
          </div>
          <?php endfor; ?>
        </div>
      </div>
    </aside>

    <!-- Main -->
    <main id="chatMain">

      <!-- Empty state -->
      <div id="chatEmpty">
        <div style="width:80px;height:80px;border-radius:50%;background:var(--bg-surface);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:2rem;color:var(--text-muted)">
          <i class="fas fa-comments"></i>
        </div>
        <h3 style="font-size:1rem;color:var(--text-secondary)">اختر محادثة</h3>
        <p style="font-size:.875rem">أو ابدأ محادثة جديدة</p>
        <button class="btn btn-primary" style="width:auto;padding:10px 24px"
                onclick="openNewChatModal()">
          <i class="fas fa-edit"></i> محادثة جديدة
        </button>
      </div>

      <!-- Active chat -->
      <div id="chatContainer">

        <!-- Chat header -->
        <div class="chat-header">
          <button class="icon-btn" id="backBtn" onclick="closeChatOnMobile()" style="display:none">
            <i class="fas fa-arrow-right"></i>
          </button>
          <img id="chatAvatar" src="/public/assets/img/default.png"
               class="avatar" width="40" height="40"
               onerror="this.src='/public/assets/img/default.png'" alt="">
          <div class="chat-peer-info flex-1">
            <div class="chat-peer-name" id="chatPeerName">—</div>
            <div class="chat-peer-status" id="chatPeerStatus"></div>
          </div>
          <button class="icon-btn" id="videoCallBtn" title="مكالمة فيديو" onclick="window.startVideoCall && window.startVideoCall()">
            <i class="fas fa-video"></i>
          </button>
          <button class="icon-btn" id="voiceCallBtn" title="مكالمة صوتية" onclick="window.startVoiceCall && window.startVoiceCall()">
            <i class="fas fa-phone-alt"></i>
          </button>
          <button class="icon-btn" id="msgSearchBtn" title="بحث في المحادثة" onclick="window.toggleMsgSearch && window.toggleMsgSearch()">
            <i class="fas fa-search"></i>
          </button>
          <a id="chatProfileLink" href="#" class="icon-btn" title="الملف الشخصي">
            <i class="fas fa-user"></i>
          </a>
          <div style="position:relative">
            <button class="icon-btn" id="chatMenuBtn" title="خيارات" onclick="window.toggleChatMenu && window.toggleChatMenu()">
              <i class="fas fa-ellipsis-v"></i>
            </button>
            <div id="chatMenu" style="display:none;position:absolute;left:0;top:100%;background:var(--bg-card,#fff);border:1px solid var(--border,#e5e7eb);border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.1);min-width:180px;z-index:50">
              <button type="button" id="blockBtn" onclick="window.toggleBlockCurrentUser && window.toggleBlockCurrentUser()"
                      style="display:block;width:100%;text-align:right;padding:10px 14px;background:none;border:none;cursor:pointer;color:var(--danger,#e53e3e);font-size:.9rem">
                <i class="fas fa-ban"></i> حظر المستخدم
              </button>
              <button type="button" id="groupMembersBtn" onclick="window.openGroupInfoModal && window.openGroupInfoModal()"
                      style="display:none;width:100%;text-align:right;padding:10px 14px;background:none;border:none;cursor:pointer;color:var(--text-primary);font-size:.9rem">
                <i class="fas fa-users"></i> أعضاء المجموعة
              </button>
              <button type="button" id="renameGroupBtn" onclick="window.promptRenameGroup && window.promptRenameGroup()"
                      style="display:none;width:100%;text-align:right;padding:10px 14px;background:none;border:none;cursor:pointer;color:var(--text-primary);font-size:.9rem">
                <i class="fas fa-pen"></i> تغيير اسم المجموعة
              </button>
              <button type="button" id="leaveGroupBtn" onclick="window.leaveCurrentGroup && window.leaveCurrentGroup()"
                      style="display:none;width:100%;text-align:right;padding:10px 14px;background:none;border:none;cursor:pointer;color:var(--danger,#e53e3e);font-size:.9rem">
                <i class="fas fa-right-from-bracket"></i> مغادرة المجموعة
              </button>
            </div>
          </div>
        </div>

        <!-- بحث داخل رسائل المحادثة -->
        <div id="msgSearchPanel" class="msg-search-panel">
          <div class="msg-search-box">
            <i class="fas fa-search search-icon"></i>
            <input type="text" id="msgSearchInput" placeholder="ابحث في الرسائل...">
            <button type="button" class="icon-btn" onclick="window.closeMsgSearch && window.closeMsgSearch()">
              <i class="fas fa-times"></i>
            </button>
          </div>
          <div id="msgSearchResults" class="msg-search-results"></div>
        </div>

        <!-- Messages -->
        <div id="messagesArea"></div>

        <!-- رجوع لآخر الرسائل بعد القفز لنتيجة بحث -->
        <button type="button" id="jumpToLatestBtn" class="jump-to-latest-btn" style="display:none" onclick="window.exitJumpMode && window.exitJumpMode()">
          <i class="fas fa-arrow-down"></i> أحدث الرسائل
        </button>

        <!-- Input -->
        <div class="chat-input-area">
          <div id="replyPreviewBar" class="reply-preview-bar" style="display:none"></div>
          <div class="chat-form">
            <div class="emoji-container">
              <button type="button" class="icon-btn" id="emojiBtn"><i class="far fa-smile"></i></button>
              <div id="emojiPicker"></div>
            </div>
            <label for="fileInput" class="icon-btn"><i class="fas fa-paperclip"></i></label>
            <input type="file" id="fileInput" accept="image/*,.pdf,.doc,.docx,.xlsx,.txt" style="display:none">
            <button type="button" class="icon-btn" id="locationBtn" title="مشاركة الموقع" onclick="window.shareLocation && window.shareLocation()">
              <i class="fas fa-map-marker-alt"></i>
            </button>
            <div class="chat-input-wrap" id="textInputWrap">
              <input type="text" id="messageInput" placeholder="اكتب رسالة..." autocomplete="off" maxlength="4000">
            </div>
            <div class="voice-record-bar" id="voiceRecordBar">
              <span class="voice-record-dot"></span>
              <span class="voice-record-timer" id="voiceTimer">0:00</span>
              <span class="voice-record-hint">جارِ التسجيل...</span>
              <button type="button" class="voice-record-cancel" id="voiceCancelBtn" title="إلغاء">
                <i class="fas fa-trash"></i>
              </button>
              <button type="button" class="voice-record-send" id="voiceSendBtn" title="إرسال">
                <i class="fas fa-paper-plane"></i>
              </button>
            </div>
            <button type="button" class="icon-btn" id="micBtn" title="رسالة صوتية">
              <i class="fas fa-microphone"></i>
            </button>
            <button type="button" class="send-btn" id="sendBtn"><i class="fas fa-paper-plane"></i></button>
          </div>
        </div>

      </div><!-- /chatContainer -->
    </main>
  </div><!-- /chat-shell -->
</div><!-- /app-shell -->

<!-- Modal -->
<div class="modal" id="newChatModal">
  <div class="modal-box">
    <div class="modal-head">
      <h3>محادثة جديدة</h3>
      <button class="close-btn" onclick="closeNewChatModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="search-box" style="margin-bottom:14px">
        <i class="fas fa-search"></i>
        <input type="text" id="userSearch" placeholder="اكتب الاسم للبحث...">
      </div>
      <div id="searchResults">
        <div style="text-align:center;color:var(--text-muted);padding:24px;font-size:.875rem">
          <i class="fas fa-search" style="font-size:1.5rem;display:block;margin-bottom:8px"></i>
          ابحث عن مستخدم
        </div>
      </div>
    </div>
  </div>
</div>

<!-- New Group Modal -->
<div class="modal" id="newGroupModal">
  <div class="modal-box">
    <div class="modal-head">
      <h3>مجموعة جديدة</h3>
      <button class="close-btn" onclick="window.closeNewGroupModal && window.closeNewGroupModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div style="margin-bottom:14px">
        <input type="text" id="newGroupTitle" placeholder="اسم المجموعة" maxlength="100"
               style="width:100%;padding:10px 14px;border:1px solid var(--border);border-radius:8px;background:var(--bg-base);color:var(--text-primary);font-size:.9375rem">
      </div>
      <div id="newGroupSelectedChips" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px"></div>
      <div class="search-box" style="margin-bottom:14px">
        <i class="fas fa-search"></i>
        <input type="text" id="groupMemberSearch" placeholder="ابحث عن أعضاء لإضافتهم...">
      </div>
      <div id="groupMemberSearchResults"></div>
      <button type="button" class="btn btn-primary" style="width:100%;margin-top:14px"
              onclick="window.submitCreateGroup && window.submitCreateGroup()">
        <i class="fas fa-users"></i> إنشاء المجموعة
      </button>
    </div>
  </div>
</div>

<!-- Group Info Modal -->
<div class="modal" id="groupInfoModal">
  <div class="modal-box">
    <div class="modal-head">
      <h3>أعضاء المجموعة</h3>
      <button class="close-btn" onclick="window.closeGroupInfoModal && window.closeGroupInfoModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div id="groupInfoMembersList" style="margin-bottom:14px">جارِ التحميل...</div>
      <div class="search-box" style="margin-bottom:10px">
        <i class="fas fa-search"></i>
        <input type="text" id="groupAddMemberSearch" placeholder="أضف عضو جديد...">
      </div>
      <div id="groupAddMemberResults"></div>
    </div>
  </div>
</div>

<!-- Lightbox -->
<div id="imgLightbox" onclick="this.style.display='none'"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:9000;align-items:center;justify-content:center;cursor:zoom-out">
  <img id="imgLightboxSrc" style="max-width:92vw;max-height:92vh;border-radius:10px;object-fit:contain">
</div>

<!-- Voice Call overlay -->
<div id="callOverlay" class="call-overlay" style="display:none">
  <div class="call-box">
    <img id="callPeerAvatar" class="call-avatar" src="/public/assets/img/default.png" alt="">
    <div class="call-peer-name" id="callPeerName">—</div>
    <div class="call-status" id="callStatusText">جارِ الاتصال...</div>
    <audio id="callRemoteAudio" autoplay playsinline></audio>
    <video id="callRemoteVideo" autoplay playsinline></video>
    <video id="callLocalVideo" autoplay playsinline muted></video>
    <div class="call-actions">
      <button type="button" class="call-btn call-btn-mute" id="callMuteBtn" title="كتم الصوت" style="display:none">
        <i class="fas fa-microphone"></i>
      </button>
      <button type="button" class="call-btn call-btn-speaker" id="callSpeakerBtn" title="تبديل السماعة" style="display:none">
        <i class="fas fa-volume-up"></i>
      </button>
      <button type="button" class="call-btn call-btn-camera" id="callCameraBtn" title="الكاميرا" style="display:none">
        <i class="fas fa-video"></i>
      </button>
      <button type="button" class="call-btn call-btn-switch-camera" id="callSwitchCameraBtn" title="تبديل الكاميرا" style="display:none">
        <i class="fas fa-sync-alt"></i>
      </button>
      <button type="button" class="call-btn call-btn-accept" id="callAcceptBtn" style="display:none" title="رد">
        <i class="fas fa-phone-alt"></i>
      </button>
      <button type="button" class="call-btn call-btn-end" id="callEndBtn" title="إنهاء">
        <i class="fas fa-phone-slash"></i>
      </button>
    </div>
  </div>
</div>

<script>window.CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;</script>
<script>window.CURRENT_USER_ID = <?= json_encode((int)$user_id) ?>;</script>
<script src="/public/assets/js/app.js"></script>
<script src="/public/assets/js/chat.js"></script>
<script>
// Mobile sidebar
function closeSidebar() {
  document.getElementById('chatSidebar').classList.remove('open');
  document.getElementById('sidebarOverlay').classList.remove('show');
}
window.toggleChatSidebar = function() {
  var sb = document.getElementById('chatSidebar');
  var ov = document.getElementById('sidebarOverlay');
  var isOpen = sb.classList.toggle('open');
  ov.classList.toggle('show', isOpen);
};
</script>
<script src="/public/assets/js/native-bridge.js"></script>
</body>
</html>
