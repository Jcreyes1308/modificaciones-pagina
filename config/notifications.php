<?php
// config/notifications.php - Sistema de Notificaciones de Pedidos
class OrderNotificationService {
    private $conn;
    private $verification_service;
    
    // Configuración de Email (reutiliza la configuración existente)
    private $smtp_config = [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'username' => 'jc.reyesm8@gmail.com',        // ✅ Tu email
        'password' => 'mmcz tpee zcqf pefg',          // ✅ Tu App Password
        'encryption' => 'tls',
        'from_email' => 'jc.reyesm8@gmail.com',       // ✅ Tu email
        'from_name' => 'Novedades Ashley'              // ✅ Nombre que verán
    ];
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
        
        // Cargar VerificationService para reutilizar la configuración de emails
        require_once __DIR__ . '/verification.php';
        $this->verification_service = new VerificationService($database_connection);
    }
    
    /**
     * 📧 Enviar email de confirmación de pedido
     */
    public function sendOrderConfirmation($order_id) {
        try {
            $order_data = $this->getOrderData($order_id);
            if (!$order_data) {
                throw new Exception('Pedido no encontrado');
            }
            
            $subject = "✅ Pedido Confirmado #{$order_data['numero_pedido']} - Novedades Ashley";
            $message = $this->getOrderConfirmationTemplate($order_data);
            
            $result = $this->sendEmail($order_data['cliente_email'], $subject, $message);
            
            // Log de notificación
            $this->logNotification($order_id, 'order_confirmation', 'email', $order_data['cliente_email'], $result);
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error enviando confirmación de pedido {$order_id}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 📦 Enviar email de cambio de estado
     */
    public function sendOrderStatusUpdate($order_id, $old_status, $new_status, $tracking_number = null) {
        try {
            $order_data = $this->getOrderData($order_id);
            if (!$order_data) {
                throw new Exception('Pedido no encontrado');
            }
            
            $status_info = $this->getStatusInfo($new_status);
            $subject = "{$status_info['emoji']} {$status_info['title']} - Pedido #{$order_data['numero_pedido']}";
            $message = $this->getStatusUpdateTemplate($order_data, $old_status, $new_status, $tracking_number);
            
            $result = $this->sendEmail($order_data['cliente_email'], $subject, $message);
            
            // Log de notificación
            $this->logNotification($order_id, 'status_update', 'email', $order_data['cliente_email'], $result, [
                'old_status' => $old_status,
                'new_status' => $new_status,
                'tracking_number' => $tracking_number
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error enviando actualización de estado {$order_id}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 🚚 Enviar email de tracking/seguimiento
     */
    public function sendShippingNotification($order_id, $tracking_number, $carrier = null) {
        try {
            $order_data = $this->getOrderData($order_id);
            if (!$order_data) {
                throw new Exception('Pedido no encontrado');
            }
            
            $subject = "🚚 Tu pedido está en camino - #{$order_data['numero_pedido']}";
            $message = $this->getShippingTemplate($order_data, $tracking_number, $carrier);
            
            $result = $this->sendEmail($order_data['cliente_email'], $subject, $message);
            
            // Log de notificación
            $this->logNotification($order_id, 'shipping_notification', 'email', $order_data['cliente_email'], $result, [
                'tracking_number' => $tracking_number,
                'carrier' => $carrier
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error enviando notificación de envío {$order_id}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 📋 Obtener datos completos del pedido
     */
    private function getOrderData($order_id) {
        try {
            $stmt = $this->conn->prepare("
                SELECT 
                    p.*,
                    c.nombre as cliente_nombre,
                    c.email as cliente_email,
                    c.telefono as cliente_telefono,
                    de.nombre_destinatario,
                    de.calle_numero,
                    de.colonia,
                    de.ciudad,
                    de.estado as estado_direccion,
                    de.codigo_postal,
                    de.telefono_contacto
                FROM pedidos p
                LEFT JOIN clientes c ON p.id_cliente = c.id
                LEFT JOIN direcciones_envio de ON p.id_direccion_envio = de.id
                WHERE p.id = ? AND p.activo = 1
            ");
            $stmt->execute([$order_id]);
            $order = $stmt->fetch();
            
            if ($order) {
                // Obtener items del pedido
                $stmt = $this->conn->prepare("
                    SELECT * FROM pedido_detalles 
                    WHERE id_pedido = ? 
                    ORDER BY id
                ");
                $stmt->execute([$order_id]);
                $order['items'] = $stmt->fetchAll();
            }
            
            return $order;
            
        } catch (Exception $e) {
            error_log("Error obteniendo datos del pedido {$order_id}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 📧 Enviar email usando PHPMailer
     */
    private function sendEmail($to_email, $subject, $message) {
        try {
            // Reutilizar la lógica de VerificationService
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
            error_log("Error enviando email a {$to_email}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 📝 Log de notificaciones enviadas
     */
    private function logNotification($order_id, $type, $method, $recipient, $success, $extra_data = null) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO order_notifications_log 
                (order_id, notification_type, method, recipient, success, extra_data, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $order_id,
                $type,
                $method,
                $recipient,
                $success ? 1 : 0,
                $extra_data ? json_encode($extra_data) : null
            ]);
        } catch (Exception $e) {
            error_log("Error logging notification: " . $e->getMessage());
        }
    }
    
    /**
     * 📊 Obtener información del estado
     */
    private function getStatusInfo($status) {
        $statuses = [
            'pendiente' => [
                'title' => 'Pedido Recibido',
                'emoji' => '⏳',
                'description' => 'Hemos recibido tu pedido y lo estamos procesando'
            ],
            'confirmado' => [
                'title' => 'Pedido Confirmado',
                'emoji' => '✅',
                'description' => 'Tu pedido ha sido confirmado y está siendo preparado'
            ],
            'procesando' => [
                'title' => 'Preparando tu Pedido',
                'emoji' => '📦',
                'description' => 'Estamos empacando cuidadosamente tus productos'
            ],
            'enviado' => [
                'title' => 'Pedido Enviado',
                'emoji' => '🚚',
                'description' => 'Tu pedido está en camino'
            ],
            'en_transito' => [
                'title' => 'En Tránsito',
                'emoji' => '🛣️',
                'description' => 'Tu pedido está viajando hacia ti'
            ],
            'entregado' => [
                'title' => 'Pedido Entregado',
                'emoji' => '🎉',
                'description' => '¡Tu pedido ha sido entregado exitosamente!'
            ],
            'cancelado' => [
                'title' => 'Pedido Cancelado',
                'emoji' => '❌',
                'description' => 'Tu pedido ha sido cancelado'
            ],
            'devuelto' => [
                'title' => 'Pedido Devuelto',
                'emoji' => '↩️',
                'description' => 'Tu pedido ha sido procesado como devolución'
            ]
        ];
        
        return $statuses[$status] ?? $statuses['pendiente'];
    }
    
    /**
     * 🎨 Template de confirmación de pedido
     */
    private function getOrderConfirmationTemplate($order) {
        $items_html = '';
        foreach ($order['items'] as $item) {
            $items_html .= "
                <tr>
                    <td style='padding: 10px; border-bottom: 1px solid #eee;'>
                        <strong>{$item['nombre_producto']}</strong><br>
                        <small style='color: #666;'>{$item['descripcion_producto']}</small>
                    </td>
                    <td style='padding: 10px; border-bottom: 1px solid #eee; text-align: center;'>{$item['cantidad']}</td>
                    <td style='padding: 10px; border-bottom: 1px solid #eee; text-align: right;'>$" . number_format($item['precio_unitario'], 2) . "</td>
                    <td style='padding: 10px; border-bottom: 1px solid #eee; text-align: right;'><strong>$" . number_format($item['subtotal'], 2) . "</strong></td>
                </tr>
            ";
        }
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f4f4f4; }
                .container { background: white; padding: 30px; border-radius: 15px; max-width: 600px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
                .header { text-align: center; margin-bottom: 30px; background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 20px; border-radius: 10px; }
                .logo { font-size: 24px; font-weight: bold; margin-bottom: 10px; }
                .order-info { background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; border-top: 1px solid #eee; padding-top: 20px; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                .totals { text-align: right; margin-top: 20px; }
                .totals table { width: auto; margin-left: auto; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div class='logo'>👑 Novedades Ashley</div>
                    <h1 style='margin: 0;'>¡Pedido Confirmado!</h1>
                    <p style='margin: 10px 0 0 0;'>Gracias por tu compra, " . htmlspecialchars($order['cliente_nombre']) . "</p>
                </div>
                
                <div class='order-info'>
                    <h3 style='color: #28a745; margin-top: 0;'>📋 Detalles del Pedido</h3>
                    <div style='display: flex; justify-content: space-between; margin-bottom: 10px;'>
                        <span><strong>Número de Pedido:</strong></span>
                        <span>" . htmlspecialchars($order['numero_pedido']) . "</span>
                    </div>
                    <div style='display: flex; justify-content: space-between; margin-bottom: 10px;'>
                        <span><strong>Fecha:</strong></span>
                        <span>" . date('d/m/Y H:i', strtotime($order['created_at'])) . "</span>
                    </div>
                    <div style='display: flex; justify-content: space-between;'>
                        <span><strong>Estado:</strong></span>
                        <span style='color: #28a745; font-weight: bold;'>✅ Confirmado</span>
                    </div>
                </div>
                
                <h3>🛍️ Productos Ordenados</h3>
                <table style='border: 1px solid #ddd;'>
                    <thead style='background: #f8f9fa;'>
                        <tr>
                            <th style='padding: 10px; text-align: left;'>Producto</th>
                            <th style='padding: 10px; text-align: center;'>Cantidad</th>
                            <th style='padding: 10px; text-align: right;'>Precio</th>
                            <th style='padding: 10px; text-align: right;'>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$items_html}
                    </tbody>
                </table>
                
                <div class='totals'>
                    <table>
                        <tr>
                            <td style='padding: 5px 15px 5px 0;'>Subtotal:</td>
                            <td style='padding: 5px 0; text-align: right;'>$" . number_format($order['subtotal'], 2) . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 5px 15px 5px 0;'>IVA:</td>
                            <td style='padding: 5px 0; text-align: right;'>$" . number_format($order['impuestos'], 2) . "</td>
                        </tr>
                        <tr>
                            <td style='padding: 5px 15px 5px 0;'>Envío:</td>
                            <td style='padding: 5px 0; text-align: right;'>" . ($order['costo_envio'] > 0 ? '$' . number_format($order['costo_envio'], 2) : '<span style=\"color: #28a745;\">GRATIS</span>') . "</td>
                        </tr>";
        
        if ($order['descuentos'] > 0) {
            $template .= "
                        <tr>
                            <td style='padding: 5px 15px 5px 0;'>Descuentos:</td>
                            <td style='padding: 5px 0; text-align: right; color: #28a745;'>-$" . number_format($order['descuentos'], 2) . "</td>
                        </tr>";
        }
        
        $template = "
                        <tr style='border-top: 2px solid #333; font-weight: bold; font-size: 1.1em;'>
                            <td style='padding: 10px 15px 10px 0;'>TOTAL:</td>
                            <td style='padding: 10px 0; text-align: right; color: #28a745;'>$" . number_format($order['total'], 2) . "</td>
                        </tr>
                    </table>
                </div>";
        
        if ($order['nombre_destinatario']) {
            $template .= "
                <div class='order-info'>
                    <h3 style='color: #007bff; margin-top: 0;'>📍 Dirección de Envío</h3>
                    <p><strong>" . htmlspecialchars($order['nombre_destinatario']) . "</strong><br>
                    " . htmlspecialchars($order['calle_numero']) . "<br>";
            
            if ($order['colonia']) {
                $template .= htmlspecialchars($order['colonia']) . ", ";
            }
            
            $template .= htmlspecialchars($order['ciudad']) . ", " . htmlspecialchars($order['estado_direccion']);
            
            if ($order['codigo_postal']) {
                $template .= " - CP " . htmlspecialchars($order['codigo_postal']);
            }
            
            $template .= "</p></div>";
        }
        
        $template .= "
                <div style='background: #e3f2fd; padding: 20px; border-radius: 10px; margin: 20px 0;'>
                    <h3 style='color: #1976d2; margin-top: 0;'>📦 ¿Qué sigue?</h3>
                    <ol style='color: #333; line-height: 1.6;'>
                        <li><strong>Preparación:</strong> Empacamos cuidadosamente tus productos</li>
                        <li><strong>Envío:</strong> Te enviaremos el número de seguimiento</li>
                        <li><strong>Entrega:</strong> Recibirás tu pedido en la dirección indicada</li>
                    </ol>
                    <p style='margin-bottom: 0;'><strong>💌 Te mantendremos informado sobre cada paso del proceso.</strong></p>
                </div>
                
                <div class='footer'>
                    <p><strong>Equipo de Novedades Ashley</strong></p>
                    <p>\"Descubre lo nuevo, siente la diferencia\"</p>
                    <hr style='border: none; border-top: 1px solid #eee; margin: 15px 0;'>
                    <p><small>Si tienes alguna pregunta, contáctanos respondiendo a este email.<br>
                    📞 558-422-6977 | 📧 jc.reyesm8@gmail.com</small></p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        return $template;
    }
    
    /**
     * 🔄 Template de actualización de estado
     */
    private function getStatusUpdateTemplate($order, $old_status, $new_status, $tracking_number = null) {
        $status_info = $this->getStatusInfo($new_status);
        
        $template = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f4f4f4; }
                .container { background: white; padding: 30px; border-radius: 15px; max-width: 600px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
                .header { text-align: center; margin-bottom: 30px; background: linear-gradient(45deg, #667eea, #764ba2); color: white; padding: 20px; border-radius: 10px; }
                .status-update { background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0; text-align: center; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; border-top: 1px solid #eee; padding-top: 20px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div style='font-size: 24px; font-weight: bold; margin-bottom: 10px;'>👑 Novedades Ashley</div>
                    <h1 style='margin: 0;'>{$status_info['emoji']} {$status_info['title']}</h1>
                    <p style='margin: 10px 0 0 0;'>Actualización de tu pedido #{$order['numero_pedido']}</p>
                </div>
                
                <div class='status-update'>
                    <h2 style='color: #667eea; margin-top: 0;'>{$status_info['description']}</h2>
                    <p style='font-size: 1.1em; color: #333;'>Tu pedido ha cambiado de estado:</p>
                    <div style='background: white; padding: 15px; border-radius: 8px; margin: 15px 0;'>
                        <div style='display: flex; align-items: center; justify-content: center; gap: 20px;'>
                            <span style='color: #999;'>" . ucfirst($old_status) . "</span>
                            <span style='font-size: 1.5em;'>→</span>
                            <span style='color: #28a745; font-weight: bold;'>" . ucfirst($new_status) . "</span>
                        </div>
                    </div>
                </div>";
        
        if ($tracking_number && in_array($new_status, ['enviado', 'en_transito'])) {
            $template .= "
                <div style='background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 10px; padding: 20px; margin: 20px 0;'>
                    <h3 style='color: #856404; margin-top: 0;'>📦 Información de Seguimiento</h3>
                    <p><strong>Número de seguimiento:</strong></p>
                    <div style='background: white; padding: 10px; border-radius: 5px; font-family: monospace; font-size: 1.2em; text-align: center; border: 2px dashed #ffc107;'>
                        {$tracking_number}
                    </div>
                    <p style='margin-bottom: 0;'><small>Puedes usar este número para rastrear tu envío en el sitio web de la paquetería.</small></p>
                </div>";
        }
        
        if ($new_status === 'entregado') {
            $template .= "
                <div style='background: #d4edda; border: 1px solid #c3e6cb; border-radius: 10px; padding: 20px; margin: 20px 0;'>
                    <h3 style='color: #155724; margin-top: 0;'>🎉 ¡Gracias por tu Compra!</h3>
                    <p>Esperamos que disfrutes tus productos. Si tienes algún problema o pregunta, no dudes en contactarnos.</p>
                    <p style='margin-bottom: 0;'><strong>¿Te gustaría dejar una reseña de tu experiencia?</strong></p>
                </div>";
        }
        
        $template .= "
                <div style='background: #e3f2fd; padding: 20px; border-radius: 10px; margin: 20px 0;'>
                    <h3 style='color: #1976d2; margin-top: 0;'>📋 Detalles del Pedido</h3>
                    <div style='display: flex; justify-content: space-between; margin-bottom: 10px;'>
                        <span><strong>Número:</strong></span>
                        <span>{$order['numero_pedido']}</span>
                    </div>
                    <div style='display: flex; justify-content: space-between; margin-bottom: 10px;'>
                        <span><strong>Total:</strong></span>
                        <span style='color: #28a745; font-weight: bold;'>$" . number_format($order['total'], 2) . "</span>
                    </div>
                    <div style='display: flex; justify-content: space-between;'>
                        <span><strong>Fecha de pedido:</strong></span>
                        <span>" . date('d/m/Y', strtotime($order['created_at'])) . "</span>
                    </div>
                </div>
                
                <div class='footer'>
                    <p><strong>Equipo de Novedades Ashley</strong></p>
                    <p>\"Descubre lo nuevo, siente la diferencia\"</p>
                    <hr style='border: none; border-top: 1px solid #eee; margin: 15px 0;'>
                    <p><small>Si tienes alguna pregunta, contáctanos respondiendo a este email.<br>
                    📞 558-422-6977 | 📧 jc.reyesm8@gmail.com</small></p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        return $template;
    }
    
    /**
     * 🚚 Template de notificación de envío
     */
    private function getShippingTemplate($order, $tracking_number, $carrier = null) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f4f4f4; }
                .container { background: white; padding: 30px; border-radius: 15px; max-width: 600px; margin: 0 auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
                .header { text-align: center; margin-bottom: 30px; background: linear-gradient(45deg, #ff9800, #ff5722); color: white; padding: 20px; border-radius: 10px; }
                .tracking-box { background: #fff3e0; border: 2px solid #ff9800; border-radius: 10px; padding: 20px; margin: 20px 0; text-align: center; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; border-top: 1px solid #eee; padding-top: 20px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div style='font-size: 24px; font-weight: bold; margin-bottom: 10px;'>👑 Novedades Ashley</div>
                    <h1 style='margin: 0;'>🚚 ¡Tu pedido está en camino!</h1>
                    <p style='margin: 10px 0 0 0;'>Pedido #{$order['numero_pedido']}</p>
                </div>
                
                <div style='text-align: center; margin: 30px 0;'>
                    <h2 style='color: #ff9800;'>📦 Tu paquete ha sido enviado</h2>
                    <p style='font-size: 1.1em; color: #333;'>Hola " . htmlspecialchars($order['cliente_nombre']) . ", tu pedido está viajando hacia ti.</p>
                </div>
                
                <div class='tracking-box'>
                    <h3 style='color: #e65100; margin-top: 0;'>🔍 Información de Seguimiento</h3>";
        
        if ($carrier) {
            $template .= "<p><strong>Paquetería:</strong> {$carrier}</p>";
        }
        
        $template .= "
                    <p><strong>Número de seguimiento:</strong></p>
                    <div style='background: white; padding: 15px; border-radius: 8px; font-family: monospace; font-size: 1.5em; font-weight: bold; color: #e65100; border: 3px dashed #ff9800; margin: 15px 0;'>
                        {$tracking_number}
                    </div>
                    <p style='color: #666; margin-bottom: 0;'><small>Guarda este número para rastrear tu envío en tiempo real</small></p>
                </div>
                
                <div style='background: #e8f5e8; border-radius: 10px; padding: 20px; margin: 20px 0;'>
                    <h3 style='color: #2e7d32; margin-top: 0;'>📍 Dirección de Entrega</h3>
                    <p style='margin-bottom: 0;'>
                        <strong>" . htmlspecialchars($order['nombre_destinatario']) . "</strong><br>
                        " . htmlspecialchars($order['calle_numero']) . "<br>";
        
        if ($order['colonia']) {
            $template .= htmlspecialchars($order['colonia']) . ", ";
        }
        
        $template .= htmlspecialchars($order['ciudad']) . ", " . htmlspecialchars($order['estado_direccion']);
        
        if ($order['codigo_postal']) {
            $template .= " - CP " . htmlspecialchars($order['codigo_postal']);
        }
        
        $template .= "
                    </p>
                </div>
                
                <div style='background: #f3e5f5; border-radius: 10px; padding: 20px; margin: 20px 0;'>
                    <h3 style='color: #7b1fa2; margin-top: 0;'>⏰ Tiempo Estimado de Entrega</h3>
                    <p style='font-size: 1.1em; color: #333; margin-bottom: 0;'>
                        <strong>2-5 días hábiles</strong> a partir de hoy
                    </p>
                    <p style='color: #666; margin-bottom: 0;'><small>Los tiempos pueden variar según la ubicación y condiciones del servicio de paquetería</small></p>
                </div>
                
                <div class='footer'>
                    <p><strong>Equipo de Novedades Ashley</strong></p>
                    <p>\"Descubre lo nuevo, siente la diferencia\"</p>
                    <hr style='border: none; border-top: 1px solid #eee; margin: 15px 0;'>
                    <p><small>Si tienes alguna pregunta sobre tu envío, contáctanos.<br>
                    📞 558-422-6977 | 📧 jc.reyesm8@gmail.com</small></p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        return $template;
    }
    
    /**
     * 📨 Enviar notificaciones automáticas basadas en el estado
     */
    public function sendAutomaticNotification($order_id, $trigger_type, $extra_data = []) {
        try {
            switch ($trigger_type) {
                case 'order_created':
                    return $this->sendOrderConfirmation($order_id);
                    
                case 'status_changed':
                    return $this->sendOrderStatusUpdate(
                        $order_id, 
                        $extra_data['old_status'] ?? '', 
                        $extra_data['new_status'] ?? '',
                        $extra_data['tracking_number'] ?? null
                    );
                    
                case 'shipped':
                    return $this->sendShippingNotification(
                        $order_id,
                        $extra_data['tracking_number'] ?? '',
                        $extra_data['carrier'] ?? null
                    );
                    
                default:
                    throw new Exception("Tipo de notificación no reconocido: {$trigger_type}");
            }
        } catch (Exception $e) {
            error_log("Error en notificación automática {$trigger_type} para pedido {$order_id}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 📊 Obtener historial de notificaciones de un pedido
     */
    public function getOrderNotificationHistory($order_id) {
        try {
            $stmt = $this->conn->prepare("
                SELECT * FROM order_notifications_log 
                WHERE order_id = ? 
                ORDER BY created_at DESC
            ");
            $stmt->execute([$order_id]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error obteniendo historial de notificaciones: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 🔄 Reenviar notificación fallida
     */
    public function resendNotification($order_id, $notification_type) {
        try {
            $order_data = $this->getOrderData($order_id);
            if (!$order_data) {
                throw new Exception('Pedido no encontrado');
            }
            
            switch ($notification_type) {
                case 'order_confirmation':
                    return $this->sendOrderConfirmation($order_id);
                    
                case 'status_update':
                    return $this->sendOrderStatusUpdate($order_id, '', $order_data['estado']);
                    
                case 'shipping_notification':
                    if ($order_data['numero_seguimiento']) {
                        return $this->sendShippingNotification($order_id, $order_data['numero_seguimiento'], $order_data['paqueteria']);
                    }
                    throw new Exception('No hay información de seguimiento disponible');
                    
                default:
                    throw new Exception("Tipo de notificación no válido: {$notification_type}");
            }
        } catch (Exception $e) {
            error_log("Error reenviando notificación {$notification_type} para pedido {$order_id}: " . $e->getMessage());
            return false;
        }
    }
}

// 📧 Función auxiliar para enviar notificaciones desde cualquier parte del sistema
function sendOrderNotification($order_id, $trigger_type, $extra_data = []) {
    try {
        require_once __DIR__ . '/database.php';
        $database = new Database();
        $conn = $database->getConnection();
        
        $notification_service = new OrderNotificationService($conn);
        return $notification_service->sendAutomaticNotification($order_id, $trigger_type, $extra_data);
        
    } catch (Exception $e) {
        error_log("Error en función auxiliar sendOrderNotification: " . $e->getMessage());
        return false;
    }
}
?>