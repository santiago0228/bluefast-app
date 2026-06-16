<?php
require 'config.php';
if (isset($_SESSION['user_id'])) { header('Location: home.php'); exit; }

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── LOGIN ─────────────────────────────────────────────────────────────
    if (isset($_POST['action_login'])) {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // Validación básica de entrada
        if (empty($username) || empty($password)) {
            $error = 'Por favor completa todos los campos.';
        } else {
            $stmt = $pdo->prepare('SELECT id, username, password FROM usuarios WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            // CORRECCIÓN CRÍTICA: eliminadas las credenciales hardcodeadas.
            // Solo se permite autenticación mediante password_verify().
            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);            // Prevenir session fixation
                $_SESSION['user_id']  = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['last_regen'] = time();
                header('Location: home.php');
                exit;
            } else {
                // Mensaje genérico — no revelar si el usuario existe o no
                $error = 'Usuario o contraseña incorrectos.';
            }
        }
    }

    // ── REGISTRO ──────────────────────────────────────────────────────────
    if (isset($_POST['action_register'])) {
        $username = strtolower(trim($_POST['reg_username'] ?? ''));
        $nombre   = trim($_POST['reg_nombre'] ?? '');
        $password = $_POST['reg_password'] ?? '';

        // Validaciones
        if (empty($username) || empty($nombre) || empty($password)) {
            $error = 'Por favor completa todos los campos.';
        } elseif (!preg_match('/^[a-z0-9_]{3,30}$/', $username)) {
            $error = 'El usuario solo puede contener letras, números y guion bajo (3-30 caracteres).';
        } elseif (strlen($password) < 8) {
            $error = 'La contraseña debe tener al menos 8 caracteres.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = 'El nombre de usuario ya está tomado.';
            } else {
                $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt   = $pdo->prepare('INSERT INTO usuarios (username, password, nombre) VALUES (?, ?, ?)');
                $stmt->execute([$username, $hashed, $nombre]);
                $success = '¡Registro exitoso! Ya puedes iniciar sesión.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>blufast - Acceso</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css">
    <style>
        body { background-color: #050507; font-family: -apple-system, BlinkMacSystemFont, sans-serif; }
        .input-blufast { background-color: #020204 !important; color: #ffffff !important; }
        .input-blufast::placeholder { color: #4b5563 !important; }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen px-4">
    <div class="w-full max-w-sm p-6 rounded-2xl bg-[#0B0B0F] border border-gray-900 text-center shadow-2xl">
        <h1 class="text-3xl font-semibold tracking-tight text-white mb-6">blufast</h1>

        <?php if ($error): ?>
            <div class="text-xs text-red-400 mb-4 bg-red-950/30 p-2 rounded-lg border border-red-900/50">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="text-xs text-green-400 mb-4 bg-green-950/30 p-2 rounded-lg border border-green-900/50">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <div class="flex border-b border-gray-800 mb-6 text-sm">
            <button onclick="switchTab('login')" id="btn-tab-login"
                class="flex-1 pb-2 border-b-2 border-purple-600 text-white font-medium focus:outline-none">
                Entrar
            </button>
            <button onclick="switchTab('reg')" id="btn-tab-reg"
                class="flex-1 pb-2 border-b-2 border-transparent text-gray-500 focus:outline-none">
                Registrarse
            </button>
        </div>

        <form id="form-login" method="POST" class="space-y-4">
            <input type="hidden" name="action_login" value="1">
            <input type="text" name="username" placeholder="Nombre de usuario" required maxlength="30"
                class="input-blufast w-full px-4 py-3 border border-gray-800 rounded-xl focus:outline-none focus:border-purple-600 text-sm transition-colors">
            <input type="password" name="password" placeholder="Contraseña" required maxlength="100"
                class="input-blufast w-full px-4 py-3 border border-gray-800 rounded-xl focus:outline-none focus:border-purple-600 text-sm transition-colors">
            <button type="submit"
                class="w-full py-3 bg-purple-700 hover:bg-purple-600 text-white font-medium rounded-xl text-sm transition-all shadow-lg mt-2">
                Iniciar Sesión
            </button>
        </form>

        <form id="form-reg" method="POST" class="space-y-4 hidden">
            <input type="hidden" name="action_register" value="1">
            <input type="text" name="reg_nombre" placeholder="Tu Nombre Completo" required maxlength="100"
                class="input-blufast w-full px-4 py-3 border border-gray-800 rounded-xl focus:outline-none focus:border-purple-600 text-sm transition-colors">
            <input type="text" name="reg_username" placeholder="Crea tu usuario (ej: marcos99)" required maxlength="30"
                class="input-blufast w-full px-4 py-3 border border-gray-800 rounded-xl focus:outline-none focus:border-purple-600 text-sm transition-colors">
            <input type="password" name="reg_password" placeholder="Crea tu contraseña (mín. 8 caracteres)" required maxlength="100"
                class="input-blufast w-full px-4 py-3 border border-gray-800 rounded-xl focus:outline-none focus:border-purple-600 text-sm transition-colors">
            <button type="submit"
                class="w-full py-3 bg-purple-900 hover:bg-purple-800 text-white font-medium rounded-xl text-sm transition-all shadow-lg mt-2">
                Crear Cuenta
            </button>
        </form>
    </div>

    <script>
        function switchTab(type) {
            const isLogin = type === 'login';
            document.getElementById('form-login').classList.toggle('hidden', !isLogin);
            document.getElementById('form-reg').classList.toggle('hidden', isLogin);
            document.getElementById('btn-tab-login').className = isLogin
                ? 'flex-1 pb-2 border-b-2 border-purple-600 text-white font-medium focus:outline-none'
                : 'flex-1 pb-2 border-b-2 border-transparent text-gray-500 focus:outline-none';
            document.getElementById('btn-tab-reg').className = isLogin
                ? 'flex-1 pb-2 border-b-2 border-transparent text-gray-500 focus:outline-none'
                : 'flex-1 pb-2 border-b-2 border-purple-600 text-white font-medium focus:outline-none';
        }
    </script>
</body>
</html>