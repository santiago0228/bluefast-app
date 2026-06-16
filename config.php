<?php
if (session_status() === PHP_SESSION_NONE) {
    // Configuración de sesión segura
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 1);    // Solo HTTPS
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
    session_start();
}

$host    = 'sql308.infinityfree.com';
$db      = 'if0_42192275_blufast'; // Asegúrate de cambiar el XXX por 'blufast'
$user    = 'if0_42192275';
$pass    = 'FlWxwA82LR4';         // ¡Aquí debes poner tu contraseña!
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // NUNCA mostrar el error real en producción
    error_log('DB Error: ' . $e->getMessage());
    die("Error de conexión. Intenta más tarde.");
}

// CORRECCIÓN CRÍTICA: Usar AES-256-CBC con IV aleatorio en lugar de AES-128-ECB
// La clave debe estar en una variable de entorno en producción:
// define('ENCRYPTION_KEY', getenv('BLUFAST_KEY'));
define('ENCRYPTION_KEY', 'BluFastPrivKeySecure2026!BluFast!'); // 32 bytes para AES-256

function cifrar(string $texto): string {
    if (empty($texto)) return '';
    $iv  = openssl_random_pseudo_bytes(16);           // IV aleatorio cada vez
    $enc = openssl_encrypt($texto, 'AES-256-CBC', ENCRYPTION_KEY, 0, $iv);
    // Guardamos IV + cifrado juntos en base64 para poder descifrar después
    return base64_encode($iv . base64_decode($enc));
}

function descifrar(string $textoCifrado): string {
    if (empty($textoCifrado)) return '';
    $data = base64_decode($textoCifrado);
    if (strlen($data) < 16) return '';                // Dato corrupto
    $iv  = substr($data, 0, 16);
    $enc = base64_encode(substr($data, 16));
    $dec = openssl_decrypt($enc, 'AES-256-CBC', ENCRYPTION_KEY, 0, $iv);
    return $dec === false ? '' : $dec;
}

function verificar_sesion(): void {
    if (!isset($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }
    // Regenerar ID de sesión periódicamente para prevenir session fixation
    if (!isset($_SESSION['last_regen']) || time() - $_SESSION['last_regen'] > 300) {
        session_regenerate_id(true);
        $_SESSION['last_regen'] = time();
    }
}
?>