<?php
// debug_session.php - Archivo temporal para diagnosticar problemas de sesión
// ELIMINAR ESTE ARCHIVO EN PRODUCCIÓN

session_start();

header('Content-Type: application/json; charset=utf-8');

$debug_info = [
    'timestamp' => date('Y-m-d H:i:s'),
    'session_info' => [
        'session_id' => session_id(),
        'session_status' => session_status(),
        'session_name' => session_name(),
        'session_save_path' => session_save_path(),
        'session_cache_limiter' => session_cache_limiter(),
        'session_module_name' => session_module_name()
    ],
    'session_data' => $_SESSION,
    'cookies' => $_COOKIE,
    'php_info' => [
        'php_version' => phpversion(),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'error_reporting' => error_reporting(),
        'display_errors' => ini_get('display_errors')
    ],
    'server_info' => [
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'Unknown',
        'http_host' => $_SERVER['HTTP_HOST'] ?? 'Unknown',
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
    ],
    'database_test' => null,
    'file_permissions' => [],
    'session_file_exists' => false
];

// Test de base de datos
try {
    require_once 'config/database.php';
    $database = new Database();
    $conn = $database->getConnection();
    
    if ($conn) {
        $debug_info['database_test'] = [
            'connection' => 'SUCCESS',
            'database_name' => 'tienda_multicategoria'
        ];
        
        // Test de usuario si hay sesión
        if (isset($_SESSION['usuario_id'])) {
            $stmt = $conn->prepare("SELECT id, nombre, email, activo FROM clientes WHERE id = ?");
            $stmt->execute([$_SESSION['usuario_id']]);
            $user = $stmt->fetch();
            
            $debug_info['database_test']['user_exists'] = $user ? 'YES' : 'NO';
            if ($user) {
                $debug_info['database_test']['user_data'] = [
                    'id' => $user['id'],
                    'nombre' => $user['nombre'],
                    'email' => $user['email'],
                    'activo' => $user['activo']
                ];
            }
        }
    } else {
        $debug_info['database_test'] = ['connection' => 'FAILED'];
    }
} catch (Exception $e) {
    $debug_info['database_test'] = [
        'connection' => 'ERROR',
        'error' => $e->getMessage()
    ];
}

// Verificar permisos de archivos críticos
$files_to_check = [
    'api/auth.php',
    'config/database.php',
    'config/verification.php',
    'index.php',
    'perfil.php',
    'mis_pedidos.php'
];

foreach ($files_to_check as $file) {
    $debug_info['file_permissions'][$file] = [
        'exists' => file_exists($file),
        'readable' => is_readable($file),
        'writable' => is_writable($file),
        'size' => file_exists($file) ? filesize($file) : 0
    ];
}

// Verificar archivo de sesión
$session_file_path = session_save_path() . '/sess_' . session_id();
$debug_info['session_file_exists'] = file_exists($session_file_path);
if (file_exists($session_file_path)) {
    $debug_info['session_file_size'] = filesize($session_file_path);
    $debug_info['session_file_modified'] = date('Y-m-d H:i:s', filemtime($session_file_path));
}

// Test de logout directo
if (isset($_GET['test_logout']) && $_GET['test_logout'] === 'true') {
    $debug_info['logout_test'] = [
        'before_logout' => [
            'session_data' => $_SESSION,
            'cookies' => $_COOKIE
        ]
    ];
    
    // Realizar logout
    $_SESSION = array();
    
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time()-3600, '/');
    }
    
    session_destroy();
    
    $debug_info['logout_test']['after_logout'] = [
        'session_destroyed' => true,
        'message' => 'Logout test completado'
    ];
}

// Headers adicionales para debugging
header('X-Debug-Session-ID: ' . session_id());
header('X-Debug-PHP-Version: ' . phpversion());
header('X-Debug-Server: ' . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'));

echo json_encode($debug_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// Log para archivo
$log_entry = date('Y-m-d H:i:s') . " - DEBUG SESSION ACCESS\n";
$log_entry .= "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . "\n";
$log_entry .= "User Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown') . "\n";
$log_entry .= "Session ID: " . session_id() . "\n";
$log_entry .= "User ID: " . ($_SESSION['usuario_id'] ?? 'Not logged in') . "\n";
$log_entry .= "--------------------\n";

// Crear directorio logs si no existe
if (!is_dir('logs')) {
    mkdir('logs', 0755, true);
}

file_put_contents('logs/debug_session.log', $log_entry, FILE_APPEND | LOCK_EX);
?>