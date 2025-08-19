<?php
// config/verification.php - Sistema de Verificación COMPLETO - ACTUALIZADO CON PASSWORD RESET
class VerificationService {
    private $conn;
    
    // Configuración de Email (Gmail SMTP) - CAMBIAR POR TUS DATOS
    private $smtp_config = [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'username' => 'jc.reyesm8@gmail.com',        // ✅ Tu email
        'password' => 'mmcz tpee zcqf pefg',          // ✅ Tu App Password
        'encryption' => 'tls',
        'from_email' => 'jc.reyesm8@gmail.com',       // ✅ Tu email
        'from_name' => 'Novedades Ashley'              // ✅ Nombre que verán
    ];
    
    // Configuración de Twilio (SMS/WhatsApp) - CAMBIAR POR TUS DATOS
    private $twilio_config = [
        'account_sid' => 'TU_TWILIO_ACCOUNT_SID',  // ⚠️ CAMBIAR
        'auth_token' => 'TU_TWILIO_AUTH_TOKEN',    // ⚠️ CAMBIAR
        'phone_number' => '+1234567890',           // ⚠️ CAMBIAR - Tu número de Twilio
        'whatsapp_number' => 'whatsapp:+14155238886' // Número sandbox WhatsApp
    ];
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
    }
    
    /**
     * Enviar código de verificación por email
     */
    public function sendEmailVerification($user_id, $email) {
        try {
            // Generar código de 6 dígitos
            $codigo = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            // Guardar código en base de datos
            $stmt = $this->conn->prepare("
                INSERT INTO verification_codes (user_id, type, code, email, expires_at, created_at) 
                VALUES (?, 'email_verification', ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                code = VALUES(code), 
                expires_at = VALUES(expires_at), 
                attempts = 0,
                created_at = NOW()
            ");
            $stmt->execute([$user_id, $codigo, $email, $expires_at]);
            
            // Enviar email REAL
            return $this->sendEmail($email, $codigo, 'email_verification');
            
        } catch (Exception $e) {
            error_log("Error enviando verificación email: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ✨ NUEVO: Enviar código de recuperación de contraseña por email
     */
    public function sendPasswordResetEmail($user_id, $email, $user_name = '') {
        try {
            // Generar código de 6 dígitos
            $codigo = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            // Guardar código en base de datos
            $stmt = $this->conn->prepare("
                INSERT INTO verification_codes (user_id, type, code, email, expires_at, created_at) 
                VALUES (?, 'password_reset', ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                code = VALUES(code), 
                expires_at = VALUES(expires_at), 
                attempts = 0,
                created_at = NOW()
            ");
            $stmt->execute([$user_id, $codigo, $email, $expires_at]);
            
            // Enviar email de recuperación
            return $this->sendEmail($email, $codigo, 'password_reset', $user_name);
            
        } catch (Exception $e) {
            error_log("Error enviando recuperación de contraseña: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ✨ NUEVO: Enviar email de confirmación de reset exitoso
     */
    public function sendPasswordResetConfirmation($email, $user_name = '') {
        try {
            return $this->sendEmail($email, '', 'password_reset_confirmation', $user_name);
        } catch (Exception $e) {
            error_log("Error enviando confirmación de reset: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Enviar código por SMS
     */
    public function sendSMSVerification($user_id, $phone) {
        try {
            // Limpiar número de teléfono
            $phone = $this->cleanPhoneNumber($phone);
            
            // Generar código de 6 dígitos
            $codigo = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            // Guardar código en base de datos
            $stmt = $this->conn->prepare("
                INSERT INTO verification_codes (user_id, type, code, phone, expires_at, created_at) 
                VALUES (?, 'phone_verification', ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                code = VALUES(code), 
                expires_at = VALUES(expires_at), 
                attempts = 0,
                created_at = NOW()
            ");
            $stmt->execute([$user_id, $codigo, $phone, $expires_at]);
            
            // Enviar SMS usando Twilio
            return $this->sendSMS($phone, $codigo);
            
        } catch (Exception $e) {
            error_log("Error enviando SMS: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ✨ NUEVO: Enviar código de recuperación de contraseña por SMS
     */
    public function sendPasswordResetSMS($user_id, $phone) {
        try {
            // Limpiar número de teléfono
            $phone = $this->cleanPhoneNumber($phone);
            
            // Generar código de 6 dígitos
            $codigo = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            // Guardar código en base de datos
            $stmt = $this->conn->prepare("
                INSERT INTO verification_codes (user_id, type, code, phone, expires_at, created_at) 
                VALUES (?, 'password_reset', ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                code = VALUES(code), 
                expires_at = VALUES(expires_at), 
                attempts = 0,
                created_at = NOW()
            ");
            $stmt->execute([$user_id, $codigo, $phone, $expires_at]);
            
            // Enviar SMS de recuperación
            return $this->sendPasswordResetSMSMessage($phone, $codigo);
            
        } catch (Exception $e) {
            error_log("Error enviando SMS de recuperación: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Enviar código por WhatsApp
     */
    public function sendWhatsAppVerification($user_id, $phone) {
        try {
            // Limpiar número de teléfono
            $phone = $this->cleanPhoneNumber($phone);
            
            // Generar código de 6 dígitos
            $codigo = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            // Guardar código en base de datos
            $stmt = $this->conn->prepare("
                INSERT INTO verification_codes (user_id, type, code, phone, expires_at, created_at) 
                VALUES (?, 'whatsapp_verification', ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                code = VALUES(code), 
                expires_at = VALUES(expires_at), 
                attempts = 0,
                created_at = NOW()
            ");
            $stmt->execute([$user_id, $codigo, $phone, $expires_at]);
            
            // Enviar WhatsApp usando Twilio
            return $this->sendWhatsApp($phone, $codigo);
            
        } catch (Exception $e) {
            error_log("Error enviando WhatsApp: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ✨ NUEVO: Enviar código de recuperación de contraseña por WhatsApp
     */
    public function sendPasswordResetWhatsApp($user_id, $phone) {
        try {
            // Limpiar número de teléfono
            $phone = $this->cleanPhoneNumber($phone);
            
            // Generar código de 6 dígitos
            $codigo = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            // Guardar código en base de datos
            $stmt = $this->conn->prepare("
                INSERT INTO verification_codes (user_id, type, code, phone, expires_at, created_at) 
                VALUES (?, 'password_reset', ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                code = VALUES(code), 
                expires_at = VALUES(expires_at), 
                attempts = 0,
                created_at = NOW()
            ");
            $stmt->execute([$user_id, $codigo, $phone, $expires_at]);
            
            // Enviar WhatsApp de recuperación
            return $this->sendPasswordResetWhatsAppMessage($phone, $codigo);
            
        } catch (Exception $e) {
            error_log("Error enviando WhatsApp de recuperación: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verificar código ingresado
     */
    public function verifyCode($user_id, $code, $type) {
        try {
            // Buscar código válido
            $stmt = $this->conn->prepare("
                SELECT * FROM verification_codes 
                WHERE user_id = ? AND type = ? AND code = ? 
                AND expires_at > NOW() AND verified = 0 AND attempts < 3
            ");
            $stmt->execute([$user_id, $type, $code]);
            $verification = $stmt->fetch();
            
            if (!$verification) {
                // Incrementar intentos fallidos
                $stmt = $this->conn->prepare("
                    UPDATE verification_codes 
                    SET attempts = attempts + 1 
                    WHERE user_id = ? AND type = ?
                ");
                $stmt->execute([$user_id, $type]);
                return false;
            }
            
            // Marcar como verificado
            $stmt = $this->conn->prepare("
                UPDATE verification_codes 
                SET verified = 1, verified_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$verification['id']]);
            
            // Actualizar estado del usuario según el tipo
            if ($type === 'email_verification') {
                $stmt = $this->conn->prepare("UPDATE clientes SET email_verified = 1 WHERE id = ?");
                $stmt->execute([$user_id]);
            } elseif (in_array($type, ['phone_verification', 'whatsapp_verification'])) {
                $stmt = $this->conn->prepare("UPDATE clientes SET phone_verified = 1 WHERE id = ?");
                $stmt->execute([$user_id]);
            }
            // Para password_reset no actualizamos estado del usuario, solo validamos el código
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error verificando código: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Enviar email usando Gmail SMTP
     */
    private function sendEmail($to_email, $codigo, $type, $user_name = '') {
        $subject = '';
        $message = '';
        
        switch ($type) {
            case 'email_verification':
                $subject = '🔐 Verificar tu correo - Novedades Ashley';
                $message = $this->getEmailVerificationTemplate($codigo);
                break;
                
            case 'email_change':
                $subject = '🔧 Confirmar cambio de correo - Novedades Ashley';
                $message = $this->getEmailChangeTemplate($codigo);
                break;
                
            case 'password_reset':
                $subject = '🔐 Recuperar tu contraseña - Novedades Ashley';
                $message = $this->getPasswordResetTemplate($codigo, $user_name);
                break;
                
            case 'password_reset_confirmation':
                $subject = '✅ Contraseña actualizada - Novedades Ashley';
                $message = $this->getPasswordResetConfirmationTemplate($user_name);
                break;
        }
        
        // FORZAR uso de PHPMailer siempre
        return $this->sendEmailWithPHPMailer($to_email, $subject, $message);
    }
    
    /**
     * Enviar email con PHPMailer (recomendado)
     */
    private function sendEmailWithPHPMailer($to_email, $subject, $message) {
        try {
            // ✅ CORRECCIÓN: Usar autoload de Composer
            require_once __DIR__ . '/../vendor/autoload.php';
            
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Configuración SMTP
            $mail->isSMTP();
            $mail->Host = $this->smtp_config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtp_config['username'];
            $mail->Password = $this->smtp_config['password'];
            $mail->SMTPSecure = $this->smtp_config['encryption'];
            $mail->Port = $this->smtp_config['port'];
            $mail->CharSet = 'UTF-8';
            
            // Remitente y destinatario
            $mail->setFrom($this->smtp_config['from_email'], $this->smtp_config['from_name']);
            $mail->addAddress($to_email);
            
            // Contenido
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $message;
            
            $mail->send();
            return true;
            
        } catch (Exception $e) {
            error_log("Error con PHPMailer: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Enviar SMS usando Twilio
     */
    private function sendSMS($phone, $codigo) {
        $message = "Tu código de verificación para Novedades Ashley es: {$codigo}. Válido por 15 minutos.";
        return $this->sendTwilioSMS($phone, $message);
    }
    
    /**
     * ✨ NUEVO: Enviar SMS de recuperación de contraseña
     */
    private function sendPasswordResetSMSMessage($phone, $codigo) {
        $message = "🔐 Tu código de recuperación de contraseña para Novedades Ashley es: {$codigo}. Válido por 15 minutos. Si no fuiste tú, ignora este mensaje.";
        return $this->sendTwilioSMS($phone, $message);
    }
    
    /**
     * Enviar WhatsApp usando Twilio
     */
    private function sendWhatsApp($phone, $codigo) {
        $message = "🔐 *Novedades Ashley*\n\nTu código de verificación es: *{$codigo}*\n\nVálido por 15 minutos.\n\n¡Gracias por confiar en nosotros! 🛍️";
        return $this->sendTwilioWhatsApp($phone, $message);
    }
    
    /**
     * ✨ NUEVO: Enviar WhatsApp de recuperación de contraseña
     */
    private function sendPasswordResetWhatsAppMessage($phone, $codigo) {
        $message = "🔐 *Novedades Ashley - Recuperar Contraseña*\n\nTu código de recuperación es: *{$codigo}*\n\nVálido por 15 minutos.\n\n⚠️ Si no solicitaste esto, ignora este mensaje.\n\nTu cuenta permanece segura. 🛡️";
        return $this->sendTwilioWhatsApp($phone, $message);
    }
    
    /**
     * Función genérica para enviar SMS con Twilio
     */
    private function sendTwilioSMS($phone, $message) {
        // Usar cURL para llamar a la API de Twilio
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->twilio_config['account_sid']}/Messages.json";
        
        $data = [
            'From' => $this->twilio_config['phone_number'],
            'To' => $phone,
            'Body' => $message
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->twilio_config['account_sid'] . ':' . $this->twilio_config['auth_token']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code >= 200 && $http_code < 300) {
            return true;
        } else {
            error_log("Error Twilio SMS: HTTP {$http_code} - {$response}");
            return false;
        }
    }
    
    /**
     * Función genérica para enviar WhatsApp con Twilio
     */
    private function sendTwilioWhatsApp($phone, $message) {
        // Usar cURL para llamar a la API de Twilio WhatsApp
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->twilio_config['account_sid']}/Messages.json";
        
        $data = [
            'From' => $this->twilio_config['whatsapp_number'],
            'To' => 'whatsapp:' . $phone,
            'Body' => $message
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->twilio_config['account_sid'] . ':' . $this->twilio_config['auth_token']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code >= 200 && $http_code < 300) {
            return true;
        } else {
            error_log("Error Twilio WhatsApp: HTTP {$http_code} - {$response}");
            return false;
        }
    }
    
    /**
     * Limpiar número de teléfono para formato internacional
     */
    private function cleanPhoneNumber($phone) {
        // Remover caracteres no numéricos
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Si empieza con 52 (México), mantenerlo
        if (substr($phone, 0, 2) === '52') {
            return '+' . $phone;
        }
        
        // Si es número mexicano de 10 dígitos, agregar +52
        if (strlen($phone) === 10) {
            return '+52' . $phone;
        }
        
        // Si ya tiene código de país, agregar +
        if (strlen($phone) > 10) {
            return '+' . $phone;
        }
        
        return '+52' . $phone; // Por defecto México
    }
    
    /**
     * Template de email para verificación
     */
    private function getEmailVerificationTemplate($codigo) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f4f4f4; }
                .container { background: white; padding: 30px; border-radius: 15px; max-width: 600px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
                .header { text-align: center; margin-bottom: 30px; }
                .logo { color: #667eea; font-size: 24px; font-weight: bold; margin-bottom: 10px; }
                .code { background: linear-gradient(45deg, #667eea, #764ba2); color: white; padding: 20px; border-radius: 10px; font-size: 32px; font-weight: bold; text-align: center; margin: 30px 0; letter-spacing: 4px; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; border-top: 1px solid #eee; padding-top: 20px; }
                .warning { background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: 15px; margin: 20px 0; color: #856404; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div class='logo'>👑 Novedades Ashley</div>
                    <h1 style='color: #333; margin: 0;'>Verificar tu Email</h1>
                    <p style='color: #666; margin: 10px 0 0 0;'>Confirma tu dirección de correo electrónico</p>
                </div>
                
                <p>¡Hola! Gracias por registrarte en Novedades Ashley.</p>
                
                <p>Para completar tu registro y activar tu cuenta, por favor ingresa el siguiente código de verificación:</p>
                
                <div class='code'>{$codigo}</div>
                
                <div class='warning'>
                    <strong>⏰ Este código expira en 15 minutos.</strong><br>
                    Si no fuiste tú quien solicitó este código, puedes ignorar este email.
                </div>
                
                <p>Una vez verificado, podrás:</p>
                <ul>
                    <li>✅ Realizar compras</li>
                    <li>📦 Rastrear tus pedidos</li>
                    <li>🔔 Recibir notificaciones importantes</li>
                    <li>💳 Guardar métodos de pago</li>
                </ul>
                
                <div class='footer'>
                    <p><strong>Equipo de Novedades Ashley</strong></p>
                    <p>\"Descubre lo nuevo, siente la diferencia\"</p>
                    <hr style='border: none; border-top: 1px solid #eee; margin: 15px 0;'>
                    <p><small>Este es un email automático, por favor no respondas.<br>
                    Si tienes problemas, contacta nuestro soporte.</small></p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    /**
     * Template de email para cambio de correo
     */
    private function getEmailChangeTemplate($codigo) {
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
                .code { background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 20px; border-radius: 10px; font-size: 32px; font-weight: bold; text-align: center; margin: 30px 0; letter-spacing: 4px; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; border-top: 1px solid #eee; padding-top: 20px; }
                .danger { background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 8px; padding: 15px; margin: 20px 0; color: #721c24; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div class='logo'>👑 Novedades Ashley</div>
                    <h1 style='color: #333; margin: 0;'>Confirmar Cambio de Email</h1>
                    <p style='color: #666; margin: 10px 0 0 0;'>Verificación de nueva dirección</p>
                </div>
                
                <p>Has solicitado cambiar tu dirección de email en Novedades Ashley.</p>
                
                <p>Para confirmar tu nueva dirección, ingresa el siguiente código:</p>
                
                <div class='code'>{$codigo}</div>
                
                <div class='danger'>
                    <strong>🚨 ¿No solicitaste este cambio?</strong><br>
                    Si no fuiste tú, contacta inmediatamente a nuestro soporte. Tu cuenta podría estar comprometida.
                </div>
                
                <div class='footer'>
                    <p><strong>Equipo de Novedades Ashley</strong></p>
                    <p>\"Descubre lo nuevo, siente la diferencia\"</p>
                    <hr style='border: none; border-top: 1px solid #eee; margin: 15px 0;'>
                    <p><small>Este es un email automático, por favor no respondas.</small></p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    /**
     * ✨ NUEVO: Template de email para recuperación de contraseña
     */
    private function getPasswordResetTemplate($codigo, $user_name = '') {
        $nombre_saludo = !empty($user_name) ? $user_name : 'Usuario';
        
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
     * ✨ NUEVO: Template de email de confirmación de reset exitoso
     */
    private function getPasswordResetConfirmationTemplate($user_name = '') {
        $nombre_saludo = !empty($user_name) ? $user_name : 'Usuario';
        
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
                    <strong>🔐 ¿Qué hacer ahora?</strong><br>
                    • Ya puedes iniciar sesión con tu nueva contraseña<br>
                    • Guarda tu contraseña en un lugar seguro<br>
                    • No compartas tu contraseña con nadie<br>
                    • Considera usar un administrador de contraseñas
                </div>
                
                <p><strong>Consejos de seguridad:</strong></p>
                <ul>
                    <li>🔒 Usa una contraseña única para cada sitio web</li>
                    <li>📱 Mantén tu información de contacto actualizada</li>
                    <li>🚨 Reporta cualquier actividad sospechosa</li>
                    <li>🔄 Cambia tu contraseña periódicamente</li>
                </ul>
                
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
    
    /**
     * Reenviar código (genera uno nuevo)
     */
    public function resendVerification($user_id, $type) {
        try {
            // Obtener información del usuario
            $stmt = $this->conn->prepare("SELECT email, telefono FROM clientes WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return false;
            }
            
            // Reenviar según el tipo
            switch ($type) {
                case 'email_verification':
                    return $this->sendEmailVerification($user_id, $user['email']);
                    
                case 'phone_verification':
                    return $this->sendSMSVerification($user_id, $user['telefono']);
                    
                case 'whatsapp_verification':
                    return $this->sendWhatsAppVerification($user_id, $user['telefono']);
                    
                case 'password_reset':
                    // Para password reset, necesitamos saber si es email, SMS o WhatsApp
                    // Por defecto intentamos email
                    return $this->sendPasswordResetEmail($user_id, $user['email']);
                    
                default:
                    return false;
            }
            
        } catch (Exception $e) {
            error_log("Error reenviando código: " . $e->getMessage());
            return false;
        }
    }
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

// Función auxiliar para enmascarar teléfono
function maskPhone($phone) {
    if (empty($phone)) return '';
    
    $clean = preg_replace('/[^0-9]/', '', $phone);
    
    if (strlen($clean) <= 4) {
        return str_repeat('*', strlen($clean));
    }
    
    return str_repeat('*', strlen($clean) - 4) . substr($clean, -4);
}
?>