<?php
// api/auth.php - MODIFICADO PARA VERIFICACIÓN OBLIGATORIA
// API para manejar autenticación (login, logout, registro CON VERIFICACIÓN)

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/verification.php';

header('Content-Type: application/json');

$database = new Database();
$conn = $database->getConnection();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    switch ($action) {
        case 'login':
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $remember = isset($_POST['remember']);
            
            if (empty($email) || empty($password)) {
                throw new Exception('Email y contraseña son requeridos');
            }
            
            // Buscar usuario EN TABLA CLIENTES (solo usuarios verificados)
            $stmt = $conn->prepare("SELECT id, nombre, email, password, telefono, activo, email_verified FROM clientes WHERE email = ?");
            $stmt->execute([$email]);
            $usuario = $stmt->fetch();
            
            if (!$usuario) {
                // Verificar si está en pending_registrations
                $stmt = $conn->prepare("SELECT email FROM pending_registrations WHERE email = ? AND expires_at > NOW()");
                $stmt->execute([$email]);
                $pendiente = $stmt->fetch();
                
                if ($pendiente) {
                    throw new Exception('Tu cuenta está pendiente de verificación. Revisa tu email.');
                } else {
                    throw new Exception('Usuario no encontrado');
                }
            }
            
            if (!password_verify($password, $usuario['password'])) {
                throw new Exception('Contraseña incorrecta');
            }
            
            if (!$usuario['activo']) {
                throw new Exception('Cuenta desactivada. Contacta al administrador');
            }
            
            // VERIFICAR QUE EL EMAIL ESTÉ VERIFICADO
            if (!$usuario['email_verified']) {
                throw new Exception('Debes verificar tu email antes de iniciar sesión. Revisa tu bandeja de entrada.');
            }
            
            // Login exitoso - usuario completamente verificado
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_nombre'] = $usuario['nombre'];
            $_SESSION['usuario_email'] = $usuario['email'];
            $_SESSION['usuario_telefono'] = $usuario['telefono'];
            
            // Migrar carrito de sesión a BD si existe
            if (isset($_SESSION['carrito']) && !empty($_SESSION['carrito'])) {
                foreach ($_SESSION['carrito'] as $item) {
                    try {
                        if ($item['tipo'] === 'producto' || isset($item['id_producto'])) {
                            $id_producto = $item['id_producto'] ?? $item['id'];
                            $cantidad = $item['cantidad'];
                            
                            // Verificar si ya existe en carrito BD
                            $stmt_check = $conn->prepare("SELECT cantidad FROM carrito_compras WHERE id_cliente = ? AND id_producto = ?");
                            $stmt_check->execute([$usuario['id'], $id_producto]);
                            $existe = $stmt_check->fetch();
                            
                            if ($existe) {
                                $stmt = $conn->prepare("UPDATE carrito_compras SET cantidad = cantidad + ? WHERE id_cliente = ? AND id_producto = ?");
                                $stmt->execute([$cantidad, $usuario['id'], $id_producto]);
                            } else {
                                $stmt = $conn->prepare("INSERT INTO carrito_compras (id_cliente, id_producto, cantidad) VALUES (?, ?, ?)");
                                $stmt->execute([$usuario['id'], $id_producto, $cantidad]);
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Error migrando item del carrito: " . $e->getMessage());
                    }
                }
                unset($_SESSION['carrito']);
            }
            
            // Cookie de recordar (opcional)
            if ($remember) {
                $token = bin2hex(random_bytes(32));
                setcookie('remember_token', $token, time() + (86400 * 30), '/');
            }
            
            $response['success'] = true;
            $response['message'] = 'Login exitoso';
            $response['data'] = [
                'usuario' => [
                    'id' => $usuario['id'],
                    'nombre' => $usuario['nombre'],
                    'email' => $usuario['email']
                ]
            ];
            break;
            
        case 'logout':
            try {
                // Log para debugging
                error_log("LOGOUT: Iniciando proceso de logout para usuario: " . ($_SESSION['usuario_id'] ?? 'no logueado'));
                
                // Verificar si hay sesión activa
                $had_session = isset($_SESSION['usuario_id']);
                $user_id = $_SESSION['usuario_id'] ?? null;
                $user_name = $_SESSION['usuario_nombre'] ?? '';
                
                // Destruir todas las variables de sesión
                $_SESSION = array();
                
                // Destruir la cookie de sesión si existe
                if (isset($_COOKIE[session_name()])) {
                    setcookie(session_name(), '', time()-3600, '/');
                }
                
                // Destruir la sesión
                session_destroy();
                
                // Limpiar cookie de recordar si existe
                if (isset($_COOKIE['remember_token'])) {
                    setcookie('remember_token', '', time() - 3600, '/');
                    setcookie('remember_token', '', time() - 3600, '/', '', false, true); // HTTPOnly
                }
                
                // Limpiar cualquier otra cookie relacionada
                $cookies_to_clear = ['user_session', 'cart_id', 'user_pref'];
                foreach ($cookies_to_clear as $cookie) {
                    if (isset($_COOKIE[$cookie])) {
                        setcookie($cookie, '', time() - 3600, '/');
                    }
                }
                
                // Log del logout exitoso
                error_log("LOGOUT: Logout exitoso para usuario ID: " . ($user_id ?? 'desconocido') . " - " . $user_name);
                
                // Respuesta exitosa
                $response['success'] = true;
                $response['message'] = $had_session ? 'Sesión cerrada correctamente' : 'No había sesión activa';
                $response['data'] = [
                    'logged_out' => true,
                    'had_session' => $had_session,
                    'redirect_url' => 'index.php',
                    'timestamp' => date('Y-m-d H:i:s')
                ];
                
                // Log adicional para debugging
                error_log("LOGOUT: Respuesta preparada - " . json_encode($response));
                
            } catch (Exception $logout_error) {
                error_log("LOGOUT: Error durante logout - " . $logout_error->getMessage());
                
                // Incluso si hay error, intentar limpiar la sesión
                session_unset();
                session_destroy();
                
                $response['success'] = false;
                $response['message'] = 'Error durante el logout: ' . $logout_error->getMessage();
                $response['data'] = [
                    'logged_out' => true, // Forzar logout aunque haya error
                    'error_details' => $logout_error->getMessage()
                ];
            }
            break;
            
        case 'register':
            // NUEVO FLUJO: REGISTRAR EN PENDING, NO EN CLIENTES
            error_log("REGISTRO NUEVO FLUJO EJECUTADO - EMAIL: " . ($_POST['email'] ?? 'no email'));
            $nombre = trim($_POST['nombre'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            $telefono = trim($_POST['telefono'] ?? '');
            $direccion = trim($_POST['direccion'] ?? '');
            
            // Validaciones
            if (empty($nombre) || empty($email) || empty($password)) {
                throw new Exception('Nombre, email y contraseña son requeridos');
            }
            
            if (strlen($password) < 6) {
                throw new Exception('La contraseña debe tener al menos 6 caracteres');
            }
            
            if ($password !== $confirm_password) {
                throw new Exception('Las contraseñas no coinciden');
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Email no válido');
            }
            
            // VERIFICAR QUE NO EXISTA EN CLIENTES (usuarios ya verificados)
            $stmt = $conn->prepare("SELECT id FROM clientes WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                throw new Exception('Este email ya está registrado y verificado');
            }
            
            // VERIFICAR SI YA ESTÁ EN PENDING (eliminar registros expirados primero)
            $stmt = $conn->prepare("DELETE FROM pending_registrations WHERE email = ? AND expires_at <= NOW()");
            $stmt->execute([$email]);
            
            $stmt = $conn->prepare("SELECT id, attempts FROM pending_registrations WHERE email = ?");
            $stmt->execute([$email]);
            $existing_pending = $stmt->fetch();
            
            if ($existing_pending && $existing_pending['attempts'] >= 3) {
                throw new Exception('Demasiados intentos de registro. Espera 24 horas o contacta soporte.');
            }
            
            // GENERAR TOKEN ÚNICO DE VERIFICACIÓN
            $verification_token = bin2hex(random_bytes(32)); // 64 caracteres
            $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours')); // 24 horas para verificar
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            // GUARDAR EN PENDING_REGISTRATIONS (NO EN CLIENTES)
            if ($existing_pending) {
                // Actualizar registro existente
                $stmt = $conn->prepare("
                    UPDATE pending_registrations 
                    SET nombre = ?, password_hash = ?, telefono = ?, direccion = ?, 
                        verification_token = ?, expires_at = ?, attempts = attempts + 1,
                        ip_address = ?, user_agent = ?
                    WHERE email = ?
                ");
                $stmt->execute([
                    $nombre, $password_hash, $telefono, $direccion, 
                    $verification_token, $expires_at, $ip_address, $user_agent, $email
                ]);
            } else {
                // Crear nuevo registro
                $stmt = $conn->prepare("
                    INSERT INTO pending_registrations 
                    (nombre, email, password_hash, telefono, direccion, verification_token, expires_at, ip_address, user_agent) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $nombre, $email, $password_hash, $telefono, $direccion, 
                    $verification_token, $expires_at, $ip_address, $user_agent
                ]);
            }
            
            // ENVIAR EMAIL DE VERIFICACIÓN
            $verification_service = new VerificationService($conn);
            $email_sent = $verification_service->sendRegistrationVerificationEmail($email, $nombre, $verification_token);
            
            if (!$email_sent) {
                throw new Exception('Error enviando email de verificación. Intenta nuevamente.');
            }
            
            // RESPUESTA: NO HAY AUTO-LOGIN
            $response['success'] = true;
            $response['message'] = 'Código de verificación enviado a tu email. Tienes 24 horas para verificar.';
            $response['data'] = [
                'require_verification' => true,
                'email_masked' => maskEmail($email),
                'expires_hours' => 24,
                'redirect_to' => 'verify_registration.php'
            ];
            break;
            
        case 'verify_registration':
            // VERIFICAR CÓDIGO Y CREAR CUENTA REAL
            
            $token = trim($_POST['token'] ?? '');
            
            if (empty($token)) {
                throw new Exception('Token de verificación requerido');
            }
            
            // Buscar registro pendiente válido
            $stmt = $conn->prepare("
                SELECT * FROM pending_registrations 
                WHERE verification_token = ? AND expires_at > NOW()
            ");
            $stmt->execute([$token]);
            $pending_user = $stmt->fetch();
            
            if (!$pending_user) {
                throw new Exception('Token de verificación inválido o expirado');
            }
            
            // CREAR CUENTA REAL EN CLIENTES
            $stmt = $conn->prepare("
                INSERT INTO clientes (nombre, email, password, telefono, direccion, email_verified, activo) 
                VALUES (?, ?, ?, ?, ?, 1, 1)
            ");
            $stmt->execute([
                $pending_user['nombre'], 
                $pending_user['email'], 
                $pending_user['password_hash'], 
                $pending_user['telefono'], 
                $pending_user['direccion']
            ]);
            
            $nuevo_id = $conn->lastInsertId();
            
            // ELIMINAR DE PENDING_REGISTRATIONS
            $stmt = $conn->prepare("DELETE FROM pending_registrations WHERE id = ?");
            $stmt->execute([$pending_user['id']]);
            
            // AUTO-LOGIN DESPUÉS DE VERIFICACIÓN EXITOSA
            $_SESSION['usuario_id'] = $nuevo_id;
            $_SESSION['usuario_nombre'] = $pending_user['nombre'];
            $_SESSION['usuario_email'] = $pending_user['email'];
            $_SESSION['usuario_telefono'] = $pending_user['telefono'];
            
            // Log de auditoría
            $stmt = $conn->prepare("
                INSERT INTO verification_logs (user_id, type, action, method, contact_info, ip_address, success) 
                VALUES (?, 'registration_verification', 'verified', 'email', 'account_created', ?, 1)
            ");
            $stmt->execute([$nuevo_id, $_SERVER['REMOTE_ADDR'] ?? '']);
            
            $response['success'] = true;
            $response['message'] = '¡Cuenta verificada y creada exitosamente!';
            $response['data'] = [
                'usuario' => [
                    'id' => $nuevo_id,
                    'nombre' => $pending_user['nombre'],
                    'email' => $pending_user['email']
                ],
                'auto_login' => true
            ];
            break;
            
        case 'resend_verification':
            // REENVIAR CÓDIGO DE VERIFICACIÓN
            
            $email = trim($_POST['email'] ?? '');
            
            if (empty($email)) {
                throw new Exception('Email requerido');
            }
            
            // Buscar en pending_registrations
            $stmt = $conn->prepare("
                SELECT * FROM pending_registrations 
                WHERE email = ? AND expires_at > NOW() AND attempts < max_attempts
            ");
            $stmt->execute([$email]);
            $pending_user = $stmt->fetch();
            
            if (!$pending_user) {
                throw new Exception('No hay registro pendiente para este email o se alcanzó el límite de intentos');
            }
            
            // Verificar cooldown (no más de 1 cada 2 minutos)
            $stmt = $conn->prepare("
                SELECT created_at FROM pending_registrations 
                WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
            ");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                throw new Exception('Debes esperar 2 minutos antes de solicitar otro código');
            }
            
            // Generar nuevo token
            $new_token = bin2hex(random_bytes(32));
            $new_expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
            
            // Actualizar registro
            $stmt = $conn->prepare("
                UPDATE pending_registrations 
                SET verification_token = ?, expires_at = ?, attempts = attempts + 1 
                WHERE email = ?
            ");
            $stmt->execute([$new_token, $new_expires, $email]);
            
            // Reenviar email
            $verification_service = new VerificationService($conn);
            $email_sent = $verification_service->sendRegistrationVerificationEmail(
                $pending_user['email'], 
                $pending_user['nombre'], 
                $new_token
            );
            
            if (!$email_sent) {
                throw new Exception('Error reenviando email. Intenta más tarde.');
            }
            
            $response['success'] = true;
            $response['message'] = 'Código de verificación reenviado correctamente';
            break;
            
        case 'check_session':
            // Verificar si hay sesión activa
            if (isset($_SESSION['usuario_id'])) {
                $response['success'] = true;
                $response['data'] = [
                    'logueado' => true,
                    'usuario' => [
                        'id' => $_SESSION['usuario_id'],
                        'nombre' => $_SESSION['usuario_nombre'],
                        'email' => $_SESSION['usuario_email']
                    ]
                ];
            } else {
                $response['success'] = true;
                $response['data'] = ['logueado' => false];
            }
            break;
            
        case 'update_profile':
            if (!isset($_SESSION['usuario_id'])) {
                throw new Exception('Debes estar logueado');
            }
            
            $nombre = trim($_POST['nombre'] ?? '');
            $telefono = trim($_POST['telefono'] ?? '');
            $direccion = trim($_POST['direccion'] ?? '');
            
            if (empty($nombre)) {
                throw new Exception('El nombre es requerido');
            }
            
            $stmt = $conn->prepare("UPDATE clientes SET nombre = ?, telefono = ?, direccion = ? WHERE id = ?");
            $stmt->execute([$nombre, $telefono, $direccion, $_SESSION['usuario_id']]);
            
            $_SESSION['usuario_nombre'] = $nombre;
            $_SESSION['usuario_telefono'] = $telefono;
            
            $response['success'] = true;
            $response['message'] = 'Perfil actualizado correctamente';
            break;
            
        default:
            throw new Exception('Acción no válida');
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log("Error en auth API: " . $e->getMessage());
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);

// FUNCIÓN AUXILIAR PARA ENMASCARAR EMAIL
function maskEmail($email) {
    if (empty($email)) return '';
    $at_pos = strpos($email, '@');
    if ($at_pos === false) return '***';
    $local = substr($email, 0, $at_pos);
    $domain = substr($email, $at_pos);
    if (strlen($local) <= 2) return '*' . $domain;
    return substr($local, 0, 2) . str_repeat('*', strlen($local) - 2) . $domain;
}
?>