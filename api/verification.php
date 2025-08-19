<?php
// api/verification.php - API para manejo de verificaciones
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/verification.php';

header('Content-Type: application/json');

// Verificar que el usuario esté logueado
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'Usuario no autenticado']);
    exit;
}

$database = new Database();
$conn = $database->getConnection();
$verification = new VerificationService($conn);

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    switch ($action) {
        case 'send_email_verification':
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
            
        default:
            throw new Exception('Acción no válida: ' . $action);
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    error_log("Error en verification API: " . $e->getMessage());
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);

// ✅ FUNCIONES ELIMINADAS - Ya están en config/verification.php
?>