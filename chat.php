<?php
require 'config.php';
if(!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }

if(isset($_GET['contact_id'])) {
    $_SESSION['partner_id'] = (int)$_GET['contact_id'];
}
if(!isset($_SESSION['partner_id'])) { header('Location: home.php'); exit; }

$user_id    = (int)$_SESSION['user_id'];
$partner_id = (int)$_SESSION['partner_id'];

$stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id=? LIMIT 1');
$stmt->execute([$user_id]);
$me = $stmt->fetch();

$stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id=? LIMIT 1');
$stmt->execute([$partner_id]);
$partner = $stmt->fetch();

if(!$partner) { header('Location: home.php'); exit; }

$bubble_color = htmlspecialchars($me['bubble_color'] ?? '#18033B');
$chat_bg      = $me['chat_bg'] ?? '';
$is_light     = ($me['theme_mode'] ?? 'dark') === 'light';

$bg_style = $is_light ? 'background:#f5f0eb;' : 'background:#0B0B0F;';
if($chat_bg) {
    if(strpos($chat_bg,'#')===0 || strpos($chat_bg,'rgb')===0) {
        $bg_style = "background-color:{$chat_bg};";
    } elseif(filter_var($chat_bg, FILTER_VALIDATE_URL)) {
        $bg_style = "background-image:url('".htmlspecialchars($chat_bg)."');background-size:cover;background-position:center;";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>blufast · <?= htmlspecialchars($partner['nombre'] ?: $partner['username']) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/peerjs@1.4.7/dist/peerjs.min.js"></script>
<style>
/* ── RESET Y BASE ───────────────────────────────────────────────────── */
*{-webkit-tap-highlight-color:transparent;box-sizing:border-box;margin:0;padding:0;}
:root{--bubble:<?= $bubble_color ?>;}

html,body{
  height:100%;width:100%;background:#000;
  display:flex;justify-content:center;align-items:stretch;
}
#app{
  width:100%;max-width:480px;height:100dvh;
  display:flex;flex-direction:column;overflow:hidden;
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
  position:relative;
}

/* ── SCROLLBAR ──────────────────────────────────────────────────────── */
#chat-box::-webkit-scrollbar{width:3px;}
#chat-box::-webkit-scrollbar-thumb{background:#333;border-radius:10px;}

/* ── BURBUJAS ───────────────────────────────────────────────────────── */
.msg-row{display:flex;margin-bottom:3px;padding:0 8px;}
.msg-row.me{justify-content:flex-end;}
.msg-row.them{justify-content:flex-start;}
.bubble{
  max-width:78%;padding:9px 13px;font-size:14px;
  line-height:1.5;position:relative;word-break:break-word;
}
.bubble.me{
  background:var(--bubble);color:#fff;
  border-radius:18px 18px 4px 18px;
}
.bubble.them{
  background:<?= $is_light ? '#e8e8f0' : '#1e1e28' ?>;color:<?= $is_light ? '#1a1a2e' : '#e5e7eb' ?>;
  border-radius:18px 18px 18px 4px;border:1px solid <?= $is_light ? '#d1d5db' : '#2a2a38' ?>;
}

/* ── DOBLE CHECK ───────────────────────────────────────────────────── */
/* Colores FIJOS, nunca heredan el color de burbuja */
.msg-check{font-size:11px;margin-left:4px;display:inline;}
.msg-check.sent{color:rgba(255,255,255,0.45)!important;}
.msg-check.read{color:#60d4f7!important;}  /* azul claro, visible en cualquier burbuja */

/* ── MENSAJE FIJADO (barra superior) ───────────────────────────────── */
#pinned-bar{
  display:none;background:#111118;border-bottom:1px solid #2a2a38;
  padding:8px 14px;flex-shrink:0;align-items:center;gap:8px;cursor:pointer;
}
#pinned-bar.visible{display:flex;}
#pinned-bar-text{
  flex:1;font-size:12px;color:#aaa;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}

/* ── MENÚ CONTEXTUAL ────────────────────────────────────────────────── */
.ctx-menu{
  display:none;position:fixed;
  background:#1a1a26;border:1px solid #2a2a38;
  border-radius:16px;padding:6px;z-index:500;
  min-width:200px;box-shadow:0 8px 32px rgba(0,0,0,0.85);
  flex-direction:column;gap:2px;
}
.ctx-menu.open{display:flex;}
.ctx-item{
  display:flex;align-items:center;gap:10px;
  padding:10px 12px;font-size:13px;cursor:pointer;
  border-radius:10px;color:#e5e7eb;transition:background .12s;
}
.ctx-item:hover{background:#2a2a38;}
.ctx-item.danger{color:#f87171;}

/* ── REACCIONES RÁPIDAS EN MENÚ ─────────────────────────────────────── */
.react-row{
  display:flex;gap:4px;padding:8px 10px;
  border-bottom:1px solid #2a2a38;justify-content:space-around;
}
.react-opt{
  font-size:22px;cursor:pointer;padding:3px 4px;
  border-radius:8px;transition:transform .12s;
}
.react-opt:hover{transform:scale(1.3);}

/* ── CHIPS DE REACCIÓN ──────────────────────────────────────────────── */
.reactions-row{display:flex;gap:3px;flex-wrap:wrap;margin-top:4px;padding:0 8px;}
.reaction-chip{
  background:rgba(255,255,255,0.1);border-radius:20px;
  padding:2px 7px;font-size:12px;cursor:pointer;
  border:1px solid transparent;transition:background .12s;
}
.reaction-chip.mine{border-color:var(--bubble);}
.reaction-chip:hover{background:rgba(255,255,255,0.18);}

/* ── REPLY PREVIEW BURBUJA ──────────────────────────────────────────── */
.reply-preview{
  border-left:3px solid rgba(255,255,255,0.4);padding:4px 8px;
  border-radius:4px;margin-bottom:6px;background:rgba(0,0,0,0.2);
  font-size:11px;line-height:1.3;
}
.bubble.them .reply-preview{border-left-color:var(--bubble);}

/* ── REPLY BAR INPUT ────────────────────────────────────────────────── */
#reply-bar{
  display:none;align-items:center;gap:8px;
  padding:8px 12px;background:#161620;
  border-top:1px solid #2a2a38;flex-shrink:0;
}

/* ── TIMESTAMP ──────────────────────────────────────────────────────── */
.msg-time{font-size:10px;opacity:0.5;margin-top:3px;display:block;}

/* ── HEADER ─────────────────────────────────────────────────────────── */
/* #app-header: estilos en el HTML inline para respetar tema claro/oscuro */
.icon-btn{
  width:38px;height:38px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;flex-shrink:0;transition:background .15s;
  background:transparent;border:none;color:#aaa;
}
.icon-btn:hover{background:#2a2a38;}
.icon-btn svg{width:21px;height:21px;}

/* ── INDICADOR ESCRIBIENDO ──────────────────────────────────────────── */
#typing-indicator{
  font-size:11px;color:#a78bfa;display:none;
  animation:blink .9s infinite;
}
@keyframes blink{0%,100%{opacity:1;}50%{opacity:.4;}}

/* ── INPUT BAR ──────────────────────────────────────────────────────── */
#input-bar{
  padding:8px 10px;background:#111118;border-top:1px solid #1e1e28;
  display:flex;align-items:flex-end;gap:6px;flex-shrink:0;position:relative;
}
#msg-input{
  flex:1;background:#1e1e28;border:1px solid #2a2a38;
  border-radius:22px;padding:10px 16px;font-size:14px;color:#fff;
  outline:none;resize:none;max-height:120px;line-height:1.4;font-family:inherit;
}
#msg-input::placeholder{color:#555;}

#send-btn{
  background:var(--bubble);width:40px;height:40px;border-radius:50%;
  display:none;align-items:center;justify-content:center;
  cursor:pointer;flex-shrink:0;border:none;transition:opacity .15s;
}
#send-btn:hover{opacity:.85;}
#send-btn svg{width:20px;height:20px;}

#audio-btn{color:#aaa;}
#audio-btn.recording{color:#ef4444;animation:pulse 1s infinite;}
@keyframes pulse{0%,100%{opacity:1;}50%{opacity:.5;}}

/* ── MENÚ ADJUNTAR ──────────────────────────────────────────────────── */
#attach-menu{
  display:none;position:absolute;bottom:64px;left:10px;
  background:#1a1a26;border:1px solid #2a2a38;border-radius:16px;
  padding:8px;gap:4px;flex-direction:column;z-index:200;
  box-shadow:0 8px 32px rgba(0,0,0,0.6);min-width:190px;
}
#attach-menu.open{display:flex;}
.attach-btn{
  display:flex;align-items:center;gap:10px;padding:10px 14px;
  border-radius:12px;cursor:pointer;font-size:13px;font-weight:500;
  transition:background .15s;color:#e5e7eb;
}
.attach-btn:hover{background:#2a2a38;}

/* ── EMOJI PICKER ───────────────────────────────────────────────────── */
#emoji-picker{
  display:none;position:absolute;bottom:64px;left:10px;
  background:#1a1a26;border:1px solid #2a2a38;border-radius:16px;
  padding:12px;z-index:200;box-shadow:0 8px 32px rgba(0,0,0,0.6);width:270px;
}
#emoji-picker.open{display:block;}
.emoji-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;}
.emoji-btn{
  font-size:22px;padding:4px;border-radius:8px;
  cursor:pointer;text-align:center;transition:background .1s;
}
.emoji-btn:hover{background:#2a2a38;}

/* ── REACCIONAR CON TEXTO (panel personalizado en ctx-menu) ─────────── */
#ctx-react-text-row{
  display:flex;align-items:center;gap:6px;
  padding:6px 10px;border-top:1px solid #2a2a38;
}
#ctx-react-text-input{
  flex:1;background:#0f0f18;border:1px solid #2a2a38;border-radius:10px;
  padding:7px 10px;font-size:13px;color:#fff;outline:none;
}
#ctx-react-text-input::placeholder{color:#555;}
#ctx-react-text-send{
  background:var(--bubble);border:none;border-radius:8px;
  padding:7px 12px;color:#fff;cursor:pointer;font-size:13px;
}

/* ── TRES PUNTOS MENÚ ───────────────────────────────────────────────── */
#dots-menu{
  display:none;position:absolute;top:56px;right:10px;
  background:#1a1a26;border:1px solid #2a2a38;border-radius:14px;
  padding:8px;z-index:300;min-width:190px;
  box-shadow:0 8px 32px rgba(0,0,0,0.7);
}
#dots-menu.open{display:block;}
.dots-item{
  padding:10px 14px;font-size:13px;cursor:pointer;
  border-radius:10px;display:flex;align-items:center;gap:10px;
  color:#e5e7eb;transition:background .12s;
}
.dots-item:hover{background:#2a2a38;}
.dots-item.danger{color:#f87171;}

/* ── MODAL INFO ─────────────────────────────────────────────────────── */
#modal-info{
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,0.85);backdrop-filter:blur(6px);z-index:400;
  align-items:flex-end;justify-content:center;
}
#modal-info.open{display:flex;}
.modal-sheet{
  width:100%;max-width:480px;background:#111118;
  border-radius:24px 24px 0 0;padding:24px;max-height:80vh;overflow-y:auto;
}

/* ── VIDEO CONTAINER ────────────────────────────────────────────────── */
#video-container{display:none;position:fixed;inset:0;background:#000;z-index:500;flex-direction:column;}
#video-container.open{display:flex;}

/* ── TOAST ──────────────────────────────────────────────────────────── */
#toast{
  position:fixed;top:20px;left:50%;transform:translateX(-50%);
  background:#22c55e;color:#fff;padding:10px 20px;
  border-radius:12px;font-size:13px;z-index:9999;
  opacity:0;transition:opacity .3s;pointer-events:none;white-space:nowrap;
}

/* ── BÚSQUEDA ───────────────────────────────────────────────────────── */
#search-bar{display:none;padding:8px 12px;background:#111118;border-bottom:1px solid #1e1e28;flex-shrink:0;}

/* ── SEPARADOR FECHA ────────────────────────────────────────────────── */
.date-sep{text-align:center;margin:8px 0;}
.date-sep span{
  background:#1a1a26;color:#666;
  font-size:11px;padding:3px 10px;border-radius:10px;
}

/* ── REPRODUCTOR DE AUDIO CUSTOM ────────────────────────────────────── */
.audio-player{
  display:flex;align-items:center;gap:8px;
  background:rgba(0,0,0,0.25);border-radius:14px;
  padding:8px 10px;margin-top:6px;width:220px;max-width:100%;
}
.audio-play-btn{
  width:32px;height:32px;border-radius:50%;border:none;cursor:pointer;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
  background:rgba(255,255,255,0.15);color:#fff;font-size:13px;transition:background .15s;
}
.audio-play-btn:hover{background:rgba(255,255,255,0.28);}
.audio-wave{
  display:flex;align-items:center;gap:2px;flex:1;height:24px;cursor:pointer;
}
.audio-wave-bar{
  width:3px;border-radius:3px;background:rgba(255,255,255,0.4);
  transition:background .15s;flex-shrink:0;
}
.audio-wave-bar.active{background:rgba(255,255,255,0.9);}
.audio-time-label{font-size:10px;color:rgba(255,255,255,0.55);white-space:nowrap;flex-shrink:0;}
</style>
</head>
<body>

<div id="app" style="<?= $bg_style ?><?= $is_light ? 'color:#1a1a2e;' : 'color:#e5e7eb;' ?>">

<!-- ═══ HEADER ════════════════════════════════════════════════════════ -->
<header id="app-header" style="background:<?= $is_light ? '#ffffff' : '#111118' ?>;border-bottom:1px solid <?= $is_light ? '#e5e7eb' : '#1e1e28' ?>;padding:10px 12px;display:flex;align-items:center;gap:10px;flex-shrink:0;position:relative;">
  <a href="home.php" class="icon-btn" aria-label="Volver">
    <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M15 19l-7-7 7-7"/></svg>
  </a>

  <div onclick="openInfoModal()" style="display:flex;align-items:center;gap:10px;flex:1;cursor:pointer;min-width:0;">
    <div style="position:relative;flex-shrink:0;">
      <img id="partner-avatar"
           src="<?= htmlspecialchars($partner['avatar_url'] ?: 'https://ui-avatars.com/api/?name='.urlencode($partner['username']).'&background=18033B&color=fff') ?>"
           style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid <?= $bubble_color ?>;">
      <span id="online-dot" style="width:9px;height:9px;background:#22c55e;border-radius:50%;position:absolute;bottom:0;right:0;border:2px solid #111118;display:none;"></span>
    </div>
    <div style="min-width:0;">
      <div style="font-weight:600;font-size:15px;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
        <?= htmlspecialchars($partner['nombre'] ?: $partner['username']) ?>
      </div>
      <div id="typing-indicator">escribiendo...</div>
      <div id="partner-username" style="font-size:11px;color:#555;">@<?= htmlspecialchars($partner['username']) ?></div>
    </div>
  </div>

  <button onclick="startCall(false)" class="icon-btn" title="Llamada">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
  </button>
  <button onclick="startCall(true)" class="icon-btn" title="Video">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 10l4.553-2.276A1 1 0 0121 8.723v6.554a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
  </button>
  <button onclick="toggleDotsMenu()" class="icon-btn">
    <svg fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
  </button>

  <!-- Menú tres puntos -->
  <div id="dots-menu">
    <div class="dots-item" onclick="openInfoModal();closeDotsMenu()">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;flex-shrink:0;"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      Info del contacto
    </div>
    <div class="dots-item" onclick="searchBar()">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;flex-shrink:0;"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      Buscar mensajes
    </div>
    <div class="dots-item" onclick="clearChat()">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;flex-shrink:0;"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
      Limpiar vista
    </div>
    <div class="dots-item danger" onclick="blockUser()">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;flex-shrink:0;"><path d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
      Bloquear
    </div>
    <div class="dots-item danger" onclick="reportUser()">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;flex-shrink:0;"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
      Reportar
    </div>
  </div>
</header>

<!-- ═══ BARRA MENSAJE FIJADO ══════════════════════════════════════════ -->
<div id="pinned-bar" onclick="scrollToPinned()">
  <span style="font-size:16px;">📌</span>
  <span id="pinned-bar-text"></span>
</div>

<!-- ═══ BARRA BÚSQUEDA ════════════════════════════════════════════════ -->
<div id="search-bar">
  <div style="display:flex;align-items:center;gap:8px;background:#1e1e28;border-radius:12px;padding:8px 12px;">
    <svg fill="none" stroke="#666" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;flex-shrink:0;"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
    <input id="search-input" placeholder="Buscar en el chat..." oninput="loadMessages()"
           style="flex:1;background:transparent;border:none;outline:none;color:#fff;font-size:13px;">
    <span onclick="closeSearchBar()" style="color:#666;cursor:pointer;font-size:20px;line-height:1;">×</span>
  </div>
</div>

<!-- ═══ CAJA DE MENSAJES ══════════════════════════════════════════════ -->
<div id="chat-box" style="flex:1;overflow-y:auto;padding:10px 0;display:flex;flex-direction:column;gap:0;<?= $bg_style ?>">
  <div style="text-align:center;padding:14px 0 6px;">
    <div style="display:inline-flex;align-items:center;gap:6px;background:rgba(0,0,0,0.4);border:1px solid #2a2a38;border-radius:20px;padding:5px 13px;font-size:11px;color:#888;">
      <svg fill="none" stroke="#22c55e" stroke-width="2" viewBox="0 0 24 24" style="width:12px;height:12px;"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
      Cifrado E2E activo · AES-256
    </div>
  </div>
</div>

<!-- ═══ REPLY BAR ═════════════════════════════════════════════════════ -->
<div id="reply-bar">
  <div style="flex:1;background:#1e1e28;border-radius:10px;padding:6px 10px;font-size:12px;border-left:3px solid <?= $bubble_color ?>;">
    <span style="color:#aaa;font-size:10px;" id="reply-user-label">Respondiendo a</span>
    <div id="reply-text-preview" style="color:#ddd;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>
  </div>
  <button onclick="cancelReply()" style="color:#888;font-size:22px;background:none;border:none;cursor:pointer;padding:4px;line-height:1;">×</button>
</div>

<!-- ═══ BARRA DE INPUT ════════════════════════════════════════════════ -->
<div id="input-bar">

  <div id="attach-menu">
    <div class="react-row" style="border-bottom:1px solid #2a2a38;padding-bottom:8px;margin-bottom:4px;">
      <?php foreach(['😀','😂','🥰','😎','🤔','😢','❤️'] as $e): ?>
        <span class="react-opt" onclick="insertEmoji('<?=$e?>')"><?=$e?></span>
      <?php endforeach; ?>
    </div>
    <div class="attach-btn" onclick="toggleEmojiPicker()" style="color:#a78bfa;">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:20px;height:20px;"><path d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      Más emojis
    </div>
    <label class="attach-btn" style="color:#7c3aed;cursor:pointer;">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:20px;height:20px;"><path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
      Fotos / Video
      <input type="file" accept="image/*,video/*" class="hidden" onchange="sendFile(this)" style="display:none;">
    </label>
    <label class="attach-btn" style="color:#2563eb;cursor:pointer;">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:20px;height:20px;"><path d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
      Documento
      <input type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.zip,.txt" class="hidden" onchange="sendFile(this)" style="display:none;">
    </label>
  </div>

  <div id="emoji-picker">
    <div style="font-size:11px;color:#666;margin-bottom:8px;font-weight:600;letter-spacing:.05em;">EMOJIS</div>
    <div class="emoji-grid" id="emoji-grid"></div>
  </div>

  <button onclick="toggleAttachMenu()" class="icon-btn" style="flex-shrink:0;" aria-label="Adjuntar">
    <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M12 4v16m8-8H4"/></svg>
  </button>

  <textarea id="msg-input" rows="1" placeholder="Mensaje..."
            oninput="onInputChange(this)" onkeydown="handleInputKeydown(event)"></textarea>

  <button id="audio-btn" onclick="toggleAudio()" class="icon-btn" style="flex-shrink:0;" aria-label="Audio">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 016 0v6a3 3 0 01-3 3z"/></svg>
  </button>

  <button id="send-btn" onclick="sendMessage()" aria-label="Enviar">
    <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
      <line x1="12" y1="19" x2="12" y2="5"/>
      <polyline points="5 12 12 5 19 12"/>
    </svg>
  </button>
</div>

<!-- ═══ MENÚ CONTEXTUAL ═══════════════════════════════════════════════ -->
<div class="ctx-menu" id="ctx-menu">
  <!-- Reacciones rápidas con emoji -->
  <div class="react-row">
    <span class="react-opt" onclick="ctxReact('❤️')">❤️</span>
    <span class="react-opt" onclick="ctxReact('😂')">😂</span>
    <span class="react-opt" onclick="ctxReact('😮')">😮</span>
    <span class="react-opt" onclick="ctxReact('😢')">😢</span>
    <span class="react-opt" onclick="ctxReact('👍')">👍</span>
    <span class="react-opt" onclick="ctxReact('🔥')">🔥</span>
  </div>

  <!-- Responder con texto (ctx) -->
  <div class="ctx-item" onclick="ctxReply()">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;flex-shrink:0;"><path d="M3 10h11a4 4 0 010 8h-1m-9-8L7 6m-4 4l4 4"/></svg>
    Responder
  </div>

  <!-- Copiar texto -->
  <div class="ctx-item" onclick="ctxCopy()">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;flex-shrink:0;"><path d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
    Copiar
  </div>

  <!-- Fijar / Desfijar -->
  <div class="ctx-item" id="ctx-pin-btn" onclick="ctxPin()">
    <span style="font-size:15px;width:16px;text-align:center;flex-shrink:0;">📌</span>
    <span id="ctx-pin-label">Fijar mensaje</span>
  </div>

  <!-- Eliminar (solo mis mensajes) -->
  <div class="ctx-item danger" id="ctx-delete-btn" onclick="ctxDelete()">
    <svg fill="none" stroke="#f87171" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;flex-shrink:0;"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
    Eliminar mensaje
  </div>

  <!-- Input reaccionar con texto -->
  <div id="ctx-react-text-row">
    <input id="ctx-react-text-input" type="text" placeholder="Reaccionar con texto…"
           maxlength="100" onkeydown="if(event.key==='Enter')ctxReactText()">
    <button id="ctx-react-text-send" onclick="ctxReactText()">→</button>
  </div>
</div>

<!-- ═══ MODAL INFO CONTACTO ══════════════════════════════════════════ -->
<div id="modal-info" onclick="closeInfoModal()">
  <div class="modal-sheet" onclick="event.stopPropagation()">
    <div style="width:40px;height:4px;background:#333;border-radius:4px;margin:0 auto 20px;"></div>
    <div style="text-align:center;margin-bottom:20px;">
      <img id="info-avatar" src="" style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid <?= $bubble_color ?>;margin-bottom:12px;">
      <div id="info-nombre" style="font-size:18px;font-weight:700;color:#fff;"></div>
      <div id="info-username" style="font-size:13px;color:#555;margin-top:2px;"></div>
      <div id="info-bio" style="font-size:13px;color:#aaa;margin-top:8px;line-height:1.5;padding:0 10px;"></div>
    </div>
    <div style="background:#1e1e28;border-radius:14px;padding:14px;margin-bottom:14px;">
      <div style="font-size:11px;color:#555;font-weight:600;margin-bottom:10px;letter-spacing:.06em;">SEGURIDAD</div>
      <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:#aaa;margin-bottom:8px;">
        <svg fill="none" stroke="#22c55e" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;flex-shrink:0;"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
        Cifrado AES-256-CBC activo
      </div>
      <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:#aaa;">
        <svg fill="none" stroke="#22c55e" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;flex-shrink:0;"><path d="M15 10l4.553-2.276A1 1 0 0121 8.723v6.554a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
        Video WebRTC P2P
      </div>
    </div>
    <div style="display:flex;gap:10px;margin-bottom:12px;">
      <button onclick="startCall(false);closeInfoModal()" style="flex:1;padding:12px;background:#1e1e28;border:1px solid #2a2a38;border-radius:14px;color:#fff;font-size:13px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
        Llamar
      </button>
      <button onclick="startCall(true);closeInfoModal()" style="flex:1;padding:12px;background:<?= $bubble_color ?>;border-radius:14px;color:#fff;font-size:13px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;border:none;">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M15 10l4.553-2.276A1 1 0 0121 8.723v6.554a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
        Video
      </button>
    </div>
    <button onclick="closeInfoModal()" style="width:100%;padding:12px;background:#1e1e28;border:1px solid #2a2a38;border-radius:14px;color:#aaa;font-size:13px;cursor:pointer;">Cerrar</button>
  </div>
</div>

<!-- ═══ VIDEO / CALL CONTAINER ══════════════════════════════════════ -->
<div id="video-container">

  <!-- Pantalla: llamada ENTRANTE -->
  <div id="call-incoming" style="display:none;flex-direction:column;align-items:center;justify-content:center;flex:1;gap:24px;padding:40px 20px;background:#0d0d14;">
    <div style="font-size:13px;color:#a78bfa;letter-spacing:1px;font-weight:600;text-transform:uppercase;">Llamada entrante</div>
    <img src="<?= htmlspecialchars($partner['avatar_url'] ?: 'https://ui-avatars.com/api/?name='.urlencode($partner['username']).' &background=18033B&color=fff') ?>"
         style="width:100px;height:100px;border-radius:50%;object-fit:cover;border:3px solid #a78bfa;animation:ring 1.2s ease infinite;">
    <div style="font-size:22px;font-weight:700;color:#fff;"><?= htmlspecialchars($partner['nombre'] ?: $partner['username']) ?></div>
    <div style="font-size:14px;color:#888;">@<?= htmlspecialchars($partner['username']) ?></div>
    <div style="display:flex;gap:40px;margin-top:16px;">
      <div style="display:flex;flex-direction:column;align-items:center;gap:8px;">
        <button onclick="rejectCall()" style="width:64px;height:64px;border-radius:50%;background:#ef4444;border:none;cursor:pointer;font-size:26px;display:flex;align-items:center;justify-content:center;">&#128565;</button>
        <span style="font-size:12px;color:#ef4444;">Rechazar</span>
      </div>
      <div style="display:flex;flex-direction:column;align-items:center;gap:8px;">
        <button onclick="answerCall()" style="width:64px;height:64px;border-radius:50%;background:#22c55e;border:none;cursor:pointer;font-size:26px;display:flex;align-items:center;justify-content:center;">&#128222;</button>
        <span style="font-size:12px;color:#22c55e;">Responder</span>
      </div>
    </div>
  </div>

  <!-- Pantalla: llamada ACTIVA -->
  <div id="call-screen" style="display:none;flex-direction:column;flex:1;position:relative;background:#0d0d14;">
    <video id="vc-remote" autoplay playsinline style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;background:#000;display:none;"></video>
    <div id="call-audio-icon" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:20px;">
      <img src="<?= htmlspecialchars($partner['avatar_url'] ?: 'https://ui-avatars.com/api/?name='.urlencode($partner['username']).' &background=18033B&color=fff') ?>"
           style="width:110px;height:110px;border-radius:50%;object-fit:cover;border:3px solid #a78bfa;">
    </div>
    <video id="vc-local" autoplay muted playsinline style="position:absolute;top:16px;right:16px;width:90px;height:130px;object-fit:cover;border-radius:14px;border:2px solid #fff;display:none;z-index:10;"></video>
    <div style="position:absolute;top:0;left:0;right:0;padding:20px 16px 0;z-index:20;background:linear-gradient(to bottom,rgba(0,0,0,0.7),transparent);">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
        <span id="call-type-icon" style="font-size:18px;">&#128222;</span>
        <span id="call-label" style="font-size:18px;font-weight:700;color:#fff;"></span>
      </div>
      <div style="display:flex;align-items:center;gap:10px;">
        <span id="call-status" style="font-size:13px;color:#aaa;"></span>
        <span id="call-timer-txt" style="font-size:13px;color:#22c55e;font-weight:600;font-variant-numeric:tabular-nums;"></span>
      </div>
    </div>
    <div style="position:absolute;bottom:0;left:0;right:0;padding:24px 20px 36px;background:linear-gradient(transparent,rgba(0,0,0,0.85));z-index:20;">
      <div style="display:flex;justify-content:center;gap:24px;">
        <div style="display:flex;flex-direction:column;align-items:center;gap:6px;">
          <button id="btn-mute" onclick="toggleMic()" style="width:54px;height:54px;border-radius:50%;background:rgba(255,255,255,0.15);border:none;cursor:pointer;font-size:22px;display:flex;align-items:center;justify-content:center;transition:background .2s;">&#127897;&#65039;</button>
          <span style="font-size:11px;color:#aaa;">Micro</span>
        </div>
        <div style="display:flex;flex-direction:column;align-items:center;gap:6px;">
          <button onclick="endCall()" style="width:64px;height:64px;border-radius:50%;background:#ef4444;border:none;cursor:pointer;font-size:26px;display:flex;align-items:center;justify-content:center;">&#128565;</button>
          <span style="font-size:11px;color:#ef4444;">Colgar</span>
        </div>
        <div style="display:flex;flex-direction:column;align-items:center;gap:6px;">
          <button id="btn-cam" onclick="toggleCam()" style="width:54px;height:54px;border-radius:50%;background:rgba(255,255,255,0.15);border:none;cursor:pointer;font-size:22px;display:flex;align-items:center;justify-content:center;transition:background .2s;">&#128247;</button>
          <span style="font-size:11px;color:#aaa;">Camara</span>
        </div>
      </div>
    </div>
  </div>

  <audio id="call-audio-el" autoplay style="display:none;"></audio>
</div>

<style>
@keyframes ring{0%,100%{box-shadow:0 0 0 0 rgba(167,139,250,0.6);}50%{box-shadow:0 0 0 20px rgba(167,139,250,0);}}
#video-container{display:none;position:fixed;inset:0;z-index:500;flex-direction:column;max-width:480px;left:50%;transform:translateX(-50%);}
#video-container.open{display:flex;}
</style>

<div id="toast"></div>

</div><!-- /app -->

<script>
const ME      = <?= $user_id ?>;
const PARTNER = <?= $partner_id ?>;
const BUBBLE  = '<?= addslashes($bubble_color) ?>';

/* ── PEERJS ────────────────────────────────────────────────────────── */
const _peerRandSuffix = Math.random().toString(36).slice(2,10);
const peer = new Peer('bf_' + ME + '_' + _peerRandSuffix);
let localStream = null, currentCall = null, callTimer = null, callSeconds = 0;

// ── UI helpers de llamada ──────────────────────────────────────────
function showCallScreen(label, withVideo, incoming = false) {
  const cs = document.getElementById('call-screen');
  const ci = document.getElementById('call-incoming');
  cs.querySelector('#call-label').textContent   = label;
  cs.querySelector('#call-status').textContent  = incoming ? 'Llamada entrante…' : 'Conectando…';
  cs.querySelector('#call-timer-txt').textContent = '';
  cs.querySelector('#call-type-icon').textContent = withVideo ? '📹' : '📞';
  document.getElementById('vc-local').style.display  = withVideo ? 'block' : 'none';
  document.getElementById('vc-remote').style.display = withVideo ? 'block' : 'none';
  document.getElementById('call-audio-icon').style.display = withVideo ? 'none' : 'flex';
  if(incoming) { ci.style.display = 'flex'; cs.style.display = 'none'; }
  else         { ci.style.display = 'none'; cs.style.display = 'flex'; }
  document.getElementById('video-container').classList.add('open');
}
function setCallConnected() {
  const cs = document.getElementById('call-screen');
  cs.querySelector('#call-status').textContent = 'Conectado';
  callSeconds = 0;
  clearInterval(callTimer);
  callTimer = setInterval(() => {
    callSeconds++;
    const m = String(Math.floor(callSeconds/60)).padStart(2,'0');
    const s = String(callSeconds%60).padStart(2,'0');
    cs.querySelector('#call-timer-txt').textContent = m + ':' + s;
  }, 1000);
}
function hideCallScreen() {
  clearInterval(callTimer);
  document.getElementById('video-container').classList.remove('open');
  document.getElementById('call-screen').style.display   = 'none';
  document.getElementById('call-incoming').style.display = 'none';
}

// ── PeerJS eventos ─────────────────────────────────────────────────
peer.on('open', id => {
  const fd = new FormData(); fd.append('peer_id', id);
  fetch('ajax.php?action=update_peer', {method:'POST', body:fd});
});

peer.on('error', err => {
  hideCallScreen();
  const msgs = {
    'peer-unavailable': 'El contacto no está disponible en este momento.',
    'network':          'Error de red. Verifica tu conexión.',
    'browser-incompatible': 'Tu navegador no soporta llamadas WebRTC.'
  };
  showToast(msgs[err.type] || 'Error en la llamada: ' + err.type, 'error');
});

let incomingCall = null;
peer.on('call', call => {
  incomingCall = call;
  const withVideo = !!call.metadata?.video;
  const name = '<?= addslashes(htmlspecialchars($partner['nombre'] ?: $partner['username'])) ?>';
  showCallScreen(name, withVideo, true);
});

function answerCall() {
  if(!incomingCall) return;
  const withVideo = !!incomingCall.metadata?.video;
  const constraints = withVideo ? {video:true, audio:true} : {audio:true, video:false};
  document.getElementById('call-incoming').style.display = 'none';
  document.getElementById('call-screen').style.display   = 'flex';
  document.getElementById('call-screen').querySelector('#call-status').textContent = 'Conectando…';

  navigator.mediaDevices.getUserMedia(constraints)
    .then(stream => {
      localStream = stream;
      if(withVideo) document.getElementById('vc-local').srcObject = stream;
      incomingCall.answer(stream);
      currentCall = incomingCall;
      currentCall.on('stream', rs => {
        setCallConnected();
        if(withVideo) document.getElementById('vc-remote').srcObject = rs;
        else {
          const a = document.getElementById('call-audio-el');
          a.srcObject = rs; a.play().catch(()=>{});
        }
      });
      currentCall.on('close', () => { showToast('Llamada finalizada'); endCall(); });
      currentCall.on('error', () => { showToast('Error durante la llamada','error'); endCall(); });
    })
    .catch(err => {
      showToast('No se pudo acceder al micrófono/cámara', 'error');
      hideCallScreen();
    });
}

function rejectCall() {
  if(incomingCall) { incomingCall.close(); incomingCall = null; }
  hideCallScreen();
}

function startCall(withVideo) {
  fetch('ajax.php?action=get_partner_info')
    .then(r => r.json())
    .then(data => {
      if(!data || !data.peer_id) {
        showToast('El contacto no está disponible ahora', 'error');
        return;
      }
      const name = data.nombre || data.username || 'Contacto';
      const constraints = withVideo ? {video:true, audio:true} : {audio:true, video:false};
      navigator.mediaDevices.getUserMedia(constraints)
        .then(stream => {
          localStream = stream;
          showCallScreen(name, withVideo, false);
          if(withVideo) document.getElementById('vc-local').srcObject = stream;
          const call = peer.call(data.peer_id, stream, {metadata:{video:withVideo}});
          currentCall = call;
          call.on('stream', rs => {
            setCallConnected();
            if(withVideo) document.getElementById('vc-remote').srcObject = rs;
            else {
              const a = document.getElementById('call-audio-el');
              a.srcObject = rs; a.play().catch(()=>{});
            }
          });
          call.on('close', () => { showToast('Llamada finalizada'); endCall(); });
          call.on('error', () => { showToast('Error durante la llamada','error'); endCall(); });
          // Timeout si no responde en 30s
          setTimeout(() => {
            if(currentCall && document.getElementById('call-screen').querySelector('#call-status').textContent === 'Conectando…') {
              showToast('Sin respuesta', 'error');
              endCall();
            }
          }, 30000);
        })
        .catch(err => {
          const msg = err.name === 'NotAllowedError'
            ? 'Permiso de ' + (withVideo?'cámara/':'') + 'micrófono denegado'
            : 'No se pudo acceder al dispositivo';
          showToast(msg, 'error');
        });
    })
    .catch(() => showToast('Error de conexión', 'error'));
}

let micMuted = false, camOff = false;
function toggleMic() {
  if(!localStream) return;
  micMuted = !micMuted;
  localStream.getAudioTracks().forEach(t => t.enabled = !micMuted);
  document.getElementById('btn-mute').textContent = micMuted ? '🎙️✕' : '🎙️';
  document.getElementById('btn-mute').style.background = micMuted ? 'rgba(239,68,68,0.7)' : 'rgba(255,255,255,0.15)';
}
function toggleCam() {
  if(!localStream) return;
  camOff = !camOff;
  localStream.getVideoTracks().forEach(t => t.enabled = !camOff);
  document.getElementById('btn-cam').textContent = camOff ? '📷✕' : '📷';
  document.getElementById('btn-cam').style.background = camOff ? 'rgba(239,68,68,0.7)' : 'rgba(255,255,255,0.15)';
}

function endCall() {
  if(currentCall) { currentCall.close(); currentCall = null; }
  if(incomingCall){ incomingCall.close(); incomingCall = null; }
  if(localStream) { localStream.getTracks().forEach(t => t.stop()); localStream = null; }
  const a = document.getElementById('call-audio-el');
  if(a) { a.srcObject = null; }
  micMuted = false; camOff = false;
  hideCallScreen();
}

/* ── INFO MODAL ────────────────────────────────────────────────────── */
function openInfoModal() {
  fetch('ajax.php?action=get_partner_info').then(r=>r.json()).then(d => {
    if(!d) return;
    document.getElementById('info-avatar').src           = d.avatar_url || '';
    document.getElementById('info-nombre').textContent   = d.nombre || d.username;
    document.getElementById('info-username').textContent = '@'+d.username;
    document.getElementById('info-bio').textContent      = d.bio || 'Sin descripción';
  });
  document.getElementById('modal-info').classList.add('open');
  closeDotsMenu();
}
function closeInfoModal() { document.getElementById('modal-info').classList.remove('open'); }

/* ── DOTS MENU ─────────────────────────────────────────────────────── */
function toggleDotsMenu() { document.getElementById('dots-menu').classList.toggle('open'); }
function closeDotsMenu()  { document.getElementById('dots-menu').classList.remove('open'); }

/* ── SEARCH ────────────────────────────────────────────────────────── */
function searchBar()      { document.getElementById('search-bar').style.display='block'; document.getElementById('search-input').focus(); closeDotsMenu(); }
function closeSearchBar() { document.getElementById('search-bar').style.display='none'; document.getElementById('search-input').value=''; loadMessages(); }

/* ── REPLY ─────────────────────────────────────────────────────────── */
let replyTo = null;
function setReply(msgId, text, user) {
  replyTo = msgId;
  document.getElementById('reply-bar').style.display = 'flex';
  document.getElementById('reply-user-label').textContent = 'Respondiendo a ' + (user||'');
  document.getElementById('reply-text-preview').textContent = text;
  document.getElementById('msg-input').focus();
  closeCtxMenu();
}
function cancelReply() {
  replyTo = null;
  document.getElementById('reply-bar').style.display = 'none';
}

/* ── MENÚ CONTEXTUAL ───────────────────────────────────────────────── */
let ctxMsg = null;
function openCtxMenu(e, msg, isMe) {
  e.preventDefault(); e.stopPropagation();
  ctxMsg = msg;
  const menu = document.getElementById('ctx-menu');
  document.getElementById('ctx-delete-btn').style.display = isMe ? 'flex' : 'none';
  document.getElementById('ctx-react-text-input').value = '';
  // Actualizar label fijar
  document.getElementById('ctx-pin-label').textContent = msg.fijado ? 'Desfijar mensaje' : 'Fijar mensaje';
  menu.classList.add('open');
  const app  = document.getElementById('app');
  const rect = app.getBoundingClientRect();
  let x = (e.clientX || e.touches?.[0]?.clientX || 100) - rect.left;
  let y = (e.clientY || e.touches?.[0]?.clientY || 200) - rect.top;
  const mw = menu.offsetWidth || 200;
  const mh = menu.offsetHeight || 200;
  if(x + mw > rect.width - 8)  x = rect.width - mw - 8;
  if(y + mh > rect.height - 8) y = y - mh - 10;
  if(y < 0) y = 4;
  menu.style.left = x + 'px';
  menu.style.top  = y + 'px';
}
function closeCtxMenu() { document.getElementById('ctx-menu').classList.remove('open'); }

function ctxReact(emoji) {
  if(!ctxMsg) return;
  sendReaction(emoji, ctxMsg.id);
  closeCtxMenu();
}
function ctxReactText() {
  if(!ctxMsg) return;
  const inp  = document.getElementById('ctx-react-text-input');
  const text = inp.value.trim();
  if(!text) return;
  const fd = new FormData();
  fd.append('mensaje', '💬 ' + text);
  fd.append('reply_to', ctxMsg.id);
  fetch('ajax.php?action=send&contact_id=' + PARTNER, {method:'POST', body:fd})
    .then(r=>r.json())
    .then(d=>{ if(d.status==='ok'){ loadMessages(); showToast('Reacción enviada'); } else showToast(d.error||'Error','error'); });
  closeCtxMenu();
}
function ctxReply() {
  if(!ctxMsg) return;
  setReply(ctxMsg.id, ctxMsg.texto || '[archivo]', ctxMsg.user || 'usuario');
}
function ctxCopy() {
  if(!ctxMsg || !ctxMsg.texto) return;
  navigator.clipboard?.writeText(ctxMsg.texto).then(() => showToast('Copiado'));
  closeCtxMenu();
}
function ctxDelete() {
  if(!ctxMsg || !ctxMsg.isMe) return;
  if(!confirm('¿Eliminar este mensaje para todos?')) return;
  const fd = new FormData();
  fd.append('msg_id', ctxMsg.id);
  fetch('ajax.php?action=delete_msg', {method:'POST', body:fd})
    .then(r=>r.json())
    .then(d => {
      if(d.status==='ok') { loadMessages(); showToast('Mensaje eliminado'); }
      else showToast(d.error||'Error','error');
    });
  closeCtxMenu();
}
function ctxPin() {
  if(!ctxMsg) return;
  const fd = new FormData();
  fd.append('msg_id', ctxMsg.id);
  fetch('ajax.php?action=pin_msg', {method:'POST', body:fd})
    .then(r=>r.json())
    .then(d => {
      if(d.status==='ok') {
        showToast(d.fijado ? '📌 Mensaje fijado' : 'Mensaje desfijado');
        loadMessages();
      } else showToast(d.error||'Error','error');
    });
  closeCtxMenu();
}

/* Cerrar menús al clicar fuera */
document.addEventListener('click', e => {
  if(!e.target.closest('#ctx-menu'))     closeCtxMenu();
  if(!e.target.closest('#dots-menu') && !e.target.closest('[onclick="toggleDotsMenu()"]')) closeDotsMenu();
  if(!e.target.closest('#attach-menu') && !e.target.closest('[onclick="toggleAttachMenu()"]')) document.getElementById('attach-menu').classList.remove('open');
  if(!e.target.closest('#emoji-picker') && !e.target.closest('[onclick="toggleEmojiPicker()"]')) document.getElementById('emoji-picker').classList.remove('open');
});

/* ── REACCIONES ────────────────────────────────────────────────────── */
function sendReaction(emoji, msgId) {
  if(!msgId) return;
  const fd = new FormData();
  fd.append('msg_id', msgId);
  fd.append('emoji', emoji);
  fetch('ajax.php?action=react', {method:'POST', body:fd}).then(()=>loadMessages());
}

/* ── ADJUNTAR ──────────────────────────────────────────────────────── */
function toggleAttachMenu() {
  document.getElementById('attach-menu').classList.toggle('open');
  document.getElementById('emoji-picker').classList.remove('open');
}
function sendFile(input) {
  const file = input.files[0];
  if(!file) return;
  document.getElementById('attach-menu').classList.remove('open');
  showToast('Subiendo...');
  const fd = new FormData();
  fd.append('archivo', file);
  if(replyTo) fd.append('reply_to', replyTo);
  fetch('ajax.php?action=send&contact_id=' + PARTNER, {method:'POST', body:fd})
    .then(r=>r.json())
    .then(d=>{ if(d.status==='ok'){ loadMessages(); cancelReply(); } else showToast(d.error||'Error','error'); input.value=''; })
    .catch(()=>showToast('Error al subir','error'));
}

/* ── EMOJI PICKER ──────────────────────────────────────────────────── */
const EMOJIS = ['😀','😂','🥰','😎','🤔','😢','😡','🥳','😴','🤯','👍','👎','❤️','🔥','✨','🎉','💯','🤝','👋','🙏','🎵','💀','🦋','🌙','⭐','🌈','💪','👀','🤫','😏','🥹','💬','📸','🎮','🏆','💎','🚀','🎯','💡','🔑','🍕','😈','🫶','💔','🫠','🤌','🥺','😤','🤙'];
function buildEmojiGrid() {
  const grid = document.getElementById('emoji-grid');
  EMOJIS.forEach(em => {
    const btn = document.createElement('span');
    btn.className = 'emoji-btn'; btn.textContent = em;
    btn.onclick = () => insertEmoji(em);
    grid.appendChild(btn);
  });
}
function toggleEmojiPicker() {
  document.getElementById('emoji-picker').classList.toggle('open');
  document.getElementById('attach-menu').classList.remove('open');
}
function insertEmoji(em) {
  const inp = document.getElementById('msg-input');
  const pos = inp.selectionStart;
  inp.value = inp.value.slice(0, pos) + em + inp.value.slice(inp.selectionEnd);
  inp.focus(); inp.setSelectionRange(pos + em.length, pos + em.length);
  onInputChange(inp);
  document.getElementById('emoji-picker').classList.remove('open');
}

/* ── INPUT HANDLING ────────────────────────────────────────────────── */
let typingDebounce = null;
function onInputChange(ta) {
  ta.style.height = 'auto';
  ta.style.height = Math.min(ta.scrollHeight, 120) + 'px';
  const hasText = ta.value.trim().length > 0;
  document.getElementById('send-btn').style.display  = hasText ? 'flex' : 'none';
  document.getElementById('audio-btn').style.display = hasText ? 'none' : 'flex';
  // Notificar "escribiendo..."
  clearTimeout(typingDebounce);
  typingDebounce = setTimeout(() => {
    fetch('ajax.php?action=typing&contact_id=' + PARTNER, {method:'POST'}).catch(()=>{});
  }, 400);
}
function handleInputKeydown(e) {
  if(e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
}

/* ── AUDIO RECORDING ───────────────────────────────────────────────── */
let mediaRec = null, audioChunks = [], isRecording = false;
function toggleAudio() { isRecording ? stopRecording() : startRecording(); }
function startRecording() {
  navigator.mediaDevices.getUserMedia({audio:true}).then(stream => {
    const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus'
                   : MediaRecorder.isTypeSupported('audio/webm')             ? 'audio/webm'
                   : MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')  ? 'audio/ogg;codecs=opus'
                   : MediaRecorder.isTypeSupported('audio/mp4')              ? 'audio/mp4'
                   : '';
    const opts = mimeType ? {mimeType} : {};
    mediaRec = new MediaRecorder(stream, opts);
    audioChunks = [];
    mediaRec.ondataavailable = e => { if(e.data.size > 0) audioChunks.push(e.data); };
    mediaRec.onstop = () => {
      const ext  = mimeType.includes('ogg') ? 'ogg' : mimeType.includes('mp4') ? 'm4a' : 'webm';
      const type = mimeType || 'audio/webm';
      const blob = new Blob(audioChunks, {type});
      const file = new File([blob], 'audio_' + Date.now() + '.' + ext, {type});
      const fd   = new FormData();
      fd.append('archivo', file);
      if(replyTo) fd.append('reply_to', replyTo);
      fetch('ajax.php?action=send&contact_id=' + PARTNER, {method:'POST', body:fd})
        .then(r=>r.json())
        .then(d=>{ if(d.status==='ok'){ loadMessages(); cancelReply(); } else showToast(d.error||'Error audio','error'); })
        .catch(()=>showToast('Error al enviar audio','error'));
      stream.getTracks().forEach(t=>t.stop());
    };
    mediaRec.start(); isRecording = true;
    document.getElementById('audio-btn').classList.add('recording');
    showToast('Grabando… toca el micro para detener');
  }).catch(()=>showToast('No se pudo acceder al micrófono','error'));
}
function stopRecording() {
  if(mediaRec) mediaRec.stop();
  isRecording = false;
  document.getElementById('audio-btn').classList.remove('recording');
}

/* ── REPRODUCTOR AUDIO CUSTOM ──────────────────────────────────────── */
function createAudioPlayer(src) {
  const wrapper = document.createElement('div');
  wrapper.className = 'audio-player';

  const audio = new Audio(src);

  const playBtn = document.createElement('button');
  playBtn.className = 'audio-play-btn';
  playBtn.innerHTML = '▶';

  // Onda simulada con barras de altura variable
  const wave = document.createElement('div');
  wave.className = 'audio-wave';
  const barCount = 20;
  const bars = [];
  const heights = Array.from({length: barCount}, () => 20 + Math.floor(Math.random() * 70));
  heights.forEach(h => {
    const bar = document.createElement('div');
    bar.className = 'audio-wave-bar';
    bar.style.height = h + '%';
    wave.appendChild(bar);
    bars.push(bar);
  });

  const timeLabel = document.createElement('span');
  timeLabel.className = 'audio-time-label';
  timeLabel.textContent = '0:00';

  wrapper.appendChild(playBtn);
  wrapper.appendChild(wave);
  wrapper.appendChild(timeLabel);

  let playing = false;

  function fmt(s) {
    const m = Math.floor(s / 60);
    const sec = Math.floor(s % 60);
    return m + ':' + String(sec).padStart(2,'0');
  }

  function updateBars() {
    if(!audio.duration) return;
    const pct = audio.currentTime / audio.duration;
    const active = Math.floor(pct * barCount);
    bars.forEach((b, i) => b.classList.toggle('active', i <= active));
    timeLabel.textContent = fmt(audio.currentTime) + ' / ' + fmt(audio.duration);
  }

  audio.addEventListener('timeupdate', updateBars);
  audio.addEventListener('ended', () => {
    playing = false;
    playBtn.innerHTML = '▶';
    bars.forEach(b => b.classList.remove('active'));
    timeLabel.textContent = '0:00 / ' + fmt(audio.duration || 0);
  });
  audio.addEventListener('loadedmetadata', () => {
    timeLabel.textContent = '0:00 / ' + fmt(audio.duration);
  });

  playBtn.onclick = () => {
    if(playing) { audio.pause(); playBtn.innerHTML = '▶'; playing = false; }
    else        { audio.play(); playBtn.innerHTML = '⏸'; playing = true; }
  };

  // Click en la ola para seek
  wave.addEventListener('click', e => {
    if(!audio.duration) return;
    const rect = wave.getBoundingClientRect();
    const pct = (e.clientX - rect.left) / rect.width;
    audio.currentTime = pct * audio.duration;
    updateBars();
  });

  return wrapper;
}

/* ── SEND MESSAGE ──────────────────────────────────────────────────── */
function sendMessage() {
  const input = document.getElementById('msg-input');
  const text  = input.value.trim();
  if(!text) return;
  const fd = new FormData();
  fd.append('mensaje', text);
  if(replyTo) fd.append('reply_to', replyTo);
  input.value = ''; input.style.height = 'auto';
  onInputChange(input); cancelReply();
  fetch('ajax.php?action=send&contact_id=' + PARTNER, {method:'POST', body:fd})
    .then(r=>r.json())
    .then(data => { if(data.status==='ok') loadMessages(); else showToast(data.error||'Error','error'); })
    .catch(()=>showToast('Error al enviar','error'));
}

/* ── MENSAJE FIJADO ────────────────────────────────────────────────── */
let pinnedMsgId = null;
function updatePinnedBar(msgs) {
  const pinned = msgs.slice().reverse().find(m => m.fijado);
  const bar = document.getElementById('pinned-bar');
  if(pinned) {
    pinnedMsgId = pinned.id;
    document.getElementById('pinned-bar-text').textContent = (pinned.contenido || '[archivo]').substring(0, 60);
    bar.classList.add('visible');
  } else {
    pinnedMsgId = null;
    bar.classList.remove('visible');
  }
}
function scrollToPinned() {
  if(!pinnedMsgId) return;
  const el = document.querySelector('[data-msg-id="' + pinnedMsgId + '"]');
  if(el) el.scrollIntoView({behavior:'smooth', block:'center'});
}

/* ── LOAD MESSAGES ─────────────────────────────────────────────────── */
let lastCount = 0;
function loadMessages() {
  const searchQ = document.getElementById('search-input')?.value?.trim() || '';
  fetch('ajax.php?action=fetch&contact_id=' + PARTNER)
  .then(r => { if(!r.ok) return []; return r.json().catch(()=>[]); })
  .then(msgs => {
    if(!Array.isArray(msgs)) return;

    // Marcar como leídos automáticamente
    fetch('ajax.php?action=mark_read&contact_id=' + PARTNER, {method:'POST'}).catch(()=>{});

    let filtered = msgs;
    if(searchQ) {
      filtered = msgs.filter(m =>
        (m.contenido || '').toLowerCase().includes(searchQ.toLowerCase())
      );
    }

    // Actualizar barra de mensaje fijado
    updatePinnedBar(msgs);

    const box   = document.getElementById('chat-box');
    const atBot = box.scrollHeight - box.scrollTop - box.clientHeight < 100;
    while(box.children.length > 1) box.removeChild(box.lastChild);

    let lastDate = '';

    filtered.forEach(m => {
      const isMe = (parseInt(m.remitente_id) === ME);
      const rawDate = m.created_at || '';
      let dateLabel = '';
      if(rawDate) {
        const d = new Date(rawDate.replace(' ','T'));
        if(!isNaN(d)) {
          const hoy  = new Date(); const ayer = new Date(); ayer.setDate(ayer.getDate()-1);
          const ds   = d.toDateString();
          dateLabel  = ds === hoy.toDateString()  ? 'Hoy'
                     : ds === ayer.toDateString() ? 'Ayer'
                     : d.toLocaleDateString('es',{day:'2-digit',month:'short',year:'numeric'});
        }
      }
      if(dateLabel && dateLabel !== lastDate) {
        lastDate = dateLabel;
        const sep = document.createElement('div');
        sep.className = 'date-sep';
        sep.innerHTML = `<span>${dateLabel}</span>`;
        box.appendChild(sep);
      }

      let hora = '';
      if(rawDate) {
        const d2 = new Date(rawDate.replace(' ','T'));
        if(!isNaN(d2)) hora = d2.toLocaleTimeString('es',{hour:'2-digit',minute:'2-digit'});
      }

      const row    = document.createElement('div');
      row.className = 'msg-row ' + (isMe ? 'me' : 'them');
      const bubble = document.createElement('div');
      bubble.className = 'bubble ' + (isMe ? 'me' : 'them');
      bubble.dataset.msgId = m.id; // para scroll a fijado

      let inner = '';

      if(m.reply_texto) {
        inner += `<div class="reply-preview">
          <span style="color:${isMe?'rgba(255,255,255,0.7)':BUBBLE};font-weight:600;">${escHtml(m.reply_user||'')}</span><br>
          ${escHtml((m.reply_texto||'').substring(0,60))}${(m.reply_texto||'').length>60?'…':''}
        </div>`;
      }

      if(m.contenido) inner += `<div>${escHtml(m.contenido)}</div>`;

      function tipoByUrl(url, tipoDb) {
        if(!url) return tipoDb || '';
        const ext = (url.split('.').pop()||'').toLowerCase().split('?')[0];
        // Si la BD ya dice 'audio', respetarlo siempre (webm puede ser audio o video)
        if(tipoDb === 'audio') return 'audio';
        if(['jpg','jpeg','png','webp','gif'].includes(ext)) return 'imagen';
        if(['mp4','mov'].includes(ext))                     return 'video';
        if(['mp3','ogg','m4a'].includes(ext))               return 'audio';
        // webm: solo es video si BD lo dice explícitamente, si no asumir audio (grabaciones del micro)
        if(ext === 'webm') return (tipoDb === 'video') ? 'video' : 'audio';
        return 'archivo';
      }
      const tipo = tipoByUrl(m.archivo_url, m.tipo);

      if(tipo==='imagen'||tipo==='image') {
        inner += `<img src="${escAttr(m.archivo_url)}" style="max-width:100%;border-radius:10px;margin-top:6px;cursor:pointer;display:block;" onclick="window.open('${escAttr(m.archivo_url)}')">`;
      } else if(tipo==='video') {
        inner += `<video src="${escAttr(m.archivo_url)}" controls style="max-width:100%;border-radius:10px;margin-top:6px;display:block;"></video>`;
      } else if(tipo==='archivo'||tipo==='documento') {
        const fname = (m.archivo_url||'').split('/').pop();
        inner += `<a href="${escAttr(m.archivo_url)}" target="_blank" rel="noopener" style="color:#a78bfa;font-size:12px;margin-top:4px;display:flex;align-items:center;gap:4px;text-decoration:none;">📎 ${escHtml(fname)}</a>`;
      }

      // Timestamp + doble check
      if(hora) {
        let checkHtml = '';
        if(isMe) {
          if(m.leido) {
            checkHtml = `<span style="font-size:11px;margin-left:4px;color:#5bc8f5;font-weight:700;" title="Leído">✓✓</span>`;
          } else {
            checkHtml = `<span style="font-size:11px;margin-left:4px;color:rgba(255,255,255,0.5);" title="Enviado">✓</span>`;
          }
        }
        inner += `<span class="msg-time" style="text-align:${isMe?'right':'left'};">${hora}${checkHtml}</span>`;
      }

      // Indicador de mensaje fijado dentro de la burbuja
      if(m.fijado) {
        inner += `<span style="font-size:10px;opacity:0.5;margin-top:2px;display:block;text-align:${isMe?'right':'left'};">📌 fijado</span>`;
      }

      bubble.innerHTML = inner;

      // Insertar reproductor audio custom (reemplaza el <audio> básico)
      if(tipo === 'audio') {
        bubble.appendChild(createAudioPlayer(m.archivo_url || ''));
      }

      /* Long press / context menu */
      const msgData = {id: m.id, texto: m.contenido||'', user: isMe?'yo':(m.reply_user||'usuario'), isMe, fijado: m.fijado||0};
      let pressTimer;
      bubble.addEventListener('touchstart', e => { pressTimer = setTimeout(() => openCtxMenu(e.touches[0], msgData, isMe), 480); }, {passive:true});
      bubble.addEventListener('touchend',   () => clearTimeout(pressTimer));
      bubble.addEventListener('touchmove',  () => clearTimeout(pressTimer));
      bubble.addEventListener('contextmenu', e => openCtxMenu(e, msgData, isMe));
      bubble.addEventListener('dblclick',    () => setReply(m.id, m.contenido||'[archivo]', msgData.user));

      row.appendChild(bubble);
      box.appendChild(row);

      /* Reacciones */
      if(m.reacciones && m.reacciones.length) {
        const rRow = document.createElement('div');
        rRow.className = 'reactions-row';
        rRow.style.justifyContent = isMe ? 'flex-end' : 'flex-start';
        m.reacciones.forEach(r => {
          const chip = document.createElement('span');
          chip.className = 'reaction-chip' + (m.my_reaction===r.emoji?' mine':'');
          chip.textContent = r.emoji + (r.total>1 ? ' '+r.total : '');
          chip.onclick = () => sendReaction(m.my_reaction===r.emoji ? 'remove' : r.emoji, m.id);
          rRow.appendChild(chip);
        });
        box.appendChild(rRow);
      }
    });

    if(atBot || filtered.length !== lastCount) {
      box.scrollTop = box.scrollHeight;
      lastCount = filtered.length;
    }
  }).catch(()=>{});
}

/* ── INDICADOR ESCRIBIENDO ─────────────────────────────────────────── */
let typingVisible = false;
function checkTyping() {
  fetch('ajax.php?action=check_typing&contact_id=' + PARTNER)
    .then(r=>r.json())
    .then(d => {
      const ind  = document.getElementById('typing-indicator');
      const sub  = document.getElementById('partner-username');
      if(d.typing) {
        ind.style.display = 'block';
        sub.style.display = 'none';
        typingVisible = true;
      } else {
        ind.style.display = 'none';
        sub.style.display = 'block';
        typingVisible = false;
      }
    }).catch(()=>{});
}

/* ── BLOCK / REPORT / CLEAR ────────────────────────────────────────── */
function blockUser() {
  if(!confirm('¿Bloquear a este usuario?')) return;
  fetch('ajax.php?action=block', {method:'POST'}).then(()=>{
    showToast('Usuario bloqueado');
    setTimeout(()=>window.location.href='home.php', 1500);
  });
  closeDotsMenu();
}
function reportUser() { showToast('Reporte enviado. Gracias.'); closeDotsMenu(); }
function clearChat() {
  if(!confirm('¿Limpiar la vista del chat?')) return;
  const box = document.getElementById('chat-box');
  while(box.children.length > 1) box.removeChild(box.lastChild);
  closeDotsMenu();
}

/* ── TOAST ─────────────────────────────────────────────────────────── */
function showToast(msg, type='ok') {
  const t = document.getElementById('toast');
  t.textContent = msg; t.style.background = type==='error' ? '#ef4444' : '#22c55e';
  t.style.opacity = '1'; clearTimeout(t._timer);
  t._timer = setTimeout(()=>t.style.opacity='0', 2800);
}

/* ── HELPERS ───────────────────────────────────────────────────────── */
function escHtml(s) {
  if(!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escAttr(s) {
  if(!s) return '';
  if(!/^(uploads\/|https?:\/\/)/.test(s)) return '';
  return escHtml(s);
}

/* ── INIT ──────────────────────────────────────────────────────────── */
buildEmojiGrid();
loadMessages();
setInterval(loadMessages, 3000);
setInterval(checkTyping, 2000);
</script>
</body>
</html>