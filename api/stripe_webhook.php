<?php
// api/stripe_webhook.php - Webhook para recibir confirmaciones automáticas de Stripe + NOTIFICACIONES EMAIL
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/stripe_config.php';
require_once __DIR__ . '/../config/verification.php'; // ✨ NUEVO: Para enviar emails

// Solo permitir POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Configurar headers para webhook
http_response_code(200);
header('Content-Type: application/json');

$database = new Database();
$conn = $database->getConnection();

// ✨ NUEVO: Inicializar servicio de verificación para emails
$verification_service = new VerificationService($conn);

try {
    // Inicializar Stripe
    initializeStripe();
    $stripe_config = getStripeConfig();
    
    // Obtener el payload del webhook
    $payload = @file_get_contents('php://input');
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    
    if (empty($payload) || empty($sig_header)) {
        throw new Exception('Payload o signature faltante');
    }
    
    // Verificar la signature del webhook
    try {
        $event = \Stripe\Webhook::constructEvent(
            $payload,
            $sig_header,
            $stripe_config['webhook_secret']
        );
    } catch (\UnexpectedValueException $e) {
        logStripeActivity('error', 'Payload inválido del webhook', ['error' => $e->getMessage()]);
        http_response_code(400);
        exit('Invalid payload');
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
        logStripeActivity('error', 'Signature inválida del webhook', ['error' => $e->getMessage()]);
        http_response_code(400);
        exit('Invalid signature');
    }
    
    // Log del evento recibido
    logStripeActivity('info', 'Webhook recibido', [
        'event_type' => $event['type'],
        'event_id' => $event['id']
    ]);
    
    // Procesar el evento
    switch ($event['type']) {
        case 'payment_intent.succeeded':
            handlePaymentIntentSucceeded($event['data']['object'], $conn, $verification_service);
            break;
            
        case 'payment_intent.payment_failed':
            handlePaymentIntentFailed($event['data']['object'], $conn, $verification_service);
            break;
            
        case 'payment_intent.canceled':
            handlePaymentIntentCanceled($event['data']['object'], $conn, $verification_service);
            break;
            
        case 'charge.dispute.created':
            handleChargeDispute($event['data']['object'], $conn);
            break;
            
        case 'invoice.payment_succeeded':
            handleInvoicePaymentSucceeded($event['data']['object'], $conn);
            break;
            
        case 'customer.subscription.created':
        case 'customer.subscription.updated':
        case 'customer.subscription.deleted':
            handleSubscriptionEvent($event['data']['object'], $event['type'], $conn);
            break;
            
        default:
            logStripeActivity('info', 'Evento no manejado', ['event_type' => $event['type']]);
            break;
    }
    
    echo json_encode(['status' => 'success']);
    
} catch (Exception $e) {
    logStripeActivity('error', 'Error procesando webhook', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

exit;

// ===================================
// FUNCIONES PARA MANEJAR EVENTOS
// ===================================

function handlePaymentIntentSucceeded($payment_intent, $conn, $verification_service) {
    try {
        $payment_intent_id = $payment_intent['id'];
        $amount = $payment_intent['amount'] / 100; // Convertir de centavos
        $customer_id = $payment_intent['customer'];
        $metadata = $payment_intent['metadata'];
        
        logStripeActivity('success', 'Pago confirmado por Stripe', [
            'payment_intent_id' => $payment_intent_id,
            'amount' => $amount,
            'customer_id' => $customer_id
        ]);
        
        // Si ya tenemos el order_id en metadata, actualizar el pedido
        if (isset($metadata['order_id'])) {
            $order_id = $metadata['order_id'];
            
            // Actualizar estado del pedido a "confirmado" si no lo está ya
            $stmt = $conn->prepare("
                UPDATE pedidos 
                SET estado = 'confirmado', 
                    notas_internas = CONCAT(COALESCE(notas_internas, ''), '\nPago confirmado por Stripe webhook: ', NOW())
                WHERE id = ? AND estado = 'pendiente'
            ");
            $stmt->execute([$order_id]);
            
            if ($stmt->rowCount() > 0) {
                // Registrar en historial
                $stmt = $conn->prepare("
                    INSERT INTO pedido_estados_historial (
                        id_pedido, estado_anterior, estado_nuevo, 
                        comentarios, usuario_cambio
                    ) VALUES (?, 'pendiente', 'confirmado', 'Pago confirmado automáticamente por Stripe webhook', 'Sistema-Webhook')
                ");
                $stmt->execute([$order_id]);
                
                logStripeActivity('success', 'Pedido actualizado por webhook', [
                    'order_id' => $order_id,
                    'payment_intent_id' => $payment_intent_id
                ]);
                
                // ✨ NUEVO: Enviar email de confirmación de pago
                try {
                    $email_sent = $verification_service->sendPaymentConfirmationEmail($order_id);
                    
                    if ($email_sent) {
                        logStripeActivity('success', 'Email de confirmación enviado', [
                            'order_id' => $order_id,
                            'payment_intent_id' => $payment_intent_id
                        ]);
                    } else {
                        logStripeActivity('warning', 'No se pudo enviar email de confirmación', [
                            'order_id' => $order_id,
                            'payment_intent_id' => $payment_intent_id
                        ]);
                    }
                } catch (Exception $email_error) {
                    logStripeActivity('error', 'Error enviando email de confirmación', [
                        'order_id' => $order_id,
                        'error' => $email_error->getMessage()
                    ]);
                    // No lanzar excepción para no fallar el webhook
                }
                
                // ✨ NUEVO: Enviar factura por email (con delay para que se genere)
                try {
                    // Esperar 2 segundos para que se procese todo
                    sleep(2);
                    
                    $invoice_sent = $verification_service->sendInvoiceEmail($order_id);
                    
                    if ($invoice_sent) {
                        logStripeActivity('success', 'Factura enviada por email', [
                            'order_id' => $order_id,
                            'payment_intent_id' => $payment_intent_id
                        ]);
                    } else {
                        logStripeActivity('warning', 'No se pudo enviar factura por email', [
                            'order_id' => $order_id,
                            'payment_intent_id' => $payment_intent_id
                        ]);
                    }
                } catch (Exception $invoice_error) {
                    logStripeActivity('error', 'Error enviando factura por email', [
                        'order_id' => $order_id,
                        'error' => $invoice_error->getMessage()
                    ]);
                    // No lanzar excepción para no fallar el webhook
                }
            }
        }
        
    } catch (Exception $e) {
        logStripeActivity('error', 'Error procesando payment_intent.succeeded', [
            'payment_intent_id' => $payment_intent['id'],
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

function handlePaymentIntentFailed($payment_intent, $conn, $verification_service) {
    try {
        $payment_intent_id = $payment_intent['id'];
        $last_payment_error = $payment_intent['last_payment_error'];
        $metadata = $payment_intent['metadata'];
        
        logStripeActivity('error', 'Pago falló en Stripe', [
            'payment_intent_id' => $payment_intent_id,
            'error_code' => $last_payment_error['code'] ?? 'unknown',
            'error_message' => $last_payment_error['message'] ?? 'Unknown error'
        ]);
        
        // Si tenemos order_id, actualizar el pedido
        if (isset($metadata['order_id'])) {
            $order_id = $metadata['order_id'];
            
            $error_message = $last_payment_error['message'] ?? 'Pago falló';
            
            // Actualizar pedido como cancelado por fallo de pago
            $stmt = $conn->prepare("
                UPDATE pedidos 
                SET estado = 'cancelado',
                    notas_internas = CONCAT(COALESCE(notas_internas, ''), '\nPago falló - Stripe: ', ?, ' - ', NOW())
                WHERE id = ? AND estado IN ('pendiente', 'procesando')
            ");
            $stmt->execute([$error_message, $order_id]);
            
            if ($stmt->rowCount() > 0) {
                // Registrar en historial
                $stmt = $conn->prepare("
                    INSERT INTO pedido_estados_historial (
                        id_pedido, estado_anterior, estado_nuevo, 
                        comentarios, usuario_cambio
                    ) VALUES (?, 'pendiente', 'cancelado', ?, 'Sistema-Webhook')
                ");
                $stmt->execute([$order_id, "Pago falló: {$error_message}"]);
                
                // El trigger automáticamente restaurará el stock
                
                logStripeActivity('info', 'Pedido cancelado por fallo de pago', [
                    'order_id' => $order_id,
                    'payment_intent_id' => $payment_intent_id
                ]);
                
                // ✨ NUEVO: Notificar al cliente sobre el fallo de pago
                try {
                    $notification_sent = $verification_service->sendOrderStatusUpdate(
                        $order_id, 
                        'pendiente', 
                        'cancelado', 
                        "Tu pago no pudo ser procesado: {$error_message}. Por favor, intenta nuevamente o contacta a soporte."
                    );
                    
                    if ($notification_sent) {
                        logStripeActivity('success', 'Notificación de pago fallido enviada', [
                            'order_id' => $order_id
                        ]);
                    }
                } catch (Exception $email_error) {
                    logStripeActivity('error', 'Error enviando notificación de pago fallido', [
                        'order_id' => $order_id,
                        'error' => $email_error->getMessage()
                    ]);
                }
            }
        }
        
    } catch (Exception $e) {
        logStripeActivity('error', 'Error procesando payment_intent.payment_failed', [
            'payment_intent_id' => $payment_intent['id'],
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

function handlePaymentIntentCanceled($payment_intent, $conn, $verification_service) {
    try {
        $payment_intent_id = $payment_intent['id'];
        $metadata = $payment_intent['metadata'];
        
        logStripeActivity('info', 'Payment Intent cancelado', [
            'payment_intent_id' => $payment_intent_id
        ]);
        
        // Si tenemos order_id, cancelar el pedido
        if (isset($metadata['order_id'])) {
            $order_id = $metadata['order_id'];
            
            // Actualizar pedido como cancelado
            $stmt = $conn->prepare("
                UPDATE pedidos 
                SET estado = 'cancelado',
                    notas_internas = CONCAT(COALESCE(notas_internas, ''), '\nPago cancelado por cliente - Stripe - ', NOW())
                WHERE id = ? AND estado IN ('pendiente', 'procesando')
            ");
            $stmt->execute([$order_id]);
            
            if ($stmt->rowCount() > 0) {
                // Registrar en historial
                $stmt = $conn->prepare("
                    INSERT INTO pedido_estados_historial (
                        id_pedido, estado_anterior, estado_nuevo, 
                        comentarios, usuario_cambio
                    ) VALUES (?, 'pendiente', 'cancelado', 'Pago cancelado por cliente en Stripe', 'Sistema-Webhook')
                ");
                $stmt->execute([$order_id]);
                
                logStripeActivity('info', 'Pedido cancelado por cancelación de pago', [
                    'order_id' => $order_id,
                    'payment_intent_id' => $payment_intent_id
                ]);
                
                // ✨ NUEVO: Notificar al cliente sobre la cancelación
                try {
                    $notification_sent = $verification_service->sendOrderStatusUpdate(
                        $order_id, 
                        'pendiente', 
                        'cancelado', 
                        'Tu pago fue cancelado. Si esto fue un error, puedes intentar realizar el pedido nuevamente.'
                    );
                    
                    if ($notification_sent) {
                        logStripeActivity('success', 'Notificación de cancelación enviada', [
                            'order_id' => $order_id
                        ]);
                    }
                } catch (Exception $email_error) {
                    logStripeActivity('error', 'Error enviando notificación de cancelación', [
                        'order_id' => $order_id,
                        'error' => $email_error->getMessage()
                    ]);
                }
            }
        }
        
    } catch (Exception $e) {
        logStripeActivity('error', 'Error procesando payment_intent.canceled', [
            'payment_intent_id' => $payment_intent['id'],
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

function handleChargeDispute($dispute, $conn) {
    try {
        $dispute_id = $dispute['id'];
        $charge_id = $dispute['charge'];
        $amount = $dispute['amount'] / 100;
        $reason = $dispute['reason'];
        
        logStripeActivity('warning', 'Disputa creada en Stripe', [
            'dispute_id' => $dispute_id,
            'charge_id' => $charge_id,
            'amount' => $amount,
            'reason' => $reason
        ]);
        
        // Buscar el pedido relacionado con este charge
        $stmt = $conn->prepare("
            SELECT id, numero_pedido 
            FROM pedidos 
            WHERE referencia_pago LIKE ? 
            OR notas_internas LIKE ?
        ");
        $stmt->execute(["%{$charge_id}%", "%{$charge_id}%"]);
        $pedido = $stmt->fetch();
        
        if ($pedido) {
            // Agregar nota sobre la disputa
            $stmt = $conn->prepare("
                UPDATE pedidos 
                SET notas_internas = CONCAT(COALESCE(notas_internas, ''), '\nDISPUTA STRIPE: ', ?, ' - Monto: , ?, ' - ', NOW())
                WHERE id = ?
            ");
            $stmt->execute([$reason, $amount, $pedido['id']]);
            
            // Registrar en historial
            $stmt = $conn->prepare("
                INSERT INTO pedido_estados_historial (
                    id_pedido, estado_anterior, estado_nuevo, 
                    comentarios, usuario_cambio
                ) VALUES (?, (SELECT estado FROM pedidos WHERE id = ?), (SELECT estado FROM pedidos WHERE id = ?), ?, 'Sistema-Webhook')
            ");
            $stmt->execute([
                $pedido['id'], 
                $pedido['id'], 
                $pedido['id'], 
                "DISPUTA STRIPE: {$reason} - Monto: \${$amount}"
            ]);
        }
        
        // Aquí podrías agregar lógica para:
        // - Notificar al admin inmediatamente
        // - Enviar email de alerta
        // - Crear ticket de soporte
        // - etc.
        
    } catch (Exception $e) {
        logStripeActivity('error', 'Error procesando charge.dispute.created', [
            'dispute_id' => $dispute['id'],
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

function handleInvoicePaymentSucceeded($invoice, $conn) {
    try {
        $invoice_id = $invoice['id'];
        $customer_id = $invoice['customer'];
        $subscription_id = $invoice['subscription'];
        $amount = $invoice['amount_paid'] / 100;
        
        logStripeActivity('success', 'Pago de invoice exitoso', [
            'invoice_id' => $invoice_id,
            'customer_id' => $customer_id,
            'subscription_id' => $subscription_id,
            'amount' => $amount
        ]);
        
        // Aquí podrías manejar pagos de suscripciones si los implementas
        // Por ahora solo registramos el evento
        
    } catch (Exception $e) {
        logStripeActivity('error', 'Error procesando invoice.payment_succeeded', [
            'invoice_id' => $invoice['id'],
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

function handleSubscriptionEvent($subscription, $event_type, $conn) {
    try {
        $subscription_id = $subscription['id'];
        $customer_id = $subscription['customer'];
        $status = $subscription['status'];
        
        logStripeActivity('info', 'Evento de suscripción', [
            'event_type' => $event_type,
            'subscription_id' => $subscription_id,
            'customer_id' => $customer_id,
            'status' => $status
        ]);
        
        // Aquí podrías manejar suscripciones si las implementas en el futuro
        // - Crear suscripciones en BD
        // - Actualizar estados
        // - Gestionar beneficios de membresía
        // - etc.
        
    } catch (Exception $e) {
        logStripeActivity('error', 'Error procesando evento de suscripción', [
            'event_type' => $event_type,
            'subscription_id' => $subscription['id'],
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

// ===================================
// ✨ NUEVAS FUNCIONES PARA NOTIFICACIONES MANUALES
// ===================================

/**
 * ✨ NUEVO: Función para notificar cambios de estado desde el admin
 * Llamar esta función cuando el admin cambie manualmente el estado de un pedido
 */
function notifyOrderStatusChange($order_id, $old_status, $new_status, $comments = '', $conn = null, $verification_service = null) {
    try {
        if (!$conn) {
            $database = new Database();
            $conn = $database->getConnection();
        }
        
        if (!$verification_service) {
            $verification_service = new VerificationService($conn);
        }
        
        // Enviar notificación por email
        $notification_sent = $verification_service->sendOrderStatusUpdate(
            $order_id, 
            $old_status, 
            $new_status, 
            $comments
        );
        
        if ($notification_sent) {
            logStripeActivity('success', 'Notificación manual de estado enviada', [
                'order_id' => $order_id,
                'old_status' => $old_status,
                'new_status' => $new_status,
                'source' => 'admin_manual'
            ]);
            return true;
        } else {
            logStripeActivity('warning', 'No se pudo enviar notificación manual', [
                'order_id' => $order_id,
                'old_status' => $old_status,
                'new_status' => $new_status
            ]);
            return false;
        }
        
    } catch (Exception $e) {
        logStripeActivity('error', 'Error enviando notificación manual', [
            'order_id' => $order_id,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * ✨ NUEVO: Función para reenviar email de confirmación de pedido
 */
function resendPaymentConfirmation($order_id, $conn = null, $verification_service = null) {
    try {
        if (!$conn) {
            $database = new Database();
            $conn = $database->getConnection();
        }
        
        if (!$verification_service) {
            $verification_service = new VerificationService($conn);
        }
        
        $email_sent = $verification_service->sendPaymentConfirmationEmail($order_id);
        
        if ($email_sent) {
            logStripeActivity('success', 'Email de confirmación reenviado', [
                'order_id' => $order_id,
                'source' => 'manual_resend'
            ]);
            return true;
        } else {
            logStripeActivity('warning', 'No se pudo reenviar email de confirmación', [
                'order_id' => $order_id
            ]);
            return false;
        }
        
    } catch (Exception $e) {
        logStripeActivity('error', 'Error reenviando email de confirmación', [
            'order_id' => $order_id,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * ✨ NUEVO: Función para reenviar factura por email
 */
function resendInvoiceEmail($order_id, $conn = null, $verification_service = null) {
    try {
        if (!$conn) {
            $database = new Database();
            $conn = $database->getConnection();
        }
        
        if (!$verification_service) {
            $verification_service = new VerificationService($conn);
        }
        
        $invoice_sent = $verification_service->sendInvoiceEmail($order_id);
        
        if ($invoice_sent) {
            logStripeActivity('success', 'Factura reenviada por email', [
                'order_id' => $order_id,
                'source' => 'manual_resend'
            ]);
            return true;
        } else {
            logStripeActivity('warning', 'No se pudo reenviar factura', [
                'order_id' => $order_id
            ]);
            return false;
        }
        
    } catch (Exception $e) {
        logStripeActivity('error', 'Error reenviando factura', [
            'order_id' => $order_id,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

// ===================================
// FUNCIÓN PARA CREAR DIRECTORIO DE LOGS
// ===================================
function ensureLogsDirectory() {
    $log_dir = __DIR__ . '/../logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    return $log_dir;
}

/**
 * ✨ NUEVO: Función para testear el sistema de emails
 * Usar esta función para probar que los emails funcionan correctamente
 */
function testEmailSystem($order_id = null) {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        $verification_service = new VerificationService($conn);
        
        if (!$order_id) {
            // Buscar el último pedido confirmado
            $stmt = $conn->prepare("
                SELECT id FROM pedidos 
                WHERE estado = 'confirmado' 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute();
            $result = $stmt->fetch();
            $order_id = $result ? $result['id'] : null;
        }
        
        if (!$order_id) {
            return ['success' => false, 'message' => 'No hay pedidos confirmados para testear'];
        }
        
        // Test 1: Email de confirmación
        $confirmation_test = $verification_service->sendPaymentConfirmationEmail($order_id);
        
        // Test 2: Email de factura
        $invoice_test = $verification_service->sendInvoiceEmail($order_id);
        
        // Test 3: Email de cambio de estado
        $status_test = $verification_service->sendOrderStatusUpdate($order_id, 'confirmado', 'procesando', 'Test del sistema de emails');
        
        return [
            'success' => true,
            'results' => [
                'confirmation_email' => $confirmation_test,
                'invoice_email' => $invoice_test,
                'status_update_email' => $status_test
            ],
            'order_id' => $order_id,
            'message' => 'Tests de email completados'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error en test: ' . $e->getMessage()
        ];
    }
}

// ===================================
// ✨ ENDPOINT PARA TESTING (solo en desarrollo)
// ===================================

// Uncomment this block ONLY for testing purposes
/*
if (isset($_GET['test_emails']) && $_GET['test_emails'] === 'true') {
    header('Content-Type: application/json');
    $test_results = testEmailSystem($_GET['order_id'] ?? null);
    echo json_encode($test_results, JSON_PRETTY_PRINT);
    exit;
}

if (isset($_GET['test_notification']) && !empty($_GET['order_id'])) {
    header('Content-Type: application/json');
    $order_id = (int)$_GET['order_id'];
    $old_status = $_GET['old_status'] ?? 'confirmado';
    $new_status = $_GET['new_status'] ?? 'procesando';
    $comments = $_GET['comments'] ?? 'Test de notificación';
    
    $result = notifyOrderStatusChange($order_id, $old_status, $new_status, $comments);
    
    echo json_encode([
        'success' => $result,
        'message' => $result ? 'Notificación enviada' : 'Error enviando notificación',
        'order_id' => $order_id
    ], JSON_PRETTY_PRINT);
    exit;
}
*/
?>