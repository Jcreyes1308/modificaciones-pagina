<?php
// api/password-reset.php - API COMPLETA para recuperación de contraseñas - CORREGIDA
session_start();
require_once __DIR__ . '/../config/database.php';

// ✅ CORREGIDO: Headers y manejo de errores mejorado
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Manejar solicitudes OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => '', 'data' => null];

// ✅ LOGGING para debugging - QUITAR EN PRODUCCIÓN
error_log("PASSWORD RESET API - Action: " . $action);
error_log("PASSWORD RESET API - POST data: " . print_r($_POST, true));

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception('Error de conexión a la base de datos');
    }
    
    switch ($action) {
        case 'send_reset_code':
            $method = $_POST['method'] ?? '';
            $user_id = intval($_POST['user_id'] ?? 0);
            $contact = trim($_POST['contact'] ?? '');
            
            if (!$user_id || empty($contact) || empty($method)) {
                throw new Exception('Datos incompletos');
            }
            
            if ($method !== 'email') {
                throw new Exception('Por ahora solo funciona el método de email');
            }
            
            // Verificar que el usuario existe
            $stmt = $conn->prepare("SELECT id, nombre, email, telefono, activo FROM clientes WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            
            if (!$user) {
                throw new Exception('Usuario no encontrado');
            }
            
            if (!$user['activo']) {
                throw new Exception('Cuenta desactivada');
            }
            
            // Validar email
            if (!filter_var($contact, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Email no válido');
            }
            
            if (strtolower($contact) !== strtolower($user['email'])) {
                throw new Exception('El email no coincide con el registrado');
            }
            
            // Verificar cooldown (no enviar más de 1 cada 2 minutos)
            $stmt = $conn->prepare("
                SELECT created_at FROM verification_codes 
                WHERE user_id = ? AND type = 'password_reset' 
                AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([$user_id]);
            if ($stmt->fetch()) {
                throw new Exception('Debes esperar 2 minutos antes de solicitar otro código');
            }
            
            // Generar código de 6 dígitos
            $codigo = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            // Guardar código en base de datos
            $stmt = $conn->prepare("
                INSERT INTO verification_codes (user_id, type, code, email, expires_at, created_at) 
                VALUES (?, 'password_reset', ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                code = VALUES(code), 
                expires_at = VALUES(expires_at), 
                attempts = 0,
                created_at = NOW()
            ");
            $stmt->execute([$user_id, $codigo, $contact, $expires_at]);
            
            // ✅ ENVIAR EMAIL REAL con PHPMailer
            $email_sent = sendPasswordResetEmail($contact, $codigo, $user['nombre']);
            
            if ($email_sent) {
                $response['success'] = true;
                $response['message'] = 'Código de recuperación enviado a tu email';
                $response['data'] = [
                    'method' => $method,
                    'masked_contact' => maskEmail($contact),
                    'expires_in_minutes' => 15,
                    'debug_code' => $codigo // ⚠️ SOLO PARA PRUEBAS - REMOVER EN PRODUCCIÓN
                ];
            } else {
                throw new Exception('Error al enviar email. Verifica tu configuración SMTP.');
            }
            break;
            
        case 'reset_password':
            $user_id = intval($_POST['user_id'] ?? 0);
            $code = trim($_POST['code'] ?? '');
            $new_password = $_POST['new_password'] ?? '';
            
            error_log("RESET PASSWORD - User ID: $user_id, Code: $code, Password length: " . strlen($new_password));
            
            if (!$user_id || empty($code) || empty($new_password)) {
                throw new Exception('Datos incompletos: user_id, code y new_password son requeridos');
            }
            
            if (strlen($code) !== 6 || !ctype_digit($code)) {
                throw new Exception('Código debe ser de 6 dígitos numéricos');
            }
            
            if (strlen($new_password) < 6) {
                throw new Exception('La contraseña debe tener al menos 6 caracteres');
            }
            
            // ✅ NUEVO: Verificar que el usuario existe Y obtener contraseña actual PRIMERO
            $stmt = $conn->prepare("SELECT id, nombre, email, password FROM clientes WHERE id = ? AND activo = 1");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            
            if (!$user) {
                throw new Exception('Usuario no encontrado o inactivo');
            }
            
            // ✅ NUEVO: Verificar que la nueva contraseña sea DIFERENTE a la actual
            if (password_verify($new_password, $user['password'])) {
                throw new Exception('La nueva contraseña debe ser diferente a la contraseña actual');
            }
            
            // Ahora sí buscar código válido
            $stmt = $conn->prepare("
                SELECT * FROM verification_codes 
                WHERE user_id = ? AND type = 'password_reset' AND code = ? 
                AND expires_at > NOW() AND verified = 0 AND attempts < 3
            ");
            $stmt->execute([$user_id, $code]);
            $verification_record = $stmt->fetch();
            
            error_log("VERIFICATION RECORD: " . print_r($verification_record, true));
            
            if (!$verification_record) {
                // Incrementar intentos fallidos
                $stmt = $conn->prepare("
                    UPDATE verification_codes 
                    SET attempts = attempts + 1 
                    WHERE user_id = ? AND type = 'password_reset'
                ");
                $stmt->execute([$user_id]);
                
                // ✅ DEBUGGING: Verificar qué códigos existen
                $stmt = $conn->prepare("
                    SELECT code, expires_at, verified, attempts 
                    FROM verification_codes 
                    WHERE user_id = ? AND type = 'password_reset' 
                    ORDER BY created_at DESC LIMIT 3
                ");
                $stmt->execute([$user_id]);
                $existing_codes = $stmt->fetchAll();
                error_log("EXISTING CODES FOR USER $user_id: " . print_r($existing_codes, true));
                
                throw new Exception('Código inválido, expirado o demasiados intentos');
            }
            
            // ✅ CORREGIDO: Iniciar transacción para atomicidad
            $conn->beginTransaction();
            
            try {
                // Actualizar contraseña
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE clientes SET password = ? WHERE id = ?");
                $result = $stmt->execute([$password_hash, $user_id]);
                
                if (!$result) {
                    throw new Exception('Error al actualizar la contraseña');
                }
                
                // Marcar código como verificado
                $stmt = $conn->prepare("
                    UPDATE verification_codes 
                    SET verified = 1, verified_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$verification_record['id']]);
                
                // Confirmar transacción
                $conn->commit();
                
                // Enviar email de confirmación (opcional, no bloquea si falla)
                try {
                    sendPasswordResetConfirmationEmail($user['email'], $user['nombre']);
                } catch (Exception $e) {
                    error_log("Error enviando confirmación: " . $e->getMessage());
                }
                
                $response['success'] = true;
                $response['message'] = 'Contraseña restablecida exitosamente';
                $response['data'] = [
                    'user_id' => $user_id,
                    'reset_time' => date('Y-m-d H:i:s')
                ];
                
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;
            
        default:
            throw new Exception('Acción no válida: ' . $action);
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    error_log("Error en password-reset API: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // ✅ DEBUGGING: Información adicional en desarrollo
    if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
        $response['debug'] = [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'action' => $action,
            'post_data' => $_POST
        ];
    }
}

// ✅ CORREGIDO: Asegurar salida JSON válida
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;

// ========================
// FUNCIONES DE EMAIL
// ========================

/**
 * Enviar email de recuperación de contraseña
 */
function sendPasswordResetEmail($email, $codigo, $nombre = '') {
    try {
        // ✅ VERIFICAR que PHPMailer esté disponible
        $autoload_path = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($autoload_path)) {
            error_log("PHPMailer autoload no encontrado en: $autoload_path");
            return false;
        }
        
        require_once $autoload_path;
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Configuración SMTP (misma que tienes en verification.php)
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'jc.reyesm8@gmail.com';        // ✅ Tu email
        $mail->Password = 'mmcz tpee zcqf pefg';          // ✅ Tu App Password
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        
        $mail->setFrom('jc.reyesm8@gmail.com', 'Novedades Ashley');
        $mail->addAddress($email);
        
        $mail->isHTML(true);
        $mail->Subject = '🔐 Recuperar tu contraseña - Novedades Ashley';
        $mail->Body = getPasswordResetEmailTemplate($codigo, $nombre);
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Error enviando email de reset: " . $e->getMessage());
        return false;
    }
}

/**
 * Enviar email de confirmación de reset exitoso
 */
function sendPasswordResetConfirmationEmail($email, $nombre = '') {
    try {
        require_once __DIR__ . '/../vendor/autoload.php';
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Misma configuración SMTP
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'jc.reyesm8@gmail.com';
        $mail->Password = 'mmcz tpee zcqf pefg';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        
        $mail->setFrom('jc.reyesm8@gmail.com', 'Novedades Ashley');
        $mail->addAddress($email);
        
        $mail->isHTML(true);
        $mail->Subject = '✅ Contraseña actualizada - Novedades Ashley';
        $mail->Body = getPasswordResetConfirmationTemplate($nombre);
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Error enviando confirmación de reset: " . $e->getMessage());
        return false;
    }
}

/**
 * Template de email para recuperación de contraseña
 */
function getPasswordResetEmailTemplate($codigo, $nombre = '') {
    $nombre_saludo = !empty($nombre) ? $nombre : 'Usuario';
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f4f4f4; }
            .container { background: white; padding: 30px; border-radius: 15px; max-width: 600px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
            .header { text-align: center; margin-bottom: 30px; }
            .logo { color: #dc3545; font-size: 24px; font-weight: bold; margin-bottom: 10px; }
            .code { background: linear-gradient(45deg, #dc3545, #fd7e14); color: white; padding: 20px; border-radius: 10px; font-size: 32px; font-weight: bold; text-align: center; margin: 30px 0; letter-spacing: 4px; }
            .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; border-top: 1px solid #eee; padding-top: 20px; }
            .warning { background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: 15px; margin: 20px 0; color: #856404; }
            .security { background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 8px; padding: 15px; margin: 20px 0; color: #721c24; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <div class='logo'>🔐 Novedades Ashley</div>
                <h1 style='color: #333; margin: 0;'>Recuperar Contraseña</h1>
                <p style='color: #666; margin: 10px 0 0 0;'>Código de recuperación solicitado</p>
            </div>
            
            <p>Hola <strong>{$nombre_saludo}</strong>,</p>
            
            <p>Recibimos una solicitud para restablecer la contraseña de tu cuenta en Novedades Ashley.</p>
            
            <p>Tu código de recuperación es:</p>
            
            <div class='code'>{$codigo}</div>
            
            <div class='warning'>
                <strong>⏰ Este código expira en 15 minutos.</strong><br>
                Úsalo en la página de recuperación para establecer tu nueva contraseña.
            </div>
            
            <div class='security'>
                <strong>🚨 ¿No solicitaste esto?</strong><br>
                Si no fuiste tú quien pidió restablecer la contraseña, ignora este email. 
                Tu cuenta permanece segura y no se realizarán cambios.
            </div>
            
            <p><strong>Instrucciones:</strong></p>
            <ol>
                <li>Ve a la página de recuperación de contraseña</li>
                <li>Ingresa el código de 6 dígitos: <strong>{$codigo}</strong></li>
                <li>Establece tu nueva contraseña</li>
                <li>¡Listo! Ya puedes iniciar sesión</li>
            </ol>
            
            <div class='footer'>
                <p><strong>Equipo de Novedades Ashley</strong></p>
                <p>\"Descubre lo nuevo, siente la diferencia\"</p>
                <hr style='border: none; border-top: 1px solid #eee; margin: 15px 0;'>
                <p><small>Este es un email automático, por favor no respondas.<br>
                Si tienes problemas, contacta nuestro soporte.</small></p>
                <p><small>IP de solicitud: " . ($_SERVER['REMOTE_ADDR'] ?? 'No disponible') . "<br>
                Fecha: " . date('d/m/Y H:i:s') . "</small></p>
            </div>
        </div>
    </body>
    </html>
    ";
}

/**
 * Template de email de confirmación de reset exitoso
 */
function getPasswordResetConfirmationTemplate($nombre = '') {
    $nombre_saludo = !empty($nombre) ? $nombre : 'Usuario';
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f4f4f4; }
            .container { background: white; padding: 30px; border-radius: 15px; max-width: 600px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
            .header { text-align: center; margin-bottom: 30px; }
            .logo { color: #28a745; font-size: 24px; font-weight: bold; margin-bottom: 10px; }
            .success { background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 20px; border-radius: 10px; text-align: center; margin: 30px 0; }
            .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; border-top: 1px solid #eee; padding-top: 20px; }
            .info { background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 8px; padding: 15px; margin: 20px 0; color: #0c5460; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <div class='logo'>✅ Novedades Ashley</div>
                <h1 style='color: #333; margin: 0;'>Contraseña Actualizada</h1>
                <p style='color: #666; margin: 10px 0 0 0;'>Tu contraseña ha sido restablecida exitosamente</p>
            </div>
            
            <div class='success'>
                <h2 style='margin: 0 0 10px 0;'>🎉 ¡Listo!</h2>
                <p style='margin: 0;'>Tu contraseña ha sido actualizada correctamente</p>
            </div>
            
            <p>Hola <strong>{$nombre_saludo}</strong>,</p>
            
            <p>Te confirmamos que la contraseña de tu cuenta en Novedades Ashley ha sido restablecida exitosamente el día " . date('d/m/Y') . " a las " . date('H:i:s') . ".</p>
            
            <div class='info'>
                <strong>📋 ¿Qué hacer ahora?</strong><br>
                • Ya puedes iniciar sesión con tu nueva contraseña<br>
                • Guarda tu contraseña en un lugar seguro<br>
                • No compartas tu contraseña con nadie<br>
                • Considera usar un administrador de contraseñas
            </div>
            
            <p>Si no realizaste este cambio, contacta inmediatamente a nuestro equipo de soporte.</p>
            
            <div class='footer'>
                <p><strong>Equipo de Novedades Ashley</strong></p>
                <p>\"Descubre lo nuevo, siente la diferencia\"</p>
                <hr style='border: none; border-top: 1px solid #eee; margin: 15px 0;'>
                <p><small>Este es un email automático, por favor no respondas.<br>
                Si tienes problemas, contacta nuestro soporte.</small></p>
                <p><small>IP de cambio: " . ($_SERVER['REMOTE_ADDR'] ?? 'No disponible') . "<br>
                Fecha: " . date('d/m/Y H:i:s') . "</small></p>
            </div>
        </div>
    </body>
    </html>
    ";
}

// Función auxiliar para enmascarar email
function maskEmail($email) {
    if (empty($email)) return '';
    
    $at_pos = strpos($email, '@');
    if ($at_pos === false) return '***';
    
    $local = substr($email, 0, $at_pos);
    $domain = substr($email, $at_pos);
    
    if (strlen($local) <= 2) {
        return '*' . $domain;
    }
    
    return substr($local, 0, 2) . str_repeat('*', strlen($local) - 2) . $domain;
}
?>