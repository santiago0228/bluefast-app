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

$bg_style = 'background:#0B0B0F;';
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

/* ── FIX 1: Layout tipo móvil centrado, nunca ancho completo ────────── */
html,body{
  height:100%;
  width:100%;
  background:#000;
  display:flex;
  justify-content:center;    /* centra horizontalmente */
  align-items:stretch;
}
#app{
  width:100%;
  max-width:480px;           /* máximo ancho de celular */
  height:100dvh;
  display:flex;
  flex-direction:column;
  overflow:hidden;
  background:#0B0B0F;
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
  color:#e5e7eb;
  position:relative;
}

/* ── SCROLLBAR ──────────────────────────────────────────────────────── */
#chat-box::-webkit-scrollbar{width:3px;}
#chat-box::-webkit-scrollbar-thumb{background:#333;border-radius:10px;}

/* ── FIX 2: BURBUJAS izquierda/derecha ─────────────────────────────── */
.msg-row{
  display:flex;
  margin-bottom:3px;
  padding:0 8px;
}
.msg-row.me{
  justify-content:flex-end;   /* mis mensajes → derecha */
}
.msg-row.them{
  justify-content:flex-start; /* mensajes recibidos → izquierda */
}
.bubble{
  max-width:78%;
  padding:9px 13px;
  font-size:14px;
  line-height:1.5;
  position:relative;
  word-break:break-word;
}
.bubble.me{
  background:var(--bubble);
  color:#fff;
  border-radius:18px 18px 4px 18px;
}
.bubble.them{
  background:#1e1e28;
  color:#e5e7eb;
  border-radius:18px 18px 18px 4px;
  border:1px solid #2a2a38;
}

/* ── CONTEXTO MENÚ (borrar/responder/reaccionar) ───────────────────── */
.ctx-menu{
  display:none;
  position:fixed;
  background:#1a1a26;
  border:1px solid #2a2a38;
  border-radius:16px;
  padding:6px;
  z-index:500;
  min-width:180px;
  box-shadow:0 8px 32px rgba(0,0,0,0.8);
  flex-direction:column;
  gap:2px;
}
.ctx-menu.open{display:flex;}
.ctx-item{
  display:flex;
  align-items:center;
  gap:10px;
  padding:10px 12px;
  font-size:13px;
  cursor:pointer;
  border-radius:10px;
  color:#e5e7eb;
  transition:background .12s;
}
.ctx-item:hover{background:#2a2a38;}
.ctx-item.danger{color:#f87171;}

/* ── REACTION PICKER (sobre menú contexto) ─────────────────────────── */
.react-row{
  display:flex;
  gap:4px;
  padding:8px 10px;
  border-bottom:1px solid #2a2a38;
  justify-content:space-around;
}
.react-opt{font-size:22px;cursor:pointer;padding:3px 4px;border-radius:8px;transition:transform .12s;}
.react-opt:hover{transform:scale(1.3);}

/* ── REACTION CHIPS EN BURBUJA ─────────────────────────────────────── */
.reactions-row{display:flex;gap:3px;flex-wrap:wrap;margin-top:4px;padding:0 8px;}
.reaction-chip{
  background:rgba(255,255,255,0.1);
  border-radius:20px;
  padding:2px 7px;
  font-size:12px;
  cursor:pointer;
  border:1px solid transparent;
  transition:background .12s;
}
.reaction-chip.mine{border-color:var(--bubble);}
.reaction-chip:hover{background:rgba(255,255,255,0.18);}

/* ── REPLY PREVIEW DENTRO DE BURBUJA ───────────────────────────────── */
.reply-preview{
  border-left:3px solid rgba(255,255,255,0.4);
  padding:4px 8px;
  border-radius:4px;
  margin-bottom:6px;
  background:rgba(0,0,0,0.2);
  font-size:11px;
  line-height:1.3;
}
.bubble.them .reply-preview{border-left-color:var(--bubble);}

/* ── REPLY BAR ─────────────────────────────────────────────────────── */
#reply-bar{
  display:none;
  align-items:center;
  gap:8px;
  padding:8px 12px;
  background:#161620;
  border-top:1px solid #2a2a38;
  flex-shrink:0;
}

/* ── TIMESTAMP ──────────────────────────────────────────────────────── */
.msg-time{font-size:10px;opacity:0.5;margin-top:3px;display:block;}

/* ── HEADER ─────────────────────────────────────────────────────────── */
#app-header{
  background:#111118;
  border-bottom:1px solid #1e1e28;
  padding:10px 12px;
  display:flex;
  align-items:center;
  gap:10px;
  flex-shrink:0;
  position:relative;
}
.icon-btn{
  width:38px;height:38px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;flex-shrink:0;
  transition:background .15s;
  background:transparent;border:none;color:#aaa;
}
.icon-btn:hover{background:#2a2a38;}
.icon-btn svg{width:21px;height:21px;}

/* ── FIX 3 y 4: BARRA DE INPUT ─────────────────────────────────────── */
#input-bar{
  padding:8px 10px;
  background:#111118;
  border-top:1px solid #1e1e28;
  display:flex;
  align-items:flex-end;  /* alinea al fondo para textarea multilinea */
  gap:6px;
  flex-shrink:0;
  position:relative;
}
#msg-input{
  flex:1;
  background:#1e1e28;
  border:1px solid #2a2a38;
  border-radius:22px;
  padding:10px 16px;
  font-size:14px;
  color:#fff;
  outline:none;
  resize:none;
  max-height:120px;
  line-height:1.4;
  font-family:inherit;
}
#msg-input::placeholder{color:#555;}

/* FIX 3: Botón enviar con avión apuntando ARRIBA ───────────────────── */
#send-btn{
  background:var(--bubble);
  width:40px;height:40px;
  border-radius:50%;
  display:none;           /* oculto hasta que haya texto */
  align-items:center;
  justify-content:center;
  cursor:pointer;
  flex-shrink:0;
  border:none;
  transition:opacity .15s;
}
#send-btn:hover{opacity:.85;}
#send-btn svg{width:20px;height:20px;}

/* FIX 4: Botón audio (visible cuando no hay texto, REEMPLAZA emoji) ── */
/* El emoji picker se mueve al menú + adjuntar, no junto al input */
#audio-btn{color:#aaa;}
#audio-btn.recording{color:#ef4444;animation:pulse 1s infinite;}
@keyframes pulse{0%,100%{opacity:1;}50%{opacity:.5;}}

/* ── MENÚ ADJUNTAR ──────────────────────────────────────────────────── */
#attach-menu{
  display:none;
  position:absolute;
  bottom:64px;
  left:10px;
  background:#1a1a26;
  border:1px solid #2a2a38;
  border-radius:16px;
  padding:8px;
  gap:4px;
  flex-direction:column;
  z-index:200;
  box-shadow:0 8px 32px rgba(0,0,0,0.6);
  min-width:190px;
}
#attach-menu.open{display:flex;}
.attach-btn{
  display:flex;align-items:center;gap:10px;
  padding:10px 14px;border-radius:12px;
  cursor:pointer;font-size:13px;font-weight:500;
  transition:background .15s;color:#e5e7eb;
}
.attach-btn:hover{background:#2a2a38;}

/* ── EMOJI PICKER (dentro del menú adjuntar) ────────────────────────── */
#emoji-picker{
  display:none;
  position:absolute;
  bottom:64px;
  left:10px;
  background:#1a1a26;
  border:1px solid #2a2a38;
  border-radius:16px;
  padding:12px;
  z-index:200;
  box-shadow:0 8px 32px rgba(0,0,0,0.6);
  width:270px;
}
#emoji-picker.open{display:block;}
.emoji-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;}
.emoji-btn{font-size:22px;padding:4px;border-radius:8px;cursor:pointer;text-align:center;transition:background .1s;}
.emoji-btn:hover{background:#2a2a38;}

/* ── TRES PUNTOS MENÚ ───────────────────────────────────────────────── */
#dots-menu{
  display:none;
  position:absolute;
  top:56px;right:10px;
  background:#1a1a26;
  border:1px solid #2a2a38;
  border-radius:14px;
  padding:8px;
  z-index:300;
  min-width:190px;
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

/* ── MODAL INFO CONTACTO ────────────────────────────────────────────── */
#modal-info{
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,0.85);
  backdrop-filter:blur(6px);
  z-index:400;
  align-items:flex-end;justify-content:center;
}
#modal-info.open{display:flex;}
.modal-sheet{
  width:100%;max-width:480px;
  background:#111118;
  border-radius:24px 24px 0 0;
  padding:24px;
  max-height:80vh;overflow-y:auto;
}

/* ── VIDEO CONTAINER ────────────────────────────────────────────────── */
#video-container{
  display:none;position:fixed;inset:0;
  background:#000;z-index:500;flex-direction:column;
}
#video-container.open{display:flex;}

/* ── TOAST ──────────────────────────────────────────────────────────── */
#toast{
  position:fixed;top:20px;left:50%;transform:translateX(-50%);
  background:#22c55e;color:#fff;padding:10px 20px;
  border-radius:12px;font-size:13px;z-index:9999;
  opacity:0;transition:opacity .3s;pointer-events:none;
  white-space:nowrap;
}
#toast.error{background:#ef4444;}

/* ── BARRA BÚSQUEDA ─────────────────────────────────────────────────── */
#search-bar{display:none;padding:8px 12px;background:#111118;border-bottom:1px solid #1e1e28;flex-shrink:0;}

/* ── SEPARADOR DE FECHA ─────────────────────────────────────────────── */
.date-sep{
  text-align:center;margin:8px 0;
}
.date-sep span{
  background:#1a1a26;color:#666;
  font-size:11px;padding:3px 10px;border-radius:10px;
}
</style>
</head>
<body>

<!-- ═══ CONTENEDOR PRINCIPAL (max 480px centrado) ═════════════════════ -->
<div id="app">

<!-- ═══ HEADER ════════════════════════════════════════════════════════ -->
<header id="app-header">
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
      <div style="font-size:11px;color:#555;">@<?= htmlspecialchars($partner['username']) ?></div>
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
      Limpiar chat
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
  <!-- Badge E2E -->
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

  <!-- Menú adjuntar + emoji -->
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

  <!-- Emoji grid completo -->
  <div id="emoji-picker">
    <div style="font-size:11px;color:#666;margin-bottom:8px;font-weight:600;letter-spacing:.05em;">EMOJIS</div>
    <div class="emoji-grid" id="emoji-grid"></div>
  </div>

  <!-- Botón + (adjuntar) -->
  <button onclick="toggleAttachMenu()" class="icon-btn" style="flex-shrink:0;" aria-label="Adjuntar">
    <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M12 4v16m8-8H4"/></svg>
  </button>

  <!-- Input de texto -->
  <textarea id="msg-input" rows="1" placeholder="Mensaje..."
            oninput="onInputChange(this)"></textarea>

  <!-- FIX 4: Solo botón audio al lado del input (sin emoji a la derecha) -->
  <button id="audio-btn" onclick="toggleAudio()" class="icon-btn" style="flex-shrink:0;" aria-label="Audio">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 016 0v6a3 3 0 01-3 3z"/></svg>
  </button>

  <!-- FIX 3: Botón enviar con avión apuntando hacia ARRIBA -->
  <button id="send-btn" onclick="sendMessage()" aria-label="Enviar">
    <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
      <!-- Avión de papel apuntando arriba -->
      <line x1="12" y1="19" x2="12" y2="5"/>
      <polyline points="5 12 12 5 19 12"/>
    </svg>
  </button>
</div>

<!-- ═══ MENÚ CONTEXTUAL (borrar / responder / reaccionar) ════════════ -->
<div class="ctx-menu" id="ctx-menu">
  <!-- Reacciones rápidas -->
  <div class="react-row">
    <span class="react-opt" onclick="ctxReact('❤️')">❤️</span>
    <span class="react-opt" onclick="ctxReact('😂')">😂</span>
    <span class="react-opt" onclick="ctxReact('😮')">😮</span>
    <span class="react-opt" onclick="ctxReact('😢')">😢</span>
    <span class="react-opt" onclick="ctxReact('👍')">👍</span>
    <span class="react-opt" onclick="ctxReact('🔥')">🔥</span>
  </div>
  <div class="ctx-item" onclick="ctxReply()">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;flex-shrink:0;"><path d="M3 10h11a4 4 0 010 8h-1m-9-8L7 6m-4 4l4 4"/></svg>
    Responder
  </div>
  <div class="ctx-item" id="ctx-delete-btn" onclick="ctxDelete()">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;flex-shrink:0;color:#f87171;"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
    <span style="color:#f87171;">Eliminar</span>
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

<!-- ═══ VIDEO CONTAINER ═══════════════════════════════════════════════ -->
<div id="video-container">
  <video id="remoteVideo" autoplay playsinline style="width:100%;height:100%;object-fit:cover;background:#000;"></video>
  <video id="localVideo"  autoplay muted playsinline style="position:absolute;top:16px;right:16px;width:100px;height:140px;object-fit:cover;border-radius:14px;border:2px solid #fff;"></video>
  <div style="position:absolute;bottom:0;left:0;right:0;padding:32px;display:flex;justify-content:center;background:linear-gradient(transparent,rgba(0,0,0,0.7));">
    <button onclick="endCall()" style="width:60px;height:60px;border-radius:50%;background:#ef4444;display:flex;align-items:center;justify-content:center;border:none;cursor:pointer;">
      <svg fill="none" stroke="#fff" stroke-width="2.5" viewBox="0 0 24 24" style="width:26px;height:26px;"><path d="M16 8l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2M5 3a16.003 16.003 0 0114 0M1 7a20.005 20.005 0 0122 0"/></svg>
    </button>
  </div>
</div>

<!-- ═══ TOAST ═════════════════════════════════════════════════════════ -->
<div id="toast"></div>

</div><!-- /app -->

<script>
/* ── CONSTANTES ────────────────────────────────────────────────────── */
const ME      = <?= $user_id ?>;
const PARTNER = <?= $partner_id ?>;
const BUBBLE  = '<?= addslashes($bubble_color) ?>';

/* ── PEERJS ────────────────────────────────────────────────────────── */
const _peerRandSuffix = Math.random().toString(36).slice(2,10);
const peer = new Peer('bf_' + ME + '_' + _peerRandSuffix);
let localStream, currentCall;

peer.on('open', id => {
  const fd = new FormData();
  fd.append('peer_id', id);
  fetch('ajax.php?action=update_peer', {method:'POST',body:fd});
});
peer.on('call', call => {
  const tipo = call.metadata?.video ? 'videollamada' : 'llamada';
  if(confirm(`📞 ${tipo} entrante. ¿Responder?`)) {
    const c = call.metadata?.video ? {video:true,audio:true} : {video:false,audio:true};
    navigator.mediaDevices.getUserMedia(c).then(stream => {
      localStream = stream;
      document.getElementById('localVideo').srcObject = stream;
      document.getElementById('video-container').classList.add('open');
      call.answer(stream);
      currentCall = call;
      call.on('stream', rs => document.getElementById('remoteVideo').srcObject = rs);
    });
  }
});
function startCall(withVideo) {
  fetch(`ajax.php?action=get_partner_info`)
  .then(r=>r.json()).then(data => {
    if(!data || !data.peer_id) { showToast('El contacto no está disponible','error'); return; }
    const c = {video:withVideo,audio:true};
    navigator.mediaDevices.getUserMedia(c).then(stream => {
      localStream = stream;
      document.getElementById('localVideo').srcObject = stream;
      document.getElementById('video-container').classList.add('open');
      const call = peer.call(data.peer_id, stream, {metadata:{video:withVideo}});
      currentCall = call;
      call.on('stream', rs => document.getElementById('remoteVideo').srcObject = rs);
    });
  });
}
function endCall() {
  if(currentCall) currentCall.close();
  if(localStream) localStream.getTracks().forEach(t=>t.stop());
  document.getElementById('video-container').classList.remove('open');
}

/* ── INFO MODAL ────────────────────────────────────────────────────── */
function openInfoModal() {
  fetch('ajax.php?action=get_partner_info')
  .then(r=>r.json()).then(d => {
    if(!d) return;
    document.getElementById('info-avatar').src              = d.avatar_url || '';
    document.getElementById('info-nombre').textContent      = d.nombre || d.username;
    document.getElementById('info-username').textContent    = '@'+d.username;
    document.getElementById('info-bio').textContent         = d.bio || 'Sin descripción';
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

/* ── MENÚ CONTEXTUAL (clic derecho / long press) ────────────────────
   Guarda el mensaje activo para borrar/responder/reaccionar            */
let ctxMsg = null; // {id, texto, isMe}
function openCtxMenu(e, msg, isMe) {
  e.preventDefault();
  e.stopPropagation();
  ctxMsg = msg;
  const menu = document.getElementById('ctx-menu');
  // Mostrar/ocultar opción eliminar solo en mis mensajes
  document.getElementById('ctx-delete-btn').style.display = isMe ? 'flex' : 'none';
  menu.classList.add('open');
  // Posicionar dentro del #app
  const app = document.getElementById('app');
  const rect = app.getBoundingClientRect();
  let x = (e.clientX || e.touches?.[0]?.clientX || 100) - rect.left;
  let y = (e.clientY || e.touches?.[0]?.clientY || 200) - rect.top;
  const mw = menu.offsetWidth || 190;
  const mh = menu.offsetHeight || 180;
  if(x + mw > rect.width - 8)  x = rect.width - mw - 8;
  if(y + mh > rect.height - 8) y = y - mh - 10;
  if(y < 0) y = 4;
  menu.style.left = x + 'px';
  menu.style.top  = y + 'px';
}
function closeCtxMenu() {
  document.getElementById('ctx-menu').classList.remove('open');
}
function ctxReact(emoji) {
  if(!ctxMsg) return;
  sendReaction(emoji, ctxMsg.id);
  closeCtxMenu();
}
function ctxReply() {
  if(!ctxMsg) return;
  setReply(ctxMsg.id, ctxMsg.texto || '[archivo]', ctxMsg.user || 'usuario');
}
function ctxDelete() {
  if(!ctxMsg || !ctxMsg.isMe) return;
  if(!confirm('¿Eliminar este mensaje?')) return;
  const fd = new FormData();
  fd.append('msg_id', ctxMsg.id);
  fetch('ajax.php?action=delete_msg', {method:'POST', body:fd})
    .then(()=>{ loadMessages(); showToast('Mensaje eliminado'); });
  closeCtxMenu();
}

/* Cerrar menú al clicar fuera */
document.addEventListener('click', e => {
  if(!e.target.closest('#ctx-menu')) closeCtxMenu();
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
    btn.className = 'emoji-btn';
    btn.textContent = em;
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
  inp.focus();
  inp.setSelectionRange(pos + em.length, pos + em.length);
  onInputChange(inp);
  document.getElementById('emoji-picker').classList.remove('open');
}

/* ── INPUT HANDLING ────────────────────────────────────────────────── */
function onInputChange(ta) {
  ta.style.height = 'auto';
  ta.style.height = Math.min(ta.scrollHeight, 120) + 'px';
  const hasText = ta.value.trim().length > 0;
  document.getElementById('send-btn').style.display  = hasText ? 'flex' : 'none';
  document.getElementById('audio-btn').style.display = hasText ? 'none' : 'flex';
}
document.getElementById('msg-input').addEventListener('keydown', e => {
  if(e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});

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
    mediaRec.start();
    isRecording = true;
    document.getElementById('audio-btn').classList.add('recording');
    showToast('Grabando… toca el micro para detener');
  }).catch(()=>showToast('No se pudo acceder al micrófono','error'));
}
function stopRecording() {
  if(mediaRec) mediaRec.stop();
  isRecording = false;
  document.getElementById('audio-btn').classList.remove('recording');
}

/* ── SEND MESSAGE ──────────────────────────────────────────────────── */
function sendMessage() {
  const input = document.getElementById('msg-input');
  const text  = input.value.trim();
  if(!text) return;
  const fd = new FormData();
  fd.append('mensaje', text);
  if(replyTo) fd.append('reply_to', replyTo);
  input.value = '';
  input.style.height = 'auto';
  onInputChange(input);
  cancelReply();
  fetch('ajax.php?action=send&contact_id=' + PARTNER, {method:'POST', body:fd})
    .then(r=>r.json())
    .then(data => { if(data.status==='ok') loadMessages(); else showToast(data.error||'Error','error'); })
    .catch(()=>showToast('Error al enviar','error'));
}

/* ── LOAD MESSAGES ─────────────────────────────────────────────────── */
let lastCount = 0;
function loadMessages() {
  fetch('ajax.php?action=fetch&contact_id=' + PARTNER)
  .then(r => {
    if(!r.ok) return [];
    return r.json().catch(()=>[]);
  })
  .then(msgs => {
    if(!Array.isArray(msgs)) return;
    const box    = document.getElementById('chat-box');
    const atBot  = box.scrollHeight - box.scrollTop - box.clientHeight < 100;

    /* Limpiar excepto badge E2E */
    while(box.children.length > 1) box.removeChild(box.lastChild);

    let lastDate = '';

    msgs.forEach(m => {
      const isMe = (parseInt(m.remitente_id) === ME);

      /* ── Separador de fecha ─────────────────────────── */
      const rawDate = m.created_at || '';
      let dateLabel = '';
      if(rawDate) {
        const d = new Date(rawDate.replace(' ','T'));
        if(!isNaN(d)) {
          const hoy   = new Date();
          const ayer  = new Date(); ayer.setDate(ayer.getDate()-1);
          const ds    = d.toDateString();
          dateLabel   = ds === hoy.toDateString()  ? 'Hoy'
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

      /* ── Hora ──────────────────────────────────────── */
      let hora = '';
      if(rawDate) {
        const d2 = new Date(rawDate.replace(' ','T'));
        if(!isNaN(d2)) hora = d2.toLocaleTimeString('es',{hour:'2-digit',minute:'2-digit'});
      }

      /* ── Fila ──────────────────────────────────────── */
      const row = document.createElement('div');
      row.className = 'msg-row ' + (isMe ? 'me' : 'them');

      /* ── Burbuja ───────────────────────────────────── */
      const bubble = document.createElement('div');
      bubble.className = 'bubble ' + (isMe ? 'me' : 'them');

      let inner = '';

      /* Reply preview */
      if(m.reply_texto) {
        inner += `<div class="reply-preview">
          <span style="color:${isMe?'rgba(255,255,255,0.7)':BUBBLE};font-weight:600;">${escHtml(m.reply_user||'')}</span><br>
          ${escHtml((m.reply_texto||'').substring(0,60))}${(m.reply_texto||'').length>60?'…':''}
        </div>`;
      }

      /* Contenido texto */
      if(m.contenido) inner += `<div>${escHtml(m.contenido)}</div>`;

      /* Archivos */
      const tipo = m.tipo || '';
      if(tipo==='imagen' || tipo==='image') {
        inner += `<img src="${escAttr(m.archivo_url)}" style="max-width:100%;border-radius:10px;margin-top:6px;cursor:pointer;display:block;" onclick="window.open('${escAttr(m.archivo_url)}')">`;
      } else if(tipo==='video') {
        inner += `<video src="${escAttr(m.archivo_url)}" controls style="max-width:100%;border-radius:10px;margin-top:6px;display:block;"></video>`;
      } else if(tipo==='audio') {
        inner += `<audio src="${escAttr(m.archivo_url)}" controls style="margin-top:6px;width:100%;max-width:220px;display:block;"></audio>`;
      } else if(tipo==='archivo' || tipo==='documento') {
        const fname = (m.archivo_url||'').split('/').pop();
        inner += `<a href="${escAttr(m.archivo_url)}" target="_blank" rel="noopener" style="color:#a78bfa;font-size:12px;margin-top:4px;display:flex;align-items:center;gap:4px;text-decoration:none;">📎 ${escHtml(fname)}</a>`;
      }

      /* Timestamp */
      if(hora) inner += `<span class="msg-time" style="text-align:${isMe?'right':'left'};">${hora}</span>`;

      bubble.innerHTML = inner;

      /* ── Long press / clic derecho → menú contextual ─ */
      const msgData = {
        id:    m.id,
        texto: m.contenido || '',
        user:  isMe ? 'yo' : (m.emisor_name || 'usuario'),
        isMe:  isMe
      };
      let pressTimer;
      bubble.addEventListener('touchstart', e => {
        pressTimer = setTimeout(() => openCtxMenu(e.touches[0], msgData, isMe), 480);
      }, {passive:true});
      bubble.addEventListener('touchend',   () => clearTimeout(pressTimer));
      bubble.addEventListener('touchmove',  () => clearTimeout(pressTimer));
      bubble.addEventListener('contextmenu', e => openCtxMenu(e, msgData, isMe));
      /* Doble toque / doble clic → responder rápido */
      bubble.addEventListener('dblclick', () => setReply(m.id, m.contenido||'[archivo]', msgData.user));

      row.appendChild(bubble);
      box.appendChild(row);

      /* ── Reacciones debajo de la burbuja ──────────── */
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

    if(atBot || msgs.length !== lastCount) {
      box.scrollTop = box.scrollHeight;
      lastCount = msgs.length;
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
  t.textContent = msg;
  t.style.background = type==='error' ? '#ef4444' : '#22c55e';
  t.style.opacity = '1';
  clearTimeout(t._timer);
  t._timer = setTimeout(()=>t.style.opacity='0', 2800);
}

/* ── HELPERS ───────────────────────────────────────────────────────── */
function escHtml(s) {
  if(!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escAttr(s) {
  if(!s) return '';
  // Solo permite rutas relativas o https para evitar XSS en src
  if(!/^(uploads\/|https?:\/\/)/.test(s)) return '';
  return escHtml(s);
}

/* ── INIT ──────────────────────────────────────────────────────────── */
buildEmojiGrid();
loadMessages();
setInterval(loadMessages, 3000);
</script>
</body>
</html>