/**
 * Backpack — Chat JS (clean rewrite)
 */
(function () {
  'use strict';

  /* ── Config ── */
  const API = {
    convs        : '/app/Modules/Chat/Api/get_conversations.php',
    messages     : '/app/Modules/Chat/Api/get_messages.php',
    send         : '/app/Modules/Chat/Api/send_message.php',
    markRead     : '/app/Modules/Chat/Api/mark_as_read.php',
    upload       : '/app/Modules/Chat/Api/upload_file.php',
    search       : '/app/Modules/Profile/Api/search_users.php',
    userInfo     : '/app/Modules/Profile/Api/get_user_info.php',
    typing       : '/app/Modules/Chat/Api/typing.php',
    reactions    : '/app/Modules/Chat/Api/get_reactions.php',
    createGroup  : '/app/Modules/Chat/Api/create_group.php',
    groupInfo    : '/app/Modules/Chat/Api/group_info.php',
    groupMembers : '/app/Modules/Chat/Api/group_members.php',
    renameGroup  : '/app/Modules/Chat/Api/rename_group.php',
    search       : '/app/Modules/Chat/Api/search_messages.php',
  };

  /* ── State ── */
  var receiverId   = null;
  var receiverName = '';
  var receiverPhoto = 'default.png';
  var lastId       = 0;
  var pollTimer    = null;
  var isSending    = false;
  var typingPingTimer   = null; // آخر مرة بعتنا فيها ping إننا بنكتب
  var typingStopTimeout = null; // مؤقت اعتبار إننا وقفنا نكتب بعد سكون

  /* ── حالة الجروب (المحادثة الجماعية النشطة، لو فيه) ── */
  var currentChatType   = 'direct'; // 'direct' | 'group'
  var groupId           = null;
  var groupTitle        = '';
  var groupMembersInfo  = [];       // آخر بيانات أعضاء اتجابت من group_info.php
  var selectedNewGroupMembers = {}; // {user_id: {id,username,profile_photo}} أثناء إنشاء جروب

  /* ── حالة "الرد على رسالة" ── */
  var replyToId   = null;
  var replyToInfo = null; // {sender, text}

  /* ── حالة "وضع البحث/القفز لرسالة" ── */
  var isJumpMode = false; // لما نكون فاتحين رسائل حوالين نتيجة بحث، بنوقف اللايف بولينج مؤقتاً

  /* ════════════════════════════════════
     CONVERSATIONS
  ════════════════════════════════════ */
  function loadConvs(filter) {
    fetch(API.convs)
      .then(function(r){ return r.json(); })
      .then(function(data){
        renderConvs(data.conversations || [], filter || '');
        // بعد تحميل المحادثات، تحقق من URL param
        if (!filter) openChatFromURL();
      })
      .catch(function(){});
  }

  function renderConvs(list, filter) {
    var el = document.getElementById('convList');
    if (!el) return;

    if (filter) {
      list = list.filter(function(c){
        var name = c.type === 'group' ? (c.title || '') : (c.username || '');
        return name.toLowerCase().indexOf(filter.toLowerCase()) !== -1;
      });
    }

    if (!list.length) {
      el.innerHTML = '<div style="padding:24px;text-align:center;color:var(--text-muted);font-size:.875rem">'
        + '<i class="fas fa-comment-slash" style="font-size:1.8rem;display:block;margin-bottom:8px"></i>'
        + (filter ? 'لا توجد نتائج' : 'لا توجد محادثات بعد<br><br>'
            + '<button onclick="openNewChatModal()" style="padding:8px 18px;background:var(--brand);'
            + 'color:#fff;border:none;border-radius:var(--radius-full);cursor:pointer;font-size:.85rem">'
            + '<i class=\'fas fa-plus\'></i> ابدأ محادثة</button>')
        + '</div>';
      return;
    }

    var html = '';
    list.forEach(function(c){
      if (c.type === 'group') {
        html += renderGroupConvItem(c);
      } else {
        html += renderDirectConvItem(c);
      }
    });
    el.innerHTML = html;
  }

  function renderDirectConvItem(c) {
    var active   = (currentChatType === 'direct' && c.user_id == receiverId) ? ' active' : '';
    var time     = c.last_message_time ? fmt(c.last_message_time) : '';
    var preview  = previewText(c);
    var online   = c.status === 'online';
    var unread   = parseInt(c.unread_count) || 0;
    var bold     = unread ? 'font-weight:700;color:var(--text-primary)' : '';

    return '<div class="conv-item' + active + '" data-id="' + c.user_id + '" data-type="direct" '
          + 'onclick="openChat(' + c.user_id + ',\'' + sa(c.username) + '\',\'' + sa(c.profile_photo) + '\')">'
          + '<div style="position:relative;flex-shrink:0">'
          + '<img src="/public/assets/img/' + sa(c.profile_photo) + '" width="50" height="50" '
          + 'style="width:50px;height:50px;border-radius:50%;object-fit:cover;display:block" '
          + 'onerror="this.src=\'/public/assets/img/default.png\'">'
          + (online ? '<span class="online-dot"></span>' : '')
          + '</div>'
          + '<div class="conv-info">'
          + '<div class="conv-name truncate">' + esc(c.username) + '</div>'
          + '<div class="conv-preview truncate" style="' + bold + '">' + preview + '</div>'
          + '</div>'
          + '<div class="conv-meta">'
          + '<div class="conv-time">' + time + '</div>'
          + (unread ? '<div class="unread-count">' + unread + '</div>' : '')
          + '</div>'
          + '</div>';
  }

  function renderGroupConvItem(c) {
    var active  = (currentChatType === 'group' && c.conversation_id == groupId) ? ' active' : '';
    var time    = c.last_message_time ? fmt(c.last_message_time) : '';
    var preview = previewText(c);
    if (c.message_type !== 'system' && c.last_sender_name) {
      var isMe = String(c.last_sender_id) === String(window.CURRENT_USER_ID);
      preview = (isMe ? 'أنت' : esc(c.last_sender_name)) + ': ' + preview;
    }
    var unread = parseInt(c.unread_count) || 0;
    var bold   = unread ? 'font-weight:700;color:var(--text-primary)' : '';

    return '<div class="conv-item' + active + '" data-id="' + c.conversation_id + '" data-type="group" data-group-id="' + c.conversation_id + '" '
          + 'onclick="openGroupChat(' + c.conversation_id + ',\'' + sa(c.title) + '\',' + (c.member_count || 0) + ')">'
          + '<div style="position:relative;flex-shrink:0">'
          + '<div style="width:50px;height:50px;border-radius:50%;background:var(--brand);'
          + 'display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.2rem">'
          + '<i class="fas fa-users"></i></div>'
          + '</div>'
          + '<div class="conv-info">'
          + '<div class="conv-name truncate">' + esc(c.title) + '</div>'
          + '<div class="conv-preview truncate" style="' + bold + '">' + preview + '</div>'
          + '</div>'
          + '<div class="conv-meta">'
          + '<div class="conv-time">' + time + '</div>'
          + (unread ? '<div class="unread-count">' + unread + '</div>' : '')
          + '</div>'
          + '</div>';
  }

  function previewText(c) {
    if (c.message_type === 'image') return '🖼 صورة';
    if (c.message_type === 'file')  return '📎 ملف';
    if (c.message_type === 'voice') return '🎤 رسالة صوتية';
    if (c.message_type === 'system') return systemMessageLabel(c.last_message);
    if (c.message_type === 'call') {
      try {
        var ci = JSON.parse(c.last_message || '{}');
        return ci.call_type === 'video' ? '📹 مكالمة فيديو' : '📞 مكالمة صوتية';
      } catch (e) { return '📞 مكالمة'; }
    }
    return esc(c.last_message || '');
  }

  // بيرجع نص عربي مفهوم لرسائل النظام جوه الجروب (إنشاء/إضافة/مغادرة/تغيير اسم)
  function systemMessageLabel(raw) {
    var info = {};
    try { info = JSON.parse(raw || '{}'); } catch (e) { return 'تحديث في المجموعة'; }
    switch (info.event) {
      case 'group_created':
        return '🎉 تم إنشاء المجموعة';
      case 'members_added':
        return '➕ تمت إضافة ' + (info.names || []).map(esc).join('، ');
      case 'member_left':
        return '🚪 ' + esc(info.name || '') + ' غادر المجموعة';
      case 'member_removed':
        return '❌ تمت إزالة ' + esc(info.name || '');
      case 'title_changed':
        return '✏️ تم تغيير اسم المجموعة إلى «' + esc(info.title || '') + '»';
      default:
        return 'تحديث في المجموعة';
    }
  }

  /* ════════════════════════════════════
     OPEN CHAT
  ════════════════════════════════════ */
  window.openChat = function(id, name, photo) {
    receiverId    = id;
    receiverName  = name;
    receiverPhoto = photo || 'default.png';
    lastId        = 0;
    isJumpMode    = false;
    window.cancelReply && window.cancelReply();
    window.closeMsgSearch && window.closeMsgSearch();

    var empty     = document.getElementById('chatEmpty');
    var container = document.getElementById('chatContainer');
    if (!container) return;

    // أخفِ empty وأظهر container
    empty.style.display     = 'none';
    container.style.display = 'flex';
    container.style.flexDirection = 'column';
    container.style.flex    = '1';
    container.style.minHeight = '0';
    container.style.overflow = 'hidden';

    /* رأس المحادثة */
    document.getElementById('chatPeerName').textContent    = name;
    document.getElementById('chatPeerStatus').textContent  = '';
    document.getElementById('chatProfileLink').href        = '/app/Modules/Profile/Views/profile.php?id=' + id;

    var av = document.getElementById('chatAvatar');
    av.src    = '/public/assets/img/' + (photo || 'default.png');
    av.onerror = function(){ this.src = '/public/assets/img/default.png'; };

    /* على الموبايل أغلق السايدبار */
    if (window.innerWidth <= 768) {
      document.getElementById('chatSidebar').classList.remove('open');
      var _ov1 = document.getElementById('sidebarOverlay');
      if (_ov1) _ov1.classList.remove('show');
    }

    /* تحديث URL */
    try { history.replaceState(null, '', '/?chat=' + id); } catch(e){}

    /* تحميل الرسائل */
    var area = document.getElementById('messagesArea');
    area.innerHTML = '<div style="text-align:center;padding:32px;color:var(--text-muted)">'
      + '<i class="fas fa-spinner fa-spin" style="font-size:1.5rem"></i></div>';

    loadMsgs(true);
    doMarkRead();

    clearInterval(pollTimer);
    pollTimer = setInterval(function(){ loadMsgs(false); refreshVisibleReactions(); }, 3000);

    /* تمييز المحادثة في القائمة */
    [].forEach.call(document.querySelectorAll('.conv-item'), function(el){
      el.classList.toggle('active', el.dataset.id == id);
    });

    /* فوكس على حقل الكتابة */
    var inp = document.getElementById('messageInput');
    if (inp) setTimeout(function(){ inp.focus(); }, 100);
  };

  /* ════════════════════════════════════
     LOAD MESSAGES
  ════════════════════════════════════ */
  function loadMsgs(full) {
    if (currentChatType === 'group') {
      if (!groupId) return;
    } else if (!receiverId) {
      return;
    }
    if (!full && isJumpMode) return; // واقفين على نتيجة بحث - متقاطعوش العرض بتحديث لايف
    var since = full ? 0 : lastId;
    var url   = (currentChatType === 'group')
      ? API.messages + '?conversation_id=' + groupId + '&since=' + since
      : API.messages + '?receiver_id=' + receiverId + '&since=' + since;

    fetch(url)
      .then(function(r){ return r.json(); })
      .then(function(data){
        var msgs = data.messages || [];
        var area = document.getElementById('messagesArea');
        if (!area) return;

        showTypingBubble(currentChatType === 'direct' && !!data.peer_typing);

        var atBottom = area.scrollHeight - area.scrollTop - area.clientHeight < 120;

        if (full) {
          if (!msgs.length) {
            var emptyName = currentChatType === 'group' ? groupTitle : receiverName;
            area.innerHTML = '<div style="flex:1;display:flex;flex-direction:column;'
              + 'align-items:center;justify-content:center;padding:32px;'
              + 'color:var(--text-muted);text-align:center;font-size:.875rem">'
              + '<i class="fas fa-hand-wave" style="font-size:2rem;margin-bottom:10px"></i>'
              + '<p>ابدأ المحادثة مع ' + esc(emptyName) + ' الآن 👋</p></div>';
          } else {
            area.innerHTML = msgs.map(renderMsg).join('');
            lastId = parseInt(msgs[msgs.length - 1].id);
            attachLongPress(area);
          }
          area.scrollTop = area.scrollHeight;
        } else {
          if (!msgs.length) return;
          var newMsgs = msgs.filter(function(m){ return parseInt(m.id) > lastId; });
          if (!newMsgs.length) return;
          var frag = document.createElement('div');
          frag.innerHTML = newMsgs.map(renderMsg).join('');
          attachLongPress(frag);
          while (frag.firstChild) area.appendChild(frag.firstChild);
          lastId = parseInt(newMsgs[newMsgs.length - 1].id);
          if (atBottom) area.scrollTop = area.scrollHeight;
          doMarkRead();
        }
      })
      .catch(function(){});
  }

  /* ════════════════════════════════════
     القفز لرسالة معينة (من رد أو من نتيجة بحث)
  ════════════════════════════════════ */
  function scrollAndHighlightMsg(el) {
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    el.classList.add('msg-highlight');
    setTimeout(function(){ el.classList.remove('msg-highlight'); }, 1800);
  }

  function enterJumpMode() {
    isJumpMode = true;
    var btn = document.getElementById('jumpToLatestBtn');
    if (btn) btn.style.display = 'flex';
  }

  window.exitJumpMode = function() {
    isJumpMode = false;
    var btn = document.getElementById('jumpToLatestBtn');
    if (btn) btn.style.display = 'none';
    loadMsgs(true);
  };

  window.jumpToMessage = function(id) {
    id = parseInt(id, 10);
    if (!id) return;
    var area = document.getElementById('messagesArea');
    if (!area) return;

    var existing = area.querySelector('.msg[data-id="' + id + '"]');
    if (existing) { scrollAndHighlightMsg(existing); return; }

    var url = (currentChatType === 'group')
      ? API.messages + '?conversation_id=' + groupId + '&around=' + id
      : API.messages + '?receiver_id=' + receiverId + '&around=' + id;

    fetch(url)
      .then(function(r){ return r.json(); })
      .then(function(data){
        var msgs = data.messages || [];
        if (!msgs.length) { showToast('تعذر العثور على الرسالة (ربما اتحذفت)', 'error'); return; }
        enterJumpMode();
        area.innerHTML = msgs.map(renderMsg).join('');
        attachLongPress(area);
        var el = area.querySelector('.msg[data-id="' + id + '"]');
        if (el) scrollAndHighlightMsg(el);
        else area.scrollTop = area.scrollHeight / 2;
      })
      .catch(function(){ showToast('خطأ في الاتصال', 'error'); });
  };

  var REACTION_EMOJIS = ['❤️','😂','👍','😮','😢','🙏'];

  function renderReactionBadges(reactions) {
    if (!reactions || !reactions.length) return '';
    return '<div class="msg-reactions">' + reactions.map(function(r){
      return '<span class="msg-reaction-badge' + (r.reacted_by_me ? ' mine' : '') + '" '
           + 'onclick="window.sendReaction(' + r._mid + ', \'' + r.emoji + '\')">'
           + r.emoji + ' <b>' + r.count + '</b></span>';
    }).join('') + '</div>';
  }

  function showTypingBubble(show) {
    var area = document.getElementById('messagesArea');
    var statusEl = document.getElementById('chatPeerStatus');
    if (!area) return;
    var existing = document.getElementById('typingBubble');

    if (show) {
      if (!existing) {
        var atBottom = area.scrollHeight - area.scrollTop - area.clientHeight < 120;
        var el = document.createElement('div');
        el.id = 'typingBubble';
        el.className = 'msg in';
        el.innerHTML = '<div class="typing-bubble"><span></span><span></span><span></span></div>';
        area.appendChild(el);
        if (atBottom) area.scrollTop = area.scrollHeight;
      }
      if (statusEl) statusEl.textContent = 'بيكتب الآن...';
    } else {
      if (existing) existing.remove();
      if (statusEl && statusEl.textContent === 'بيكتب الآن...') statusEl.textContent = '';
    }
  }

  /* ════════════════════════════════════
     TYPING INDICATOR (إرسال)
  ════════════════════════════════════ */
  function pingTyping() {
    if (!receiverId) return;
    fetch(API.typing, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ receiver_id: receiverId, action: 'ping', csrf_token: window.CSRF_TOKEN })
    }).catch(function(){});
  }

  function stopTyping() {
    if (!receiverId) return;
    clearTimeout(typingStopTimeout);
    fetch(API.typing, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'stop', csrf_token: window.CSRF_TOKEN })
    }).catch(function(){});
  }

  function onTypingInput(value) {
    if (!receiverId) return;
    if (value && value.trim()) {
      // ping بحد أقصى مرة كل 2.5 ثانية (مش على كل ضغطة زرار)
      if (!typingPingTimer) {
        pingTyping();
        typingPingTimer = setTimeout(function(){ typingPingTimer = null; }, 2500);
      }
      // لو المستخدم سكت لمدة 4 ثواني من غير ما يكتب، نعتبره وقف
      clearTimeout(typingStopTimeout);
      typingStopTimeout = setTimeout(stopTyping, 4000);
    } else {
      stopTyping();
    }
  }

  function replySnippetText(type, text) {
    if (type === 'image')    return '📷 صورة';
    if (type === 'voice')    return '🎤 رسالة صوتية';
    if (type === 'file')     return '📎 ملف';
    if (type === 'location') return '📍 موقع';
    if (type === 'call')     return '📞 مكالمة';
    text = (text || '').trim();
    return text.length > 60 ? text.slice(0, 60) + '…' : (text || 'رسالة');
  }

  function renderMsg(m) {
    if (m.message_type === 'system') {
      return '<div class="msg-system" data-id="' + m.id + '" '
           + 'style="align-self:center;text-align:center;color:var(--text-muted);'
           + 'font-size:.78rem;background:var(--bg-surface);border-radius:var(--radius-full);'
           + 'padding:4px 14px;margin:6px 0">' + systemMessageLabel(m.message_text) + '</div>';
    }

    var isOut  = String(m.sender_id) === String(window.CURRENT_USER_ID);
    var dir    = isOut ? 'out' : 'in';
    var time   = fmt(m.created_at);
    var read   = m.is_read == 1 || m.is_read === true || m.is_read === '1';
    var isText = m.message_type === 'text' || !m.message_type;
    var content = '';

    // في الجروب، اعرض اسم المرسل فوق الرسايل الواردة (فيه أكتر من مرسل)
    var senderLabel = '';
    if (currentChatType === 'group' && !isOut && m.sender_name) {
      senderLabel = '<div class="msg-sender-label" style="font-size:.72rem;font-weight:600;'
                  + 'color:var(--brand);margin-bottom:2px">' + esc(m.sender_name) + '</div>';
    }

    if (m.message_type === 'image' && m.file_path) {
      content = '<img src="/' + m.file_path + '" loading="lazy" '
              + 'style="max-width:220px;max-height:280px;border-radius:10px;display:block;cursor:pointer;-webkit-touch-callout:none" '
              + 'onclick="showLightbox(this.src)" '
              + 'onerror="window.handleImgError(this)">'
              + '<div class="img-fallback" style="display:none">'
              + '<i class="fas fa-image"></i> تعذر تحميل الصورة'
              + '<a href="/' + m.file_path + '" target="_blank" rel="noopener">فتح الرابط مباشرة</a>'
              + '</div>';
    } else if (m.message_type === 'voice' && m.file_path) {
      var vid = 'voice_' + m.id;
      content = '<div class="voice-msg" data-vid="' + vid + '">'
              + '<button type="button" class="voice-play-btn" onclick="window.toggleVoicePlay(\'' + vid + '\', this)">'
              + '<i class="fas fa-play"></i></button>'
              + '<div class="voice-progress"><div class="voice-progress-fill"></div></div>'
              + '<span class="voice-duration">0:00</span>'
              + '<audio id="' + vid + '" preload="metadata" src="/' + m.file_path + '" '
              + 'onloadedmetadata="window.voiceMetaLoaded(\'' + vid + '\')" '
              + 'onerror="window.handleVoiceError(\'' + vid + '\')"></audio>'
              + '</div>';
    } else if (m.message_type === 'call') {
      var callInfo = {};
      try { callInfo = JSON.parse(m.message_text || '{}'); } catch (e) {}
      var isVideo   = callInfo.call_type === 'video';
      var callWord  = isVideo ? 'مكالمة فيديو' : 'مكالمة صوتية';
      var isMissed = callInfo.status === 'call_rejected' || (callInfo.status === 'call_cancelled' && !isOut);
      var callLabel;
      if (callInfo.status === 'call_ended') {
        callLabel = callWord + ' · ' + fmtDuration(callInfo.duration || 0);
      } else if (isMissed) {
        callLabel = callWord + ' فائتة';
      } else {
        callLabel = callWord + ' ملغاة';
      }
      content = '<div class="call-log-msg' + (isMissed ? ' missed' : '') + '">'
              + '<i class="fas ' + (isMissed ? 'fa-phone-slash' : (isVideo ? 'fa-video' : 'fa-phone-alt')) + '"></i>'
              + '<span>' + callLabel + '</span></div>';
    } else if (m.message_type === 'location') {
      var loc = {};
      try { loc = JSON.parse(m.message_text || '{}'); } catch (e) {}
      if (loc && loc.lat != null && loc.lng != null) {
        var mapsUrl = 'https://www.google.com/maps?q=' + loc.lat + ',' + loc.lng;
        var embedUrl = 'https://maps.google.com/maps?q=' + loc.lat + ',' + loc.lng + '&z=15&output=embed';
        content = '<a href="' + mapsUrl + '" target="_blank" rel="noopener" class="loc-share-card">'
                + '<iframe src="' + embedUrl + '" loading="lazy" class="loc-share-map" '
                + 'style="width:220px;height:130px;border:0;border-radius:8px 8px 0 0;display:block;pointer-events:none"></iframe>'
                + '<div class="loc-share-label"><i class="fas fa-map-marker-alt"></i> موقع تمت مشاركته'
                + '<span class="loc-share-open">فتح في الخرائط</span></div>'
                + '</a>';
      } else {
        content = '<span><i class="fas fa-map-marker-alt"></i> تعذر عرض الموقع</span>';
      }
    } else if (m.message_type === 'file' && m.file_path) {
      var fname = (m.file_path.split('/').pop() || 'ملف').replace(/\w+_\d+\./, '.');
      content = '<a href="/' + m.file_path + '" download '
              + 'style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;'
              + 'background:rgba(255,255,255,.15);border-radius:6px;font-size:.85rem;color:inherit;'
              + 'text-decoration:none;border:1px solid rgba(255,255,255,.25)">'
              + '<i class="fas fa-file-alt"></i> ' + esc(fname) + '</a>';
    } else {
      var txt = esc(m.message_text || '');
      content = txt.replace(/(https?:\/\/[^\s<]+)/g,
        '<a href="$1" target="_blank" rel="noopener" style="color:inherit;text-decoration:underline">$1</a>');
    }

    var tick = isOut
      ? '<i class="fas fa-check' + (read ? '-double' : '') + '" '
        + 'style="font-size:.6rem;color:' + (read ? 'rgba(255,255,255,.8)' : 'rgba(255,255,255,.5)') + '"></i>'
      : '';

    var editedLabel = m.edited_at ? '<span class="msg-edited-label">(تم التعديل)</span> ' : '';

    var reactionsWithId = (m.reactions || []).map(function(r){ return Object.assign({_mid: m.id}, r); });

    var replyBlock = '';
    if (m.reply_to_id) {
      var replySnippet = replySnippetText(m.reply_type, m.reply_text);
      replyBlock = '<div class="msg-reply-quote" data-jump="' + m.reply_to_id + '" onclick="window.jumpToMessage(' + m.reply_to_id + ')">'
                 + '<span class="msg-reply-quote-name">' + esc(m.reply_sender_name || 'رسالة') + '</span>'
                 + '<span class="msg-reply-quote-text">' + esc(replySnippet) + '</span>'
                 + '</div>';
    }

    return '<div class="msg ' + dir + '" data-id="' + m.id + '" data-out="' + (isOut ? '1' : '0') + '" '
         + 'data-type="' + m.message_type + '" style="position:relative">'
         + senderLabel
         + '<div class="msg-bubble" data-mid="' + m.id + '">' + replyBlock + content + '</div>'
         + '<div class="msg-reactions-wrap" data-mid="' + m.id + '">' + renderReactionBadges(reactionsWithId) + '</div>'
         + '<div class="msg-time">' + editedLabel + time + ' ' + tick + '</div>'
         + '</div>';
  }

  window.handleImgError = function(img) {
    img.style.display = 'none';
    var fb = img.nextElementSibling;
    if (fb && fb.classList.contains('img-fallback')) fb.style.display = 'flex';
  };

  function fmtDuration(sec) {
    if (!isFinite(sec) || sec < 0) sec = 0;
    var m = Math.floor(sec / 60);
    var s = Math.floor(sec % 60);
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  window.voiceMetaLoaded = function(vid) {
    var audio = document.getElementById(vid);
    var wrap  = audio ? audio.closest('.voice-msg') : null;
    if (!wrap) return;
    var durEl = wrap.querySelector('.voice-duration');
    if (durEl && audio.duration && isFinite(audio.duration)) {
      durEl.textContent = fmtDuration(audio.duration);
    }
  };

  window.handleVoiceError = function(vid) {
    var audio = document.getElementById(vid);
    var wrap  = audio ? audio.closest('.voice-msg') : null;
    if (!wrap) return;
    wrap.innerHTML = '<span style="font-size:.8rem;opacity:.8"><i class="fas fa-exclamation-circle"></i> تعذر تحميل الرسالة الصوتية</span>';
  };

  window.toggleVoicePlay = function(vid, btn) {
    var audio = document.getElementById(vid);
    if (!audio) return;

    // وقف أي رسالة صوتية تانية شغالة عشان ميشتغلش أكتر من واحدة مع بعض
    [].forEach.call(document.querySelectorAll('audio'), function(a){
      if (a !== audio && !a.paused) { a.pause(); a.currentTime = 0; }
    });
    [].forEach.call(document.querySelectorAll('.voice-play-btn'), function(b){
      if (b !== btn) b.innerHTML = '<i class="fas fa-play"></i>';
    });

    if (audio.paused) {
      audio.play().catch(function(){});
      btn.innerHTML = '<i class="fas fa-pause"></i>';
    } else {
      audio.pause();
      btn.innerHTML = '<i class="fas fa-play"></i>';
    }
  };

  document.addEventListener('timeupdate', function(e){
    if (e.target.tagName !== 'AUDIO') return;
    var wrap = e.target.closest('.voice-msg');
    if (!wrap) return;
    var fill  = wrap.querySelector('.voice-progress-fill');
    var durEl = wrap.querySelector('.voice-duration');
    if (fill && e.target.duration) {
      fill.style.width = ((e.target.currentTime / e.target.duration) * 100) + '%';
    }
    if (durEl) durEl.textContent = fmtDuration(e.target.currentTime);
  }, true);

  document.addEventListener('ended', function(e){
    if (e.target.tagName !== 'AUDIO') return;
    var wrap = e.target.closest('.voice-msg');
    if (!wrap) return;
    var btn   = wrap.querySelector('.voice-play-btn');
    var fill  = wrap.querySelector('.voice-progress-fill');
    var durEl = wrap.querySelector('.voice-duration');
    if (btn) btn.innerHTML = '<i class="fas fa-play"></i>';
    if (fill) fill.style.width = '0%';
    if (durEl && e.target.duration) durEl.textContent = fmtDuration(e.target.duration);
  }, true);

  /* ════════════════════════════════════
     ضغط مستمر (Long Press) لفتح قائمة
     تفاعل/تعديل/حذف - بيشتغل باللمس والماوس
  ════════════════════════════════════ */
  var LONG_PRESS_MS = 450;
  var lpTimer = null, lpStartX = 0, lpStartY = 0, lpTarget = null;

  function attachLongPress(root) {
    var msgs = root.querySelectorAll ? root.querySelectorAll('.msg[data-id]') : [];
    [].forEach.call(msgs, function(el){
      if (el.dataset.lpBound) return;
      el.dataset.lpBound = '1';
      el.addEventListener('pointerdown', onLpStart);
      el.addEventListener('pointerup', onLpCancel);
      el.addEventListener('pointercancel', onLpCancel);
      el.addEventListener('pointerleave', onLpCancel);
      el.addEventListener('pointermove', onLpMove);
      el.addEventListener('contextmenu', function(e){ e.preventDefault(); });
    });
  }

  function onLpStart(e) {
    if (e.target.closest('a') || e.target.closest('.msg-reaction-badge')) return;
    lpTarget = e.currentTarget;
    lpStartX = e.clientX; lpStartY = e.clientY;
    clearTimeout(lpTimer);
    lpTimer = setTimeout(function(){
      if (navigator.vibrate) navigator.vibrate(15);
      openMessageActions(lpTarget);
    }, LONG_PRESS_MS);
  }
  function onLpMove(e) {
    if (!lpTarget) return;
    if (Math.abs(e.clientX - lpStartX) > 10 || Math.abs(e.clientY - lpStartY) > 10) {
      clearTimeout(lpTimer);
    }
  }
  function onLpCancel() {
    clearTimeout(lpTimer);
    lpTarget = null;
  }

  function closeMessageActions() {
    var existing = document.getElementById('msgActionSheet');
    if (existing) existing.remove();
    var overlay = document.getElementById('msgActionOverlay');
    if (overlay) overlay.remove();
  }

  function openMessageActions(msgEl) {
    closeMessageActions();
    var mid    = msgEl.dataset.id;
    var isOut  = msgEl.dataset.out === '1';
    var isText = msgEl.dataset.type === 'text' || !msgEl.dataset.type;

    var overlay = document.createElement('div');
    overlay.id = 'msgActionOverlay';
    overlay.className = 'msg-action-overlay';
    overlay.onclick = closeMessageActions;

    var sheet = document.createElement('div');
    sheet.id = 'msgActionSheet';
    sheet.className = 'msg-action-sheet';
    sheet.onclick = function(e){ e.stopPropagation(); };

    var html = '<div class="msg-action-emojis">' + REACTION_EMOJIS.map(function(e){
      return '<span class="emoji-item" onclick="window.sendReaction(' + mid + ', \'' + e + '\'); window.closeMessageActionsPublic();">' + e + '</span>';
    }).join('') + '</div>';

    html += '<button type="button" class="msg-action-btn" onclick="window.startReply(' + mid + '); window.closeMessageActionsPublic();">'
          + '<i class="fas fa-reply"></i> رد</button>';

    if (isOut && isText) {
      html += '<button type="button" class="msg-action-btn" onclick="window.startEditMessage(' + mid + ')">'
            + '<i class="fas fa-pen"></i> تعديل الرسالة</button>';
    }
    if (isOut) {
      html += '<button type="button" class="msg-action-btn danger" onclick="window.deleteChatMessage(' + mid + '); window.closeMessageActionsPublic();">'
            + '<i class="fas fa-trash"></i> حذف الرسالة</button>';
    }

    sheet.innerHTML = html;
    document.body.appendChild(overlay);
    document.body.appendChild(sheet);
  }
  window.closeMessageActionsPublic = closeMessageActions;

  window.startEditMessage = function(messageId) {
    closeMessageActions();
    var bubble = document.querySelector('.msg-bubble[data-mid="' + messageId + '"]');
    if (!bubble) return;
    var originalHTML = bubble.innerHTML;
    var originalText = bubble.textContent;

    bubble.innerHTML =
      '<div class="msg-edit-box">'
      + '<textarea class="msg-edit-input">' + esc(originalText) + '</textarea>'
      + '<div class="msg-edit-actions">'
      +   '<button type="button" class="msg-edit-cancel">إلغاء</button>'
      +   '<button type="button" class="msg-edit-save">حفظ</button>'
      + '</div></div>';

    var textarea = bubble.querySelector('.msg-edit-input');
    textarea.focus();
    textarea.setSelectionRange(textarea.value.length, textarea.value.length);

    bubble.querySelector('.msg-edit-cancel').onclick = function(){
      bubble.innerHTML = originalHTML;
    };
    bubble.querySelector('.msg-edit-save').onclick = function(){
      var newText = textarea.value.trim();
      if (!newText || newText === originalText) { bubble.innerHTML = originalHTML; return; }

      fetch('/app/Modules/Chat/Api/edit_message.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message_id: messageId, message_text: newText, csrf_token: window.CSRF_TOKEN })
      })
        .then(function(r){ return r.json(); })
        .then(function(data){
          if (data.success) {
            var msgEl = bubble.closest('.msg');
            var timeEl = msgEl ? msgEl.querySelector('.msg-time') : null;
            bubble.textContent = data.message_text;
            if (timeEl && !timeEl.querySelector('.msg-edited-label')) {
              timeEl.insertAdjacentHTML('afterbegin', '<span class="msg-edited-label">(تم التعديل)</span> ');
            }
          } else {
            showToast(data.message || 'تعذر تعديل الرسالة', 'error');
            bubble.innerHTML = originalHTML;
          }
        })
        .catch(function(){
          showToast('خطأ في الاتصال', 'error');
          bubble.innerHTML = originalHTML;
        });
    };
  };

  window.startReply = function(messageId) {
    var msgEl = document.querySelector('.msg[data-id="' + messageId + '"]');
    if (!msgEl) return;
    var isOut = msgEl.dataset.out === '1';
    var type  = msgEl.dataset.type;

    var senderName;
    if (isOut) {
      senderName = 'أنت';
    } else if (currentChatType === 'group') {
      var lbl = msgEl.querySelector('.msg-sender-label');
      senderName = lbl ? lbl.textContent : groupTitle;
    } else {
      senderName = receiverName;
    }

    var snippetText;
    if (type === 'text' || !type) {
      var bubble = msgEl.querySelector('.msg-bubble');
      var clone  = bubble ? bubble.cloneNode(true) : null;
      var q      = clone ? clone.querySelector('.msg-reply-quote') : null;
      if (q) q.remove();
      snippetText = clone ? clone.textContent.trim() : '';
    } else {
      snippetText = replySnippetText(type, '');
    }
    if (snippetText.length > 80) snippetText = snippetText.slice(0, 80) + '…';

    replyToId   = parseInt(messageId, 10);
    replyToInfo = { sender: senderName, text: snippetText || 'رسالة' };
    renderReplyBar();

    var inp = document.getElementById('messageInput');
    if (inp) inp.focus();
  };

  window.cancelReply = function() {
    replyToId   = null;
    replyToInfo = null;
    renderReplyBar();
  };

  function renderReplyBar() {
    var bar = document.getElementById('replyPreviewBar');
    if (!bar) return;
    if (!replyToId || !replyToInfo) {
      bar.style.display = 'none';
      bar.innerHTML = '';
      return;
    }
    bar.style.display = 'flex';
    bar.innerHTML =
        '<div class="reply-bar-line"></div>'
      + '<div class="reply-bar-body">'
      +   '<div class="reply-bar-name">' + esc(replyToInfo.sender) + '</div>'
      +   '<div class="reply-bar-text">' + esc(replyToInfo.text) + '</div>'
      + '</div>'
      + '<button type="button" class="reply-bar-close" onclick="window.cancelReply()" title="إلغاء الرد">'
      +   '<i class="fas fa-times"></i></button>';
  }

  window.sendReaction = function(messageId, emoji) {
    fetch('/app/Modules/Chat/Api/react_message.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message_id: messageId, emoji: emoji, csrf_token: window.CSRF_TOKEN })
    })
      .then(function(r){ return r.json(); })
      .then(function(){ refreshVisibleReactions(); })
      .catch(function(){});
  };

  function refreshVisibleReactions() {
    var area = document.getElementById('messagesArea');
    if (!area) return;
    var ids = [].map.call(area.querySelectorAll('.msg[data-id]'), function(el){ return el.dataset.id; });
    if (!ids.length) return;
    // آخر 60 رسالة ظاهرة بس (كافية، وبتحافظ على الطلب خفيف)
    ids = ids.slice(-60);

    fetch('/app/Modules/Chat/Api/get_reactions.php?ids=' + ids.join(','))
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (!data.success) return;
        var reactions = data.reactions || {};
        ids.forEach(function(id){
          var wrap = area.querySelector('.msg-reactions-wrap[data-mid="' + id + '"]');
          if (!wrap) return;
          var list = (reactions[id] || []).map(function(r){ return Object.assign({_mid: id}, r); });
          wrap.innerHTML = renderReactionBadges(list);
        });
      })
      .catch(function(){});
  }

  window.deleteChatMessage = function (messageId) {
    if (!confirm('هل تريد حذف هذه الرسالة؟')) return;

    fetch('/app/Modules/Chat/Api/delete_message.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message_id: messageId, csrf_token: window.CSRF_TOKEN })
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          var msgEl = document.querySelector('.msg[data-id="' + messageId + '"]');
          if (msgEl) msgEl.remove();
          showToast('تم حذف الرسالة', 'success');
        } else {
          showToast(data.message || 'تعذر حذف الرسالة', 'error');
        }
      })
      .catch(function () { showToast('خطأ في الاتصال', 'error'); });
  };

  function doMarkRead() {
    if (currentChatType === 'group') {
      if (!groupId) return;
      fetch(API.markRead, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ conversation_id: groupId, csrf_token: window.CSRF_TOKEN })
      }).catch(function(){});
      return;
    }
    if (!receiverId) return;
    fetch(API.markRead, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ sender_id: receiverId, csrf_token: window.CSRF_TOKEN })
    }).catch(function(){});
  }

  /* ════════════════════════════════════
     SEND MESSAGE
  ════════════════════════════════════ */
  function doSend(text, filePath, type) {
    var isGroup = currentChatType === 'group';
    if (isGroup ? !groupId : !receiverId) { showToast('اختر محادثة أولاً', 'error'); return; }
    if (isSending) return;
    text = (text || '').trim();
    if (!text && !filePath) return;

    isSending = true;
    setBtnState(true);

    var payload = {
      message_text : text,
      file_path    : filePath || null,
      message_type : type || 'text',
      reply_to_id  : replyToId || null,
      csrf_token   : window.CSRF_TOKEN
    };
    if (isGroup) payload.conversation_id = groupId;
    else payload.receiver_id = receiverId;

    var _sentReply = replyToId;
    fetch(API.send, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
      if (data.success) {
        if (replyToId === _sentReply) window.cancelReply();
        loadMsgs(false);
        loadConvs();
      } else {
        showToast('فشل الإرسال: ' + (data.message || 'خطأ'), 'error');
      }
    })
    .catch(function(){ showToast('خطأ في الاتصال', 'error'); })
    .finally(function(){
      isSending = false;
      setBtnState(false);
    });
  }

  function setBtnState(busy) {
    var btn = document.getElementById('sendBtn');
    if (!btn) return;
    btn.disabled  = busy;
    btn.innerHTML = busy
      ? '<i class="fas fa-spinner fa-spin"></i>'
      : '<i class="fas fa-paper-plane"></i>';
  }

  /* ════════════════════════════════════
     البحث داخل رسائل المحادثة الحالية
  ════════════════════════════════════ */
  window.toggleMsgSearch = function() {
    var panel = document.getElementById('msgSearchPanel');
    if (!panel) return;
    if (panel.classList.contains('open')) {
      window.closeMsgSearch();
      return;
    }
    if (currentChatType === 'group' ? !groupId : !receiverId) {
      showToast('اختر محادثة أولاً', 'error');
      return;
    }
    panel.classList.add('open');
    var inp = document.getElementById('msgSearchInput');
    var res = document.getElementById('msgSearchResults');
    if (inp) { inp.value = ''; setTimeout(function () { inp.focus(); }, 50); }
    if (res) res.innerHTML = '<div class="msg-search-hint">اكتبي كلمة للبحث عنها في المحادثة</div>';
  };

  window.closeMsgSearch = function() {
    var panel = document.getElementById('msgSearchPanel');
    if (panel) panel.classList.remove('open');
  };

  function escapeRegex(s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  function highlightMatch(escapedText, rawQuery) {
    var escQ = escapeRegex(esc(rawQuery));
    if (!escQ) return escapedText;
    try {
      return escapedText.replace(new RegExp('(' + escQ + ')', 'gi'), '<mark>$1</mark>');
    } catch (e) {
      return escapedText;
    }
  }

  function runMsgSearch(q) {
    var results = document.getElementById('msgSearchResults');
    if (!results) return;
    q = (q || '').trim();
    if (q.length < 2) {
      results.innerHTML = '<div class="msg-search-hint">اكتبي حرفين على الأقل للبحث</div>';
      return;
    }
    results.innerHTML = '<div style="text-align:center;padding:16px"><i class="fas fa-spinner fa-spin"></i></div>';

    var url = (currentChatType === 'group')
      ? API.search + '?conversation_id=' + groupId + '&q=' + encodeURIComponent(q)
      : API.search + '?receiver_id=' + receiverId + '&q=' + encodeURIComponent(q);

    fetch(url)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var list = data.results || [];
        if (!list.length) {
          results.innerHTML = '<div class="msg-search-hint">مفيش نتائج لـ"' + esc(q) + '"</div>';
          return;
        }
        results.innerHTML = list.map(function (m) {
          var senderLabel = (currentChatType === 'group' && m.sender_name) ? esc(m.sender_name) + ' · ' : '';
          var snippet = highlightMatch(esc(m.message_text || ''), q);
          return '<div class="msg-search-result" onclick="window.jumpToMessage(' + m.id + '); window.closeMsgSearch();">'
               + '<div class="msg-search-result-meta">' + senderLabel + fmt(m.created_at) + '</div>'
               + '<div class="msg-search-result-text">' + snippet + '</div>'
               + '</div>';
        }).join('');
      })
      .catch(function () {
        results.innerHTML = '<div class="msg-search-hint">خطأ في الاتصال</div>';
      });
  }

  /* ════════════════════════════════════
     مشاركة الموقع
  ════════════════════════════════════ */
  window.shareLocation = function() {
    if (currentChatType === 'group' ? !groupId : !receiverId) {
      showToast('اختر محادثة أولاً', 'error');
      return;
    }
    if (!navigator.geolocation) {
      showToast('المتصفح ده مش بيدعم مشاركة الموقع', 'error');
      return;
    }
    var btn = document.getElementById('locationBtn');
    if (btn) btn.disabled = true;

    navigator.geolocation.getCurrentPosition(
      function (pos) {
        if (btn) btn.disabled = false;
        var loc = { lat: pos.coords.latitude, lng: pos.coords.longitude };
        doSend(JSON.stringify(loc), null, 'location');
      },
      function () {
        if (btn) btn.disabled = false;
        showToast('تعذر الحصول على موقعك، تأكد من تفعيل صلاحية الموقع للمتصفح', 'error');
      },
      { enableHighAccuracy: true, timeout: 10000 }
    );
  };

  /* ════════════════════════════════════
     FILE UPLOAD
  ════════════════════════════════════ */
  function doUpload(file) {
    if (currentChatType === 'group' ? !groupId : !receiverId) { showToast('اختر محادثة أولاً', 'error'); return; }
    var fd = new FormData();
    fd.append('file', file);
    fd.append('csrf_token', window.CSRF_TOKEN);
    setBtnState(true);
    fetch(API.upload, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data.success) {
          // نعتمد على تصنيف السيرفر الحقيقي (مبني على فحص محتوى الملف)
          // بدل ما نعتمد على file.type من المتصفح، عشان الصورة دايماً تتعرض
          // كصورة جوه الشات مش كلينك تحميل
          var t = data.is_image ? 'image' : (data.is_audio ? 'voice' : 'file');
          doSend('', data.file_path, t);
        } else {
          showToast('فشل رفع الملف: ' + (data.message || ''), 'error');
          setBtnState(false);
        }
      })
      .catch(function(){
        showToast('خطأ في رفع الملف', 'error');
        setBtnState(false);
      });
  }

  /* ════════════════════════════════════
     رسالة صوتية (تسجيل)
  ════════════════════════════════════ */
  var VOICE_MAX_SECONDS = 180; // حد أقصى 3 دقايق للتسجيل الواحد
  var mediaRecorder = null;
  var recordedChunks = [];
  var recordStream = null;
  var recordTimerInterval = null;
  var recordSeconds = 0;
  var recordCancelled = false;
  var isRecordingVoice = false;

  function pickVoiceMimeType() {
    var candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'];
    for (var i = 0; i < candidates.length; i++) {
      if (window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(candidates[i])) {
        return candidates[i];
      }
    }
    return ''; // سيب المتصفح يختار الافتراضي
  }

  function setRecordingUI(active) {
    var bar   = document.getElementById('voiceRecordBar');
    var wrap  = document.getElementById('textInputWrap');
    var mic   = document.getElementById('micBtn');
    var send  = document.getElementById('sendBtn');
    var attach = document.querySelector('label[for="fileInput"]');
    var emoji = document.querySelector('.emoji-container');
    if (bar)  bar.classList.toggle('active', active);
    if (wrap) wrap.style.display = active ? 'none' : '';
    if (mic)  mic.style.display  = active ? 'none' : '';
    if (send) send.style.display = active ? 'none' : '';
    if (attach) attach.style.display = active ? 'none' : '';
    if (emoji) emoji.style.display = active ? 'none' : '';
  }

  function startVoiceRecording() {
    if (!receiverId) { showToast('اختر محادثة أولاً', 'error'); return; }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      showToast('المتصفح ده مش بيدعم تسجيل الصوت', 'error');
      return;
    }
    if (!window.MediaRecorder) {
      showToast('المتصفح ده مش بيدعم تسجيل الصوت', 'error');
      return;
    }

    navigator.mediaDevices.getUserMedia({ audio: true })
      .then(function(stream){
        stopTyping();
        recordStream = stream;
        recordedChunks = [];
        recordCancelled = false;
        recordSeconds = 0;

        var mimeType = pickVoiceMimeType();
        try {
          mediaRecorder = mimeType ? new MediaRecorder(stream, { mimeType: mimeType }) : new MediaRecorder(stream);
        } catch (e) {
          showToast('تعذر بدء التسجيل', 'error');
          stream.getTracks().forEach(function(t){ t.stop(); });
          return;
        }

        mediaRecorder.ondataavailable = function(e){
          if (e.data && e.data.size > 0) recordedChunks.push(e.data);
        };
        mediaRecorder.onstop = handleVoiceRecordingStop;
        mediaRecorder.start();

        isRecordingVoice = true;
        setRecordingUI(true);

        var timerEl = document.getElementById('voiceTimer');
        if (timerEl) timerEl.textContent = '0:00';
        recordTimerInterval = setInterval(function(){
          recordSeconds++;
          if (timerEl) timerEl.textContent = fmtDuration(recordSeconds);
          if (recordSeconds >= VOICE_MAX_SECONDS) {
            stopVoiceRecording(true); // وصل للحد الأقصى - ابعت تلقائي
          }
        }, 1000);
      })
      .catch(function(){
        showToast('لازم تسمح باستخدام المايكروفون عشان تبعت رسالة صوتية', 'error');
      });
  }

  function stopVoiceRecording(shouldSend) {
    if (!isRecordingVoice || !mediaRecorder) return;
    recordCancelled = !shouldSend;

    clearInterval(recordTimerInterval);
    isRecordingVoice = false;
    setRecordingUI(false);

    if (mediaRecorder.state !== 'inactive') {
      mediaRecorder.stop();
    }
    if (recordStream) {
      recordStream.getTracks().forEach(function(t){ t.stop(); });
      recordStream = null;
    }
  }

  function handleVoiceRecordingStop() {
    if (recordCancelled || recordSeconds < 1) {
      recordedChunks = [];
      return;
    }
    if (!recordedChunks.length) return;

    var mimeType = mediaRecorder.mimeType || 'audio/webm';
    var blob = new Blob(recordedChunks, { type: mimeType });
    recordedChunks = [];

    var ext = mimeType.indexOf('mp4') > -1 ? 'm4a' : (mimeType.indexOf('ogg') > -1 ? 'ogg' : 'webm');
    var file = new File([blob], 'voice.' + ext, { type: mimeType });
    doUpload(file);
  }

  function initVoiceRecordingUI() {
    var micBtn    = document.getElementById('micBtn');
    var cancelBtn = document.getElementById('voiceCancelBtn');
    var sendBtn2  = document.getElementById('voiceSendBtn');

    if (micBtn)    micBtn.addEventListener('click', startVoiceRecording);
    if (cancelBtn) cancelBtn.addEventListener('click', function(){ stopVoiceRecording(false); });
    if (sendBtn2)  sendBtn2.addEventListener('click', function(){ stopVoiceRecording(true); });
  }


  /* ════════════════════════════════════
     SEARCH USERS (modal)
  ════════════════════════════════════ */
  window.openNewChatModal = function() {
    var m = document.getElementById('newChatModal');
    if (m) m.classList.add('active');
    var inp = document.getElementById('userSearch');
    if (inp) { inp.value = ''; inp.focus(); }
    var res = document.getElementById('searchResults');
    if (res) res.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:24px;font-size:.875rem">'
      + '<i class="fas fa-search" style="font-size:1.5rem;display:block;margin-bottom:8px"></i>'
      + 'اكتب اسم المستخدم للبحث</div>';
  };

  window.closeNewChatModal = function() {
    var m = document.getElementById('newChatModal');
    if (!m) return;
    m.classList.remove('active');
    m.style.display = 'none';
    // reset بعد ثانية عشان يقدر يتفتح تاني
    setTimeout(function(){ m.style.display = ''; }, 100);
  };

  function searchUsers(q) {
    var res = document.getElementById('searchResults');
    if (!res) return;

    if (!q || q.length < 2) {
      res.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:24px;font-size:.875rem">اكتب حرفين على الأقل</div>';
      return;
    }

    res.innerHTML = '<div style="text-align:center;padding:24px"><i class="fas fa-spinner fa-spin" style="color:var(--brand)"></i></div>';

    fetch(API.search + '?q=' + encodeURIComponent(q))
      .then(function(r){ return r.json(); })
      .then(function(data){
        var users = data.users || [];
        if (!users.length) {
          res.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:24px;font-size:.875rem">'
            + '<i class="fas fa-user-slash" style="font-size:1.5rem;display:block;margin-bottom:8px"></i>'
            + 'لا توجد نتائج لـ «' + esc(q) + '»</div>';
          return;
        }
        var html = '';
        users.forEach(function(u){
          var online = u.status === 'online';
          html += '<div style="display:flex;align-items:center;gap:12px;padding:12px 16px;'
                + 'border-bottom:1px solid var(--border);transition:background .15s;cursor:pointer" '
                + 'onmouseover="this.style.background=\'var(--bg-base)\'" '
                + 'onmouseout="this.style.background=\'\'">'
                + '<img src="/public/assets/img/' + sa(u.profile_photo) + '" width="46" height="46" '
                + 'style="width:46px;height:46px;border-radius:50%;object-fit:cover;flex-shrink:0" '
                + 'onerror="this.src=\'/public/assets/img/default.png\'">'
                + '<div style="flex:1;min-width:0">'
                + '<div style="font-weight:600;font-size:.9375rem">' + esc(u.username) + '</div>'
                + '<div style="font-size:.75rem;color:' + (online ? 'var(--success)' : 'var(--text-muted)') + '">'
                + (online ? '● متصل الآن' : 'غير متصل') + '</div>'
                + '</div>'
                + '<div style="display:flex;gap:6px;flex-shrink:0">'
                + '<a href="/app/Modules/Profile/Views/profile.php?id=' + u.id + '" '
                + 'style="width:34px;height:34px;border:1px solid var(--border);border-radius:50%;'
                + 'display:flex;align-items:center;justify-content:center;color:var(--text-secondary);'
                + 'font-size:.8rem;text-decoration:none" title="الملف الشخصي">'
                + '<i class="fas fa-user"></i></a>'
                + '<button onclick="startChat(' + u.id + ',\'' + sa(u.username) + '\',\'' + sa(u.profile_photo) + '\')" '
                + 'style="padding:0 16px;height:34px;border:none;border-radius:var(--radius-full);'
                + 'background:var(--brand);color:#fff;font-size:.8125rem;font-weight:600;cursor:pointer">'
                + '<i class="fas fa-comment"></i> راسل</button>'
                + '</div>'
                + '</div>';
        });
        res.innerHTML = html;
      })
      .catch(function(){
        res.innerHTML = '<div style="text-align:center;color:var(--danger);padding:24px">خطأ في الاتصال</div>';
      });
  }

  window.startChat = function(id, name, photo) {
    // أغلق المودال بشكل كامل
    var modal = document.getElementById('newChatModal');
    if (modal) {
      modal.classList.remove('active');
      modal.style.cssText = 'display:none!important';
      setTimeout(function(){ modal.style.cssText = ''; }, 300);
    }
    // افتح المحادثة بعد إغلاق المودال
    setTimeout(function(){ openChat(id, name, photo); }, 50);
    /* أضف للقائمة فوراً لو مش موجود */
    if (!document.querySelector('.conv-item[data-id="' + id + '"]')) {
      var list = document.getElementById('convList');
      if (list) {
        var div = document.createElement('div');
        div.className   = 'conv-item active';
        div.dataset.id  = id;
        div.onclick     = function(){ openChat(id, name, photo); };
        div.innerHTML   = '<div style="position:relative;flex-shrink:0">'
          + '<img src="/public/assets/img/' + sa(photo) + '" width="50" height="50" '
          + 'style="width:50px;height:50px;border-radius:50%;object-fit:cover;display:block" '
          + 'onerror="this.src=\'/public/assets/img/default.png\'"></div>'
          + '<div class="conv-info">'
          + '<div class="conv-name">' + esc(name) + '</div>'
          + '<div class="conv-preview" style="color:var(--text-muted)">ابدأ المحادثة</div>'
          + '</div>';
        list.insertBefore(div, list.firstChild);
        [].forEach.call(list.querySelectorAll('.conv-item'), function(el){
          el.classList.toggle('active', el.dataset.id == id);
        });
      }
    }
  };

  /* ════════════════════════════════════
     MOBILE
  ════════════════════════════════════ */
  window.toggleChatSidebar = function() {
    var sb = document.getElementById('chatSidebar');
    if (sb) sb.classList.toggle('open');
  };

  /* لازم ترجّع قائمة المحادثات (السايدبار) تظهر تاني على الموبايل
     كل ما مفيش محادثة مفتوحة - غير كده المستخدم بيوصل لشاشة فاضية
     ومفيش أي طريقة يرجع منها لقائمة اللي بعتلهم قبل كده */
  function showConvListOnMobile() {
    if (window.innerWidth <= 768) {
      var sb = document.getElementById('chatSidebar');
      if (sb) sb.classList.add('open');
    }
  }

  window.closeChatOnMobile = function() {
    var container = document.getElementById('chatContainer');
    var empty     = document.getElementById('chatEmpty');
    container.style.display = 'none';
    empty.style.display = 'flex';
    receiverId = null;
    groupId = null;
    currentChatType = 'direct';
    clearInterval(pollTimer);
    try { history.replaceState(null, '', '/'); } catch(e){}
    showConvListOnMobile();
  };

  /* ════════════════════════════════════
     EMOJI PICKER
  ════════════════════════════════════ */
  var EMOJI = [
    '😀','😂','😍','🥰','😎','🤩','😢','😡','😱','🤭','😊','🤣','😭','😘',
    '👍','👎','❤️','🔥','🎉','✅','🙏','💯','💪','🙌','👏','🎊','💫','⭐',
    '🥺','🤔','😴','🤗','✨','💥','📩','📱','🕐','✈️','🌟','😇','🥳','😏'
  ];

  function initEmoji() {
    var picker = document.getElementById('emojiPicker');
    var btn    = document.getElementById('emojiBtn');
    if (!picker || !btn) return;

    picker.innerHTML = EMOJI.map(function(e){
      return '<div class="emoji-item" data-e="' + e + '">' + e + '</div>';
    }).join('');

    var open = false;
    btn.addEventListener('click', function(e){
      e.stopPropagation();
      open = !open;
      picker.style.display = open ? 'grid' : 'none';
    });

    picker.addEventListener('click', function(e){
      var em = e.target.closest('.emoji-item');
      if (!em) return;
      var inp = document.getElementById('messageInput');
      if (!inp) return;
      var s = inp.selectionStart || inp.value.length;
      inp.value = inp.value.slice(0, s) + em.dataset.e + inp.value.slice(s);
      inp.selectionStart = inp.selectionEnd = s + em.dataset.e.length;
      inp.focus();
      open = false;
      picker.style.display = 'none';
    });

    document.addEventListener('click', function(e){
      if (!btn.contains(e.target) && !picker.contains(e.target)) {
        open = false;
        picker.style.display = 'none';
      }
    });
  }

  /* ════════════════════════════════════
     LIGHTBOX
  ════════════════════════════════════ */
  window.showLightbox = function(src) {
    var lb  = document.getElementById('imgLightbox');
    var img = document.getElementById('imgLightboxSrc');
    if (!lb || !img) return;
    img.src = src;
    lb.style.display = 'flex';
  };

  /* ════════════════════════════════════
     URL ?chat= param
  ════════════════════════════════════ */
  var _urlOpened = false;

  function openChatFromURL() {
    if (_urlOpened) return;

    var gid = new URLSearchParams(location.search).get('group');
    if (gid) {
      _urlOpened = true;
      var gItem = document.querySelector('.conv-item[data-type="group"][data-group-id="' + gid + '"]');
      if (gItem) { gItem.click(); return; }
      fetch(API.groupInfo + '?conversation_id=' + gid)
        .then(function(r){ return r.json(); })
        .then(function(data){
          if (data.success && data.conversation) {
            window.openGroupChat(data.conversation.id, data.conversation.title, (data.members || []).length);
          }
        })
        .catch(function(){});
      return;
    }

    var id = new URLSearchParams(location.search).get('chat');
    if (!id) { showConvListOnMobile(); return; }

    // لو في قائمة المحادثات، افتحه مباشرة
    var item = document.querySelector('.conv-item[data-type="direct"][data-id="' + id + '"]');
    if (item) {
      _urlOpened = true;
      item.click();
      return;
    }

    // مش في القائمة — جيب بيانات المستخدم مباشرة
    _urlOpened = true;
    fetch(API.userInfo + '?id=' + id)
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data.success && data.user) {
          startChat(data.user.id, data.user.username, data.user.profile_photo);
        } else {
          // fallback: جرب البحث
          fetch(API.search + '?q=' + id)
            .then(function(r){ return r.json(); })
            .then(function(d){
              var users = d.users || [];
              var u = users.find(function(x){ return String(x.id) === String(id); });
              if (u) startChat(u.id, u.username, u.profile_photo);
            })
            .catch(function(){});
        }
      })
      .catch(function(){});
  }

  function handleURLParam() {
    // deprecated - kept for compatibility
    openChatFromURL();
  }

  /* ════════════════════════════════════
     HELPERS
  ════════════════════════════════════ */
  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  }

  function sa(s) {
    return (s || '').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'&quot;');
  }

  function fmt(d) {
    return window.formatTime ? window.formatTime(d) : (d || '');
  }

  function fmtDuration(totalSeconds) {
    totalSeconds = Math.max(0, parseInt(totalSeconds, 10) || 0);
    var m = Math.floor(totalSeconds / 60);
    var s = totalSeconds % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  /* ════════════════════════════════════
     INIT
  ════════════════════════════════════ */
  document.addEventListener('DOMContentLoaded', function() {

    loadConvs();
    initEmoji();
    initVoiceRecordingUI();

    /* إرسال بالزر */
    var sendBtn = document.getElementById('sendBtn');
    if (sendBtn) {
      sendBtn.addEventListener('click', function(){
        var inp = document.getElementById('messageInput');
        var txt = inp ? inp.value.trim() : '';
        if (txt) { stopTyping(); doSend(txt); if(inp) inp.value = ''; }
      });
    }

    /* إرسال بـ Enter + مؤشر الكتابة */
    var msgInput = document.getElementById('messageInput');
    if (msgInput) {
      msgInput.addEventListener('keydown', function(e){
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          var txt = this.value.trim();
          if (txt) { stopTyping(); doSend(txt); this.value = ''; }
        }
      });
      msgInput.addEventListener('input', function(){
        onTypingInput(this.value);
      });
      msgInput.addEventListener('blur', function(){
        if (!this.value.trim()) stopTyping();
      });
    }

    /* رفع ملف */
    var fileInput = document.getElementById('fileInput');
    if (fileInput) {
      fileInput.addEventListener('change', function(){
        if (this.files[0]) doUpload(this.files[0]);
        this.value = '';
      });
    }

    /* بحث في قائمة المحادثات */
    var convSearch = document.getElementById('convSearch');
    if (convSearch) {
      var ft;
      convSearch.addEventListener('input', function(){
        clearTimeout(ft);
        var q = this.value.trim();
        ft = setTimeout(function(){ loadConvs(q); }, 250);
      });
    }

    /* بحث داخل رسائل المحادثة المفتوحة */
    var msgSearchInput = document.getElementById('msgSearchInput');
    if (msgSearchInput) {
      var mst;
      msgSearchInput.addEventListener('input', function () {
        clearTimeout(mst);
        var q = this.value;
        mst = setTimeout(function () { runMsgSearch(q); }, 300);
      });
    }

    /* بحث عن مستخدم جديد */
    var userSearch = document.getElementById('userSearch');
    if (userSearch) {
      var st;
      userSearch.addEventListener('input', function(){
        clearTimeout(st);
        var q = this.value.trim();
        st = setTimeout(function(){ searchUsers(q); }, 350);
      });
    }

    /* إغلاق المودال بالضغط خارجه */
    var modal = document.getElementById('newChatModal');
    if (modal) {
      modal.addEventListener('click', function(e){
        if (e.target === this) closeNewChatModal();
      });
    }

    /* URL param */
    // openChatFromURL is now called after loadConvs completes

    /* تحديث القائمة كل 15 ثانية */
    setInterval(function(){ loadConvs(); }, 15000);
  });


  /* ══ TOAST NOTIFICATIONS ══ */
  function showToast(msg, type) {
    var t = document.getElementById('chatToast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'chatToast';
      t.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);'
        + 'padding:10px 20px;border-radius:24px;font-size:.875rem;font-weight:500;'
        + 'z-index:9999;transition:opacity .3s;white-space:nowrap;display:none;'
        + 'box-shadow:0 4px 16px rgba(0,0,0,.2)';
      document.body.appendChild(t);
    }
    t.textContent = msg;
    t.style.background = type === 'error' ? 'var(--danger)' : 'var(--success)';
    t.style.color = '#fff';
    t.style.display = 'block';
    t.style.opacity = '1';
    clearTimeout(t._timer);
    t._timer = setTimeout(function(){
      t.style.opacity = '0';
      setTimeout(function(){ t.style.display = 'none'; }, 300);
    }, 3000);
  }

  window.showChatToast = showToast;

  /* ════════════════════════════════════
     BLOCK / UNBLOCK USER
  ════════════════════════════════════ */
  var blockedState = false; // هل المستخدم المفتوح حالياً محظور من طرفنا؟

  window.toggleChatMenu = function () {
    var menu = document.getElementById('chatMenu');
    if (!menu) return;
    menu.style.display = (menu.style.display === 'none' || !menu.style.display) ? 'block' : 'none';
  };

  // اقفل المنيو لو ضغط المستخدم برة
  document.addEventListener('click', function (e) {
    var menu = document.getElementById('chatMenu');
    var btn  = document.getElementById('chatMenuBtn');
    if (!menu || menu.style.display === 'none') return;
    if (e.target === btn || btn.contains(e.target)) return;
    if (!menu.contains(e.target)) menu.style.display = 'none';
  });

  function updateBlockBtnLabel() {
    var btn = document.getElementById('blockBtn');
    if (!btn) return;
    btn.innerHTML = blockedState
      ? '<i class="fas fa-user-check"></i> إلغاء حظر المستخدم'
      : '<i class="fas fa-ban"></i> حظر المستخدم';
  }

  window.toggleBlockCurrentUser = function () {
    if (!receiverId) return;
    var menu = document.getElementById('chatMenu');
    if (menu) menu.style.display = 'none';

    var action = blockedState ? 'unblock' : 'block';
    var confirmMsg = blockedState
      ? 'هل تريد إلغاء حظر هذا المستخدم؟'
      : 'هل تريد حظر هذا المستخدم؟ لن تتمكنا من مراسلة بعضكما.';
    if (!confirm(confirmMsg)) return;

    fetch('/app/Modules/Chat/Api/block_user.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ user_id: receiverId, action: action, csrf_token: window.CSRF_TOKEN })
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          blockedState = !blockedState;
          updateBlockBtnLabel();
          showToast(blockedState ? 'تم حظر المستخدم' : 'تم إلغاء الحظر', 'success');
        } else {
          showToast(data.message || 'حدث خطأ', 'error');
        }
      })
      .catch(function () { showToast('خطأ في الاتصال', 'error'); });
  };

  // إعادة ضبط حالة الحظر المعروضة كل ما تتفتح محادثة جديدة
  var _origOpenChat = window.openChat;
  window.openChat = function (id, name, photo) {
    blockedState = false;
    updateBlockBtnLabel();
    var result = _origOpenChat(id, name, photo);
    fetch('/app/Modules/Chat/Api/block_status.php?user_id=' + encodeURIComponent(id))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success && id === receiverId) {
          blockedState = !!data.blocked;
          updateBlockBtnLabel();
        }
      })
      .catch(function () {});
    return result;
  };

  window.addEventListener('beforeunload', function(){
    clearInterval(pollTimer);
    if (receiverId) {
      // بنحاول نبلغ السيرفر إننا وقفنا نكتب (best-effort، مش مضمون ينفذ دايماً)
      try {
        navigator.sendBeacon && navigator.sendBeacon(
          API.typing,
          new Blob([JSON.stringify({ action: 'stop', csrf_token: window.CSRF_TOKEN })], { type: 'application/json' })
        );
      } catch(e) {}
    }
    if (window.hangUpVoiceCall) window.hangUpVoiceCall();
  });

  /* ════════════════════════════════════
     VOICE CALL (WebRTC + polling signaling)
  ════════════════════════════════════ */
  API.callStart  = '/app/Modules/Chat/Api/call_start.php';
  API.callAnswer = '/app/Modules/Chat/Api/call_answer.php';
  API.callEnd    = '/app/Modules/Chat/Api/call_end.php';
  API.callPoll   = '/app/Modules/Chat/Api/call_poll.php';

  var pc              = null;
  var localStream      = null;
  var activeCallId     = null;
  var callRole         = null;  // 'caller' | 'callee'
  var callPeerId       = null;
  var pendingOffer     = null;
  var pendingCandidates = [];
  var earlyLocalCandidates = []; // مرشحات المتصل اللي اتجمعت قبل ما نعرف call_id من السيرفر
  var iceSinceId       = 0;
  var callTickTimer    = null;
  var callDurationTimer = null;
  var callSecondsElapsed = 0;
  var isMuted          = false;
  var callEnding        = false;
  var audioCtx          = null;
  var ringtoneInterval  = null;
  var ringbackInterval  = null;

  var currentCallType  = 'audio'; // 'audio' | 'video' — نوع المكالمة الحالية
  var cameraOff        = false;
  var currentFacingMode = 'user'; // 'user' (أمامية) | 'environment' (خلفية)
  var speakerOn         = false; // false = سماعة داخلية (earpiece)، true = سماعة خارجية (loudspeaker)
  var speakerDeviceId   = null;
  var autoAnswerOnNextIncomingCall = false;

  function getAudioCtx() {
    if (!audioCtx) {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (Ctx) { try { audioCtx = new Ctx(); } catch (e) {} }
    }
    return audioCtx;
  }

  // نغمة قصيرة (بيب مزدوج) بتتولّد بالكود، مافيش محتاجين ملف صوت خارجي
  function playTone(freq1, freq2, duration) {
    var ctx = getAudioCtx();
    if (!ctx) return;
    if (ctx.state === 'suspended') { try { ctx.resume(); } catch (e) {} }
    var now = ctx.currentTime;
    [freq1, freq2].forEach(function (freq) {
      if (!freq) return;
      var osc  = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.value = freq;
      gain.gain.setValueAtTime(0.0001, now);
      gain.gain.exponentialRampToValueAtTime(0.18, now + 0.05);
      gain.gain.exponentialRampToValueAtTime(0.0001, now + duration);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start(now);
      osc.stop(now + duration + 0.05);
    });
  }

  // رنة المكالمة الواردة (بتتكرر لحد ما نرد أو نرفض)
  function startRingtone() {
    stopRingtone();
    playTone(950, 1400, 0.4);
    ringtoneInterval = setInterval(function () { playTone(950, 1400, 0.4); }, 1600);
    notifyIncomingCall();
  }
  function stopRingtone() {
    clearInterval(ringtoneInterval);
    ringtoneInterval = null;
  }

  // نغمة انتظار الرد عند المتصل (ringback)
  function startRingback() {
    stopRingback();
    ringbackInterval = setInterval(function () { playTone(425, 425, 1.0); }, 3000);
    playTone(425, 425, 1.0);
  }
  function stopRingback() {
    clearInterval(ringbackInterval);
    ringbackInterval = null;
  }

  // إشعار المتصفح بمكالمة واردة (لو المستخدم مدّاله إذن الإشعارات)
  // (ده إشعار فوري من الصفحة المفتوحة؛ لو الموقع مقفول تمامًا فالإشعار
  //  بيوصل عن طريق Web Push من السيرفر، شوف push-notifications.js)
  function notifyIncomingCall() {
    try {
      if (window.Notification && Notification.permission === 'granted') {
        var name = document.getElementById('callPeerName').textContent || 'شخص ما';
        var label = (currentCallType === 'video') ? 'مكالمة فيديو واردة' : 'مكالمة صوتية واردة';
        var n = new Notification(label, {
          body: name + ' يتصل بك الآن',
          icon: '/public/assets/img/icon-192.png',
          tag: 'chat-ag-voice-call',
        });
        n.onclick = function () { window.focus(); n.close(); };
      }
    } catch (e) {}
  }

  // رسالة خطأ واضحة حسب سبب فشل الوصول للمايكروفون/الكاميرا
  function micErrorMessage(err) {
    var name = err && err.name;
    var isVideo = currentCallType === 'video';
    var device = isVideo ? 'المايكروفون أو الكاميرا' : 'المايكروفون';
    if (!window.isSecureContext) {
      return (isVideo ? 'مكالمات الفيديو' : 'المكالمات الصوتية') + ' تحتاج اتصال آمن (HTTPS) عشان تشتغل';
    }
    if (name === 'NotAllowedError' || name === 'PermissionDeniedError' || name === 'SecurityError') {
      return 'تم رفض إذن ' + device + '. فعّل الإذن لهذا الموقع من إعدادات المتصفح وحاول تاني';
    }
    if (name === 'NotFoundError' || name === 'DevicesNotFoundError' || name === 'OverconstrainedError') {
      return 'لم يتم العثور على ' + device + ' متصل بجهازك';
    }
    if (name === 'NotReadableError' || name === 'TrackStartError') {
      return device + ' مستخدم من تطبيق آخر حالياً';
    }
    return 'تعذر الوصول إلى ' + device;
  }

  // قيود getUserMedia حسب نوع المكالمة الحالية
  function mediaConstraints() {
    if (currentCallType === 'video') {
      return { audio: true, video: { facingMode: currentFacingMode, width: { ideal: 640 }, height: { ideal: 480 } } };
    }
    return { audio: true, video: false };
  }

  // بيوحّد نهايات الأسطر في الـ SDP (\r\n) قبل ما نديها للمتصفح.
  // لو حرف \r اتشال في أي حتة في رحلة النقل (JSON/قاعدة بيانات/شبكة)،
  // كروم بيبقى صارم وبيرفض بعض الأسطر (زي a=ssrc msid) برسالة
  // "Failed to parse SessionDescription". التوحيد ده بيصلّح الشكل
  // بغض النظر عن اللي حصل في الطريق.
  function normalizeSdp(sdp) {
    if (typeof sdp !== 'string') return sdp;
    var out = sdp.replace(/\r\n/g, '\n').replace(/\n/g, '\r\n');
    if (out.slice(-2) !== '\r\n') out += '\r\n';
    return out;
  }

  function iceServers() {
    return [
      { urls: 'stun:stun.l.google.com:19302' },
      { urls: 'stun:stun1.l.google.com:19302' },
      // خوادم TURN مجانية (Open Relay Project) — دي اللي بتحل مشكلة
      // "بسمعه هو ومش سامعني" أو "الصوت مش واصل خالص" لما الطرفين يكونوا
      // على شبكتين مختلفتين (نت بيت + بيانات موبايل مثلاً)، لأن الاتصال
      // المباشر بين الجهازين مش دايمًا ممكن، فلازم سيرفر وسيط (relay) يمرر
      // الصوت/الفيديو بينهم.
      { urls: 'turn:openrelay.metered.ca:80', username: 'openrelayproject', credential: 'openrelayproject' },
      { urls: 'turn:openrelay.metered.ca:443', username: 'openrelayproject', credential: 'openrelayproject' },
      { urls: 'turn:openrelay.metered.ca:443?transport=tcp', username: 'openrelayproject', credential: 'openrelayproject' },
    ];
  }

  function createPeerConnection() {
    var conn = new RTCPeerConnection({ iceServers: iceServers() });
    conn.onicecandidate = function (e) {
      if (!e.candidate) return;
      if (activeCallId) {
        pollActiveCall(JSON.stringify(e.candidate));
      } else {
        earlyLocalCandidates.push(e.candidate);
      }
    };
    conn.ontrack = function (e) {
      var stream = e.streams[0];
      var hasVideo = stream && stream.getVideoTracks().length > 0;
      if (hasVideo) {
        var videoEl = document.getElementById('callRemoteVideo');
        if (videoEl) {
          videoEl.srcObject = stream;
          videoEl.volume = 1;
          playRemoteMedia(videoEl);
        }
      } else {
        // مكالمة صوتية فقط: الصوت بيتشغل من عنصر audio لوحده
        var audioEl = document.getElementById('callRemoteAudio');
        if (audioEl) {
          audioEl.srcObject = stream;
          audioEl.volume = 1;
          playRemoteMedia(audioEl);
        }
      }
    };
    return conn;
  }

  // بعض المتصفحات (خصوصًا سفاري/آيفون) محتاجة أمر play() صريح
  // مش بس خاصية autoplay، وإلا الصوت بييجي بس مش بيتسمع
  function playRemoteMedia(el) {
    var p = el.play();
    if (p && p.catch) {
      p.catch(function () {
        // المتصفح رافض التشغيل التلقائي بدون تفاعل — اطلب من المستخدم يضغط
        showUnmutePrompt(el);
      });
    }
  }

  function showUnmutePrompt(el) {
    if (document.getElementById('callUnmuteBtn')) return;
    var overlay = document.getElementById('callOverlay');
    var btn = document.createElement('button');
    btn.id = 'callUnmuteBtn';
    btn.type = 'button';
    btn.style.cssText = 'position:absolute;top:16px;left:50%;transform:translateX(-50%);'
      + 'background:var(--warning,#f5a623);color:#111;border:none;border-radius:20px;'
      + 'padding:8px 16px;font-size:.8rem;font-weight:700;z-index:5;cursor:pointer';
    btn.innerHTML = '<i class="fas fa-volume-up"></i> اضغط لسماع الصوت';
    btn.addEventListener('click', function () {
      el.play().catch(function () {});
      btn.remove();
    });
    overlay.appendChild(btn);
  }

  function flushPendingCandidates() {
    if (!pc || !pc.remoteDescription) return;
    pendingCandidates.forEach(function (c) {
      try { pc.addIceCandidate(new RTCIceCandidate(c)); } catch (e) {}
    });
    pendingCandidates = [];
  }

  function setCallState(state) {
    var acceptBtn  = document.getElementById('callAcceptBtn');
    var endBtn     = document.getElementById('callEndBtn');
    var muteBtn    = document.getElementById('callMuteBtn');
    var speakerBtn = document.getElementById('callSpeakerBtn');
    var cameraBtn  = document.getElementById('callCameraBtn');
    var switchBtn  = document.getElementById('callSwitchCameraBtn');
    var statusEl   = document.getElementById('callStatusText');
    var voiceBtn   = document.getElementById('voiceCallBtn');
    var videoBtn   = document.getElementById('videoCallBtn');
    var isVideo    = currentCallType === 'video';

    acceptBtn.style.display = (state === 'incoming') ? 'flex' : 'none';
    endBtn.style.display    = 'flex';
    muteBtn.style.display   = (state === 'connected') ? 'flex' : 'none';
    if (speakerBtn && state !== 'connected') speakerBtn.style.display = 'none';
    if (cameraBtn) cameraBtn.style.display = (state === 'connected' && isVideo) ? 'flex' : 'none';
    if (switchBtn) switchBtn.style.display = (state === 'connected' && isVideo && isMobileDevice()) ? 'flex' : 'none';
    if (voiceBtn) voiceBtn.classList.toggle('in-call', !!activeCallId);
    if (videoBtn) videoBtn.classList.toggle('in-call', !!activeCallId);

    var callWord = isVideo ? 'مكالمة فيديو' : 'مكالمة صوتية';
    if (state === 'calling') { statusEl.textContent = 'جارِ الاتصال...'; startRingback(); }
    if (state === 'incoming') { statusEl.textContent = callWord + ' واردة...'; startRingtone(); }
    if (state === 'connected') {
      stopRingback();
      stopRingtone();
      statusEl.classList.add('connected');
      statusEl.textContent = '00:00';
      document.getElementById('callOverlay').classList.toggle('video-connected', isVideo);
      setupSpeakerToggle();
    }
  }

  function isMobileDevice() {
    return /Android|iPhone|iPad|iPod/i.test(navigator.userAgent || '');
  }

  // عنصر تشغيل الصوت البعيد الحالي (فيديو لو مكالمة فيديو، أو audio لو صوتية فقط)
  function currentRemoteMediaEl() {
    return (currentCallType === 'video')
      ? document.getElementById('callRemoteVideo')
      : document.getElementById('callRemoteAudio');
  }

  // بيفحص هل الجهاز/المتصفح بيدعم تبديل السماعة الخارجية/الداخلية، ولو
  // أيوه بيظهر الزرار. الدعم متاح على أندرويد كروم بشكل أساسي — سفاري
  // على آيفون مبيدعمش التحكم في مخرج الصوت من المتصفح خالص (قيد من آبل).
  function setupSpeakerToggle() {
    var btn = document.getElementById('callSpeakerBtn');
    if (!btn) return;
    var el = currentRemoteMediaEl();
    if (!el || typeof el.setSinkId !== 'function' || !navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
      btn.style.display = 'none';
      return;
    }
    navigator.mediaDevices.enumerateDevices().then(function (devices) {
      var outputs = devices.filter(function (d) { return d.kind === 'audiooutput'; });
      var speakerDev = outputs.find(function (d) { return /speaker/i.test(d.label) && !/earpiece/i.test(d.label); });
      if (!speakerDev || outputs.length < 2) {
        btn.style.display = 'none'; // الجهاز عنده مخرج صوت واحد بس متاح للتحكم فيه
        return;
      }
      speakerDeviceId = speakerDev.deviceId;
      speakerOn = false;
      btn.classList.remove('speaker-on');
      btn.innerHTML = '<i class="fas fa-volume-down"></i>';
      btn.style.display = 'flex';
    }).catch(function () { btn.style.display = 'none'; });
  }

  function openCallOverlay(name, photo, state) {
    document.getElementById('callPeerName').textContent = name || '—';
    var av = document.getElementById('callPeerAvatar');
    av.src = '/public/assets/img/' + (photo || 'default.png');
    av.onerror = function () { this.src = '/public/assets/img/default.png'; };
    document.getElementById('callStatusText').classList.remove('connected');
    var overlay = document.getElementById('callOverlay');
    overlay.classList.toggle('video-call', currentCallType === 'video');
    overlay.classList.remove('video-connected');
    overlay.style.display = 'flex';
    setCallState(state);
  }

  function closeCallOverlay() {
    var overlay = document.getElementById('callOverlay');
    overlay.style.display = 'none';
    overlay.classList.remove('video-call', 'video-connected');
    var remoteVideo = document.getElementById('callRemoteVideo');
    var localVideo  = document.getElementById('callLocalVideo');
    if (remoteVideo) remoteVideo.srcObject = null;
    if (localVideo)  { localVideo.srcObject = null; localVideo.classList.remove('active'); }
    var unmuteBtn = document.getElementById('callUnmuteBtn');
    if (unmuteBtn) unmuteBtn.remove();
  }

  function startCallTimer() {
    callSecondsElapsed = 0;
    clearInterval(callDurationTimer);
    callDurationTimer = setInterval(function () {
      callSecondsElapsed++;
      var statusEl = document.getElementById('callStatusText');
      if (statusEl) statusEl.textContent = fmtDuration(callSecondsElapsed);
    }, 1000);
  }

  function teardownCall(message) {
    stopRingtone();
    stopRingback();
    clearInterval(callDurationTimer);
    callDurationTimer = null;
    if (pc) { try { pc.close(); } catch (e) {} pc = null; }
    if (localStream) { localStream.getTracks().forEach(function (t) { t.stop(); }); localStream = null; }
    activeCallId = null;
    callRole = null;
    callPeerId = null;
    pendingOffer = null;
    pendingCandidates = [];
    earlyLocalCandidates = [];
    iceSinceId = 0;
    isMuted = false;
    callEnding = false;
    cameraOff = false;
    currentFacingMode = 'user';
    speakerOn = false;
    speakerDeviceId = null;
    closeCallOverlay();
    var voiceBtn = document.getElementById('voiceCallBtn');
    var videoBtn = document.getElementById('videoCallBtn');
    if (voiceBtn) voiceBtn.classList.remove('in-call');
    if (videoBtn) videoBtn.classList.remove('in-call');
    currentCallType = 'audio';
    if (message) showToast(message, 'success');
  }

  // بدء مكالمة صادرة (نوعها: 'audio' أو 'video')
  function startCall(type) {
    if (!receiverId) return;
    if (activeCallId) { showToast('لديك مكالمة جارية بالفعل', 'error'); return; }
    if (!navigator.mediaDevices || !window.RTCPeerConnection) {
      showToast('المتصفح لا يدعم المكالمات', 'error');
      return;
    }

    currentCallType = (type === 'video') ? 'video' : 'audio';
    callRole = 'caller';
    callPeerId = receiverId;
    openCallOverlay(receiverName, receiverPhoto, 'calling');

    navigator.mediaDevices.getUserMedia(mediaConstraints())
      .then(function (stream) {
        localStream = stream;
        if (currentCallType === 'video') attachLocalVideo(stream);
        // من هنا لغاية آخر السلسلة الأخطاء مالهاش علاقة بالمايكروفون/الكاميرا خالص،
        // فبنفصلها في catch لوحدها عشان الرسالة اللي تظهر تكون صح
        return setupOutgoingCall(stream);
      })
      .catch(function (err) {
        showToast(micErrorMessage(err), 'error');
        teardownCall();
      });
  }
  window.startVoiceCall = function () { startCall('audio'); };
  window.startVideoCall = function () { startCall('video'); };

  // يعرض الفيديو المحلي (كاميرتي) في نافذة صغيرة (PIP)
  function attachLocalVideo(stream) {
    var localVideo = document.getElementById('callLocalVideo');
    if (!localVideo) return;
    localVideo.srcObject = stream;
    localVideo.classList.add('active');
  }

  function setupOutgoingCall(stream) {
    pc = createPeerConnection();
    stream.getTracks().forEach(function (t) { pc.addTrack(t, stream); });
    return pc.createOffer()
      .then(function (offer) { return pc.setLocalDescription(offer).then(function () { return offer; }); })
      .then(function (offer) {
        console.log('[call][offer][local before send] length=', offer.sdp.length);
        return fetch(API.callStart, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ receiver_id: callPeerId, offer: normalizeSdp(offer.sdp), call_type: currentCallType, csrf_token: window.CSRF_TOKEN }),
        }).then(function (r) { return r.json(); });
      })
      .then(function (data) {
        if (!data.success) {
          showToast(data.message || 'تعذر بدء المكالمة', 'error');
          teardownCall();
          return;
        }
        activeCallId = data.call_id;
        earlyLocalCandidates.forEach(function (c) { pollActiveCall(JSON.stringify(c)); });
        earlyLocalCandidates = [];
      })
      .catch(function (err) {
        // خطأ في إعداد الاتصال أو الشبكة أو السيرفر - مش خطأ مايكروفون
        showToast((err && err.message) ? err.message : 'تعذر بدء المكالمة، جرّب تاني', 'error');
        teardownCall();
      });
  }

  // قبول مكالمة واردة
  function acceptIncomingCall() {
    if (!pendingOffer || !activeCallId) return;
    stopRingtone();
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      showToast(micErrorMessage(null), 'error');
      endCall();
      return;
    }
    navigator.mediaDevices.getUserMedia(mediaConstraints())
      .then(function (stream) {
        localStream = stream;
        if (currentCallType === 'video') attachLocalVideo(stream);
        // من هنا لغاية آخر السلسلة الأخطاء مالهاش علاقة بإذن المايكروفون/الكاميرا،
        // فلو حصل خطأ هنا هيظهر برسالته الحقيقية مش "تعذر الوصول للمايكروفون"
        return setupIncomingCall(stream);
      })
      .catch(function (err) {
        showToast(micErrorMessage(err), 'error');
        endCall();
      });
  }

  function setupIncomingCall(stream) {
    pc = createPeerConnection();
    stream.getTracks().forEach(function (t) { pc.addTrack(t, stream); });
    var normalizedOffer = normalizeSdp(pendingOffer);
    console.log('[call][offer][about to setRemoteDescription] length=', normalizedOffer.length);
    console.log('[call][offer][full text]', normalizedOffer);
    return pc.setRemoteDescription({ type: 'offer', sdp: normalizedOffer })
      .then(function () {
        flushPendingCandidates();
        return pc.createAnswer();
      })
      .then(function (answer) { return pc.setLocalDescription(answer).then(function () { return answer; }); })
      .then(function (answer) {
        console.log('[call][answer][local before send] length=', answer.sdp.length);
        return fetch(API.callAnswer, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ call_id: activeCallId, answer: normalizeSdp(answer.sdp), csrf_token: window.CSRF_TOKEN }),
        }).then(function (r) { return r.json(); });
      })
      .then(function (data) {
        if (!data.success) {
          showToast(data.message || 'تعذرت المكالمة', 'error');
          teardownCall();
          return;
        }
        setCallState('connected');
        startCallTimer();
      })
      .catch(function (err) {
        // خطأ في إعداد الاتصال أو الشبكة أو السيرفر - مش خطأ مايكروفون
        showToast((err && err.message) ? err.message : 'تعذرت المكالمة، جرّب تاني', 'error');
        endCall();
      });
  }

  // إنهاء/رفض/إلغاء المكالمة الحالية
  function endCall() {
    if (!activeCallId || callEnding) { teardownCall(); return; }
    callEnding = true;
    var id = activeCallId;
    fetch(API.callEnd, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ call_id: id, csrf_token: window.CSRF_TOKEN }),
    }).catch(function () {}).finally(function () { teardownCall(); });
  }
  window.hangUpVoiceCall = function () { if (activeCallId) endCall(); };

  // نداء واحد للـ polling: مكالمة نشطة أو تفقّد مكالمة واردة
  function pollActiveCall(candidate) {
    if (!activeCallId) return;
    var id = activeCallId;
    fetch(API.callPoll, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        call_id: id,
        ice_since: iceSinceId,
        candidate: candidate || undefined,
        csrf_token: window.CSRF_TOKEN,
      }),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (id !== activeCallId || !data.success || !data.call) return;

        (data.ice_candidates || []).forEach(function (c) {
          iceSinceId = Math.max(iceSinceId, c.id);
          try {
            var parsed = JSON.parse(c.candidate);
            if (pc && pc.remoteDescription) {
              pc.addIceCandidate(new RTCIceCandidate(parsed));
            } else {
              pendingCandidates.push(parsed);
            }
          } catch (e) {}
        });

        var status = data.call.status;

        if (callRole === 'caller' && status === 'accepted' && pc && !pc.currentRemoteDescription && data.call.answer_sdp) {
          var normalizedAnswer = normalizeSdp(data.call.answer_sdp);
          console.log('[call][answer][about to setRemoteDescription] length=', normalizedAnswer.length);
          console.log('[call][answer][full text]', normalizedAnswer);
          pc.setRemoteDescription({ type: 'answer', sdp: normalizedAnswer })
            .then(function () {
              flushPendingCandidates();
              setCallState('connected');
              startCallTimer();
            })
            .catch(function (err) {
              console.error('[call][answer][setRemoteDescription failed]', err);
              showToast((err && err.message) ? err.message : 'تعذّر إتمام المكالمة (خطأ في بيانات الاتصال)', 'error');
              teardownCall();
            });
          return;
        }

        if (status === 'rejected' && callRole === 'caller') { teardownCall('لم يتم الرد على المكالمة'); return; }
        if (status === 'missed'   && callRole === 'caller') { teardownCall('لم يتم الرد على المكالمة'); return; }
        if (status === 'cancelled' && callRole === 'callee') { teardownCall('تم إلغاء المكالمة'); return; }
        if (status === 'ended') { teardownCall('انتهت المكالمة'); return; }
      })
      .catch(function () {});
  }

  // تفقّد وجود مكالمة واردة (وقت ما مفيش مكالمة نشطة عندنا)
  function checkIncomingCall() {
    if (activeCallId) return;
    fetch(API.callPoll, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ call_id: 0, csrf_token: window.CSRF_TOKEN }),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (activeCallId || !data.success || !data.incoming) return;
        var inc = data.incoming;
        callRole = 'callee';
        callPeerId = inc.caller_id;
        pendingOffer = inc.offer;
        currentCallType = (inc.call_type === 'video') ? 'video' : 'audio';
        console.log('[call][offer][received via poll] length=', (inc.offer || '').length);
        activeCallId = inc.call_id;
        iceSinceId = 0;
        openCallOverlay(inc.caller_name, inc.caller_photo, 'incoming');

        // فتح الصفحة كان بضغطة "رد" من الإشعار نفسه؟ رد على المكالمة أوتوماتيك
        if (autoAnswerOnNextIncomingCall) {
          autoAnswerOnNextIncomingCall = false;
          acceptIncomingCall();
        }
      })
      .catch(function () {});
  }

  document.addEventListener('DOMContentLoaded', function () {
    var acceptBtn = document.getElementById('callAcceptBtn');
    var endBtn    = document.getElementById('callEndBtn');
    var muteBtn   = document.getElementById('callMuteBtn');

    if (acceptBtn) acceptBtn.addEventListener('click', acceptIncomingCall);
    if (endBtn) endBtn.addEventListener('click', endCall);
    if (muteBtn) muteBtn.addEventListener('click', function () {
      if (!localStream) return;
      isMuted = !isMuted;
      localStream.getAudioTracks().forEach(function (t) { t.enabled = !isMuted; });
      muteBtn.classList.toggle('muted', isMuted);
      muteBtn.innerHTML = '<i class="fas fa-microphone' + (isMuted ? '-slash' : '') + '"></i>';
    });

    var speakerBtn = document.getElementById('callSpeakerBtn');
    if (speakerBtn) speakerBtn.addEventListener('click', function () {
      var el = currentRemoteMediaEl();
      if (!el || typeof el.setSinkId !== 'function' || !speakerDeviceId) return;
      var goingToSpeaker = !speakerOn;
      var targetId = goingToSpeaker ? speakerDeviceId : ''; // '' = خرج الصوت الافتراضي (الداخلي)
      el.setSinkId(targetId)
        .then(function () {
          speakerOn = goingToSpeaker;
          speakerBtn.classList.toggle('speaker-on', speakerOn);
          speakerBtn.innerHTML = '<i class="fas fa-volume-' + (speakerOn ? 'up' : 'down') + '"></i>';
          showToast(speakerOn ? 'السماعة الخارجية 🔊' : 'السماعة الداخلية', 'success');
        })
        .catch(function () { showToast('تعذر تبديل السماعة', 'error'); });
    });

    var cameraBtn = document.getElementById('callCameraBtn');
    if (cameraBtn) cameraBtn.addEventListener('click', function () {
      if (!localStream) return;
      cameraOff = !cameraOff;
      localStream.getVideoTracks().forEach(function (t) { t.enabled = !cameraOff; });
      cameraBtn.classList.toggle('camera-off', cameraOff);
      cameraBtn.innerHTML = '<i class="fas fa-video' + (cameraOff ? '-slash' : '') + '"></i>';
      var localVideo = document.getElementById('callLocalVideo');
      if (localVideo) localVideo.style.visibility = cameraOff ? 'hidden' : 'visible';
    });

    var switchCameraBtn = document.getElementById('callSwitchCameraBtn');
    if (switchCameraBtn) switchCameraBtn.addEventListener('click', function () {
      if (!localStream || !pc) return;
      currentFacingMode = (currentFacingMode === 'user') ? 'environment' : 'user';
      navigator.mediaDevices.getUserMedia({ video: { facingMode: currentFacingMode } })
        .then(function (newStream) {
          var newTrack = newStream.getVideoTracks()[0];
          if (!newTrack) return;
          var sender = pc.getSenders().find(function (s) { return s.track && s.track.kind === 'video'; });
          if (sender) sender.replaceTrack(newTrack);
          var oldTrack = localStream.getVideoTracks()[0];
          if (oldTrack) { oldTrack.stop(); localStream.removeTrack(oldTrack); }
          localStream.addTrack(newTrack);
          attachLocalVideo(localStream);
        })
        .catch(function () { showToast('تعذر تبديل الكاميرا', 'error'); });
    });

    // اطلب إذن إشعارات المتصفح (لو لسه ماتحددش) عشان نقدر ننبّه بمكالمة واردة
    if (window.Notification && Notification.permission === 'default') {
      try { Notification.requestPermission(); } catch (e) {}
    }

    // متصفحات كتير بتمنع تشغيل صوت تلقائي قبل أول تفاعل من المستخدم مع الصفحة،
    // فبنفك القفل ده أول ما المستخدم يضغط في أي مكان
    document.addEventListener('click', function unlockAudioOnce() {
      var ctx = getAudioCtx();
      if (ctx && ctx.state === 'suspended') { try { ctx.resume(); } catch (e) {} }
      document.removeEventListener('click', unlockAudioOnce);
    }, { once: true });

    // بولنج دائم كل 1.5 ثانية: مكالمة نشطة (ICE/status) أو تفقّد مكالمة واردة
    clearInterval(callTickTimer);
    if (new URLSearchParams(location.search).get('autoanswer') === '1') {
      autoAnswerOnNextIncomingCall = true;
      try {
        var cleanUrl = location.pathname + location.search.replace(/[?&]autoanswer=1/, '').replace(/^&/, '?');
        history.replaceState(null, '', cleanUrl);
      } catch (e) {}
    }
    checkIncomingCall(); // فحص فوري (مهم لو الصفحة اتفتحت من إشعار مكالمة واردة)
    callTickTimer = setInterval(function () {
      if (activeCallId) pollActiveCall(); else checkIncomingCall();
    }, 1500);
  });

  /* ════════════════════════════════════
     GROUP CHAT
  ════════════════════════════════════ */

  // تبديل واجهة رأس الشات بين محادثة فردية وجروب (المكالمات وحظر المستخدم
  // متاحين بس للمحادثات الفردية دلوقتي؛ المكالمات الجماعية جاية في مرحلة تالية)
  function applyGroupChatUI(memberCount) {
    var video = document.getElementById('videoCallBtn');
    var voice = document.getElementById('voiceCallBtn');
    var profileLink = document.getElementById('chatProfileLink');
    if (video) video.style.display = 'none';
    if (voice) voice.style.display = 'none';
    if (profileLink) profileLink.style.display = 'none';

    var blockBtn = document.getElementById('blockBtn');
    var membersBtn = document.getElementById('groupMembersBtn');
    var renameBtn = document.getElementById('renameGroupBtn');
    var leaveBtn = document.getElementById('leaveGroupBtn');
    if (blockBtn) blockBtn.style.display = 'none';
    if (membersBtn) membersBtn.style.display = 'block';
    if (renameBtn) renameBtn.style.display = 'block';
    if (leaveBtn) leaveBtn.style.display = 'block';

    var statusEl = document.getElementById('chatPeerStatus');
    if (statusEl) statusEl.textContent = (memberCount || 0) + ' عضو';
  }

  function restoreDirectChatUI() {
    var video = document.getElementById('videoCallBtn');
    var voice = document.getElementById('voiceCallBtn');
    var profileLink = document.getElementById('chatProfileLink');
    if (video) video.style.display = '';
    if (voice) voice.style.display = '';
    if (profileLink) profileLink.style.display = '';

    var blockBtn = document.getElementById('blockBtn');
    var membersBtn = document.getElementById('groupMembersBtn');
    var renameBtn = document.getElementById('renameGroupBtn');
    var leaveBtn = document.getElementById('leaveGroupBtn');
    if (blockBtn) blockBtn.style.display = 'block';
    if (membersBtn) membersBtn.style.display = 'none';
    if (renameBtn) renameBtn.style.display = 'none';
    if (leaveBtn) leaveBtn.style.display = 'none';
  }

  // لو المستخدم فتح محادثة فردية وهو كان جوه جروب، ارجع الواجهة لوضعها الطبيعي
  var _origOpenChatForGroups = window.openChat;
  window.openChat = function (id, name, photo) {
    currentChatType = 'direct';
    groupId = null;
    groupTitle = '';
    restoreDirectChatUI();
    return _origOpenChatForGroups(id, name, photo);
  };

  window.openGroupChat = function (id, title, memberCount) {
    receiverId = null;
    receiverName = '';
    lastId = 0;
    isJumpMode = false;
    window.cancelReply && window.cancelReply();
    window.closeMsgSearch && window.closeMsgSearch();
    currentChatType = 'group';
    groupId = id;
    groupTitle = title;

    var empty     = document.getElementById('chatEmpty');
    var container = document.getElementById('chatContainer');
    if (!container) return;

    empty.style.display     = 'none';
    container.style.display = 'flex';
    container.style.flexDirection = 'column';
    container.style.flex    = '1';
    container.style.minHeight = '0';
    container.style.overflow = 'hidden';

    document.getElementById('chatPeerName').textContent   = title;
    var av = document.getElementById('chatAvatar');
    av.src = '/public/assets/img/default.png';
    av.onerror = function(){ this.src = '/public/assets/img/default.png'; };

    applyGroupChatUI(memberCount);

    if (window.innerWidth <= 768) {
      document.getElementById('chatSidebar').classList.remove('open');
      var _ov2 = document.getElementById('sidebarOverlay');
      if (_ov2) _ov2.classList.remove('show');
    }

    try { history.replaceState(null, '', '/?group=' + id); } catch(e){}

    var area = document.getElementById('messagesArea');
    area.innerHTML = '<div style="text-align:center;padding:32px;color:var(--text-muted)">'
      + '<i class="fas fa-spinner fa-spin" style="font-size:1.5rem"></i></div>';

    loadMsgs(true);
    doMarkRead();

    clearInterval(pollTimer);
    pollTimer = setInterval(function(){ loadMsgs(false); refreshVisibleReactions(); }, 3000);

    [].forEach.call(document.querySelectorAll('.conv-item'), function(el){
      el.classList.toggle('active', el.dataset.type === 'group' && el.dataset.groupId == id);
    });

    var inp = document.getElementById('messageInput');
    if (inp) setTimeout(function(){ inp.focus(); }, 100);
  };

  /* ── إنشاء جروب جديد ── */
  window.openNewGroupModal = function () {
    selectedNewGroupMembers = {};
    var m = document.getElementById('newGroupModal');
    if (m) m.classList.add('active');
    var titleInp = document.getElementById('newGroupTitle');
    if (titleInp) titleInp.value = '';
    var searchInp = document.getElementById('groupMemberSearch');
    if (searchInp) searchInp.value = '';
    renderSelectedChips();
    var res = document.getElementById('groupMemberSearchResults');
    if (res) res.innerHTML = '';
  };

  window.closeNewGroupModal = function () {
    var m = document.getElementById('newGroupModal');
    if (!m) return;
    m.classList.remove('active');
    m.style.display = 'none';
    setTimeout(function(){ m.style.display = ''; }, 100);
  };

  function renderSelectedChips() {
    var wrap = document.getElementById('newGroupSelectedChips');
    if (!wrap) return;
    var chips = Object.keys(selectedNewGroupMembers).map(function(uid) {
      var u = selectedNewGroupMembers[uid];
      return '<span style="display:inline-flex;align-items:center;gap:6px;background:var(--bg-surface);'
        + 'border:1px solid var(--border);border-radius:var(--radius-full);padding:4px 10px;font-size:.8rem">'
        + esc(u.username)
        + '<i class="fas fa-times" style="cursor:pointer" onclick="window.removeSelectedGroupMember(' + uid + ')"></i>'
        + '</span>';
    });
    wrap.innerHTML = chips.join('');
  }

  window.removeSelectedGroupMember = function (uid) {
    delete selectedNewGroupMembers[uid];
    renderSelectedChips();
  };

  var _groupMemberSearchTimer = null;
  function bindGroupMemberSearch() {
    var inp = document.getElementById('groupMemberSearch');
    if (!inp || inp._bound) return;
    inp._bound = true;
    inp.addEventListener('input', function () {
      clearTimeout(_groupMemberSearchTimer);
      var q = inp.value.trim();
      _groupMemberSearchTimer = setTimeout(function () { searchGroupMemberCandidates(q); }, 300);
    });
  }

  function searchGroupMemberCandidates(q) {
    var res = document.getElementById('groupMemberSearchResults');
    if (!res) return;
    if (!q || q.length < 2) { res.innerHTML = ''; return; }
    res.innerHTML = '<div style="text-align:center;padding:12px"><i class="fas fa-spinner fa-spin"></i></div>';

    fetch(API.search + '?q=' + encodeURIComponent(q))
      .then(function(r){ return r.json(); })
      .then(function(data){
        var users = (data.users || []).filter(function(u){ return String(u.id) !== String(window.CURRENT_USER_ID); });
        if (!users.length) {
          res.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:12px;font-size:.85rem">لا توجد نتائج</div>';
          return;
        }
        res.innerHTML = users.map(function(u){
          var checked = selectedNewGroupMembers[u.id] ? ' checked' : '';
          return '<div style="display:flex;align-items:center;gap:10px;padding:8px 4px;cursor:pointer" '
            + 'onclick="window.toggleGroupMemberCandidate(' + u.id + ',\'' + sa(u.username) + '\',\'' + sa(u.profile_photo) + '\')">'
            + '<input type="checkbox"' + checked + ' style="pointer-events:none">'
            + '<img src="/public/assets/img/' + sa(u.profile_photo) + '" width="34" height="34" '
            + 'style="width:34px;height:34px;border-radius:50%;object-fit:cover" onerror="this.src=\'/public/assets/img/default.png\'">'
            + '<span style="font-size:.9rem">' + esc(u.username) + '</span>'
            + '</div>';
        }).join('');
      })
      .catch(function(){ res.innerHTML = '<div style="text-align:center;color:var(--danger);padding:12px">خطأ في الاتصال</div>'; });
  }

  window.toggleGroupMemberCandidate = function (id, username, photo) {
    if (selectedNewGroupMembers[id]) {
      delete selectedNewGroupMembers[id];
    } else {
      selectedNewGroupMembers[id] = { id: id, username: username, profile_photo: photo };
    }
    renderSelectedChips();
    var inp = document.getElementById('groupMemberSearch');
    if (inp) searchGroupMemberCandidates(inp.value.trim());
  };

  window.submitCreateGroup = function () {
    var titleInp = document.getElementById('newGroupTitle');
    var title = titleInp ? titleInp.value.trim() : '';
    var memberIds = Object.keys(selectedNewGroupMembers).map(Number);

    if (!title) { showToast('اكتب اسم المجموعة', 'error'); return; }
    if (!memberIds.length) { showToast('اختر عضو واحد على الأقل', 'error'); return; }

    fetch(API.createGroup, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ title: title, member_ids: memberIds, csrf_token: window.CSRF_TOKEN })
    })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data.success) {
          window.closeNewGroupModal();
          showToast('تم إنشاء المجموعة', 'success');
          loadConvs();
          setTimeout(function(){ window.openGroupChat(data.conversation_id, title, memberIds.length + 1); }, 150);
        } else {
          showToast(data.message || 'تعذر إنشاء المجموعة', 'error');
        }
      })
      .catch(function(){ showToast('خطأ في الاتصال', 'error'); });
  };

  /* ── لوحة معلومات/أعضاء الجروب ── */
  window.openGroupInfoModal = function () {
    var menu = document.getElementById('chatMenu');
    if (menu) menu.style.display = 'none';
    if (!groupId) return;

    var m = document.getElementById('groupInfoModal');
    if (m) m.classList.add('active');
    var addSearch = document.getElementById('groupAddMemberSearch');
    if (addSearch) addSearch.value = '';
    var addRes = document.getElementById('groupAddMemberResults');
    if (addRes) addRes.innerHTML = '';

    loadGroupInfo();
  };

  window.closeGroupInfoModal = function () {
    var m = document.getElementById('groupInfoModal');
    if (!m) return;
    m.classList.remove('active');
    m.style.display = 'none';
    setTimeout(function(){ m.style.display = ''; }, 100);
  };

  function loadGroupInfo() {
    var list = document.getElementById('groupInfoMembersList');
    if (!list || !groupId) return;
    list.innerHTML = '<div style="text-align:center;padding:16px"><i class="fas fa-spinner fa-spin"></i></div>';

    fetch(API.groupInfo + '?conversation_id=' + groupId)
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (!data.success) {
          list.innerHTML = '<div style="color:var(--danger);text-align:center;padding:16px">' + esc(data.message || 'خطأ') + '</div>';
          return;
        }
        groupMembersInfo = data.members || [];
        var myRole = 'member';
        groupMembersInfo.forEach(function(m){ if (String(m.id) === String(window.CURRENT_USER_ID)) myRole = m.role; });
        var canManage = myRole === 'owner' || myRole === 'admin';

        list.innerHTML = groupMembersInfo.map(function(m){
          var isMe = String(m.id) === String(window.CURRENT_USER_ID);
          var roleLabel = m.role === 'owner' ? 'مالك' : (m.role === 'admin' ? 'مشرف' : '');
          var removeBtn = (canManage && !isMe)
            ? '<button onclick="window.removeGroupMember(' + m.id + ')" title="إزالة" '
              + 'style="border:none;background:none;color:var(--danger);cursor:pointer;font-size:.85rem">'
              + '<i class="fas fa-user-minus"></i></button>'
            : '';
          return '<div style="display:flex;align-items:center;gap:10px;padding:8px 4px;border-bottom:1px solid var(--border)">'
            + '<img src="/public/assets/img/' + sa(m.profile_photo) + '" width="38" height="38" '
            + 'style="width:38px;height:38px;border-radius:50%;object-fit:cover" onerror="this.src=\'/public/assets/img/default.png\'">'
            + '<div style="flex:1;min-width:0">'
            + '<div style="font-size:.9rem;font-weight:600">' + esc(m.username) + (isMe ? ' (أنت)' : '') + '</div>'
            + (roleLabel ? '<div style="font-size:.72rem;color:var(--text-muted)">' + roleLabel + '</div>' : '')
            + '</div>' + removeBtn + '</div>';
        }).join('');
      })
      .catch(function(){ list.innerHTML = '<div style="color:var(--danger);text-align:center;padding:16px">خطأ في الاتصال</div>'; });
  }

  window.removeGroupMember = function (userId) {
    if (!groupId) return;
    if (!confirm('هل تريد إزالة هذا العضو من المجموعة؟')) return;
    fetch(API.groupMembers, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ conversation_id: groupId, action: 'remove', user_id: userId, csrf_token: window.CSRF_TOKEN })
    })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data.success) { showToast('تمت إزالة العضو', 'success'); loadGroupInfo(); loadMsgs(false); }
        else showToast(data.message || 'تعذر تنفيذ العملية', 'error');
      })
      .catch(function(){ showToast('خطأ في الاتصال', 'error'); });
  };

  var _groupAddSearchTimer = null;
  document.addEventListener('DOMContentLoaded', function () {
    bindGroupMemberSearch();
    var addInp = document.getElementById('groupAddMemberSearch');
    if (addInp) {
      addInp.addEventListener('input', function () {
        clearTimeout(_groupAddSearchTimer);
        var q = addInp.value.trim();
        _groupAddSearchTimer = setTimeout(function () { searchAddToGroup(q); }, 300);
      });
    }
  });

  function searchAddToGroup(q) {
    var res = document.getElementById('groupAddMemberResults');
    if (!res) return;
    if (!q || q.length < 2) { res.innerHTML = ''; return; }
    res.innerHTML = '<div style="text-align:center;padding:12px"><i class="fas fa-spinner fa-spin"></i></div>';

    fetch(API.search + '?q=' + encodeURIComponent(q))
      .then(function(r){ return r.json(); })
      .then(function(data){
        var existingIds = (groupMembersInfo || []).map(function(m){ return String(m.id); });
        var users = (data.users || []).filter(function(u){ return existingIds.indexOf(String(u.id)) === -1; });
        if (!users.length) {
          res.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:12px;font-size:.85rem">لا توجد نتائج</div>';
          return;
        }
        res.innerHTML = users.map(function(u){
          return '<div style="display:flex;align-items:center;gap:10px;padding:8px 4px">'
            + '<img src="/public/assets/img/' + sa(u.profile_photo) + '" width="34" height="34" '
            + 'style="width:34px;height:34px;border-radius:50%;object-fit:cover" onerror="this.src=\'/public/assets/img/default.png\'">'
            + '<span style="flex:1;font-size:.9rem">' + esc(u.username) + '</span>'
            + '<button onclick="window.addMemberToGroup(' + u.id + ')" '
            + 'style="padding:4px 12px;border:none;border-radius:var(--radius-full);background:var(--brand);color:#fff;font-size:.78rem;cursor:pointer">إضافة</button>'
            + '</div>';
        }).join('');
      })
      .catch(function(){ res.innerHTML = '<div style="text-align:center;color:var(--danger);padding:12px">خطأ في الاتصال</div>'; });
  }

  window.addMemberToGroup = function (userId) {
    if (!groupId) return;
    fetch(API.groupMembers, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ conversation_id: groupId, action: 'add', member_ids: [userId], csrf_token: window.CSRF_TOKEN })
    })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data.success) { showToast('تمت الإضافة', 'success'); loadGroupInfo(); loadMsgs(false); document.getElementById('groupAddMemberSearch').value=''; document.getElementById('groupAddMemberResults').innerHTML=''; }
        else showToast(data.message || 'تعذر تنفيذ العملية', 'error');
      })
      .catch(function(){ showToast('خطأ في الاتصال', 'error'); });
  };

  window.promptRenameGroup = function () {
    var menu = document.getElementById('chatMenu');
    if (menu) menu.style.display = 'none';
    if (!groupId) return;
    var newTitle = prompt('اسم المجموعة الجديد:', groupTitle);
    if (newTitle === null) return;
    newTitle = newTitle.trim();
    if (!newTitle) return;

    fetch(API.renameGroup, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ conversation_id: groupId, title: newTitle, csrf_token: window.CSRF_TOKEN })
    })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data.success) {
          groupTitle = data.title;
          document.getElementById('chatPeerName').textContent = data.title;
          showToast('تم تغيير الاسم', 'success');
          loadConvs();
          loadMsgs(false);
        } else {
          showToast(data.message || 'تعذر تنفيذ العملية', 'error');
        }
      })
      .catch(function(){ showToast('خطأ في الاتصال', 'error'); });
  };

  window.leaveCurrentGroup = function () {
    var menu = document.getElementById('chatMenu');
    if (menu) menu.style.display = 'none';
    if (!groupId) return;
    if (!confirm('هل تريد مغادرة هذه المجموعة؟')) return;

    var leavingId = groupId;
    fetch(API.groupMembers, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ conversation_id: leavingId, action: 'leave', csrf_token: window.CSRF_TOKEN })
    })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data.success) {
          showToast('تم مغادرة المجموعة', 'success');
          window.closeChatOnMobile && window.closeChatOnMobile();
          var container = document.getElementById('chatContainer');
          var empty = document.getElementById('chatEmpty');
          if (container) container.style.display = 'none';
          if (empty) empty.style.display = 'flex';
          currentChatType = 'direct';
          groupId = null;
          clearInterval(pollTimer);
          try { history.replaceState(null, '', '/'); } catch(e){}
          loadConvs();
        } else {
          showToast(data.message || 'تعذر تنفيذ العملية', 'error');
        }
      })
      .catch(function(){ showToast('خطأ في الاتصال', 'error'); });
  };

})();
