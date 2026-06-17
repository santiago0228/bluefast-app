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

    <!-- Imagen de la historia -->
    <div class="w-full h-full flex items-center justify-center select-none">
        <img id="story-img" src="" style="max-width:100%;max-height:100%;object-fit:contain;">
    </div>

    <!-- Tiempo restante -->
    <div id="story-timer"><span id="timer-text"></span></div>

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

</div>

<div id="story-toast"></div>

<script>
const stories    = <?= json_encode($user_stories) ?>;
const IS_OWN     = <?= $is_own ? 'true' : 'false' ?>;
const VIEWER_ID  = <?= $viewer_id ?>;
const OWNER_USER = '<?= addslashes(htmlspecialchars($username)) ?>';

let currentIdx = 0;
let timeout;
let heartSent = false;

/* ── Mostrar historia ──────────────────────────────────────────────── */
function showStory(idx) {
    if (idx >= stories.length) { window.location.href = 'home.php'; return; }
    if (idx < 0) idx = 0;
    currentIdx = idx;

    document.getElementById('story-img').src = stories[idx].imagen_url;

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

    // Iniciar barra de progreso
    setTimeout(() => {
        const f = document.getElementById('fill-' + idx);
        if (f) {
            f.style.transition = 'width 5s linear';
            f.style.width = '100%';
        }
    }, 80);

    clearTimeout(timeout);
    timeout = setTimeout(() => nextStory(), 5000);
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
    if (h > 0)  txt = `Expira en ${h}h ${m}m`;
    else        txt = `Expira en ${m} min`;
    document.getElementById('timer-text').textContent = txt;
}
setInterval(updateTimer, 30000);

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
        // Enviar como mensaje al dueño de la historia
        const fd = new FormData();
        fd.append('mensaje', '❤️ Reaccionó a tu historia');
        fetch('ajax.php?action=send&contact_id=<?= $story_owner ?>', {method:'POST', body:fd})
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
    fetch('ajax.php?action=send&contact_id=<?= $story_owner ?>', {method:'POST', body:fd})
        .then(r => r.json())
        .then(d => {
            if (d.status === 'ok') {
                showToast('✅ Respuesta enviada');
                inp.value = '';
                inp.blur();
                // Reanudar
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
    replyInput.addEventListener('focus',  () => clearTimeout(timeout));
    replyInput.addEventListener('blur',   () => {
        if (!replyInput.value) timeout = setTimeout(() => nextStory(), 3000);
    });
}

/* ── Arrancar ──────────────────────────────────────────────────────── */
showStory(0);
</script>
</body>
</html>