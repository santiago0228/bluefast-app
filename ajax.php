<?php
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

header('Content-Type: application/json');

$user_id = (int) $_SESSION['user_id'];
$action  = $_GET['action'] ?? '';

// ── PARTNER ──────────────────────────────────────────────────────────────
if (isset($_GET['contact_id'])) {
    $cid = (int) $_GET['contact_id'];
    if ($cid > 0) $_SESSION['partner_id'] = $cid;
}
$partner_id = isset($_SESSION['partner_id']) ? (int) $_SESSION['partner_id'] : null;

// ── MIME / EXT ────────────────────────────────────────────────────────────
// Acepta todos los MIME reales que genera el navegador/móvil para audio/video
const ALLOWED_MIME = [
    'image/jpeg','image/png','image/webp','image/gif',
    'video/mp4','video/webm','video/quicktime',
    'application/pdf',
    'audio/mpeg','audio/mp3','audio/ogg','audio/webm','audio/wav',
    'audio/x-wav','audio/x-m4a','audio/mp4',
];
const ALLOWED_EXT  = ['jpg','jpeg','png','webp','gif','mp4','webm','mov','pdf','mp3','ogg','wav','m4a'];
const MAX_SIZE     = 20 * 1024 * 1024; // 20 MB

function subirArchivo(array $file, string $prefix = ''): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK)  return null;
    if ($file['size']  > MAX_SIZE)          return null;

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mime     = $finfo->file($file['tmp_name']);
    // Strip parámetros del MIME (ej: "audio/webm;codecs=opus" → "audio/webm")
    $mimeBase = strtolower(trim(explode(';', $mime)[0]));

    if (!in_array($mimeBase, ALLOWED_MIME, true)) {
        // Fallback: confiar en extensión si MIME es genérico
        $ext2 = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext2, ALLOWED_EXT, true)) return null;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXT, true)) {
        // Si no tiene extensión válida (ej: blob), inferirla del MIME
        $mimeToExt = [
            'audio/webm'=>'webm','audio/ogg'=>'ogg','audio/mpeg'=>'mp3',
            'audio/wav'=>'wav','audio/mp4'=>'m4a','video/webm'=>'webm',
            'video/mp4'=>'mp4','image/jpeg'=>'jpg','image/png'=>'png',
            'image/webp'=>'webp','image/gif'=>'gif',
        ];
        $ext = $mimeToExt[$mimeBase] ?? 'bin';
    }

    $dest = 'uploads/' . $prefix . time() . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return null;
    return $dest;
}

function tipoDeExt(string $url): string {
    $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg','jpeg','png','webp','gif']))          return 'imagen';
    if (in_array($ext, ['mp4','webm','mov']))                       return 'video';
    if (in_array($ext, ['mp3','ogg','wav','m4a','webm']))           return 'audio';
    if ($ext === 'pdf')                                             return 'archivo';
    return 'archivo';
}

// ─────────────────────────────────────────────────────────────────────────
// 1. ENVIAR MENSAJE
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'send') {
    // Solo requiere que partner_id sea un usuario real — sin barrera de contactos
    if ($partner_id === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Sin destinatario']);
        exit;
    }
    $chkU = $pdo->prepare('SELECT id FROM usuarios WHERE id = ? LIMIT 1');
    $chkU->execute([$partner_id]);
    if (!$chkU->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Destinatario no existe']);
        exit;
    }

    $texto       = isset($_POST['mensaje']) ? trim($_POST['mensaje']) : '';
    $reply_to    = isset($_POST['reply_to']) ? (int) $_POST['reply_to'] : null;
    $archivo_url = null;
    $tipo        = 'texto';

    if (!empty($_FILES['archivo']['name']) || (!empty($_FILES['archivo']['tmp_name']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK)) {
        $archivo_url = subirArchivo($_FILES['archivo']);
        if ($archivo_url === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Archivo no válido o demasiado grande']);
            exit;
        }
        $tipo = tipoDeExt($archivo_url);
        // Audio grabado puede tener ext webm pero es audio
        $mimeReal = strtolower(trim(explode(';', (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['archivo']['tmp_name']))[0]));
        if (str_starts_with($mimeReal, 'audio/')) $tipo = 'audio';
    }

    if (empty($texto) && $archivo_url === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Mensaje vacío']);
        exit;
    }

    $contenido = !empty($texto) ? cifrar($texto) : null;

    if ($reply_to !== null) {
        $chkR = $pdo->prepare('SELECT id FROM mensajes WHERE id=? AND (remitente_id=? OR destinatario_id=?) LIMIT 1');
        $chkR->execute([$reply_to, $user_id, $user_id]);
        if (!$chkR->fetch()) $reply_to = null;
    }

    $stmt = $pdo->prepare('INSERT INTO mensajes (remitente_id,destinatario_id,contenido,tipo,archivo_url,created_at,reply_to) VALUES (?,?,?,?,?,NOW(),?)');
    $stmt->execute([$user_id, $partner_id, $contenido, $tipo, $archivo_url, $reply_to]);

    echo json_encode(['status' => 'ok', 'id' => (int)$pdo->lastInsertId()]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// 2. FETCH MENSAJES
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'fetch') {
    if ($partner_id === null) {
        echo json_encode([]);
        exit;
    }

    $limit  = min((int)($_GET['limit'] ?? 60), 100);
    $before = isset($_GET['before_id']) ? (int)$_GET['before_id'] : PHP_INT_MAX;

    $stmt = $pdo->prepare('
        SELECT m.id, m.remitente_id, m.destinatario_id, m.contenido, m.tipo, m.archivo_url, m.created_at, m.reply_to
        FROM mensajes m
        WHERE ((m.remitente_id=? AND m.destinatario_id=?) OR (m.remitente_id=? AND m.destinatario_id=?))
          AND m.id < ?
        ORDER BY m.id DESC
        LIMIT ?
    ');
    $stmt->execute([$user_id, $partner_id, $partner_id, $user_id, $before, $limit]);
    $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

    foreach ($rows as &$m) {
        $m['id']              = (int)$m['id'];
        $m['remitente_id']    = (int)$m['remitente_id'];
        $m['destinatario_id'] = (int)$m['destinatario_id'];
        $m['reply_to']        = $m['reply_to'] ? (int)$m['reply_to'] : null;
        if ($m['contenido'])  $m['contenido'] = descifrar($m['contenido']);

        // Reacciones
        $rStmt = $pdo->prepare('SELECT emoji, COUNT(*) as total, MAX(usuario_id=?) as mine FROM reacciones WHERE mensaje_id=? GROUP BY emoji');
        $rStmt->execute([$user_id, $m['id']]);
        $m['reacciones']  = $rStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $m['my_reaction'] = null;
        foreach ($m['reacciones'] as $r) {
            if ((int)$r['mine']) { $m['my_reaction'] = $r['emoji']; break; }
        }

        // Reply preview
        $m['reply_texto'] = null;
        $m['reply_user']  = null;
        if ($m['reply_to']) {
            $rpStmt = $pdo->prepare('SELECT m2.contenido, u.nombre FROM mensajes m2 JOIN usuarios u ON u.id=m2.remitente_id WHERE m2.id=? LIMIT 1');
            $rpStmt->execute([$m['reply_to']]);
            $rp = $rpStmt->fetch(PDO::FETCH_ASSOC);
            if ($rp) {
                $m['reply_texto'] = $rp['contenido'] ? descifrar($rp['contenido']) : '[archivo]';
                $m['reply_user']  = $rp['nombre'];
            }
        }
    }
    unset($m);

    echo json_encode($rows);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// 3. SUBIR HISTORIA
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'upload_story') {
    if (empty($_FILES['story_file']) || $_FILES['story_file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'No se recibió archivo']);
        exit;
    }
    $dest = subirArchivo($_FILES['story_file'], 'story_' . $user_id . '_');
    if (!$dest) {
        http_response_code(400);
        echo json_encode(['error' => 'Solo imágenes permitidas']);
        exit;
    }
    $pdo->prepare('INSERT INTO historias (usuario_id,imagen_url) VALUES (?,?)')->execute([$user_id, $dest]);
    echo json_encode(['status' => 'ok', 'url' => $dest]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// 4. FETCH HISTORIAS
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'fetch_stories') {
    $stmt = $pdo->prepare('
        SELECT h.id, h.usuario_id, h.imagen_url, h.created_at, u.username, u.nombre, u.avatar_url
        FROM historias h JOIN usuarios u ON h.usuario_id=u.id
        WHERE h.created_at >= NOW() - INTERVAL 48 HOUR
        ORDER BY h.created_at DESC LIMIT 200
    ');
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id']         = (int)$r['id'];
        $r['usuario_id'] = (int)$r['usuario_id'];
    }
    unset($r);
    echo json_encode($rows);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// 5. INFO DEL PARTNER
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'get_partner_info') {
    if (!$partner_id) { echo json_encode(null); exit; }
    $stmt = $pdo->prepare('SELECT id,username,nombre,avatar_url,bio,peer_id FROM usuarios WHERE id=? LIMIT 1');
    $stmt->execute([$partner_id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($p) $p['id'] = (int)$p['id'];
    echo json_encode($p ?: null);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// 6. UPDATE PEER ID
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'update_peer') {
    $pid = isset($_POST['peer_id']) ? trim($_POST['peer_id']) : null;
    if ($pid && !preg_match('/^[a-zA-Z0-9_\-]{1,80}$/', $pid)) $pid = null;
    $pdo->prepare('UPDATE usuarios SET peer_id=? WHERE id=?')->execute([$pid, $user_id]);
    echo json_encode(['status' => 'ok']);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// 7. ELIMINAR MENSAJE
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'delete_msg') {
    $mid = (int)($_POST['msg_id'] ?? 0);
    if (!$mid) { http_response_code(400); echo json_encode(['error'=>'ID inválido']); exit; }
    $stmt = $pdo->prepare('DELETE FROM mensajes WHERE id=? AND remitente_id=?');
    $stmt->execute([$mid, $user_id]);
    echo json_encode($stmt->rowCount() > 0 ? ['status'=>'ok'] : ['error'=>'No autorizado']);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// 8. REACCIONAR
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'react') {
    $mid   = (int)($_POST['msg_id'] ?? 0);
    $emoji = trim($_POST['emoji'] ?? '');
    if (!$mid) { http_response_code(400); echo json_encode(['error'=>'ID inválido']); exit; }
    $chk = $pdo->prepare('SELECT id FROM mensajes WHERE id=? AND (remitente_id=? OR destinatario_id=?) LIMIT 1');
    $chk->execute([$mid, $user_id, $user_id]);
    if (!$chk->fetch()) { http_response_code(403); echo json_encode(['error'=>'Acceso denegado']); exit; }
    if ($emoji === 'remove') {
        $pdo->prepare('DELETE FROM reacciones WHERE mensaje_id=? AND usuario_id=?')->execute([$mid, $user_id]);
    } else {
        if (mb_strlen($emoji) > 8) { http_response_code(400); echo json_encode(['error'=>'Emoji inválido']); exit; }
        $pdo->prepare('INSERT INTO reacciones (mensaje_id,usuario_id,emoji) VALUES (?,?,?) ON DUPLICATE KEY UPDATE emoji=VALUES(emoji),fecha=NOW()')->execute([$mid, $user_id, $emoji]);
    }
    echo json_encode(['status'=>'ok']);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// 9. BLOQUEAR
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'block') {
    if (!$partner_id) { http_response_code(400); echo json_encode(['error'=>'Sin partner']); exit; }
    $pdo->prepare('INSERT IGNORE INTO bloqueados (usuario_id,bloqueado_id) VALUES (?,?)')->execute([$user_id, $partner_id]);
    echo json_encode(['status'=>'ok']);
    exit;
}

http_response_code(400);
echo json_encode(['error'=>'Acción no reconocida']);