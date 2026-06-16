<?php
require 'config.php';
verificar_sesion();

if (!isset($_GET['username'])) {
    header('Location: home.php');
    exit;
}

$username = $_GET['username'];

// Obtener las historias activas (48h) de este usuario específico
$stmt = $pdo->prepare('SELECT h.*, u.nombre, u.avatar_url FROM historias h JOIN usuarios u ON h.usuario_id = u.id WHERE u.username = ? AND h.created_at >= NOW() - INTERVAL 48 HOUR ORDER BY h.created_at ASC');
$stmt->execute([$username]);
$user_stories = $stmt->fetchAll();

if (empty($user_stories)) {
    header('Location: home.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Historias de @<?= htmlspecialchars($username) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css">
    <style>
        body { background-color: #000000; font-family: -apple-system, BlinkMacSystemFont, sans-serif; }
        .story-container { max-width: 430px; width: 100%; height: 100vh; position: relative; overflow: hidden; }
        .progress-bar { height: 3px; background-color: rgba(255,255,255,0.3); flex: 1; margin: 0 3px; border-radius: 2px; overflow: hidden; }
        .progress-fill { height: 100%; background-color: #ffffff; width: 0%; transition: width 4s linear; }
    </style>
</head>
<body class="flex justify-center items-center min-h-screen">
    <div class="story-container flex flex-col justify-between bg-black text-white">
        
        <!-- Líneas de tiempo superiores y meta-info del autor -->
        <div class="absolute top-0 left-0 w-full p-4 bg-gradient-to-b from-black/90 to-transparent z-20">
            <div class="flex mb-4">
                <?php foreach($user_stories as $index => $st): ?>
                    <div class="progress-bar">
                        <div id="fill-<?= $index ?>" class="progress-fill"></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <img src="<?= htmlspecialchars($user_stories[0]['avatar_url']) ?>" class="w-10 h-10 rounded-full object-cover border-2 border-purple-500" onerror="this.src='https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=150'">
                    <div>
                        <h4 class="text-sm font-semibold"><?= htmlspecialchars($user_stories[0]['nombre']) ?></h4>
                        <span class="text-[10px] text-gray-300">@<?= htmlspecialchars($username) ?></span>
                    </div>
                </div>
                <a href="home.php" class="text-white text-2xl font-light px-2 focus:outline-none">&times;</a>
            </div>
        </div>

        <!-- Renderizado de la Imagen -->
        <div class="w-full h-full flex items-center justify-center cursor-pointer select-none" onclick="nextStory()">
            <img id="story-img" src="" class="max-w-full max-h-full object-contain">
        </div>
    </div>

    <script>
        const stories = <?= json_encode($user_stories) ?>;
        let currentIdx = 0;
        let timeout;

        function showStory() {
            if (currentIdx >= stories.length) {
                window.location.href = 'home.php';
                return;
            }
            
            document.getElementById('story-img').src = stories[currentIdx].imagen_url;
            
            // Forzar el llenado visual de las barras previas si saltó rápido
            for (let i = 0; i < currentIdx; i++) {
                const prevFill = document.getElementById('fill-' + i);
                if (prevFill) prevFill.style.width = '100%';
            }

            setTimeout(() => {
                const fill = document.getElementById('fill-' + currentIdx);
                if(fill) fill.style.width = '100%';
            }, 50);

            clearTimeout(timeout);
            timeout = setTimeout(() => {
                currentIdx++;
                showStory();
            }, 4000);
        }

        function nextStory() {
            const fill = document.getElementById('fill-' + currentIdx);
            if(fill) fill.style.width = '100%';
            currentIdx++;
            showStory();
        }

        showStory();
    </script>
</body>
</html>