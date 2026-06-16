<?php
require 'config.php';
verificar_sesion();

$user_id = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: home.php');
    exit;
}

// ── Recuperar datos actuales ─────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT avatar_url FROM usuarios WHERE id = ? LIMIT 1');
$stmt->execute([$user_id]);
$current = $stmt->fetch();
$avatar_url = $current['avatar_url'] ?? null;

// ── Validar y sanear campos de texto ────────────────────────────────────
$nombre      = trim($_POST['nombre']      ?? '');
$bio         = trim($_POST['bio']         ?? '');
$bubble_color = trim($_POST['bubble_color'] ?? '#18033B');
$chat_bg     = trim($_POST['chat_bg']     ?? '');

// Validar color hex
if (!preg_match('/^#[0-9A-Fa-f]{3,6}$/', $bubble_color)) {
    $bubble_color = '#18033B';
}

// Limitar longitudes
$nombre      = mb_substr($nombre, 0, 100);
$bio         = mb_substr($bio,    0, 500);
$chat_bg     = mb_substr($chat_bg, 0, 500);

// ── Subida de avatar ─────────────────────────────────────────────────────
if (isset($_FILES['avatar_file']) && $_FILES['avatar_file']['error'] === UPLOAD_ERR_OK) {
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeReal = $finfo->file($_FILES['avatar_file']['tmp_name']);
    $allowedAvatar = ['image/jpeg','image/png','image/webp','image/gif'];

    if (in_array($mimeReal, $allowedAvatar, true) && $_FILES['avatar_file']['size'] <= 5 * 1024 * 1024) {
        $ext      = strtolower(pathinfo($_FILES['avatar_file']['name'], PATHINFO_EXTENSION));
        $newName  = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
        $destPath = 'uploads/' . $newName;

        if (move_uploaded_file($_FILES['avatar_file']['tmp_name'], $destPath)) {
            $avatar_url = $destPath;
        }
    }
}

// ── UPDATE usando solo columnas que existen en el schema ─────────────────
// CORRECCIÓN: eliminadas theme_mode y chat_color que no están en el CREATE TABLE
// Usar bubble_color y chat_bg que sí existen.
$stmt = $pdo->prepare('
    UPDATE usuarios
    SET nombre       = ?,
        bio          = ?,
        bubble_color = ?,
        chat_bg      = ?,
        avatar_url   = ?
    WHERE id = ?
');
$stmt->execute([$nombre, $bio, $bubble_color, $chat_bg, $avatar_url, $user_id]);

header('Location: home.php');
exit;
?>