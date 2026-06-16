<?php
require 'config.php';
verificar_sesion();

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['story_file'])) {
    if ($_FILES['story_file']['error'] == UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['story_file']['tmp_name'];
        $file_name = $_FILES['story_file']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        $extensions_allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        
        if (in_array($file_ext, $extensions_allowed)) {
            $new_file_name = 'story_' . $user_id . '_' . time() . '.' . $file_ext;
            $dest_path = 'uploads/' . $new_file_name;
            
            if (move_uploaded_file($file_tmp, $dest_path)) {
                $stmt = $pdo->prepare('INSERT INTO historias (usuario_id, imagen_url) VALUES (?, ?)');
                $stmt->execute([$user_id, $dest_path]);
            }
        }
    }
}

header('Location: home.php');
exit;