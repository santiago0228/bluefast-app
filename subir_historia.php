<?php
require 'config.php';
verificar_sesion();

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['story_file'])) {
    if ($_FILES['story_file']['error'] == UPLOAD_ERR_OK) {
        $file_tmp  = $_FILES['story_file']['tmp_name'];
        $file_name = $_FILES['story_file']['name'];
        $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        $extensions_allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        // Validar MIME real además de extensión
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeReal = $finfo->file($file_tmp);
        $mimeOk   = ['image/jpeg','image/png','image/webp','image/gif'];

        if (in_array($file_ext, $extensions_allowed) && in_array($mimeReal, $mimeOk)) {
            $new_file_name = 'story_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $file_ext;
            $dest_path = 'uploads/' . $new_file_name;

            if (move_uploaded_file($file_tmp, $dest_path)) {
                $stmt = $pdo->prepare('INSERT INTO historias (usuario_id, imagen_url) VALUES (?, ?)');
                $stmt->execute([$user_id, $dest_path]);
            }
        }
    }
}

// Si viene de fetch AJAX devolver JSON, sino redirect
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
} else {
    header('Location: home.php');
}
exit;