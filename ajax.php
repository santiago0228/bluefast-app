<?php
/*
 * SQL REQUERIDO (ejecutar una sola vez):
 *
 * ALTER TABLE usuarios ADD COLUMN typing_at TIMESTAMP NULL DEFAULT NULL;
 * ALTER TABLE mensajes ADD COLUMN leido_at TIMESTAMP NULL DEFAULT NULL;
 * ALTER TABLE mensajes ADD COLUMN fijado TINYINT(1) DEFAULT 0;
 *
 * CREATE TABLE IF NOT EXISTS solicitudes (
 *   id INT AUTO_INCREMENT PRIMARY KEY,
 *   remitente_id INT NOT NULL,
 *   destinatario_id INT NOT NULL,
 *   estado ENUM('pendiente','aceptada','rechazada') DEFAULT 'pendiente',
 *   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *   FOREIGN KEY (remitente_id) REFERENCES usuarios(id),
 *   FOREIGN KEY (destinatario_id) REFERENCES usuarios(id),
 *   UNIQUE KEY uq_sol (remitente_id, destinatario_id)
 * );
 *
 * CREATE TABLE IF NOT EXISTS historia_vistas (
 *   id INT AUTO_INCREMENT PRIMARY KEY,
 *   historia_id INT NOT NULL,
 *   usuario_id INT NOT NULL,
 *   visto_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *   FOREIGN KEY (historia_id) REFERENCES historias(id) ON DELETE CASCADE,
 *   UNIQUE KEY uq_vista (historia_id, usuario_id)
 * );
 */

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
    'video/mp4', 'video/webm', 'video/quicktime',
    'application/pdf',
    'audio/mpeg', 'audio/ogg',
];
const ALLOWED_EXT  = ['jpg','jpeg','png','webp','gif','mp4','webm','mov','pdf','mp3','ogg'];
const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB
const MAX_STORY_VIDEO_SIZE = 50 * 1024 * 1024; // 50 MB para historias de video

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
        $ext  = strtolower(pathinfo($archivo_url, PATHINFO_EXTENSION));
        $tipo = in_array($ext, ['jpg','jpeg','png','webp','gif']) ? 'imagen'
               : (in_array($ext, ['mp4','webm','mov'])           ? 'video'
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
// 2. FETCH MENSAJES  (con reacciones, leido_at y fijado)
// ────────────────────────────────────────────────────────────────────────
if ($action === 'fetch') {
    if (!verificarAccesoConversacion($pdo, $user_id, $partner_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Acceso denegado']);
        exit;
    }

    $limit  = min((int) ($_GET['limit'] ?? 50), 100);
    $before = isset($_GET['before_id']) ? (int) $_GET['before_id'] : PHP_INT_MAX;

    $stmt = $pdo->prepare("
        SELECT m.id, m.remitente_id, m.destinatario_id, m.contenido, m.tipo, m.archivo_url,
               m.created_at, m.reply_to, m.leido_at, m.fijado,
               rm.contenido AS reply_contenido, ru.nombre AS reply_user
        FROM mensajes m
        LEFT JOIN mensajes rm ON rm.id = m.reply_to
        LEFT JOIN usuarios ru ON ru.id = rm.remitente_id
        WHERE ((m.remitente_id = ? AND m.destinatario_id = ?)
            OR (m.remitente_id = ? AND m.destinatario_id = ?))
          AND m.id < ?
        ORDER BY m.id DESC
        LIMIT $limit
    ");
    $stmt->execute([$user_id, $partner_id, $partner_id, $user_id, $before]);
    $rows = array_reverse($stmt->fetchAll());

    // Cargar reacciones de todos los mensajes en una sola query
    if (!empty($rows)) {
        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // Reacciones agrupadas
        $stmtR = $pdo->prepare("
            SELECT mensaje_id, emoji, COUNT(*) as total
            FROM reacciones
            WHERE mensaje_id IN ($placeholders)
            GROUP BY mensaje_id, emoji
        ");
        $stmtR->execute($ids);
        $reactions = [];
        foreach ($stmtR->fetchAll() as $r) {
            $reactions[(int)$r['mensaje_id']][] = [
                'emoji' => $r['emoji'],
                'total' => (int)$r['total']
            ];
        }

        // Mi reacción
        $stmtMy = $pdo->prepare("
            SELECT mensaje_id, emoji FROM reacciones
            WHERE mensaje_id IN ($placeholders) AND usuario_id = ?
        ");
        $stmtMy->execute(array_merge($ids, [$user_id]));
        $myReactions = [];
        foreach ($stmtMy->fetchAll() as $r) {
            $myReactions[(int)$r['mensaje_id']] = $r['emoji'];
        }

        foreach ($rows as &$m) {
            $m['id']              = (int) $m['id'];
            $m['remitente_id']    = (int) $m['remitente_id'];
            $m['destinatario_id'] = (int) $m['destinatario_id'];
            $m['fijado']          = (int) ($m['fijado'] ?? 0);
            if ($m['contenido'])      $m['contenido']      = descifrar($m['contenido']);
            if ($m['reply_contenido'])$m['reply_texto']    = descifrar($m['reply_contenido']);
            if ($m['reply_to'])       $m['reply_to']       = (int) $m['reply_to'];
            $m['reacciones']  = $reactions[(int)$m['id']] ?? [];
            $m['my_reaction'] = $myReactions[(int)$m['id']] ?? null;
            $m['leido']       = !empty($m['leido_at']);
            unset($m['reply_contenido']);
        }
        unset($m);
    }

    echo json_encode($rows);
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// 3. SUBIR HISTORIA (imagen o video)
// ────────────────────────────────────────────────────────────────────────
if ($action === 'upload_story') {
    if (empty($_FILES['story_file']['name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No se recibió archivo']);
        exit;
    }

    $allowedMimeStory = [
        'image/jpeg','image/png','image/webp','image/gif',
        'video/mp4','video/webm','video/quicktime'
    ];
    $allowedExtStory = ['jpg','jpeg','png','webp','gif','mp4','webm','mov'];

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeReal = $finfo->file($_FILES['story_file']['tmp_name']);

    if (!in_array($mimeReal, $allowedMimeStory, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Solo se permiten imágenes o videos cortos']);
        exit;
    }

    // Límite de tamaño: 50 MB para video, 10 MB para imagen
    $isVideo = in_array($mimeReal, ['video/mp4','video/webm','video/quicktime'], true);
    $maxSize = $isVideo ? MAX_STORY_VIDEO_SIZE : MAX_FILE_SIZE;

    if ($_FILES['story_file']['size'] > $maxSize) {
        http_response_code(400);
        echo json_encode(['error' => $isVideo ? 'El video no puede superar 50 MB' : 'La imagen no puede superar 10 MB']);
        exit;
    }

    $ext = strtolower(pathinfo($_FILES['story_file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtStory, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Extensión no permitida']);
        exit;
    }

    $dest = 'uploads/story_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['story_file']['tmp_name'], $dest)) {
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
// 4. FETCH HISTORIAS  (solo de contactos mutuos + propias)
// ────────────────────────────────────────────────────────────────────────
if ($action === 'fetch_stories') {
    // Traer historias de: yo mismo + contactos que me tienen (bidireccional)
    $stmt = $pdo->prepare('
        SELECT h.id, h.usuario_id, h.imagen_url, h.created_at,
               u.username, u.nombre, u.avatar_url
        FROM historias h
        JOIN usuarios u ON h.usuario_id = u.id
        WHERE h.created_at >= NOW() - INTERVAL 48 HOUR
          AND (
              h.usuario_id = ?
              OR h.usuario_id IN (
                  SELECT c.contacto_id FROM contactos c WHERE c.usuario_id = ?
                  UNION
                  SELECT c2.usuario_id FROM contactos c2 WHERE c2.contacto_id = ?
              )
          )
        ORDER BY h.created_at DESC
        LIMIT 200
    ');
    $stmt->execute([$user_id, $user_id, $user_id]);
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
// 8. ELIMINAR HISTORIA (solo el dueño)
// ────────────────────────────────────────────────────────────────────────
if ($action === 'delete_story') {
    $story_id = (int) ($_POST['story_id'] ?? 0);
    if (!$story_id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID inválido']);
        exit;
    }

    // Obtener la URL del archivo antes de borrar
    $stmtSel = $pdo->prepare('SELECT imagen_url FROM historias WHERE id = ? AND usuario_id = ? LIMIT 1');
    $stmtSel->execute([$story_id, $user_id]);
    $story = $stmtSel->fetch();

    if (!$story) {
        http_response_code(403);
        echo json_encode(['error' => 'No autorizado o historia no encontrada']);
        exit;
    }

    // Borrar de BD
    $stmt = $pdo->prepare('DELETE FROM historias WHERE id = ? AND usuario_id = ?');
    $stmt->execute([$story_id, $user_id]);

    // Intentar borrar archivo físico
    if ($story['imagen_url'] && file_exists($story['imagen_url'])) {
        @unlink($story['imagen_url']);
    }

    echo json_encode(['status' => 'ok']);
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// 9. REACCIONAR A MENSAJE
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
// 10. BLOQUEAR USUARIO
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

// ────────────────────────────────────────────────────────────────────────
// 11. NOTIFICACIONES (polling)
// ────────────────────────────────────────────────────────────────────────
if ($action === 'fetch_notifications') {
    // Mensajes no leídos por conversación (recibidos por mí, sin leido_at)
    $stmtUnread = $pdo->prepare('
        SELECT remitente_id, COUNT(*) as total
        FROM mensajes
        WHERE destinatario_id = ?
          AND leido_at IS NULL
        GROUP BY remitente_id
    ');
    $stmtUnread->execute([$user_id]);
    $unread = [];
    foreach ($stmtUnread->fetchAll() as $row) {
        $unread[(int)$row['remitente_id']] = (int)$row['total'];
    }

    // Solicitudes de contacto pendientes
    $stmtReq = $pdo->prepare('
        SELECT COUNT(*) as total FROM solicitudes
        WHERE destinatario_id = ? AND estado = ?
    ');
    $stmtReq->execute([$user_id, 'pendiente']);
    $pendingRequests = (int)$stmtReq->fetchColumn();

    echo json_encode([
        'unread'          => $unread,
        'total_unread'    => array_sum($unread),
        'pending_requests'=> $pendingRequests,
    ]);
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// 12. MARCAR MENSAJES COMO LEÍDOS
// ────────────────────────────────────────────────────────────────────────
if ($action === 'mark_read') {
    if ($partner_id === null) {
        echo json_encode(['status' => 'ok']);
        exit;
    }
    $pdo->prepare('
        UPDATE mensajes
        SET leido_at = NOW()
        WHERE destinatario_id = ?
          AND remitente_id = ?
          AND leido_at IS NULL
    ')->execute([$user_id, $partner_id]);
    echo json_encode(['status' => 'ok']);
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// 13. INDICADOR "ESCRIBIENDO..."
// ────────────────────────────────────────────────────────────────────────
if ($action === 'typing') {
    // Actualiza timestamp de escritura del usuario actual
    $pdo->prepare('UPDATE usuarios SET typing_at = NOW() WHERE id = ?')->execute([$user_id]);
    echo json_encode(['status' => 'ok']);
    exit;
}

if ($action === 'check_typing') {
    // Devuelve si el partner está escribiendo (actividad en los últimos 3 segundos)
    if ($partner_id === null) {
        echo json_encode(['typing' => false]);
        exit;
    }
    $stmt = $pdo->prepare('
        SELECT typing_at FROM usuarios
        WHERE id = ?
          AND typing_at >= NOW() - INTERVAL 3 SECOND
        LIMIT 1
    ');
    $stmt->execute([$partner_id]);
    $row = $stmt->fetch();
    echo json_encode(['typing' => (bool)$row]);
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// 14. FIJAR / DESFIJAR MENSAJE
// ────────────────────────────────────────────────────────────────────────
if ($action === 'pin_msg') {
    $msg_id = (int) ($_POST['msg_id'] ?? 0);
    if (!$msg_id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID inválido']);
        exit;
    }

    // Verificar que el mensaje pertenece a esta conversación
    $chk = $pdo->prepare('
        SELECT id, fijado FROM mensajes
        WHERE id = ?
          AND (remitente_id = ? OR destinatario_id = ?)
        LIMIT 1
    ');
    $chk->execute([$msg_id, $user_id, $user_id]);
    $msg = $chk->fetch();

    if (!$msg) {
        http_response_code(403);
        echo json_encode(['error' => 'No autorizado']);
        exit;
    }

    $nuevo = $msg['fijado'] ? 0 : 1;
    $pdo->prepare('UPDATE mensajes SET fijado = ? WHERE id = ?')->execute([$nuevo, $msg_id]);
    echo json_encode(['status' => 'ok', 'fijado' => $nuevo]);
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// 15. SOLICITUDES DE CONTACTO
// ────────────────────────────────────────────────────────────────────────
if ($action === 'send_request') {
    $dest_id = (int) ($_POST['dest_id'] ?? 0);
    if (!$dest_id || $dest_id === $user_id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID inválido']);
        exit;
    }
    // Verificar que el destinatario existe
    $chk = $pdo->prepare('SELECT id FROM usuarios WHERE id = ? LIMIT 1');
    $chk->execute([$dest_id]);
    if (!$chk->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuario no encontrado']);
        exit;
    }
    // Verificar si ya son contactos
    $chk2 = $pdo->prepare('SELECT id FROM contactos WHERE usuario_id=? AND contacto_id=? LIMIT 1');
    $chk2->execute([$user_id, $dest_id]);
    if ($chk2->fetch()) {
        echo json_encode(['status' => 'already_contacts']);
        exit;
    }
    try {
        $pdo->prepare('
            INSERT INTO solicitudes (remitente_id, destinatario_id) VALUES (?,?)
            ON DUPLICATE KEY UPDATE estado=IF(estado="rechazada","pendiente",estado), created_at=NOW()
        ')->execute([$user_id, $dest_id]);
        echo json_encode(['status' => 'ok']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'exists']);
    }
    exit;
}

if ($action === 'accept_request') {
    $req_id = (int) ($_POST['req_id'] ?? 0);
    if (!$req_id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID inválido']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT * FROM solicitudes WHERE id=? AND destinatario_id=? AND estado=? LIMIT 1');
    $stmt->execute([$req_id, $user_id, 'pendiente']);
    $sol = $stmt->fetch();
    if (!$sol) {
        http_response_code(403);
        echo json_encode(['error' => 'Solicitud no encontrada']);
        exit;
    }
    // Actualizar estado
    $pdo->prepare('UPDATE solicitudes SET estado=? WHERE id=?')->execute(['aceptada', $req_id]);
    // Insertar en contactos (bidireccional)
    $pdo->prepare('INSERT IGNORE INTO contactos (usuario_id, contacto_id) VALUES (?,?)')->execute([$user_id, $sol['remitente_id']]);
    $pdo->prepare('INSERT IGNORE INTO contactos (usuario_id, contacto_id) VALUES (?,?)')->execute([$sol['remitente_id'], $user_id]);
    echo json_encode(['status' => 'ok']);
    exit;
}

if ($action === 'reject_request') {
    $req_id = (int) ($_POST['req_id'] ?? 0);
    if (!$req_id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID inválido']);
        exit;
    }
    $pdo->prepare('UPDATE solicitudes SET estado=? WHERE id=? AND destinatario_id=?')
        ->execute(['rechazada', $req_id, $user_id]);
    echo json_encode(['status' => 'ok']);
    exit;
}

if ($action === 'fetch_requests') {
    $stmt = $pdo->prepare('
        SELECT s.id, s.remitente_id, s.created_at,
               u.username, u.nombre, u.avatar_url
        FROM solicitudes s
        JOIN usuarios u ON u.id = s.remitente_id
        WHERE s.destinatario_id = ? AND s.estado = ?
        ORDER BY s.created_at DESC
    ');
    $stmt->execute([$user_id, 'pendiente']);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['id']           = (int)$r['id'];
        $r['remitente_id'] = (int)$r['remitente_id'];
    }
    unset($r);
    echo json_encode($rows);
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// 16. VISTAS DE HISTORIAS
// ────────────────────────────────────────────────────────────────────────
if ($action === 'register_view') {
    $story_id = (int) ($_POST['story_id'] ?? 0);
    if (!$story_id) {
        echo json_encode(['status' => 'ok']);
        exit;
    }
    // No registrar vista propia
    $chk = $pdo->prepare('SELECT usuario_id FROM historias WHERE id=? LIMIT 1');
    $chk->execute([$story_id]);
    $owner = $chk->fetchColumn();
    if ($owner && (int)$owner !== $user_id) {
        $pdo->prepare('INSERT IGNORE INTO historia_vistas (historia_id, usuario_id) VALUES (?,?)')
            ->execute([$story_id, $user_id]);
    }
    echo json_encode(['status' => 'ok']);
    exit;
}

if ($action === 'get_story_views') {
    $story_id = (int) ($_GET['story_id'] ?? 0);
    if (!$story_id) {
        echo json_encode(['count' => 0, 'viewers' => []]);
        exit;
    }
    // Solo el dueño puede ver quién la vio
    $chk = $pdo->prepare('SELECT usuario_id FROM historias WHERE id=? LIMIT 1');
    $chk->execute([$story_id]);
    $owner = (int)$chk->fetchColumn();
    if ($owner !== $user_id) {
        echo json_encode(['count' => 0, 'viewers' => []]);
        exit;
    }
    $stmt = $pdo->prepare('
        SELECT hv.visto_at, u.username, u.nombre, u.avatar_url
        FROM historia_vistas hv
        JOIN usuarios u ON u.id = hv.usuario_id
        WHERE hv.historia_id = ?
        ORDER BY hv.visto_at DESC
    ');
    $stmt->execute([$story_id]);
    $viewers = $stmt->fetchAll();
    echo json_encode(['count' => count($viewers), 'viewers' => $viewers]);
    exit;
}

// Acción desconocida
http_response_code(400);
echo json_encode(['error' => 'Acción no reconocida']);
?>