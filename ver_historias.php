<?php
require 'config.php';
verificar_sesion();

if (!isset($_GET['username'])) {
    header('Location: home.php');
    exit;
}

$username   = $_GET['username'];
$viewer_id  = (int)$_SESSION['user_id'];

// ── Solo permite ver si son contactos mutuos (o el mismo usuario) ────────
$stmt = $pdo->prepare('SELECT id FROM usuarios WHERE username = ? LIMIT 1');
$stmt->execute([$username]);
$story_owner = $stmt->fetchColumn();

if (!$story_owner) { header('Location: home.php'); exit; }

$story_owner = (int)$story_owner;

if ($story_owner !== $viewer_id) {
    $chk = $pdo->prepare('
        SELECT id FROM contactos
        WHERE (usuario_id = ? AND contacto_id = ?)
           OR (usuario_id = ? AND contacto_id = ?)
        LIMIT 1
    ');
    $chk->execute([$viewer_id, $story_owner, $story_owner, $viewer_id]);
    if (!$chk->fetch()) {
        header('Location: home.php');
        exit;
    }
}

// ── Historias activas (48h) de este usuario ──────────────────────────────
$stmt = $pdo->prepare('
    SELECT h.*, u.nombre, u.avatar_url
    FROM historias h
    JOIN usuarios u ON h.usuario_id = u.id
    WHERE u.username = ?
      AND h.created_at >= NOW() - INTERVAL 48 HOUR
    ORDER BY h.created_at ASC
');
$stmt->execute([$username]);
$user_stories = $stmt->fetchAll();

if (empty($user_stories)) {
    header('Location: home.php');
    exit;
}

$is_own = ($story_owner === $viewer_id);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Historias de @<?= htmlspecialchars($username) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css">
    <style>
        *{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent;}
        body{background:#000;font-family:-apple-system,BlinkMacSystemFont,sans-serif;touch-action:pan-y;}
        .story-container{max-width:430px;width:100%;height:100dvh;position:relative;overflow:hidden;background:#000;}

        /* Barras de progreso */
        .progress-bar{height:3px;background:rgba(255,255,255,0.3);flex:1;margin:0 3px;border-radius:2px;overflow:hidden;}
        .progress-fill{height:100%;background:#fff;width:0%;transition:width 5s linear;}

        /* Zonas tap izq/der */
        .tap-zone{position:absolute;top:0;bottom:0;width:35%;z-index:10;cursor:pointer;}
        #tap-prev{left:0;}
        #tap-next{right:0;}

        /* Panel de reacción / respuesta */
        #reaction-bar{
            position:absolute;bottom:0;left:0;right:0;z-index:30;
            display:flex;align-items:center;gap:8px;
            padding:14px 12px 28px;
            background:linear-gradient(transparent, rgba(0,0,0,0.85));
        }
        #story-reply-input{
            flex:1;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.3);
            border-radius:24px;padding:10px 16px;color:#fff;font-size:14px;outline:none;
        }
        #story-reply-input::placeholder{color:rgba(255,255,255,0.6);}
        .heart-btn{
            font-size:28px;cursor:pointer;flex-shrink:0;
            transition:transform .15s;user-select:none;
        }
        .heart-btn:active{transform:scale(1.35);}
        .send-story-reply{
            background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.3);
            border-radius:50%;width:38px;height:38px;display:flex;align-items:center;
            justify-content:center;cursor:pointer;flex-shrink:0;
        }

        /* Tiempo restante */
        #story-timer{
            position:absolute;bottom:76px;left:0;right:0;z-index:25;
            text-align:center;pointer-events:none;
        }
        #story-timer span{
            background:rgba(0,0,0,0.55);color:rgba(255,255,255,0.8);
            font-size:11px;padding:3px 10px;border-radius:10px;
        }

        /* Toast */
        #story-toast{
            position:fixed;top:20px;left:50%;transform:translateX(-50%);
            background:#22c55e;color:#fff;padding:9px 18px;border-radius:12px;
            font-size:13px;z-index:9999;opacity:0;transition:opacity .3s;pointer-events:none;
            white-space:nowrap;
        }

        /* Botón eliminar */
        #delete-story-btn{
            position:absolute;top:72px;right:14px;z-index:25;
            background:rgba(239,68,68,0.85);color:#fff;border:none;
            border-radius:10px;padding:7px 12px;font-size:12px;cursor:pointer;
            display:none;
        }

        /* Contador de vistas */
        #views-counter{
            position:absolute;top:72px;left:14px;z-index:25;
            display:none;align-items:center;gap:5px;
            background:rgba(0,0,0,0.55);border-radius:20px;
            padding:5px 10px;cursor:pointer;
        }
        #views-counter.visible{display:flex;}
        #views-count-num{font-size:13px;color:#fff;font-weight:600;}

        /* Panel de vistas */
        #views-panel{
            position:absolute;bottom:0;left:0;right:0;z-index:40;
            background:#111118;border-radius:20px 20px 0 0;
            padding:16px;max-height:60vh;overflow-y:auto;
            display:none;
        }
        #views-panel.open{display:block;}
        .viewer-row{
            display:flex;align-items:center;gap:10px;
            padding:8px 0;border-bottom:1px solid #1e1e28;
        }
        .viewer-row:last-child{border-bottom:none;}

        /* Contenedor media (imagen o video) */
        #story-media-wrap{
            width:100%;height:100%;display:flex;
            align-items:center;justify-content:center;
            position:absolute;top:0;left:0;
        }
        #story-img{max-width:100%;max-height:100%;object-fit:contain;display:none;}
        #story-video{
            max-width:100%;max-height:100%;object-fit:contain;
            display:none;background:#000;
        }

        /* Corazón animado flotante */
        .floating-heart{
            position:absolute;font-size:36px;z-index:100;
            animation:floatUp 1.1s ease forwards;pointer-events:none;
        }
        @keyframes floatUp{
            0%{opacity:1;transform:translateY(0) scale(1);}
            100%{opacity:0;transform:translateY(-120px) scale(1.5);}
        }
    </style>
</head>
<body class="flex justify-center items-center min-h-screen">
<div class="story-container flex flex-col justify-between text-white" style="position:relative;">

    <!-- Zonas tap -->
    <div id="tap-prev" class="tap-zone" onclick="prevStory()"></div>
    <div id="tap-next" class="tap-zone" onclick="nextStory()"></div>

    <!-- Header: barras + autor -->
    <div class="absolute top-0 left-0 w-full p-4 z-20"
         style="background:linear-gradient(to bottom,rgba(0,0,0,0.75),transparent)">
        <div class="flex mb-3">
            <?php foreach($user_stories as $index => $st): ?>
                <div class="progress-bar">
                    <div id="fill-<?= $index ?>" class="progress-fill"></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <img src="<?= htmlspecialchars($user_stories[0]['avatar_url'] ?? '') ?>"
                     class="w-10 h-10 rounded-full object-cover border-2 border-purple-500"
                     onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($username) ?>&background=18033B&color=fff'">
                <div>
                    <h4 class="text-sm font-semibold"><?= htmlspecialchars($user_stories[0]['nombre']) ?></h4>
                    <span class="text-xs text-gray-300">@<?= htmlspecialchars($username) ?></span>
                </div>
            </div>
            <a href="home.php" class="text-white text-3xl font-light px-2 z-30 relative"
               style="line-height:1;">&times;</a>
        </div>
    </div>

    <!-- Contenedor de media (imagen o video) -->
    <div id="story-media-wrap">
        <img id="story-img" src="" alt="">
        <video id="story-video" playsinline muted autoplay></video>
    </div>

    <!-- Tiempo restante -->
    <div id="story-timer"><span id="timer-text"></span></div>

    <!-- Contador de vistas (solo propias) -->
    <?php if($is_own): ?>
    <div id="views-counter" onclick="openViewsPanel()">
        <svg fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;">
            <path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            <path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
        </svg>
        <span id="views-count-num">0</span>
    </div>
    <?php endif; ?>

    <!-- Botón eliminar (solo para historias propias) -->
    <?php if($is_own): ?>
    <button id="delete-story-btn" onclick="deleteCurrentStory()">🗑 Eliminar historia</button>
    <?php endif; ?>

    <!-- Barra inferior: corazón + input reply + enviar -->
    <?php if(!$is_own): ?>
    <div id="reaction-bar">
        <span class="heart-btn" onclick="sendHeartReaction()" id="heart-btn">🤍</span>
        <input id="story-reply-input" type="text" placeholder="Responder a la historia…"
               maxlength="300" onkeydown="if(event.key==='Enter')sendStoryReply()">
        <div class="send-story-reply" onclick="sendStoryReply()">
            <svg fill="none" stroke="#fff" stroke-width="2.5" viewBox="0 0 24 24" style="width:18px;height:18px;">
                <line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/>
            </svg>
        </div>
    </div>
    <?php endif; ?>

    <!-- Panel de vistas (solo propias) -->
    <?php if($is_own): ?>
    <div id="views-panel">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
            <span style="font-size:14px;font-weight:700;color:#fff;">👁 Vistas</span>
            <span onclick="closeViewsPanel()" style="color:#888;font-size:22px;cursor:pointer;line-height:1;">×</span>
        </div>
        <div id="viewers-list">
            <div style="color:#666;font-size:13px;text-align:center;padding:20px 0;">Cargando...</div>
        </div>
    </div>
    <?php endif; ?>

</div>

<div id="story-toast"></div>

<script>
const stories    = <?= json_encode($user_stories) ?>;
const IS_OWN     = <?= $is_own ? 'true' : 'false' ?>;
const VIEWER_ID  = <?= $viewer_id ?>;
const OWNER_ID   = <?= $story_owner ?>;
const OWNER_USER = '<?= addslashes(htmlspecialchars($username)) ?>';

let currentIdx  = 0;
let timeout;
let heartSent   = false;
let storyPaused = false;

/* ── Helpers ──────────────────────────────────────────────────────── */
function isVideo(url) {
    if(!url) return false;
    const ext = url.split('.').pop().toLowerCase().split('?')[0];
    return ['mp4','webm','mov'].includes(ext);
}

/* ── Mostrar historia ──────────────────────────────────────────────── */
function showStory(idx) {
    if (idx >= stories.length) { window.location.href = 'home.php'; return; }
    if (idx < 0) idx = 0;
    currentIdx = idx;

    const story   = stories[idx];
    const url     = story.imagen_url || '';
    const imgEl   = document.getElementById('story-img');
    const vidEl   = document.getElementById('story-video');
    const storyId = parseInt(story.id);

    // Detener video anterior si lo había
    vidEl.pause();
    vidEl.src = '';

    if (isVideo(url)) {
        imgEl.style.display  = 'none';
        vidEl.style.display  = 'block';
        vidEl.src = url;
        vidEl.load();
        vidEl.muted  = true;
        vidEl.play().catch(()=>{});
    } else {
        vidEl.style.display  = 'none';
        imgEl.style.display  = 'block';
        imgEl.src = url;
    }

    // Rellenar barras previas
    for (let i = 0; i < stories.length; i++) {
        const f = document.getElementById('fill-' + i);
        if (!f) continue;
        f.style.transition = 'none';
        f.style.width = i < idx ? '100%' : '0%';
    }

    // Botón eliminar
    if (IS_OWN) {
        document.getElementById('delete-story-btn').style.display = 'block';
    }

    // Resetear corazón
    heartSent = false;
    const hb = document.getElementById('heart-btn');
    if (hb) hb.textContent = '🤍';

    // Actualizar tiempo restante
    updateTimer();

    // Registrar vista (si no es propia)
    if (!IS_OWN && storyId) {
        registerView(storyId);
    }

    // Actualizar contador de vistas (si es propia)
    if (IS_OWN && storyId) {
        loadViewsCount(storyId);
    }

    // Para video: avanzar cuando termina
    vidEl.onended = () => nextStory();

    // Duración: video usa su duración real, imagen usa 5 segundos
    const duration = isVideo(url) ? null : 5000; // null = espera evento onended

    // Iniciar barra de progreso
    clearTimeout(timeout);
    setTimeout(() => {
        const f = document.getElementById('fill-' + idx);
        if (!f) return;
        if (isVideo(url)) {
            // La barra de video se anima en paralelo usando la duración del video
            vidEl.addEventListener('loadedmetadata', function onMeta() {
                vidEl.removeEventListener('loadedmetadata', onMeta);
                const dur = vidEl.duration || 5;
                f.style.transition = `width ${dur}s linear`;
                f.style.width = '100%';
            }, {once: true});
        } else {
            f.style.transition = 'width 5s linear';
            f.style.width = '100%';
            timeout = setTimeout(() => nextStory(), 5000);
        }
    }, 80);
}

function nextStory() {
    const f = document.getElementById('fill-' + currentIdx);
    if (f) f.style.width = '100%';
    showStory(currentIdx + 1);
}
function prevStory() {
    const f = document.getElementById('fill-' + currentIdx);
    if (f) { f.style.transition = 'none'; f.style.width = '0%'; }
    showStory(currentIdx - 1);
}

/* ── Tiempo restante ───────────────────────────────────────────────── */
function updateTimer() {
    const story = stories[currentIdx];
    if (!story) return;
    const created = new Date(story.created_at.replace(' ', 'T'));
    const expires = new Date(created.getTime() + 48 * 60 * 60 * 1000);
    const now     = new Date();
    const diff    = expires - now;

    if (diff <= 0) {
        document.getElementById('timer-text').textContent = 'Expira pronto';
        return;
    }
    const h = Math.floor(diff / 3600000);
    const m = Math.floor((diff % 3600000) / 60000);
    let txt = '';
    if (h > 0) txt = `Expira en ${h}h ${m}m`;
    else       txt = `Expira en ${m} min`;
    document.getElementById('timer-text').textContent = txt;
}
setInterval(updateTimer, 30000);

/* ── Registrar vista ───────────────────────────────────────────────── */
function registerView(storyId) {
    const fd = new FormData();
    fd.append('story_id', storyId);
    fetch('ajax.php?action=register_view', {method:'POST', body:fd}).catch(()=>{});
}

/* ── Cargar contador de vistas ─────────────────────────────────────── */
function loadViewsCount(storyId) {
    fetch('ajax.php?action=get_story_views&story_id=' + storyId)
        .then(r => r.json())
        .then(d => {
            const counter = document.getElementById('views-counter');
            const num     = document.getElementById('views-count-num');
            if(counter && num) {
                num.textContent = d.count || 0;
                counter.classList.add('visible');
            }
        }).catch(()=>{});
}

/* ── Panel de vistas ───────────────────────────────────────────────── */
function openViewsPanel() {
    // Pausar historia mientras se consultan las vistas
    clearTimeout(timeout);
    const vidEl = document.getElementById('story-video');
    if(vidEl && !vidEl.paused) vidEl.pause();
    storyPaused = true;

    document.getElementById('views-panel').classList.add('open');
    const storyId = parseInt(stories[currentIdx]?.id || 0);
    if(!storyId) return;

    fetch('ajax.php?action=get_story_views&story_id=' + storyId)
        .then(r => r.json())
        .then(d => {
            const list = document.getElementById('viewers-list');
            if(!d.viewers || d.viewers.length === 0) {
                list.innerHTML = '<div style="color:#666;font-size:13px;text-align:center;padding:20px 0;">Nadie ha visto esta historia aún.</div>';
                return;
            }
            list.innerHTML = d.viewers.map(v => `
                <div class="viewer-row">
                    <img src="${escHtml(v.avatar_url||'https://ui-avatars.com/api/?name='+encodeURIComponent(v.username||'U')+'&background=18033B&color=fff')}"
                         style="width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                    <div style="flex:1;">
                        <div style="font-weight:600;color:#fff;font-size:13px;">${escHtml(v.nombre||v.username)}</div>
                        <div style="font-size:11px;color:#666;">@${escHtml(v.username||'')}</div>
                    </div>
                    <div style="font-size:11px;color:#555;">${formatTime(v.visto_at)}</div>
                </div>
            `).join('');
        }).catch(()=>{});
}

function closeViewsPanel() {
    document.getElementById('views-panel').classList.remove('open');
    storyPaused = false;
    // Reanudar
    const vidEl = document.getElementById('story-video');
    if(vidEl && vidEl.src && isVideo(vidEl.src)) {
        vidEl.play().catch(()=>{});
    } else {
        timeout = setTimeout(() => nextStory(), 3000);
    }
}

function formatTime(ts) {
    if(!ts) return '';
    const d = new Date(ts.replace(' ','T'));
    if(isNaN(d)) return '';
    return d.toLocaleTimeString('es',{hour:'2-digit',minute:'2-digit'});
}

function escHtml(s) {
    if(!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── Reacción corazón ──────────────────────────────────────────────── */
function sendHeartReaction() {
    const hb = document.getElementById('heart-btn');

    // Animación visual
    const rect = hb.getBoundingClientRect();
    const h = document.createElement('span');
    h.className = 'floating-heart';
    h.textContent = '❤️';
    h.style.left = (rect.left + 6) + 'px';
    h.style.top  = (rect.top - 20) + 'px';
    h.style.position = 'fixed';
    document.body.appendChild(h);
    setTimeout(() => h.remove(), 1200);

    if (!heartSent) {
        hb.textContent = '❤️';
        heartSent = true;
        const fd = new FormData();
        fd.append('mensaje', '❤️ Reaccionó a tu historia');
        fetch('ajax.php?action=send&contact_id=' + OWNER_ID, {method:'POST', body:fd})
            .catch(() => {});
    }
    showToast('❤️ Reacción enviada');
}

/* ── Responder historia ────────────────────────────────────────────── */
function sendStoryReply() {
    const inp  = document.getElementById('story-reply-input');
    const text = inp.value.trim();
    if (!text) return;

    // Pausar historia mientras escribe
    clearTimeout(timeout);

    const fd = new FormData();
    fd.append('mensaje', '💬 Historia: ' + text);
    fetch('ajax.php?action=send&contact_id=' + OWNER_ID, {method:'POST', body:fd})
        .then(r => r.json())
        .then(d => {
            if (d.status === 'ok') {
                showToast('✅ Respuesta enviada');
                inp.value = '';
                inp.blur();
                timeout = setTimeout(() => nextStory(), 5000);
            } else {
                showToast('Error al enviar', 'error');
            }
        })
        .catch(() => showToast('Error de conexión', 'error'));
}

/* ── Eliminar historia ─────────────────────────────────────────────── */
function deleteCurrentStory() {
    if (!confirm('¿Eliminar esta historia?')) return;
    const storyId = stories[currentIdx].id;

    fetch('ajax.php?action=delete_story', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'story_id=' + storyId
    })
    .then(r => r.json())
    .then(d => {
        if (d.status === 'ok') {
            showToast('Historia eliminada');
            stories.splice(currentIdx, 1);
            if (stories.length === 0) {
                setTimeout(() => window.location.href = 'home.php', 800);
            } else {
                if (currentIdx >= stories.length) currentIdx = stories.length - 1;
                showStory(currentIdx);
            }
        } else {
            showToast(d.error || 'Error al eliminar', 'error');
        }
    })
    .catch(() => showToast('Error de conexión', 'error'));
}

/* ── Toast ─────────────────────────────────────────────────────────── */
function showToast(msg, type = 'ok') {
    const t = document.getElementById('story-toast');
    t.textContent = msg;
    t.style.background = type === 'error' ? '#ef4444' : '#22c55e';
    t.style.opacity = '1';
    clearTimeout(t._t);
    t._t = setTimeout(() => t.style.opacity = '0', 2500);
}

/* ── Pausar al enfocar input ───────────────────────────────────────── */
const replyInput = document.getElementById('story-reply-input');
if (replyInput) {
    replyInput.addEventListener('focus', () => {
        clearTimeout(timeout);
        const vidEl = document.getElementById('story-video');
        if(vidEl && !vidEl.paused) vidEl.pause();
    });
    replyInput.addEventListener('blur', () => {
        if (!replyInput.value) {
            const vidEl = document.getElementById('story-video');
            if(vidEl && vidEl.src && isVideo(vidEl.src)) {
                vidEl.play().catch(()=>{});
            } else {
                timeout = setTimeout(() => nextStory(), 3000);
            }
        }
    });
}

/* ── Arrancar ──────────────────────────────────────────────────────── */
showStory(0);
</script>
</body>
</html>