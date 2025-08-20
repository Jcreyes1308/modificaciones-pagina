<?php
// api/verification.php - API para manejo de verificaciones
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/verification.php';

header('Content-Type: application/json');

$database = new Database();
$conn = $database->getConnection();
$verification = new VerificationService($conn);

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    switch ($action) {
        case 'send_email_verification':
            // Verificar que el usuario esté logueado
            if (!isset($_SESSION['usuario_id'])) {
                throw new Exception('Usuario no autenticado');
            }
            
            $email = trim($_POST['email'] ?? '');
            
            if (empty($email)) {
                throw new Exception('Email requerido');
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Email no válido');
            }
            
            // Verificar cooldown (no enviar más de 1 cada 2 minutos)
            $stmt = $conn->prepare("
                SELECT created_at FROM verification_codes 
                WHERE user_id = ? AND type = 'email_verification' 
                AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([$_SESSION['usuario_id']]);
            if ($stmt->fetch()) {
                throw new Exception('Debes esperar 2 minutos antes de solicitar otro código');
            }
            
            $success = $verification->sendEmailVerification($_SESSION['usuario_id'], $email);
            
            if ($success) {
                // Log de auditoría
                $stmt = $conn->prepare("
                    INSERT INTO verification_logs (user_id, type, action, method, contact_info, ip_address, success) 
                    VALUES (?, 'email_verification', 'sent', 'email', ?, ?, 1)
                ");
                $stmt->execute([
                    $_SESSION['usuario_id'], 
                    maskEmail($email), 
                    $_SERVER['REMOTE_ADDR'] ?? ''
                ]);
                
                $response['success'] = true;
                $response['message'] = 'Código enviado a tu email';
                $response['data'] = ['masked_email' => maskEmail($email)];
            } else {
                throw new Exception('Error al enviar email. Verifica la configuración SMTP.');
            }
            break;
            
        case 'send_sms_verification':
            // Verificar que el usuario esté logueado
            if (!isset($_SESSION['usuario_id'])) {
                throw new Exception('Usuario no autenticado');
            }
            
            $phone = trim($_POST['phone'] ?? '');
            
            if (empty($phone)) {
                throw new Exception('Teléfono requerido');
            }
            
            // Verificar formato de teléfono
            $clean_phone = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($clean_phone) < 10) {
                throw new Exception('Número de teléfono inválido');
            }
            
            // Verificar cooldown
            $stmt = $conn->prepare("
                SELECT created_at FROM verification_codes 
                WHERE user_id = ? AND type = 'phone_verification' 
                AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([$_SESSION['usuario_id']]);
            if ($stmt->fetch()) {
                throw new Exception('Debes esperar 2 minutos antes de solicitar otro código');
            }
            
            $success = $verification->sendSMSVerification($_SESSION['usuario_id'], $phone);
            
            if ($success) {
                // Log de auditoría
                $stmt = $conn->prepare("
                    INSERT INTO verification_logs (user_id, type, action, method, contact_info, ip_address, success) 
                    VALUES (?, 'phone_verification', 'sent', 'sms', ?, ?, 1)
                ");
                $stmt->execute([
                    $_SESSION['usuario_id'], 
                    maskPhone($phone), 
                    $_SERVER['REMOTE_ADDR'] ?? ''
                ]);
                
                $response['success'] = true;
                $response['message'] = 'Código SMS enviado';
                $response['data'] = ['masked_phone' => maskPhone($phone)];
            } else {
                throw new Exception('Error al enviar SMS. Verifica que Twilio esté configurado.');
            }
            break;
            
        case 'send_whatsapp_verification':
            // Verificar que el usuario esté logueado
            if (!isset($_SESSION['usuario_id'])) {
                throw new Exception('Usuario no autenticado');
            }
            
            $phone = trim($_POST['phone'] ?? '');
            
            if (empty($phone)) {
                throw new Exception('Teléfono requerido');
            }
            
            // Verificar formato de teléfono
            $clean_phone = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($clean_phone) < 10) {
                throw new Exception('Número de teléfono inválido');
            }
            
            // Verificar cooldown
            $stmt = $conn->prepare("
                SELECT created_at FROM verification_codes 
                WHERE user_id = ? AND type = 'whatsapp_verification' 
                AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([$_SESSION['usuario_id']]);
            if ($stmt->fetch()) {
                throw new Exception('Debes esperar 2 minutos antes de solicitar otro código');
            }
            
            $success = $verification->sendWhatsAppVerification($_SESSION['usuario_id'], $phone);
            
            if ($success) {
                // Log de auditoría
                $stmt = $conn->prepare("
                    INSERT INTO verification_logs (user_id, type, action, method, contact_info, ip_address, success) 
                    VALUES (?, 'whatsapp_verification', 'sent', 'whatsapp', ?, ?, 1)
                ");
                $stmt->execute([
                    $_SESSION['usuario_id'], 
                    maskPhone($phone), 
                    $_SERVER['REMOTE_ADDR'] ?? ''
                ]);
                
                $response['success'] = true;
                $response['message'] = 'Código WhatsApp enviado';
                $response['data'] = ['masked_phone' => maskPhone($phone)];
            } else {
                throw new Exception('Error al enviar WhatsApp. Verifica tu configuración.');
            }
            break;
            
        case 'verify_email':
            // Verificar que el usuario esté logueado
            if (!isset($_SESSION['usuario_id'])) {
                throw new Exception('Usuario no autenticado');
            }
            
            $code = trim($_POST['code'] ?? '');
            
            if (empty($code)) {
                throw new Exception('Código requerido');
            }
            
            if (strlen($code) !== 6 || !ctype_digit($code)) {
                throw new Exception('Código debe ser de 6 dígitos');
            }
            
            $success = $verification->verifyCode($_SESSION['usuario_id'], $code, 'email_verification');
            
            if ($success) {
                // Log de auditoría
                $stmt = $conn->prepare("
                    INSERT INTO verification_logs (user_id, type, action, method, contact_info, ip_address, success) 
                    VALUES (?, 'email_verification', 'verified', 'email', 'email_verified', ?, 1)
                ");
                $stmt->execute([$_SESSION['usuario_id'], $_SERVER['REMOTE_ADDR'] ?? '']);
                
                $response['success'] = true;
                $response['message'] = '¡Email verificado correctamente!';
            } else {
                // Log del fallo
                $stmt = $conn->prepare("
                    INSERT INTO verification_logs (user_id, type, action, method, contact_info, ip_address, success) 
                    VALUES (?, 'email_verification', 'failed', 'email', 'invalid_code', ?, 0)
                ");
                $stmt->execute([$_SESSION['usuario_id'], $_SERVER['REMOTE_ADDR'] ?? '']);
                
                throw new Exception('Código inválido o expirado');
            }
            break;
            
        case 'verify_phone':
            // Verificar que el usuario esté logueado
            if (!isset($_SESSION['usuario_id'])) {
                throw new Exception('Usuario no autenticado');
            }
            
            $code = trim($_POST['code'] ?? '');
            $verification_type = $_POST['verification_type'] ?? 'phone_verification';
            
            if (empty($code)) {
                throw new Exception('Código requerido');
            }
            
            if (strlen($code) !== 6 || !ctype_digit($code)) {
                throw new Exception('Código debe ser de 6 dígitos');
            }
            
            if (!in_array($verification_type, ['phone_verification', 'whatsapp_verification'])) {
                throw new Exception('Tipo de verificación inválido');
            }
            
            $success = $verification->verifyCode($_SESSION['usuario_id'], $code, $verification_type);
            
            if ($success) {
                // Log de auditoría
                $method = $verification_type === 'whatsapp_verification' ? 'whatsapp' : 'sms';
                $stmt = $conn->prepare("
                    INSERT INTO verification_logs (user_id, type, action, method, contact_info, ip_address, success) 
                    VALUES (?, ?, 'verified', ?, 'phone_verified', ?, 1)
                ");
                $stmt->execute([
                    $_SESSION['usuario_id'], 
                    $verification_type, 
                    $method, 
                    $_SERVER['REMOTE_ADDR'] ?? ''
                ]);
                
                $response['success'] = true;
                $response['message'] = '¡Teléfono verificado correctamente!';
            } else {
                // Log del fallo
                $method = $verification_type === 'whatsapp_verification' ? 'whatsapp' : 'sms';
                $stmt = $conn->prepare("
                    INSERT INTO verification_logs (user_id, type, action, method, contact_info, ip_address, success) 
                    VALUES (?, ?, 'failed', ?, 'invalid_code', ?, 0)
                ");
                $stmt->execute([
                    $_SESSION['usuario_id'], 
                    $verification_type, 
                    $method, 
                    $_SERVER['REMOTE_ADDR'] ?? ''
                ]);
                
                throw new Exception('Código inválido o expirado');
            }
            break;
            
        case 'get_verification_status':
            // Verificar que el usuario esté logueado
            if (!isset($_SESSION['usuario_id'])) {
                throw new Exception('Usuario no autenticado');
            }
            
            // Obtener estado de verificaciones del usuario
            $stmt = $conn->prepare("
                SELECT 
                    email_verified,
                    phone_verified,
                    email,
                    telefono,
                    created_at as registro_fecha
                FROM clientes 
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['usuario_id']]);
            $user = $stmt->fetch();
            
            if (!$user) {
                throw new Exception('Usuario no encontrado');
            }
            
            // Obtener códigos pendientes
            $stmt = $conn->prepare("
                SELECT 
                    type,
                    created_at,
                    expires_at,
                    attempts
                FROM verification_codes 
                WHERE user_id = ? AND verified = 0 AND expires_at > NOW()
                ORDER BY created_at DESC
            ");
            $stmt->execute([$_SESSION['usuario_id']]);
            $pending_codes = $stmt->fetchAll();
            
            $response['success'] = true;
            $response['data'] = [
                'email_verified' => (bool)$user['email_verified'],
                'phone_verified' => (bool)$user['phone_verified'],
                'has_email' => !empty($user['email']),
                'has_phone' => !empty($user['telefono']),
                'masked_email' => !empty($user['email']) ? maskEmail($user['email']) : null,
                'masked_phone' => !empty($user['telefono']) ? maskPhone($user['telefono']) : null,
                'pending_codes' => $pending_codes,
                'verification_required' => (!$user['email_verified'] && !empty($user['email'])) || 
                                         (!$user['phone_verified'] && !empty($user['telefono']))
            ];
            break;
            
        case 'resend_code':
            // Verificar que el usuario esté logueado
            if (!isset($_SESSION['usuario_id'])) {
                throw new Exception('Usuario no autenticado');
            }
            
            $type = $_POST['type'] ?? '';
            
            if (!in_array($type, ['email_verification', 'phone_verification', 'whatsapp_verification'])) {
                throw new Exception('Tipo de verificación inválido');
            }
            
            // Verificar cooldown
            $stmt = $conn->prepare("
                SELECT created_at FROM verification_codes 
                WHERE user_id = ? AND type = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([$_SESSION['usuario_id'], $type]);
            if ($stmt->fetch()) {
                throw new Exception('Debes esperar 2 minutos antes de reenviar');
            }
            
            $success = $verification->resendVerification($_SESSION['usuario_id'], $type);
            
            if ($success) {
                $response['success'] = true;
                $response['message'] = 'Código reenviado correctamente';
            } else {
                throw new Exception('Error al reenviar código');
            }
            break;
            
        case 'get_verification_history':
            // Verificar que el usuario esté logueado
            if (!isset($_SESSION['usuario_id'])) {
                throw new Exception('Usuario no autenticado');
            }
            
            // Obtener historial de verificaciones (últimas 10)
            $stmt = $conn->prepare("
                SELECT 
                    type,
                    action,
                    method,
                    contact_info,
                    success,
                    created_at
                FROM verification_logs 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 10
            ");
            $stmt->execute([$_SESSION['usuario_id']]);
            $history = $stmt->fetchAll();
            
            $response['success'] = true;
            $response['data'] = $history;
            break;
            
        // NUEVOS ENDPOINTS PARA SISTEMA DE REGISTRO
        case 'verify_registration_token':
            // Verificar token de registro desde enlace
            $token = trim($_POST['token'] ?? $_GET['token'] ?? '');
            
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
            
            // Verificar que el email no exista ya en clientes
            $stmt = $conn->prepare("SELECT id FROM clientes WHERE email = ?");
            $stmt->execute([$pending_user['email']]);
            if ($stmt->fetch()) {
                throw new Exception('Esta cuenta ya existe y está verificada');
            }
            
            // Crear cuenta real en clientes
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
            
            // Eliminar de pending_registrations
            $stmt = $conn->prepare("DELETE FROM pending_registrations WHERE id = ?");
            $stmt->execute([$pending_user['id']]);
            
            // Log de auditoría
            $stmt = $conn->prepare("
                INSERT INTO verification_logs (user_id, type, action, method, contact_info, ip_address, success) 
                VALUES (?, 'registration_verification', 'verified', 'email', 'account_created', ?, 1)
            ");
            $stmt->execute([$nuevo_id, $_SERVER['REMOTE_ADDR'] ?? '']);
            
            $response['success'] = true;
            $response['message'] = 'Cuenta verificada y creada exitosamente';
            $response['data'] = [
                'user_id' => $nuevo_id,
                'nombre' => $pending_user['nombre'],
                'email' => $pending_user['email'],
                'verified_at' => date('Y-m-d H:i:s')
            ];
            break;
            
        case 'check_registration_status':
            // Verificar estado de un registro pendiente
            $email = trim($_POST['email'] ?? $_GET['email'] ?? '');
            
            if (empty($email)) {
                throw new Exception('Email requerido');
            }
            
            // Verificar si ya existe en clientes
            $stmt = $conn->prepare("SELECT id, email_verified FROM clientes WHERE email = ?");
            $stmt->execute([$email]);
            $existing_user = $stmt->fetch();
            
            if ($existing_user) {
                $response['success'] = true;
                $response['data'] = [
                    'status' => 'verified',
                    'message' => 'Esta cuenta ya existe y está verificada',
                    'can_login' => true
                ];
                break;
            }
            
            // Verificar si está en pending
            $stmt = $conn->prepare("
                SELECT *, 
                       TIMESTAMPDIFF(HOUR, NOW(), expires_at) as hours_remaining,
                       TIMESTAMPDIFF(MINUTE, NOW(), expires_at) as minutes_remaining
                FROM pending_registrations 
                WHERE email = ?
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([$email]);
            $pending = $stmt->fetch();
            
            if (!$pending) {
                $response['success'] = true;
                $response['data'] = [
                    'status' => 'not_found',
                    'message' => 'No hay registro pendiente para este email',
                    'can_register' => true
                ];
                break;
            }
            
            if ($pending['expires_at'] <= date('Y-m-d H:i:s')) {
                $response['success'] = true;
                $response['data'] = [
                    'status' => 'expired',
                    'message' => 'El registro ha expirado',
                    'can_register' => true,
                    'expired_at' => $pending['expires_at']
                ];
                break;
            }
            
            if ($pending['attempts'] >= $pending['max_attempts']) {
                $response['success'] = true;
                $response['data'] = [
                    'status' => 'max_attempts',
                    'message' => 'Se alcanzó el límite máximo de intentos',
                    'can_register' => false,
                    'attempts' => $pending['attempts'],
                    'max_attempts' => $pending['max_attempts']
                ];
                break;
            }
            
            $response['success'] = true;
            $response['data'] = [
                'status' => 'pending',
                'message' => 'Registro pendiente de verificación',
                'can_resend' => true,
                'hours_remaining' => max(0, $pending['hours_remaining']),
                'minutes_remaining' => max(0, $pending['minutes_remaining']),
                'attempts' => $pending['attempts'],
                'max_attempts' => $pending['max_attempts'],
                'created_at' => $pending['created_at']
            ];
            break;
            
        case 'cleanup_expired_registrations':
            // Limpiar registros expirados (para uso administrativo)
            if (!isset($_SESSION['usuario_id'])) {
                throw new Exception('Acción no autorizada');
            }
            
            $stmt = $conn->prepare("
                DELETE FROM pending_registrations 
                WHERE expires_at <= NOW() OR created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute();
            $deleted = $stmt->rowCount();
            
            $response['success'] = true;
            $response['message'] = "Se eliminaron {$deleted} registros expirados";
            $response['data'] = ['deleted_count' => $deleted];
            break;
            
        case 'get_pending_stats':
            // Estadísticas de registros pendientes (para admin)
            if (!isset($_SESSION['usuario_id'])) {
                throw new Exception('Acción no autorizada');
            }
            
            $stats = [];
            
            // Total pendientes activos
            $stmt = $conn->prepare("SELECT COUNT(*) FROM pending_registrations WHERE expires_at > NOW()");
            $stmt->execute();
            $stats['active_pending'] = $stmt->fetchColumn();
            
            // Expirados hoy
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM pending_registrations 
                WHERE DATE(expires_at) = CURDATE() AND expires_at <= NOW()
            ");
            $stmt->execute();
            $stats['expired_today'] = $stmt->fetchColumn();
            
            // Por día última semana
            $stmt = $conn->prepare("
                SELECT DATE(created_at) as fecha, COUNT(*) as cantidad 
                FROM pending_registrations 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY DATE(created_at) 
                ORDER BY fecha DESC
            ");
            $stmt->execute();
            $stats['by_day'] = $stmt->fetchAll();
            
            // Con más intentos
            $stmt = $conn->prepare("
                SELECT email, attempts, created_at, expires_at
                FROM pending_registrations 
                WHERE attempts >= 2 AND expires_at > NOW()
                ORDER BY attempts DESC, created_at DESC
                LIMIT 10
            ");
            $stmt->execute();
            $stats['high_attempts'] = $stmt->fetchAll();
            
            $response['success'] = true;
            $response['data'] = $stats;
            break;
            
        default:
            throw new Exception('Acción no válida: ' . $action);
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    error_log("Error en verification API: " . $e->getMessage());
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);

/**
 * Función auxiliar para validar token de registro
 */
function validateRegistrationToken($conn, $token) {
    $stmt = $conn->prepare("
        SELECT * FROM pending_registrations 
        WHERE verification_token = ? AND expires_at > NOW()
    ");
    $stmt->execute([$token]);
    return $stmt->fetch();
}

/**
 * Función auxiliar para crear cuenta desde registro pendiente
 */
function createAccountFromPending($conn, $pending_user) {
    // Verificar que el email no exista ya
    $stmt = $conn->prepare("SELECT id FROM clientes WHERE email = ?");
    $stmt->execute([$pending_user['email']]);
    if ($stmt->fetch()) {
        throw new Exception('Esta cuenta ya existe');
    }
    
    // Crear cuenta
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
    
    // Eliminar de pending
    $stmt = $conn->prepare("DELETE FROM pending_registrations WHERE id = ?");
    $stmt->execute([$pending_user['id']]);
    
    return $nuevo_id;
}
?>