<?php
// api/checkout.php - API para procesar checkout y crear pedidos
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

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
    switch ($action) {
        case 'validate_coupon':
            $coupon_code = trim($_POST['coupon_code'] ?? '');
            $order_total = floatval($_POST['order_total'] ?? 0);
            
            if (empty($coupon_code)) {
                throw new Exception('Código de cupón requerido');
            }
            
            if ($order_total <= 0) {
                throw new Exception('Total del pedido inválido');
            }
            
            // Buscar cupón
            $stmt = $conn->prepare("
                SELECT * FROM cupones 
                WHERE codigo = ? 
                AND activo = 1 
                AND fecha_inicio <= CURDATE() 
                AND fecha_fin >= CURDATE()
                AND (limite_usos IS NULL OR usos_actuales < limite_usos)
                LIMIT 1
            ");
            $stmt->execute([$coupon_code]);
            $cupon = $stmt->fetch();
            
            if (!$cupon) {
                throw new Exception('Cupón no válido o expirado');
            }
            
            // Verificar monto mínimo
            if ($cupon['monto_minimo'] && $order_total < $cupon['monto_minimo']) {
                throw new Exception("Monto mínimo requerido: $" . number_format($cupon['monto_minimo'], 2));
            }
            
            // Calcular descuento
            $descuento_aplicado = 0;
            if ($cupon['tipo_descuento'] === 'porcentaje') {
                $descuento_aplicado = $order_total * ($cupon['valor_descuento'] / 100);
            } else {
                $descuento_aplicado = $cupon['valor_descuento'];
            }
            
            // No puede exceder el total del pedido
            $descuento_aplicado = min($descuento_aplicado, $order_total);
            
            $response['success'] = true;
            $response['message'] = 'Cupón válido';
            $response['data'] = [
                'codigo' => $cupon['codigo'],
                'nombre' => $cupon['nombre'],
                'tipo_descuento' => $cupon['tipo_descuento'],
                'valor_descuento' => $cupon['valor_descuento'],
                'descuento_aplicado' => $descuento_aplicado
            ];
            break;
            
        case 'create_order':
            $address_id = intval($_POST['address_id'] ?? 0);
            $payment_id = intval($_POST['payment_id'] ?? 0);
            $coupon_code = trim($_POST['coupon_code'] ?? '');
            $order_notes = trim($_POST['order_notes'] ?? '');
            $totals = json_decode($_POST['totals'] ?? '{}', true);
            
            if (!$address_id || !$payment_id) {
                throw new Exception('Dirección y método de pago requeridos');
            }
            
            if (empty($totals) || !isset($totals['total'])) {
                throw new Exception('Totales del pedido requeridos');
            }
            
            // Verificar que la dirección pertenece al usuario
            $stmt = $conn->prepare("
                SELECT * FROM direcciones_envio 
                WHERE id = ? AND id_cliente = ? AND activo = 1
            ");
            $stmt->execute([$address_id, $_SESSION['usuario_id']]);
            $direccion = $stmt->fetch();
            
            if (!$direccion) {
                throw new Exception('Dirección no válida');
            }
            
            // Verificar que el método de pago pertenece al usuario
            $stmt = $conn->prepare("
                SELECT * FROM metodos_pago 
                WHERE id = ? AND id_cliente = ? AND activo = 1
            ");
            $stmt->execute([$payment_id, $_SESSION['usuario_id']]);
            $metodo_pago = $stmt->fetch();
            
            if (!$metodo_pago) {
                throw new Exception('Método de pago no válido');
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
            
            // Iniciar transacción
            $conn->beginTransaction();
            
            try {
                // Crear el pedido
                $stmt = $conn->prepare("
                    INSERT INTO pedidos (
                        id_cliente, id_direccion_envio, id_metodo_pago,
                        subtotal, impuestos, costo_envio, descuentos, total,
                        metodo_pago_usado, notas_cliente,
                        estado, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', NOW())
                ");
                
                $metodo_pago_texto = $metodo_pago['nombre_tarjeta'] . ' •••• ' . $metodo_pago['ultimos_4_digitos'];
                
                $stmt->execute([
                    $_SESSION['usuario_id'],
                    $address_id,
                    $payment_id,
                    $totals['subtotal'],
                    $totals['tax'],
                    $totals['shipping'],
                    $totals['discount'],
                    $totals['total'],
                    $metodo_pago_texto,
                    $order_notes
                ]);
                
                $pedido_id = $conn->lastInsertId();
                
                // Obtener el número de pedido generado automáticamente
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
                    ) VALUES (?, NULL, 'pendiente', 'Pedido creado', 'Sistema')
                ");
                $stmt->execute([$pedido_id]);
                
                // Confirmar transacción
                $conn->commit();
                
                $response['success'] = true;
                $response['message'] = 'Pedido creado exitosamente';
                $response['data'] = [
                    'order_id' => $pedido_id,
                    'numero_pedido' => $numero_pedido,
                    'total' => $totals['total']
                ];
                
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;
            
        case 'get_shipping_cost':
            $estado = $_POST['estado'] ?? '';
            $subtotal = floatval($_POST['subtotal'] ?? 0);
            
            // Obtener configuración de envío
            $stmt = $conn->prepare("
                SELECT clave, valor FROM configuracion_sistema 
                WHERE clave IN ('costo_envio_local', 'costo_envio_nacional', 'envio_gratis_minimo')
            ");
            $stmt->execute();
            $config_envio = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $envio_gratis_minimo = floatval($config_envio['envio_gratis_minimo'] ?? 1000);
            
            // Si supera el mínimo, envío gratis
            if ($subtotal >= $envio_gratis_minimo) {
                $costo_envio = 0;
            } else {
                // Determinar si es local o nacional
                $estados_locales = ['CDMX', 'México']; // Estados considerados locales
                
                if (in_array($estado, $estados_locales)) {
                    $costo_envio = floatval($config_envio['costo_envio_local'] ?? 50);
                } else {
                    $costo_envio = floatval($config_envio['costo_envio_nacional'] ?? 120);
                }
            }
            
            $response['success'] = true;
            $response['data'] = [
                'costo_envio' => $costo_envio,
                'es_gratis' => $costo_envio === 0,
                'minimo_envio_gratis' => $envio_gratis_minimo
            ];
            break;
            
        case 'validate_stock':
            // Validar que todos los productos en el carrito tengan stock
            $stmt = $conn->prepare("
                SELECT 
                    p.nombre,
                    p.cantidad_etiquetas,
                    c.cantidad as cantidad_carrito
                FROM carrito_compras c
                INNER JOIN productos p ON c.id_producto = p.id
                WHERE c.id_cliente = ? AND p.activo = 1
            ");
            $stmt->execute([$_SESSION['usuario_id']]);
            $items = $stmt->fetchAll();
            
            $stock_insuficiente = [];
            foreach ($items as $item) {
                if ($item['cantidad_carrito'] > $item['cantidad_etiquetas']) {
                    $stock_insuficiente[] = [
                        'nombre' => $item['nombre'],
                        'solicitado' => $item['cantidad_carrito'],
                        'disponible' => $item['cantidad_etiquetas']
                    ];
                }
            }
            
            if (!empty($stock_insuficiente)) {
                $response['success'] = false;
                $response['message'] = 'Stock insuficiente para algunos productos';
                $response['data'] = $stock_insuficiente;
            } else {
                $response['success'] = true;
                $response['message'] = 'Stock disponible para todos los productos';
            }
            break;
            
        case 'estimate_delivery':
            $estado = $_POST['estado'] ?? '';
            $ciudad = $_POST['ciudad'] ?? '';
            
            // Estimación simple basada en ubicación
            $dias_entrega = 3; // Por defecto
            
            $estados_rapidos = ['CDMX', 'México', 'Jalisco', 'Nuevo León'];
            $estados_medios = ['Puebla', 'Guanajuato', 'Veracruz', 'Michoacán'];
            
            if (in_array($estado, $estados_rapidos)) {
                $dias_entrega = 2;
            } elseif (in_array($estado, $estados_medios)) {
                $dias_entrega = 3;
            } else {
                $dias_entrega = 5;
            }
            
            $fecha_estimada = date('Y-m-d', strtotime("+$dias_entrega days"));
            
            $response['success'] = true;
            $response['data'] = [
                'dias_entrega' => $dias_entrega,
                'fecha_estimada' => $fecha_estimada,
                'fecha_estimada_formatted' => date('d/m/Y', strtotime($fecha_estimada))
            ];
            break;
            
        default:
            throw new Exception('Acción no válida: ' . $action);
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    error_log("Error en checkout API: " . $e->getMessage());
    
    // Si hay una transacción activa, hacer rollback
    if ($conn->inTransaction()) {
        $conn->rollback();
    }
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;