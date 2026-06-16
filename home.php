<?php
require 'config.php';
verificar_sesion();
$user_id = $_SESSION['user_id'];

// ─── POST handlers ────────────────────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST') {
    if(isset($_POST['action_profile'])) {
        $nombre = trim($_POST['nombre']??'');
        $bio    = trim($_POST['bio']??'');
        if($nombre) $pdo->prepare('UPDATE usuarios SET nombre=?,bio=? WHERE id=?')->execute([$nombre,$bio,$user_id]);
        if(!empty($_FILES['avatar_file']['name'])) {
            $ext  = strtolower(pathinfo($_FILES['avatar_file']['name'],PATHINFO_EXTENSION));
            $dest = 'uploads/avatar_'.$user_id.'_'.time().'.'.$ext;
            if(move_uploaded_file($_FILES['avatar_file']['tmp_name'],$dest))
                $pdo->prepare('UPDATE usuarios SET avatar_url=? WHERE id=?')->execute([$dest,$user_id]);
        }
        header('Location: home.php'); exit;
    }
    if(isset($_POST['action_appearance'])) {
        $theme  = $_POST['theme_mode']   ?? 'dark';
        $bg     = trim($_POST['chat_bg'] ?? '');
        $bubble = trim($_POST['bubble_color'] ?? '#18033B');
        $pdo->prepare('UPDATE usuarios SET theme_mode=?,chat_bg=?,bubble_color=? WHERE id=?')->execute([$theme,$bg,$bubble,$user_id]);
        header('Location: home.php'); exit;
    }
    if(isset($_POST['add_username'])) {
        $uname = ltrim(trim($_POST['add_username']),'@');
        $stmt  = $pdo->prepare('SELECT id FROM usuarios WHERE username=?');
        $stmt->execute([$uname]);
        $tgt   = $stmt->fetch();
        if($tgt && $tgt['id']!=$user_id) {
            $chk = $pdo->prepare('SELECT id FROM contactos WHERE usuario_id=? AND contacto_id=?');
            $chk->execute([$user_id,$tgt['id']]);
            if(!$chk->fetch()) $pdo->prepare('INSERT INTO contactos (usuario_id,contacto_id) VALUES (?,?)')->execute([$user_id,$tgt['id']]);
        }
        header('Location: home.php?sec=add'); exit;
    }
}

$stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id=?');
$stmt->execute([$user_id]);
$me   = $stmt->fetch();

$stmt = $pdo->prepare('SELECT u.* FROM contactos c JOIN usuarios u ON c.contacto_id=u.id WHERE c.usuario_id=?');
$stmt->execute([$user_id]);
$chat_list = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT h.*,u.username,u.avatar_url FROM historias h JOIN usuarios u ON h.usuario_id=u.id WHERE h.created_at >= NOW() - INTERVAL 1 DAY ORDER BY h.created_at DESC');
$stmt->execute();
$stories = $stmt->fetchAll();

$is_dark    = ($me['theme_mode']??'dark')==='dark';
$bubble_col = $me['bubble_color'] ?? '#18033B';
$init_sec   = $_GET['sec'] ?? 'chats';

$bg    = $is_dark ? '#0B0B0F' : '#ffffff';
$bg2   = $is_dark ? '#111118' : '#f9fafb';
$bg3   = $is_dark ? '#1e1e28' : '#f3f4f6';
$bord  = $is_dark ? '#1e1e28' : '#e5e7eb';
$txt   = $is_dark ? '#ffffff' : '#111827';
$txt2  = $is_dark ? '#aaaaaa' : '#6b7280';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>blufast</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
  :root{--bubble:<?= $bubble_col ?>;}
  *{box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:<?= $is_dark?'#050507':'#f3f4f6' ?>;display:flex;justify-content:center;min-height:100vh;}
  .app{max-width:430px;width:100%;height:100dvh;background:<?= $bg ?>;display:flex;flex-direction:column;overflow:hidden;position:relative;}
  .no-scroll::-webkit-scrollbar{display:none;}
  input,select,textarea{font-family:inherit;}

  /* Secciones */
  .view{flex:1;display:flex;flex-direction:column;overflow:hidden;}
  .view.hidden{display:none;}

  /* Setting rows */
  .srow{display:flex;align-items:center;justify-content:space-between;padding:13px 4px;border-bottom:1px solid <?= $bord ?>;cursor:pointer;}
  .srow:last-child{border-bottom:none;}
  .srow:hover{background:<?= $bg3 ?>;border-radius:10px;}
  .srow-left{display:flex;align-items:center;gap:12px;}
  .sicon{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}

  /* Input */
  .inp{width:100%;padding:11px 14px;border-radius:14px;border:1px solid <?= $bord ?>;background:<?= $bg3 ?>;color:<?= $txt ?>;font-size:14px;outline:none;}
  .inp:focus{border-color:var(--bubble);}

  /* Subpanel */
  .subpanel{position:fixed;inset:0;background:rgba(0,0,0,0.65);display:none;align-items:flex-end;justify-content:center;z-index:500;}
  .subpanel.open{display:flex;}
  .subpanel-inner{width:100%;max-width:430px;background:<?= $bg ?>;border-radius:24px 24px 0 0;padding:20px 20px 32px;max-height:85vh;overflow-y:auto;}
  .subpanel-handle{width:40px;height:4px;background:#333;border-radius:4px;margin:0 auto 18px;}

  /* Avatar edit overlay */
  .avatar-wrap{position:relative;display:inline-block;}
  .avatar-edit{position:absolute;bottom:0;right:0;background:var(--bubble);border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;cursor:pointer;border:2px solid <?= $bg ?>;}

  /* Tabs añadir */
  .pill-active{background:var(--bubble);color:#fff;}
  .pill-inactive{background:<?= $bg3 ?>;color:<?= $txt2 ?>;}

  /* Presets fondo */
  .bg-preset{width:42px;height:42px;border-radius:10px;cursor:pointer;border:2px solid transparent;flex-shrink:0;}
  .bg-preset.sel{border-color:var(--bubble);}

  /* Color swatch */
  .cswatch{width:34px;height:34px;border-radius:50%;cursor:pointer;border:2px solid transparent;}
  .cswatch.sel{border-color:#fff;box-shadow:0 0 0 2px var(--bubble);}
</style>
</head>
<body>
<div class="app">

<!-- ═══ VISTA CHATS ═══════════════════════════════════════════════════════════ -->
<div id="view-chats" class="view">
  <header style="padding:14px 16px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid <?= $bord ?>;flex-shrink:0;">
    <h1 style="font-size:20px;font-weight:800;color:<?= $txt ?>;letter-spacing:-0.5px;">blufast</h1>
    <button onclick="openSub('edit-profile')" style="width:32px;height:32px;border-radius:50%;overflow:hidden;border:2px solid <?= $bubble_col ?>;cursor:pointer;padding:0;">
      <img src="<?= htmlspecialchars($me['avatar_url']?:'https://ui-avatars.com/api/?name='.urlencode($me['username']).'&background=18033B&color=fff') ?>" style="width:100%;height:100%;object-fit:cover;">
    </button>
  </header>

  <!-- Historias -->
  <div style="padding:12px 14px;display:flex;gap:12px;overflow-x:auto;border-bottom:1px solid <?= $bord ?>;flex-shrink:0;" class="no-scroll">
    <div style="display:flex;flex-direction:column;align-items:center;gap:4px;flex-shrink:0;">
      <form action="ajax.php?action=upload_story" method="POST" enctype="multipart/form-data" id="form-story">
        <label style="width:50px;height:50px;border-radius:50%;background:<?= $bubble_col ?>;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:22px;font-weight:300;color:#fff;">+
          <input type="file" name="story_file" accept="image/*,video/*" class="hidden" onchange="document.getElementById('form-story').submit();">
        </label>
      </form>
      <span style="font-size:10px;color:<?= $txt2 ?>;">Mi estado</span>
    </div>
    <?php
      $vistos=[];
      foreach($stories as $s): if(!in_array($s['usuario_id'],$vistos)): $vistos[]=$s['usuario_id'];
    ?>
    <a href="ver_historias.php?username=<?= urlencode($s['username']) ?>" style="display:flex;flex-direction:column;align-items:center;gap:4px;flex-shrink:0;text-decoration:none;">
      <div style="width:50px;height:50px;border-radius:50%;padding:2px;background:linear-gradient(135deg,<?= $bubble_col ?>,#7c3aed);">
        <img src="<?= htmlspecialchars($s['avatar_url']?:'') ?>" style="width:100%;height:100%;border-radius:50%;object-fit:cover;border:2px solid <?= $bg ?>;">
      </div>
      <span style="font-size:10px;color:<?= $txt2 ?>;max-width:52px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">@<?= htmlspecialchars($s['username']) ?></span>
    </a>
    <?php endif; endforeach; ?>
  </div>

  <!-- Lista chats -->
  <div style="padding:8px 10px;font-size:11px;font-weight:600;color:<?= $txt2 ?>;letter-spacing:0.5px;flex-shrink:0;">MENSAJES</div>
  <main style="flex:1;overflow-y:auto;padding:0 8px;" class="no-scroll">
    <?php if(empty($chat_list)): ?>
      <div style="text-align:center;padding:40px 20px;color:<?= $txt2 ?>;font-size:14px;">
        Aún no tienes contactos. <br>
        <button onclick="switchSec('add')" style="margin-top:12px;padding:10px 20px;background:<?= $bubble_col ?>;color:#fff;border-radius:12px;font-size:13px;cursor:pointer;border:none;">Añadir contacto</button>
      </div>
    <?php else: ?>
      <?php foreach($chat_list as $c): ?>
        <a href="chat.php?contact_id=<?= $c['id'] ?>" style="display:flex;align-items:center;gap:12px;padding:10px 8px;border-radius:14px;text-decoration:none;transition:background 0.15s;" onmouseover="this.style.background='<?= $bg3 ?>'" onmouseout="this.style.background='transparent'">
          <img src="<?= htmlspecialchars($c['avatar_url']?:'https://ui-avatars.com/api/?name='.urlencode($c['username']).'&background=18033B&color=fff') ?>" style="width:46px;height:46px;border-radius:50%;object-fit:cover;flex-shrink:0;">
          <div style="flex:1;min-width:0;">
            <div style="font-weight:600;color:<?= $txt ?>;font-size:14px;"><?= htmlspecialchars($c['nombre']?:$c['username']) ?></div>
            <div style="font-size:12px;color:<?= $txt2 ?>;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($c['bio']??'') ?></div>
          </div>
          <div style="flex-shrink:0;">
            <svg fill="none" stroke="<?= $txt2 ?>" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M9 5l7 7-7 7"/></svg>
          </div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>
</div>

<!-- ═══ VISTA AÑADIR ══════════════════════════════════════════════════════════ -->
<div id="view-add" class="view hidden" style="padding:20px 16px;">
  <h2 style="font-size:17px;font-weight:700;color:<?= $txt ?>;margin-bottom:18px;">Añadir contacto</h2>
  <form method="POST" style="display:flex;gap:10px;">
    <input type="text" name="add_username" placeholder="@nombre_usuario" class="inp" style="flex:1;" required>
    <button type="submit" style="padding:11px 18px;background:<?= $bubble_col ?>;color:#fff;border-radius:14px;font-size:14px;font-weight:600;white-space:nowrap;border:none;cursor:pointer;">Buscar</button>
  </form>
  <?php if(isset($_GET['sec']) && $_GET['sec']==='add' && isset($_GET['added'])): ?>
    <div style="margin-top:12px;padding:12px 16px;background:#16a34a20;border:1px solid #16a34a40;border-radius:12px;color:#86efac;font-size:13px;">✓ Contacto añadido correctamente.</div>
  <?php endif; ?>
  <div style="margin-top:28px;">
    <div style="font-size:11px;font-weight:600;color:<?= $txt2 ?>;margin-bottom:12px;letter-spacing:0.5px;">MIS CONTACTOS (<?= count($chat_list) ?>)</div>
    <?php foreach($chat_list as $c): ?>
      <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid <?= $bord ?>;">
        <img src="<?= htmlspecialchars($c['avatar_url']?:'https://ui-avatars.com/api/?name='.urlencode($c['username']).'&background=18033B&color=fff') ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">
        <div style="flex:1;">
          <div style="font-weight:600;color:<?= $txt ?>;font-size:13px;"><?= htmlspecialchars($c['nombre']?:$c['username']) ?></div>
          <div style="font-size:11px;color:<?= $txt2 ?>;">@<?= htmlspecialchars($c['username']) ?></div>
        </div>
        <a href="chat.php?contact_id=<?= $c['id'] ?>" style="padding:6px 14px;background:<?= $bubble_col ?>;color:#fff;border-radius:10px;font-size:12px;text-decoration:none;">Chat</a>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ═══ VISTA AJUSTES ═════════════════════════════════════════════════════════ -->
<div id="view-settings" class="view hidden" style="overflow-y:auto;" class="no-scroll">
  <!-- Perfil mini -->
  <div style="padding:20px 16px 16px;display:flex;align-items:center;gap:14px;border-bottom:1px solid <?= $bord ?>;">
    <div class="avatar-wrap" onclick="openSub('edit-profile')" style="cursor:pointer;">
      <img src="<?= htmlspecialchars($me['avatar_url']?:'https://ui-avatars.com/api/?name='.urlencode($me['username']).'&background=18033B&color=fff') ?>"
           style="width:58px;height:58px;border-radius:50%;object-fit:cover;border:2px solid <?= $bubble_col ?>;">
      <div class="avatar-edit">
        <svg fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      </div>
    </div>
    <div>
      <div style="font-weight:700;font-size:16px;color:<?= $txt ?>;"><?= htmlspecialchars($me['nombre']?:$me['username']) ?></div>
      <div style="font-size:12px;color:<?= $txt2 ?>;margin-top:2px;">@<?= htmlspecialchars($me['username']) ?></div>
      <div style="font-size:11px;color:<?= $txt2 ?>;margin-top:2px;"><?= htmlspecialchars($me['bio']??'') ?></div>
    </div>
  </div>

  <div style="padding:8px 16px;">
    <!-- Cuenta -->
    <div style="font-size:11px;font-weight:600;color:<?= $txt2 ?>;padding:12px 0 6px;letter-spacing:0.5px;">CUENTA</div>
    <div class="srow" onclick="openSub('edit-profile')">
      <div class="srow-left">
        <div class="sicon" style="background:#7c3aed20;"><svg fill="none" stroke="#7c3aed" stroke-width="2" viewBox="0 0 24 24" style="width:18px;height:18px;"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg></div>
        <div>
          <div style="font-size:14px;color:<?= $txt ?>;font-weight:500;">Editar perfil</div>
          <div style="font-size:11px;color:<?= $txt2 ?>;">Nombre, bio y foto</div>
        </div>
      </div>
      <svg fill="none" stroke="<?= $txt2 ?>" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M9 5l7 7-7 7"/></svg>
    </div>
    <div class="srow" onclick="openSub('appearance')">
      <div class="srow-left">
        <div class="sicon" style="background:#059669 20;"><svg fill="none" stroke="#059669" stroke-width="2" viewBox="0 0 24 24" style="width:18px;height:18px;"><path d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg></div>
        <div>
          <div style="font-size:14px;color:<?= $txt ?>;font-weight:500;">Apariencia</div>
          <div style="font-size:11px;color:<?= $txt2 ?>;">Tema, fondo y color de burbujas</div>
        </div>
      </div>
      <svg fill="none" stroke="<?= $txt2 ?>" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M9 5l7 7-7 7"/></svg>
    </div>

    <!-- App -->
    <div style="font-size:11px;font-weight:600;color:<?= $txt2 ?>;padding:12px 0 6px;letter-spacing:0.5px;">APLICACIÓN</div>
    <div class="srow" onclick="alert('Notificaciones activadas.')">
      <div class="srow-left">
        <div class="sicon" style="background:#f5920020;"><svg fill="none" stroke="#f59200" stroke-width="2" viewBox="0 0 24 24" style="width:18px;height:18px;"><path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg></div>
        <div style="font-size:14px;color:<?= $txt ?>;font-weight:500;">Notificaciones</div>
      </div>
      <span style="font-size:12px;color:#22c55e;font-weight:500;">Activo</span>
    </div>
    <div class="srow" onclick="alert('Privacidad: solo tus contactos pueden escribirte.')">
      <div class="srow-left">
        <div class="sicon" style="background:#3b82f620;"><svg fill="none" stroke="#3b82f6" stroke-width="2" viewBox="0 0 24 24" style="width:18px;height:18px;"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg></div>
        <div style="font-size:14px;color:<?= $txt ?>;font-weight:500;">Privacidad</div>
      </div>
      <svg fill="none" stroke="<?= $txt2 ?>" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M9 5l7 7-7 7"/></svg>
    </div>
    <div class="srow" onclick="alert('Cifrado E2E AES-128 activo en todos tus chats.')">
      <div class="srow-left">
        <div class="sicon" style="background:#22c55e20;"><svg fill="none" stroke="#22c55e" stroke-width="2" viewBox="0 0 24 24" style="width:18px;height:18px;"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg></div>
        <div>
          <div style="font-size:14px;color:<?= $txt ?>;font-weight:500;">Seguridad</div>
          <div style="font-size:11px;color:<?= $txt2 ?>;">Cifrado AES-128 activo</div>
        </div>
      </div>
      <span style="font-size:12px;color:#22c55e;font-weight:500;">✓ Seguro</span>
    </div>

    <!-- Danger -->
    <div style="margin-top:24px;padding-bottom:20px;">
      <a href="logout_process.php" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:13px;background:#ef444410;border:1px solid #ef444430;border-radius:14px;color:#ef4444;font-size:14px;font-weight:600;text-decoration:none;">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
        Cerrar sesión
      </a>
    </div>
  </div>
</div>

<!-- ═══ NAVBAR ════════════════════════════════════════════════════════════════ -->
<footer style="height:58px;background:<?= $bg2 ?>;border-top:1px solid <?= $bord ?>;display:flex;align-items:center;justify-content:around;flex-shrink:0;padding:0 10px;">
  <button onclick="switchSec('chats')" id="nav-chats" style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;cursor:pointer;padding:6px;border:none;background:transparent;color:<?= $bubble_col ?>;">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:22px;height:22px;"><path d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
    <span style="font-size:10px;font-weight:600;">Chats</span>
  </button>
  <button onclick="switchSec('add')" id="nav-add" style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;cursor:pointer;padding:6px;border:none;background:transparent;color:<?= $txt2 ?>;">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:22px;height:22px;"><path d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
    <span style="font-size:10px;font-weight:600;">Amigos</span>
  </button>
  <button onclick="switchSec('settings')" id="nav-settings" style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;cursor:pointer;padding:6px;border:none;background:transparent;color:<?= $txt2 ?>;">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:22px;height:22px;"><path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
    <span style="font-size:10px;font-weight:600;">Ajustes</span>
  </button>
</footer>

<!-- ═══ SUBPANEL EDITAR PERFIL ════════════════════════════════════════════════ -->
<div id="sub-edit-profile" class="subpanel" onclick="closeSub('edit-profile')">
  <div class="subpanel-inner" onclick="event.stopPropagation()">
    <div class="subpanel-handle"></div>
    <h3 style="font-size:16px;font-weight:700;color:<?= $txt ?>;margin-bottom:20px;">Editar perfil</h3>
    
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action_profile" value="1">
      
      <!-- Avatar -->
      <div style="display:flex;justify-content:center;margin-bottom:20px;">
        <label style="cursor:pointer;position:relative;display:inline-block;">
          <img id="avatar-preview" src="<?= htmlspecialchars($me['avatar_url']?:'https://ui-avatars.com/api/?name='.urlencode($me['username']).'&background=18033B&color=fff') ?>"
               style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid <?= $bubble_col ?>;">
          <div style="position:absolute;bottom:0;right:0;background:<?= $bubble_col ?>;border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;border:2px solid <?= $bg ?>;">
            <svg fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
          </div>
          <input type="file" name="avatar_file" accept="image/*" class="hidden" onchange="previewAvatar(this)">
        </label>
      </div>

      <div style="display:flex;flex-direction:column;gap:12px;">
        <div>
          <label style="font-size:11px;font-weight:600;color:<?= $txt2 ?>;letter-spacing:0.5px;display:block;margin-bottom:6px;">NOMBRE PÚBLICO</label>
          <input type="text" name="nombre" value="<?= htmlspecialchars($me['nombre']??'') ?>" placeholder="Tu nombre" class="inp">
        </div>
        <div>
          <label style="font-size:11px;font-weight:600;color:<?= $txt2 ?>;letter-spacing:0.5px;display:block;margin-bottom:6px;">BIOGRAFÍA</label>
          <textarea name="bio" placeholder="Escribe algo sobre ti..." class="inp" rows="2" style="resize:none;"><?= htmlspecialchars($me['bio']??'') ?></textarea>
        </div>
        <div style="background:<?= $bg3 ?>;border-radius:12px;padding:12px;">
          <div style="font-size:11px;color:<?= $txt2 ?>;margin-bottom:4px;">USUARIO</div>
          <div style="font-size:14px;color:<?= $txt ?>;font-weight:500;">@<?= htmlspecialchars($me['username']) ?></div>
          <div style="font-size:11px;color:<?= $txt2 ?>;margin-top:2px;">El nombre de usuario no se puede cambiar.</div>
        </div>
        <button type="submit" style="padding:13px;background:<?= $bubble_col ?>;color:#fff;border-radius:14px;font-size:15px;font-weight:600;border:none;cursor:pointer;margin-top:4px;">Guardar cambios</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ SUBPANEL APARIENCIA ══════════════════════════════════════════════════ -->
<div id="sub-appearance" class="subpanel" onclick="closeSub('appearance')">
  <div class="subpanel-inner" onclick="event.stopPropagation()">
    <div class="subpanel-handle"></div>
    <h3 style="font-size:16px;font-weight:700;color:<?= $txt ?>;margin-bottom:20px;">Apariencia</h3>
    
    <form method="POST" id="form-appearance">
      <input type="hidden" name="action_appearance" value="1">

      <!-- Tema -->
      <div style="margin-bottom:20px;">
        <label style="font-size:11px;font-weight:600;color:<?= $txt2 ?>;letter-spacing:0.5px;display:block;margin-bottom:10px;">TEMA</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <label style="cursor:pointer;">
            <input type="radio" name="theme_mode" value="dark" <?= $is_dark?'checked':'' ?> style="display:none;" onchange="document.getElementById('preview-theme').textContent='Oscuro'">
            <div id="dark-pill" style="padding:12px;background:#111118;border:2px solid <?= $is_dark?$bubble_col:'#333' ?>;border-radius:14px;text-align:center;" onclick="selectTheme('dark')">
              <div style="font-size:18px;margin-bottom:4px;">🌙</div>
              <div style="font-size:13px;font-weight:600;color:#fff;">Oscuro</div>
            </div>
          </label>
          <label style="cursor:pointer;">
            <input type="radio" name="theme_mode" value="light" <?= !$is_dark?'checked':'' ?> style="display:none;">
            <div id="light-pill" style="padding:12px;background:#f9fafb;border:2px solid <?= !$is_dark?$bubble_col:'#e5e7eb' ?>;border-radius:14px;text-align:center;" onclick="selectTheme('light')">
              <div style="font-size:18px;margin-bottom:4px;">☀️</div>
              <div style="font-size:13px;font-weight:600;color:#111;">Claro</div>
            </div>
          </label>
        </div>
      </div>

      <!-- Color burbuja -->
      <div style="margin-bottom:20px;">
        <label style="font-size:11px;font-weight:600;color:<?= $txt2 ?>;letter-spacing:0.5px;display:block;margin-bottom:10px;">COLOR DE BURBUJAS</label>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <?php
          $colors=['#18033B','#7c3aed','#2563eb','#059669','#dc2626','#ea580c','#0891b2','#be185d'];
          foreach($colors as $c): ?>
          <div class="cswatch <?= $c===$bubble_col?'sel':'' ?>" style="background:<?= $c ?>;" onclick="selectBubble('<?= $c ?>')"></div>
          <?php endforeach; ?>
          <div style="display:flex;align-items:center;gap:6px;">
            <input type="color" id="custom-bubble-color" value="<?= htmlspecialchars($bubble_col) ?>" style="width:34px;height:34px;border-radius:50%;border:2px solid <?= $bord ?>;cursor:pointer;padding:0;background:none;" onchange="selectBubble(this.value)">
            <span style="font-size:11px;color:<?= $txt2 ?>;">Personalizar</span>
          </div>
        </div>
        <input type="hidden" name="bubble_color" id="bubble-color-input" value="<?= htmlspecialchars($bubble_col) ?>">
      </div>

      <!-- Fondo del chat -->
      <div style="margin-bottom:20px;">
        <label style="font-size:11px;font-weight:600;color:<?= $txt2 ?>;letter-spacing:0.5px;display:block;margin-bottom:10px;">FONDO DEL CHAT</label>
        <div style="display:flex;gap:8px;margin-bottom:10px;overflow-x:auto;padding-bottom:4px;" class="no-scroll">
          <?php
          $presets=[
            ['label'=>'Predeterminado','val'=>'','style'=>'background:#0B0B0F;'],
            ['label'=>'Noche','val'=>'#050507','style'=>'background:#050507;'],
            ['label'=>'Índigo','val'=>'#0f0a25','style'=>'background:linear-gradient(135deg,#0f0a25,#1e1a3f);'],
            ['label'=>'Azul','val'=>'#040d1a','style'=>'background:linear-gradient(135deg,#040d1a,#0d1b3e);'],
            ['label'=>'Verde','val'=>'#031a0a','style'=>'background:linear-gradient(135deg,#031a0a,#0a2e14);'],
          ];
          $curBg = $me['chat_bg']??'';
          foreach($presets as $p): ?>
          <div class="bg-preset <?= $p['val']===$curBg?'sel':'' ?>" style="<?= $p['style'] ?>" onclick="selectBg('<?= addslashes($p['val']) ?>')" title="<?= htmlspecialchars($p['label']) ?>"></div>
          <?php endforeach; ?>
        </div>
        <input type="text" name="chat_bg" id="chat-bg-input" value="<?= htmlspecialchars($curBg) ?>" placeholder="URL de imagen o color HEX" class="inp" style="font-size:12px;">
        <div style="font-size:11px;color:<?= $txt2 ?>;margin-top:6px;">También puedes pegar una URL de imagen para usar como fondo.</div>
      </div>

      <button type="submit" style="width:100%;padding:13px;background:<?= $bubble_col ?>;color:#fff;border-radius:14px;font-size:15px;font-weight:600;border:none;cursor:pointer;">Aplicar cambios</button>
    </form>
  </div>
</div>

<script>
const BUBBLE = '<?= addslashes($bubble_col) ?>';
const INIT   = '<?= addslashes($init_sec) ?>';

// ── SECCIÓN ────────────────────────────────────────────────────────────────
function switchSec(sec) {
  ['chats','add','settings'].forEach(s => {
    document.getElementById('view-'+s).classList.toggle('hidden', s!==sec);
    const nav = document.getElementById('nav-'+s);
    nav.style.color = s===sec ? BUBBLE : '#6b7280';
  });
}
switchSec(INIT);

// ── SUBPANELES ─────────────────────────────────────────────────────────────
function openSub(id) { document.getElementById('sub-'+id).classList.add('open'); }
function closeSub(id){ document.getElementById('sub-'+id).classList.remove('open'); }

// ── AVATAR PREVIEW ────────────────────────────────────────────────────────
function previewAvatar(inp) {
  if(!inp.files[0]) return;
  const r = new FileReader();
  r.onload = e => document.getElementById('avatar-preview').src = e.target.result;
  r.readAsDataURL(inp.files[0]);
}

// ── TEMA ──────────────────────────────────────────────────────────────────
function selectTheme(t) {
  document.querySelectorAll('[name=theme_mode]').forEach(r => r.value===t ? r.checked=true : null);
  document.getElementById('dark-pill').style.borderColor  = t==='dark'  ? BUBBLE : '#333';
  document.getElementById('light-pill').style.borderColor = t==='light' ? BUBBLE : '#e5e7eb';
}

// ── COLOR BURBUJA ─────────────────────────────────────────────────────────
function selectBubble(col) {
  document.getElementById('bubble-color-input').value = col;
  document.querySelectorAll('.cswatch').forEach(s => s.classList.toggle('sel', s.style.background===col));
  document.getElementById('custom-bubble-color').value = col;
}

// ── FONDO ─────────────────────────────────────────────────────────────────
function selectBg(val) {
  document.getElementById('chat-bg-input').value = val;
  document.querySelectorAll('.bg-preset').forEach(p => p.classList.remove('sel'));
  event.currentTarget?.classList.add('sel');
}
</script>
</body>
</html>
