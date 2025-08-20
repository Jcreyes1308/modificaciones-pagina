<?php
// api/notification_processor.php - Procesador automático de notificaciones
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/notifications.php';

class NotificationProcessor {
    private $conn;
    private $notification_service;
    private $config;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->notification_service = new OrderNotificationService($this->conn);
        $this->loadConfig();
    }
    
    /**
     * 🔄 Procesar notificaciones pendientes
     */
    public function processNotifications() {
        try {
            if (!$this->config['notifications_enabled']) {
                $this->log("Notificaciones desactivadas en configuración");
                return;
            }
            
            // Procesar pedidos recién creados
            $this->processNewOrders();
            
            // Procesar cambios de estado
            $this->processStatusChanges();
            
            // Reintentar notificaciones fallidas
            $this->retryFailedNotifications();
            
            $this->log("Procesamiento de notificaciones completado");
            
        } catch (Exception $e) {
            $this->log("Error en procesamiento: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * 📦 Procesar pedidos nuevos que necesitan confirmación
     */
    private function processNewOrders() {
        try {
            // Buscar pedidos creados en las últimas 2 horas que no tienen notificación de confirmación
            $stmt = $this->conn->prepare("
                SELECT DISTINCT p.id, p.numero_pedido, p.estado, p.created_at
                FROM pedidos p
                LEFT JOIN order_notifications_log n ON p.id = n.order_id 
                    AND n.notification_type = 'order_confirmation'
                    AND n.success = 1
                WHERE p.activo = 1 
                    AND p.created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
                    AND p.estado IN ('confirmado', 'pendiente')
                    AND n.id IS NULL
                ORDER BY p.created_at ASC
                LIMIT 50
            ");
            $stmt->execute();
            $orders = $stmt->fetchAll();
            
            foreach ($orders as $order) {
                $this->log("Procesando confirmación para pedido #{$order['numero_pedido']}");
                
                $result = $this->notification_service->sendOrderConfirmation($order['id']);
                
                if ($result) {
                    $this->log("✅ Confirmación enviada para pedido #{$order['numero_pedido']}");
                } else {
                    $this->log("❌ Error enviando confirmación para pedido #{$order['numero_pedido']}", 'ERROR');
                }
                
                // Pequeña pausa para no sobrecargar el servidor SMTP
                usleep(500000); // 0.5 segundos
            }
            
            $this->log("Procesados " . count($orders) . " pedidos nuevos");
            
        } catch (Exception $e) {
            $this->log("Error procesando pedidos nuevos: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * 🔄 Procesar cambios de estado recientes
     */
    private function processStatusChanges() {
        try {
            // Buscar triggers de cambio de estado que no han sido procesados
            $stmt = $this->conn->prepare("
                SELECT DISTINCT
                    n.order_id,
                    JSON_EXTRACT(n.extra_data, '$.old_status') as old_status,
                    JSON_EXTRACT(n.extra_data, '$.new_status') as new_status,
                    JSON_EXTRACT(n.extra_data, '$.tracking_number') as tracking_number,
                    JSON_EXTRACT(n.extra_data, '$.carrier') as carrier,
                    p.numero_pedido
                FROM order_notifications_log n
                INNER JOIN pedidos p ON n.order_id = p.id
                LEFT JOIN order_notifications_log processed ON n.order_id = processed.order_id 
                    AND processed.notification_type = 'status_update'
                    AND processed.extra_data->>'$.new_status' = n.extra_data->>'$.new_status'
                    AND processed.success = 1
                WHERE n.notification_type = 'trigger_status_changed'
                    AND n.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                    AND processed.id IS NULL
                    AND p.activo = 1
                ORDER BY n.created_at ASC
                LIMIT 30
            ");
            $stmt->execute();
            $status_changes = $stmt->fetchAll();
            
            foreach ($status_changes as $change) {
                $old_status = trim($change['old_status'], '"');
                $new_status = trim($change['new_status'], '"');
                $tracking_number = $change['tracking_number'] ? trim($change['tracking_number'], '"') : null;
                $carrier = $change['carrier'] ? trim($change['carrier'], '"') : null;
                
                $this->log("Procesando cambio de estado para pedido #{$change['numero_pedido']}: {$old_status} → {$new_status}");
                
                $result = $this->notification_service->sendOrderStatusUpdate(
                    $change['order_id'], 
                    $old_status, 
                    $new_status, 
                    $tracking_number
                );
                
                if ($result) {
                    $this->log("✅ Notificación de estado enviada para pedido #{$change['numero_pedido']}");
                    
                    // Si tiene tracking number y el estado es 'enviado', enviar notificación específica de envío
                    if ($tracking_number && in_array($new_status, ['enviado', 'en_transito'])) {
                        $shipping_result = $this->notification_service->sendShippingNotification(
                            $change['order_id'], 
                            $tracking_number, 
                            $carrier
                        );
                        
                        if ($shipping_result) {
                            $this->log("📦 Notificación de envío enviada para pedido #{$change['numero_pedido']}");
                        }
                    }
                } else {
                    $this->log("❌ Error enviando notificación de estado para pedido #{$change['numero_pedido']}", 'ERROR');
                }
                
                usleep(500000); // 0.5 segundos
            }
            
            $this->log("Procesados " . count($status_changes) . " cambios de estado");
            
        } catch (Exception $e) {
            $this->log("Error procesando cambios de estado: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * 🔁 Reintentar notificaciones fallidas
     */
    private function retryFailedNotifications() {
        try {
            $max_attempts = intval($this->config['notification_retry_attempts']);
            $retry_delay = intval($this->config['notification_retry_delay']);
            
            // Buscar notificaciones fallidas que pueden reintentarse
            $stmt = $this->conn->prepare("
                SELECT 
                    n.order_id,
                    n.notification_type,
                    n.recipient,
                    COUNT(*) as attempt_count,
                    MAX(n.created_at) as last_attempt,
                    p.numero_pedido
                FROM order_notifications_log n
                INNER JOIN pedidos p ON n.order_id = p.id
                WHERE n.success = 0 
                    AND n.method = 'email'
                    AND n.notification_type IN ('order_confirmation', 'status_update', 'shipping_notification')
                    AND n.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY n.order_id, n.notification_type
                HAVING attempt_count < ? 
                    AND TIMESTAMPDIFF(MINUTE, MAX(n.created_at), NOW()) >= ?
                ORDER BY last_attempt ASC
                LIMIT 10
            ");
            $stmt->execute([$max_attempts, $retry_delay]);
            $failed_notifications = $stmt->fetchAll();
            
            foreach ($failed_notifications as $notification) {
                $this->log("Reintentando {$notification['notification_type']} para pedido #{$notification['numero_pedido']} (intento " . ($notification['attempt_count'] + 1) . "/{$max_attempts})");
                
                $result = $this->notification_service->resendNotification(
                    $notification['order_id'], 
                    $notification['notification_type']
                );
                
                if ($result) {
                    $this->log("✅ Reintento exitoso para pedido #{$notification['numero_pedido']}");
                } else {
                    $this->log("❌ Reintento fallido para pedido #{$notification['numero_pedido']}", 'ERROR');
                }
                
                usleep(1000000); // 1 segundo entre reintentos
            }
            
            $this->log("Procesados " . count($failed_notifications) . " reintentos");
            
        } catch (Exception $e) {
            $this->log("Error en reintentos: " . $e->getMessage(), 'ERROR');
        }
    }
    
    /**
     * ⚙️ Cargar configuración del sistema
     */
    private function loadConfig() {
        try {
            $stmt = $this->conn->query("
                SELECT clave, valor 
                FROM configuracion_sistema 
                WHERE categoria = 'notificaciones'
            ");
            $config = $stmt->fetchAll();
            
            $this->config = [
                'notifications_enabled' => 1,
                'email_notifications' => 1,
                'sms_notifications' => 0,
                'notification_retry_attempts' => 3,
                'notification_retry_delay' => 30
            ];
            
            foreach ($config as $item) {
                $this->config[$item['clave']] = $item['valor'];
            }
            
        } catch (Exception $e) {
            $this->log("Error cargando configuración: " . $e->getMessage(), 'ERROR');
            // Usar valores por defecto
            $this->config = [
                'notifications_enabled' => 1,
                'email_notifications' => 1,
                'sms_notifications' => 0,
                'notification_retry_attempts' => 3,
                'notification_retry_delay' => 30
            ];
        }
    }
    
    /**
     * 📝 Sistema de logging
     */
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] [{$level}] NotificationProcessor: {$message}" . PHP_EOL;
        
        // Escribir a archivo de log
        $log_file = __DIR__ . '/../logs/notifications.log';
        
        // Crear directorio si no existe
        $log_dir = dirname($log_file);
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
        
        file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
        
        // También output si se ejecuta desde línea de comandos
        if (php_sapi_name() === 'cli') {
            echo $log_message;
        }
    }
    
    /**
     * 📊 Obtener estadísticas de notificaciones
     */
    public function getStats($days = 7) {
        try {
            $stmt = $this->conn->prepare("
                SELECT 
                    notification_type,
                    method,
                    COUNT(*) as total,
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful,
                    ROUND(AVG(CASE WHEN success = 1 THEN 1 ELSE 0 END) * 100, 2) as success_rate
                FROM order_notifications_log 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    AND method != 'system'
                GROUP BY notification_type, method
                ORDER BY notification_type, method
            ");
            $stmt->execute([$days]);
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            $this->log("Error obteniendo estadísticas: " . $e->getMessage(), 'ERROR');
            return [];
        }
    }
}

// 🚀 Ejecutar si se llama directamente
if (php_sapi_name() === 'cli' || (isset($_GET['process']) && $_GET['process'] === 'notifications')) {
    $processor = new NotificationProcessor();
    
    if (isset($_GET['stats'])) {
        $stats = $processor->getStats();
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => $stats]);
    } else {
        $processor->processNotifications();
        
        if (!php_sapi_name() === 'cli') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Notificaciones procesadas']);
        }
    }
}
?>