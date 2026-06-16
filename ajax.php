<?php
require 'config.php';

// Autenticación obligatoria para todas las acciones
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

header('Content-Type: application/json');

$user_id = (int) $_SESSION['user_id'];
$action  = $_GET['action'] ?? '';

// ── PERSISTENCIA DEL PARTNER con validación ──────────────────────────────
if (isset($_GET['contact_id'])) {
    $contact_id = (int) $_GET['contact_id'];
    $chk = $pdo->prepare('SELECT id FROM usuarios WHERE id = ? LIMIT 1');
    $chk->execute([$contact_id]);
    if ($chk->fetch()) {
        $_SESSION['partner_id'] = $contact_id;
    }
}
$partner_id = isset($_SESSION['partner_id']) ? (int) $_SESSION['partner_id'] : null;

// ── TIPOS MIME PERMITIDOS ────────────────────────────────────────────────
const ALLOWED_MIME = [
    'image/jpeg', 'image/png', 'image/webp', 'image/gif',
    'video/mp4', 'video/webm',
    'application/pdf',
    'audio/mpeg', 'audio/ogg',
];
const ALLOWED_EXT = ['jpg','jpeg','png','webp','gif','mp4','webm','pdf','mp3','ogg'];
const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB

function subirArchivo(array $file, string $prefijo = ''): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > MAX_FILE_SIZE) return null;

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeReal = $finfo->file($file['tmp_name']);
    if (!in_array($mimeReal, ALLOWED_MIME, true)) return null;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXT, true)) return null;

    $dest = 'uploads/' . $prefijo . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return null;
    return $dest;
}

function verificarAccesoConversacion(PDO $pdo, int $user_id, ?int $partner_id): bool {
    if ($partner_id === null) return false;

    $stmt = $pdo->prepare('
        SELECT id FROM contactos
        WHERE (usuario_id = ? AND contacto_id = ?)
           OR (usuario_id = ? AND contacto_id = ?)
        LIMIT 1
    ');
    $stmt->execute([$user_id, $partner_id, $partner_id, $user_id]);
    if ($stmt->fetch()) return true;

    $stmt2 = $pdo->prepare('
        SELECT id FROM mensajes
        WHERE (remitente_id = ? AND destinatario_id = ?)
           OR (remitente_id = ? AND destinatario_id = ?)
        LIMIT 1
    ');
    $stmt2->execute([$user_id, $partner_id, $partner_id, $user_id]);
    return (bool) $stmt2->fetch();
}

// ────────────────────────────────────────────────────────────────────────
// 1. ENVIAR MENSAJE
// ────────────────────────────────────────────────────────────────────────
if ($action === 'send') {
    if (!verificarAccesoConversacion($pdo, $user_id, $partner_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Acceso denegado']);
        exit;
    }

    $texto       = isset($_POST['mensaje']) ? trim($_POST['mensaje']) : '';
    $reply_to    = isset($_POST['reply_to']) ? (int) $_POST['reply_to'] : null;
    $archivo_url = null;
    $tipo        = 'texto';

    if (!empty($_FILES['archivo']['name'])) {
        $archivo_url = subirArchivo($_FILES['archivo']);
        if ($archivo_url === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Archivo no válido o demasiado grande']);
            exit;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['archivo']['tmp_name']);
        $ext  = strtolower(pathinfo($archivo_url, PATHINFO_EXTENSION));
        $tipo = in_array($ext, ['jpg','jpeg','png','webp','gif']) ? 'imagen'
               : (in_array($ext, ['mp4','webm'])                 ? 'video'
               : (in_array($ext, ['mp3','ogg'])                  ? 'audio'
               : 'archivo'));
    }

    if (empty($texto) && $archivo_url === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Mensaje vacío']);
        exit;
    }

    $contenido = !empty($texto) ? cifrar($texto) : null;

    if ($reply_to !== null) {
        $chkReply = $pdo->prepare('
            SELECT id FROM mensajes
            WHERE id = ?
              AND ((remitente_id = ? AND destinatario_id = ?)
                OR (remitente_id = ? AND destinatario_id = ?))
            LIMIT 1
        ');
        $chkReply->execute([$reply_to, $user_id, $partner_id, $partner_id, $user_id]);
        if (!$chkReply->fetch()) $reply_to = null;
    }

    $stmt = $pdo->prepare('
        INSERT INTO mensajes (remitente_id, destinatario_id, contenido, tipo, archivo_url, created_at, reply_to)
        VALUES (?, ?, ?, ?, ?, NOW(), ?)
    ');
    $stmt->execute([$user_id, $partner_id, $contenido, $tipo, $archivo_url, $reply_to]);

    echo json_encode(['status' => 'ok', 'id' => (int) $pdo->lastInsertId()]);
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// 2. FETCH MENSAJES
// ────────────────────────────────────────────────────────────────────────
if ($action === 'fetch') {
    if (!verificarAccesoConversacion($pdo, $user_id, $partner_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Acceso denegado']);
        exit;
    }

    // CORRECCIÓN: LIMIT con entero directo en query para evitar error SQL con PDO
    $limit  = min((int) ($_GET['limit'] ?? 50), 100);
    $before = isset($_GET['before_id']) ? (int) $_GET['before_id'] : PHP_INT_MAX;

    $stmt = $pdo->prepare("
        SELECT id, remitente_id, destinatario_id, contenido, tipo, archivo_url, created_at, reply_to
        FROM mensajes
        WHERE ((remitente_id = ? AND destinatario_id = ?)
            OR (remitente_id = ? AND destinatario_id = ?))
          AND id < ?
        ORDER BY id DESC
        LIMIT $limit
    ");
    $stmt->execute([$user_id, $partner_id, $partner_id, $user_id, $before]);
    $rows = array_reverse($stmt->fetchAll());

    foreach ($rows as &$m) {
        $m['id']              = (int) $m['id'];
        $m['remitente_id']    = (int) $m['remitente_id'];
        $m['destinatario_id'] = (int) $m['destinatario_id'];
        if ($m['contenido'])  $m['contenido'] = descifrar($m['contenido']);
        if ($m['reply_to'])   $m['reply_to']  = (int) $m['reply_to'];
    }
    unset($m);

    echo json_encode($rows);
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// 3. SUBIR HISTORIA
// ────────────────────────────────────────────────────────────────────────
if ($action === 'upload_story') {
    if (empty($_FILES['story_file']['name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No se recibió archivo']);
        exit;
    }

    $allowedMimeStory = ['image/jpeg','image/png','image/webp','image/gif'];
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeReal = $finfo->file($_FILES['story_file']['tmp_name']);

    if (!in_array($mimeReal, $allowedMimeStory, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Solo se permiten imágenes']);
        exit;
    }

    $dest = subirArchivo($_FILES['story_file'], 'story_' . $user_id . '_');
    if ($dest === null) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al subir']);
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO historias (usuario_id, imagen_url) VALUES (?, ?)');
    $stmt->execute([$user_id, $dest]);

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        echo json_encode(['status' => 'ok', 'url' => $dest]);
    } else {
        header('Location: home.php');
    }
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// 4. FETCH HISTORIAS
// ────────────────────────────────────────────────────────────────────────
if ($action === 'fetch_stories') {
    $stmt = $pdo->prepare('
        SELECT h.id, h.usuario_id, h.imagen_url, h.created_at,
               u.username, u.nombre, u.avatar_url
        FROM historias h
        JOIN usuarios u ON h.usuario_id = u.id
        WHERE h.created_at >= NOW() - INTERVAL 24 HOUR
        ORDER BY h.created_at DESC
        LIMIT 200
    ');
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['id']         = (int) $r['id'];
        $r['usuario_id'] = (int) $r['usuario_id'];
        $r['imagen_url'] = htmlspecialchars($r['imagen_url'], ENT_QUOTES, 'UTF-8');
        $r['avatar_url'] = htmlspecialchars($r['avatar_url'] ?? '', ENT_QUOTES, 'UTF-8');
    }
    unset($r);
    echo json_encode($rows);
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// 5. INFO DEL PARTNER
// ────────────────────────────────────────────────────────────────────────
if ($action === 'get_partner_info') {
    if ($partner_id === null) {
        echo json_encode(null);
        exit;
    }
    $stmt = $pdo->prepare('SELECT id, username, nombre, avatar_url, peer_id FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$partner_id]);
    $partner = $stmt->fetch();
    if ($partner) {
        $partner['id'] = (int) $partner['id'];
        unset($partner['password']);
    }
    echo json_encode($partner ?: null);
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// 6. ACTUALIZAR PEER ID (WebRTC)
// ────────────────────────────────────────────────────────────────────────
if ($action === 'update_peer') {
    $peer_id = isset($_POST['peer_id']) ? trim($_POST['peer_id']) : null;
    if ($peer_id !== null && !preg_match('/^[a-zA-Z0-9\-]{1,64}$/', $peer_id)) {
        $peer_id = null;
    }
    $pdo->prepare('UPDATE usuarios SET peer_id = ? WHERE id = ?')->execute([$peer_id, $user_id]);
    echo json_encode(['status' => 'ok']);
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// 7. ELIMINAR MENSAJE
// ────────────────────────────────────────────────────────────────────────
if ($action === 'delete_msg') {
    $msg_id = (int) ($_POST['msg_id'] ?? 0);
    if (!$msg_id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID inválido']);
        exit;
    }
    $stmt = $pdo->prepare('DELETE FROM mensajes WHERE id = ? AND remitente_id = ?');
    $stmt->execute([$msg_id, $user_id]);
    if ($stmt->rowCount() > 0) {
        echo json_encode(['status' => 'ok']);
    } else {
        http_response_code(403);
        echo json_encode(['error' => 'No autorizado o mensaje no encontrado']);
    }
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// 8. REACCIONAR A MENSAJE
// ────────────────────────────────────────────────────────────────────────
if ($action === 'react') {
    $msg_id = (int) ($_POST['msg_id'] ?? 0);
    $emoji  = trim($_POST['emoji'] ?? '');

    if (!$msg_id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID inválido']);
        exit;
    }

    $chk = $pdo->prepare('SELECT id FROM mensajes WHERE id = ? AND (remitente_id=? OR destinatario_id=?) LIMIT 1');
    $chk->execute([$msg_id, $user_id, $user_id]);
    if (!$chk->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Acceso denegado']);
        exit;
    }

    if ($emoji === 'remove') {
        $pdo->prepare('DELETE FROM reacciones WHERE mensaje_id=? AND usuario_id=?')
            ->execute([$msg_id, $user_id]);
    } else {
        if (mb_strlen($emoji) > 8) {
            http_response_code(400);
            echo json_encode(['error' => 'Emoji inválido']);
            exit;
        }
        $pdo->prepare('INSERT INTO reacciones (mensaje_id, usuario_id, emoji) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE emoji=VALUES(emoji), fecha=NOW()')
            ->execute([$msg_id, $user_id, $emoji]);
    }
    echo json_encode(['status' => 'ok']);
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// 9. BLOQUEAR USUARIO
// ────────────────────────────────────────────────────────────────────────
if ($action === 'block') {
    if ($partner_id === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Sin partner']);
        exit;
    }
    $pdo->prepare('INSERT IGNORE INTO bloqueados (usuario_id, bloqueado_id) VALUES (?,?)')
        ->execute([$user_id, $partner_id]);
    echo json_encode(['status' => 'ok']);
    exit;
}

// Acción desconocida
http_response_code(400);
echo json_encode(['error' => 'Acción no reconocida']);
?>