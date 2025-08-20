<?php
// api/stripe_checkout.php - API integrada con Stripe para pagos reales + NOTIFICACIONES
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/stripe_config.php';

// ✅ NUEVA LÍNEA: Cargar sistema de notificaciones
require_once __DIR__ . '/../config/notifications.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Verificar que el usuario esté logueado
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'Usuario no autenticado']);
    exit;
}

$database = new Database();
$conn = $database->getConnection();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    // Inicializar Stripe
    initializeStripe();
    $stripe_config = getStripeConfig();
    
    switch ($action) {
        case 'create_payment_intent':
            // Crear Payment Intent de Stripe
            $amount = floatval($_POST['amount'] ?? 0);
            $currency = $_POST['currency'] ?? 'mxn';
            $order_data = json_decode($_POST['order_data'] ?? '{}', true);
            
            if ($amount <= 0) {
                throw new Exception('Monto inválido');
            }
            
            // Convertir a centavos (Stripe maneja centavos)
            $amount_cents = intval($amount * 100);
            
            // Obtener información del cliente
            $stmt = $conn->prepare("SELECT * FROM clientes WHERE id = ?");
            $stmt->execute([$_SESSION['usuario_id']]);
            $cliente = $stmt->fetch();
            
            if (!$cliente) {
                throw new Exception('Cliente no encontrado');
            }
            
            // Crear o buscar customer en Stripe
            $stripe_customer = createOrGetStripeCustomer($cliente);
            
            // Metadatos para el Payment Intent
            $metadata = [
                'user_id' => $_SESSION['usuario_id'],
                'user_email' => $cliente['email'],
                'environment' => STRIPE_MODE,
                'order_type' => 'ecommerce',
                'source' => 'novedades_ashley'
            ];
            
            // Agregar datos del pedido si están disponibles
            if (!empty($order_data)) {
                $metadata['order_items'] = substr(json_encode($order_data), 0, 500); // Stripe limita metadata
            }
            
            // Crear Payment Intent
            $payment_intent = \Stripe\PaymentIntent::create([
                'amount' => $amount_cents,
                'currency' => $currency,
                'customer' => $stripe_customer->id,
                'metadata' => $metadata,
                'description' => "Compra en Novedades Ashley - Usuario: {$cliente['nombre']}",
                'statement_descriptor_suffix' => 'ASHLEY',
                'setup_future_usage' => 'off_session', // Para guardar método de pago
                'payment_method_types' => ['card'],
                'capture_method' => 'automatic'
            ]);
            
            logStripeActivity('success', 'Payment Intent creado', [
                'payment_intent_id' => $payment_intent->id,
                'amount' => $amount,
                'customer_id' => $stripe_customer->id,
                'user_id' => $_SESSION['usuario_id']
            ]);
            
            $response['success'] = true;
            $response['data'] = [
                'client_secret' => $payment_intent->client_secret,
                'payment_intent_id' => $payment_intent->id,
                'amount' => $amount,
                'currency' => $currency,
                'customer_id' => $stripe_customer->id,
                'environment' => STRIPE_MODE
            ];
            break;
            
        case 'confirm_payment':
            // Confirmar pago y crear pedido
            $payment_intent_id = $_POST['payment_intent_id'] ?? '';
            $address_id = intval($_POST['address_id'] ?? 0);
            $order_notes = trim($_POST['order_notes'] ?? '');
            $coupon_code = trim($_POST['coupon_code'] ?? '');
            $totals = json_decode($_POST['totals'] ?? '{}', true);
            
            if (empty($payment_intent_id)) {
                throw new Exception('Payment Intent ID requerido');
            }
            
            if (!$address_id) {
                throw new Exception('Dirección de envío requerida');
            }
            
            // Verificar Payment Intent en Stripe
            $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
            
            if ($payment_intent->status !== 'succeeded') {
                throw new Exception('El pago no ha sido confirmado por Stripe');
            }
            
            // Verificar que el Payment Intent pertenece al usuario actual
            if ($payment_intent->metadata->user_id != $_SESSION['usuario_id']) {
                throw new Exception('Payment Intent no válido para este usuario');
            }
            
            // Obtener método de pago de Stripe
            $payment_method = \Stripe\PaymentMethod::retrieve($payment_intent->payment_method);
            $card_info = $payment_method->card;
            
            // Verificar dirección
            $stmt = $conn->prepare("
                SELECT * FROM direcciones_envio 
                WHERE id = ? AND id_cliente = ? AND activo = 1
            ");
            $stmt->execute([$address_id, $_SESSION['usuario_id']]);
            $direccion = $stmt->fetch();
            
            if (!$direccion) {
                throw new Exception('Dirección no válida');
            }
            
            // Obtener items del carrito
            $stmt = $conn->prepare("
                SELECT 
                    c.cantidad,
                    p.id as producto_id,
                    p.nombre,
                    p.descripcion,
                    p.precio,
                    p.cantidad_etiquetas,
                    p.clave_producto
                FROM carrito_compras c
                INNER JOIN productos p ON c.id_producto = p.id
                WHERE c.id_cliente = ? AND p.activo = 1
            ");
            $stmt->execute([$_SESSION['usuario_id']]);
            $carrito_items = $stmt->fetchAll();
            
            if (empty($carrito_items)) {
                throw new Exception('El carrito está vacío');
            }
            
            // Verificar stock disponible
            foreach ($carrito_items as $item) {
                if ($item['cantidad'] > $item['cantidad_etiquetas']) {
                    throw new Exception("Stock insuficiente para: " . $item['nombre']);
                }
            }
            
            // Validar cupón si se proporcionó
            $cupon_id = null;
            if (!empty($coupon_code)) {
                $stmt = $conn->prepare("
                    SELECT id FROM cupones 
                    WHERE codigo = ? 
                    AND activo = 1 
                    AND fecha_inicio <= CURDATE() 
                    AND fecha_fin >= CURDATE()
                    AND (limite_usos IS NULL OR usos_actuales < limite_usos)
                ");
                $stmt->execute([$coupon_code]);
                $cupon = $stmt->fetch();
                
                if ($cupon) {
                    $cupon_id = $cupon['id'];
                }
            }
            
            // Crear método de pago temporal en BD (para compatibilidad)
            $metodo_pago_texto = "Stripe {$card_info->brand} •••• {$card_info->last4}";
            
            // Iniciar transacción
            $conn->beginTransaction();
            
            try {
                // Crear el pedido
                $stmt = $conn->prepare("
                    INSERT INTO pedidos (
                        id_cliente, id_direccion_envio, id_metodo_pago,
                        subtotal, impuestos, costo_envio, descuentos, total,
                        metodo_pago_usado, referencia_pago, notas_cliente,
                        estado, created_at
                    ) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmado', NOW())
                ");
                
                $stmt->execute([
                    $_SESSION['usuario_id'],
                    $address_id,
                    $totals['subtotal'] ?? 0,
                    $totals['tax'] ?? 0,
                    $totals['shipping'] ?? 0,
                    $totals['discount'] ?? 0,
                    $totals['total'] ?? 0,
                    $metodo_pago_texto,
                    $payment_intent_id, // Guardar ID de Stripe como referencia
                    $order_notes
                ]);
                
                $pedido_id = $conn->lastInsertId();
                
                // Obtener número de pedido generado
                $stmt = $conn->prepare("SELECT numero_pedido FROM pedidos WHERE id = ?");
                $stmt->execute([$pedido_id]);
                $numero_pedido = $stmt->fetchColumn();
                
                // Agregar detalles del pedido
                $stmt_detalle = $conn->prepare("
                    INSERT INTO pedido_detalles (
                        id_pedido, id_producto, nombre_producto, descripcion_producto,
                        cantidad, precio_unitario, subtotal
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                foreach ($carrito_items as $item) {
                    $subtotal_item = $item['precio'] * $item['cantidad'];
                    
                    $stmt_detalle->execute([
                        $pedido_id,
                        $item['producto_id'],
                        $item['nombre'],
                        $item['descripcion'],
                        $item['cantidad'],
                        $item['precio'],
                        $subtotal_item
                    ]);
                    
                    // Reducir stock del producto
                    $stmt_stock = $conn->prepare("
                        UPDATE productos 
                        SET cantidad_etiquetas = cantidad_etiquetas - ? 
                        WHERE id = ?
                    ");
                    $stmt_stock->execute([$item['cantidad'], $item['producto_id']]);
                }
                
                // Incrementar uso del cupón si se aplicó
                if ($cupon_id) {
                    $stmt = $conn->prepare("
                        UPDATE cupones 
                        SET usos_actuales = usos_actuales + 1 
                        WHERE id = ?
                    ");
                    $stmt->execute([$cupon_id]);
                }
                
                // Limpiar carrito del usuario
                $stmt = $conn->prepare("DELETE FROM carrito_compras WHERE id_cliente = ?");
                $stmt->execute([$_SESSION['usuario_id']]);
                
                // Registrar el estado inicial en el historial
                $stmt = $conn->prepare("
                    INSERT INTO pedido_estados_historial (
                        id_pedido, estado_anterior, estado_nuevo, 
                        comentarios, usuario_cambio
                    ) VALUES (?, NULL, 'confirmado', 'Pedido creado y pagado con Stripe', 'Sistema-Stripe')
                ");
                $stmt->execute([$pedido_id]);
                
                // Actualizar Payment Intent con información del pedido
                \Stripe\PaymentIntent::update($payment_intent_id, [
                    'metadata' => array_merge($payment_intent->metadata->toArray(), [
                        'order_id' => $pedido_id,
                        'order_number' => $numero_pedido,
                        'order_status' => 'confirmed'
                    ])
                ]);
                
                // Confirmar transacción ANTES de intentar enviar notificaciones
                $conn->commit();
                
                // ✅ NUEVO: Enviar notificación automática de confirmación
                // Se ejecuta DESPUÉS del commit para no afectar la transacción principal
                try {
                    $notification_service = new OrderNotificationService($conn);
                    $notification_sent = $notification_service->sendOrderConfirmation($pedido_id);
                    
                    if ($notification_sent) {
                        logStripeActivity('success', 'Email de confirmación enviado', [
                            'order_id' => $pedido_id,
                            'numero_pedido' => $numero_pedido,
                            'user_id' => $_SESSION['usuario_id']
                        ]);
                    } else {
                        logStripeActivity('warning', 'Error enviando email de confirmación', [
                            'order_id' => $pedido_id,
                            'numero_pedido' => $numero_pedido,
                            'user_id' => $_SESSION['usuario_id']
                        ]);
                    }
                } catch (Exception $e) {
                    // Log del error pero NO fallar el proceso principal
                    logStripeActivity('error', 'Error en notificación automática', [
                        'order_id' => $pedido_id,
                        'error' => $e->getMessage(),
                        'user_id' => $_SESSION['usuario_id']
                    ]);
                    error_log("Error en notificación para pedido {$pedido_id}: " . $e->getMessage());
                }
                
                logStripeActivity('success', 'Pedido creado exitosamente', [
                    'payment_intent_id' => $payment_intent_id,
                    'order_id' => $pedido_id,
                    'order_number' => $numero_pedido,
                    'amount' => $payment_intent->amount / 100,
                    'user_id' => $_SESSION['usuario_id']
                ]);
                
                $response['success'] = true;
                $response['message'] = 'Pedido creado exitosamente';
                $response['data'] = [
                    'order_id' => $pedido_id,
                    'numero_pedido' => $numero_pedido,
                    'payment_intent_id' => $payment_intent_id,
                    'total' => $totals['total'] ?? 0,
                    'status' => 'confirmado'
                ];
                
            } catch (Exception $e) {
                $conn->rollback();
                
                // Intentar cancelar el Payment Intent si algo salió mal
                try {
                    \Stripe\PaymentIntent::cancel($payment_intent_id);
                    logStripeActivity('info', 'Payment Intent cancelado por error en pedido', [
                        'payment_intent_id' => $payment_intent_id,
                        'error' => $e->getMessage()
                    ]);
                } catch (Exception $cancel_error) {
                    logStripeActivity('error', 'No se pudo cancelar Payment Intent', [
                        'payment_intent_id' => $payment_intent_id,
                        'cancel_error' => $cancel_error->getMessage()
                    ]);
                }
                
                throw $e;
            }
            break;
            
        case 'get_public_key':
            // Obtener clave pública para el frontend
            $response['success'] = true;
            $response['data'] = [
                'public_key' => $stripe_config['public_key'],
                'environment' => STRIPE_MODE,
                'currency' => $stripe_config['currency']
            ];
            break;
            
        case 'validate_setup':
            // Validar configuración de Stripe
            try {
                validateStripeConfig();
                
                // Intentar hacer una llamada simple a la API
                $test_customer = \Stripe\Customer::all(['limit' => 1]);
                
                $response['success'] = true;
                $response['message'] = 'Stripe configurado correctamente';
                $response['data'] = [
                    'environment' => STRIPE_MODE,
                    'currency' => $stripe_config['currency'],
                    'api_working' => true
                ];
                
                logStripeActivity('success', 'Configuración Stripe validada');
                
            } catch (Exception $e) {
                throw new Exception('Error de configuración Stripe: ' . $e->getMessage());
            }
            break;
            
        default:
            throw new Exception('Acción no válida: ' . $action);
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    
    logStripeActivity('error', 'Error en API Stripe', [
        'action' => $action,
        'error' => $e->getMessage(),
        'user_id' => $_SESSION['usuario_id'] ?? null
    ]);
    
    // Si hay una transacción activa, hacer rollback
    if ($conn && $conn->inTransaction()) {
        $conn->rollback();
    }
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;

// ===================================
// FUNCIONES AUXILIARES (MANTENIDAS IGUAL)
// ===================================

function createOrGetStripeCustomer($cliente_data) {
    try {
        // Buscar customer existente por email
        $existing_customers = \Stripe\Customer::all([
            'email' => $cliente_data['email'],
            'limit' => 1
        ]);
        
        if (count($existing_customers->data) > 0) {
            $customer = $existing_customers->data[0];
            
            // Actualizar información si es necesario
            $updated_customer = \Stripe\Customer::update($customer->id, [
                'name' => $cliente_data['nombre'],
                'phone' => $cliente_data['telefono'] ?? null,
                'metadata' => [
                    'user_id' => $cliente_data['id'],
                    'updated_at' => date('Y-m-d H:i:s')
                ]
            ]);
            
            return $updated_customer;
        }
        
        // Crear nuevo customer
        $new_customer = \Stripe\Customer::create([
            'email' => $cliente_data['email'],
            'name' => $cliente_data['nombre'],
            'phone' => $cliente_data['telefono'] ?? null,
            'description' => "Cliente de Novedades Ashley - ID: {$cliente_data['id']}",
            'metadata' => [
                'user_id' => $cliente_data['id'],
                'source' => 'novedades_ashley',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        
        logStripeActivity('success', 'Nuevo customer creado en Stripe', [
            'stripe_customer_id' => $new_customer->id,
            'user_id' => $cliente_data['id'],
            'email' => $cliente_data['email']
        ]);
        
        return $new_customer;
        
    } catch (Exception $e) {
        logStripeActivity('error', 'Error creando/obteniendo customer', [
            'error' => $e->getMessage(),
            'user_id' => $cliente_data['id']
        ]);
        throw $e;
    }
}

function formatStripeAmount($amount, $currency = 'mxn') {
    // Convertir a centavos según la moneda
    $zero_decimal_currencies = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];
    
    if (in_array(strtolower($currency), $zero_decimal_currencies)) {
        return intval($amount); // Monedas sin decimales
    }
    
    return intval($amount * 100); // Monedas con decimales (como MXN)
}

function parseStripeAmount($amount_cents, $currency = 'mxn') {
    // Convertir de centavos a unidad
    $zero_decimal_currencies = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];
    
    if (in_array(strtolower($currency), $zero_decimal_currencies)) {
        return floatval($amount_cents); // Monedas sin decimales
    }
    
    return floatval($amount_cents / 100); // Monedas con decimales
}
?>