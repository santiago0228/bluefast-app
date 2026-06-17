<?php
/*
 * subir_historia.php
 * Acepta: imágenes (jpg, jpeg, png, webp, gif) y videos cortos (mp4, webm, mov)
 * Límite: 50 MB para video, 10 MB para imagen
 * Nota: la validación de duración máxima (60s) se hace en el cliente con JS;
 *       en el servidor solo se valida MIME, extensión y tamaño.
 */
require 'config.php';
verificar_sesion();

$user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['story_file'])) {

    if ($_FILES['story_file']['error'] !== UPLOAD_ERR_OK) {
        $err = [UPLOAD_ERR_INI_SIZE=>'Archivo muy grande (php.ini)',
                UPLOAD_ERR_FORM_SIZE=>'Archivo muy grande (form)',
                UPLOAD_ERR_NO_FILE=>'No se recibió archivo'];
        $msg = $err[$_FILES['story_file']['error']] ?? 'Error al subir';
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['status'=>'error','message'=>$msg]);
        } else {
            header('Location: home.php?story_error='.urlencode($msg));
        }
        exit;
    }

    $file_tmp  = $_FILES['story_file']['tmp_name'];
    $file_name = $_FILES['story_file']['name'];
    $file_size = $_FILES['story_file']['size'];
    $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

    // ── Tipos permitidos ────────────────────────────────────────────────────
    $allowed_ext_img   = ['jpg','jpeg','png','webp','gif'];
    $allowed_ext_video = ['mp4','webm','mov'];
    $allowed_mime_img  = ['image/jpeg','image/png','image/webp','image/gif'];
    $allowed_mime_vid  = ['video/mp4','video/webm','video/quicktime'];

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeReal = $finfo->file($file_tmp);

    $is_img   = in_array($file_ext, $allowed_ext_img,   true) && in_array($mimeReal, $allowed_mime_img, true);
    $is_video = in_array($file_ext, $allowed_ext_video, true) && in_array($mimeReal, $allowed_mime_vid, true);

    if (!$is_img && !$is_video) {
        $msg = 'Solo se permiten imágenes (jpg/png/webp/gif) o videos cortos (mp4/webm/mov)';
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['status'=>'error','message'=>$msg]);
        } else {
            header('Location: home.php?story_error='.urlencode($msg));
        }
        exit;
    }

    // ── Validar tamaño ──────────────────────────────────────────────────────
    $max_size = $is_video ? 50 * 1024 * 1024 : 10 * 1024 * 1024;
    if ($file_size > $max_size) {
        $msg = $is_video ? 'El video no puede superar 50 MB' : 'La imagen no puede superar 10 MB';
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['status'=>'error','message'=>$msg]);
        } else {
            header('Location: home.php?story_error='.urlencode($msg));
        }
        exit;
    }

    // ── Mover archivo ───────────────────────────────────────────────────────
    $new_file_name = 'story_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $file_ext;
    $dest_path     = 'uploads/' . $new_file_name;

    if (move_uploaded_file($file_tmp, $dest_path)) {
        $stmt = $pdo->prepare('INSERT INTO historias (usuario_id, imagen_url) VALUES (?, ?)');
        $stmt->execute([$user_id, $dest_path]);

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['status'=>'ok','url'=>$dest_path,'tipo'=>($is_video?'video':'imagen')]);
        } else {
            header('Location: home.php');
        }
    } else {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['status'=>'error','message'=>'No se pudo guardar el archivo']);
        } else {
            header('Location: home.php?story_error=upload_failed');
        }
    }
    exit;
}

// GET directo → redirigir
header('Location: home.php');
exit;
?>