<?php
// admin/pedidos.php - Gestión de Pedidos con funcionalidades completas + NOTIFICACIONES
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/notifications.php'; // ✅ NUEVA LÍNEA

$database = new Database();
$conn = $database->getConnection();

$success = '';
$error = '';
$action = $_GET['action'] ?? '';

// Verificar mensaje de éxito
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success = 'Estado del pedido actualizado exitosamente';
}

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_estado') {
        $id_pedido = intval($_POST['id_pedido'] ?? 0);
        $nuevo_estado = $_POST['nuevo_estado'] ?? '';
        $notas_internas = trim($_POST['notas_internas'] ?? '');
        $numero_seguimiento = trim($_POST['numero_seguimiento'] ?? '');
        $paqueteria = trim($_POST['paqueteria'] ?? '');
        
        if ($id_pedido > 0 && !empty($nuevo_estado)) {
            try {
                // Verificar que el pedido existe y obtener estado actual
                $stmt = $conn->prepare("SELECT id, estado, numero_pedido FROM pedidos WHERE id = ? AND activo = 1");
                $stmt->execute([$id_pedido]);
                $pedido_actual = $stmt->fetch();
                
                if (!$pedido_actual) {
                    $error = 'Pedido no encontrado';
                } else {
                    $estado_anterior = $pedido_actual['estado'];
                    
                    // Preparar la actualización
                    $update_sql = "
                        UPDATE pedidos SET 
                        estado = ?, 
                        notas_internas = ?, 
                        numero_seguimiento = ?, 
                        paqueteria = ?,
                        updated_at = NOW()
                    ";
                    
                    $update_params = [$nuevo_estado, $notas_internas, $numero_seguimiento, $paqueteria];
                    
                    // Si el estado es 'entregado', actualizar fecha de entrega
                    if ($nuevo_estado === 'entregado') {
                        $update_sql .= ", fecha_entregado = NOW()";
                    }
                    
                    $update_sql .= " WHERE id = ?";
                    $update_params[] = $id_pedido;
                    
                    // Ejecutar la actualización
                    $stmt = $conn->prepare($update_sql);
                    $stmt->execute($update_params);
                    
                    // Verificar que se actualizó correctamente
                    if ($stmt->rowCount() > 0) {
                        // ✅ NUEVO: Enviar notificación automática si cambió el estado
                        if ($estado_anterior !== $nuevo_estado) {
                            try {
                                $notification_service = new OrderNotificationService($conn);
                                $notification_sent = $notification_service->sendOrderStatusUpdate(
                                    $id_pedido, 
                                    $estado_anterior, 
                                    $nuevo_estado, 
                                    $numero_seguimiento
                                );
                                
                                if ($notification_sent) {
                                    error_log("✅ Email de cambio de estado enviado para pedido #{$pedido_actual['numero_pedido']} ({$estado_anterior} → {$nuevo_estado})");
                                    $success = 'Estado actualizado y notificación enviada al cliente';
                                } else {
                                    error_log("❌ Error enviando email para pedido #{$pedido_actual['numero_pedido']}");
                                    $success = 'Estado actualizado pero hubo un problema enviando la notificación';
                                }
                            } catch (Exception $e) {
                                error_log("Error en notificación para pedido {$id_pedido}: " . $e->getMessage());
                                $success = 'Estado actualizado (notificación pendiente)';
                            }
                        } else {
                            $success = 'Información del pedido actualizada';
                        }
                        
                        // Redirigir para evitar reenvío del formulario
                        if (isset($_GET['id'])) {
                            header("Location: pedidos.php?action=ver&id=" . $id_pedido . "&success=1");
                            exit();
                        }
                    } else {
                        $error = 'No se pudo actualizar el pedido. Verifica que los datos sean correctos.';
                    }
                }
            } catch (Exception $e) {
                $error = 'Error al actualizar estado: ' . $e->getMessage();
                error_log("Error actualizando pedido {$id_pedido}: " . $e->getMessage());
            }
        } else {
            $error = 'Datos inválidos para actualizar el estado';
        }
    }
    
    elseif ($action === 'crear_pedido_manual') {
        $id_cliente = intval($_POST['id_cliente'] ?? 0);
        $productos_json = $_POST['productos'] ?? '';
        $metodo_pago = $_POST['metodo_pago'] ?? 'efectivo';
        $notas_cliente = trim($_POST['notas_cliente'] ?? '');
        $costo_envio = floatval($_POST['costo_envio'] ?? 0);
        
        if ($id_cliente > 0 && !empty($productos_json)) {
            try {
                $productos = json_decode($productos_json, true);
                
                if (is_array($productos) && count($productos) > 0) {
                    $conn->beginTransaction();
                    
                    // Generar número de pedido
                    $stmt = $conn->query("SELECT generar_numero_pedido() as numero");
                    $numero_pedido = $stmt->fetch()['numero'];
                    
                    // Calcular totales
                    $subtotal = 0;
                    foreach ($productos as $producto) {
                        $subtotal += $producto['precio'] * $producto['cantidad'];
                    }
                    
                    $impuestos = $subtotal * 0.16; // 16% IVA
                    $total = $subtotal + $impuestos + $costo_envio;
                    
                    // Crear pedido
                    $stmt = $conn->prepare("
                        INSERT INTO pedidos (numero_pedido, id_cliente, estado, subtotal, impuestos, costo_envio, total, metodo_pago_usado, notas_cliente)
                        VALUES (?, ?, 'confirmado', ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$numero_pedido, $id_cliente, $subtotal, $impuestos, $costo_envio, $total, $metodo_pago, $notas_cliente]);
                    
                    $id_pedido = $conn->lastInsertId();
                    
                    // Agregar detalles del pedido
                    $stmt_detalle = $conn->prepare("
                        INSERT INTO pedido_detalles (id_pedido, id_producto, nombre_producto, descripcion_producto, cantidad, precio_unitario, subtotal)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    foreach ($productos as $producto) {
                        $subtotal_item = $producto['precio'] * $producto['cantidad'];
                        $stmt_detalle->execute([
                            $id_pedido,
                            $producto['id'],
                            $producto['nombre'],
                            $producto['descripcion'] ?? '',
                            $producto['cantidad'],
                            $producto['precio'],
                            $subtotal_item
                        ]);
                        
                        // Actualizar stock
                        $stmt_stock = $conn->prepare("
                            UPDATE productos SET cantidad_etiquetas = cantidad_etiquetas - ? WHERE id = ?
                        ");
                        $stmt_stock->execute([$producto['cantidad'], $producto['id']]);
                    }
                    
                    $conn->commit();
                    
                    // ✅ NUEVO: Enviar notificación automática de confirmación
                    try {
                        $notification_service = new OrderNotificationService($conn);
                        $notification_sent = $notification_service->sendOrderConfirmation($id_pedido);
                        
                        if ($notification_sent) {
                            $success = "Pedido {$numero_pedido} creado exitosamente y notificación enviada";
                            error_log("✅ Email de confirmación enviado para pedido manual #{$numero_pedido}");
                        } else {
                            $success = "Pedido {$numero_pedido} creado exitosamente (notificación pendiente)";
                            error_log("❌ Error enviando email para pedido manual #{$numero_pedido}");
                        }
                    } catch (Exception $e) {
                        error_log("Error en notificación para pedido manual {$id_pedido}: " . $e->getMessage());
                        $success = "Pedido {$numero_pedido} creado exitosamente";
                    }
                    
                } else {
                    $error = 'No se especificaron productos válidos';
                }
            } catch (Exception $e) {
                if ($conn->inTransaction()) {
                    $conn->rollback();
                }
                $error = 'Error al crear pedido: ' . $e->getMessage();
            }
        } else {
            $error = 'Cliente y productos son requeridos';
        }
    }
    
    // ✅ NUEVA ACCIÓN: Reenviar notificación
    elseif ($action === 'resend_notification') {
        $id_pedido = intval($_POST['id_pedido'] ?? 0);
        $notification_type = $_POST['notification_type'] ?? '';
        
        if ($id_pedido > 0 && !empty($notification_type)) {
            try {
                $notification_service = new OrderNotificationService($conn);
                $result = $notification_service->resendNotification($id_pedido, $notification_type);
                
                if ($result) {
                    $success = 'Notificación reenviada exitosamente';
                } else {
                    $error = 'Error al reenviar la notificación';
                }
            } catch (Exception $e) {
                $error = 'Error al reenviar notificación: ' . $e->getMessage();
            }
        } else {
            $error = 'Datos inválidos para reenviar notificación';
        }
    }
}

// Obtener filtros
$estado_filter = $_GET['estado'] ?? '';
$cliente_filter = $_GET['cliente'] ?? '';
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';
$search = trim($_GET['search'] ?? '');

// Construir consulta de pedidos
$where_conditions = ['p.activo = 1'];
$params = [];

if ($estado_filter) {
    $where_conditions[] = 'p.estado = ?';
    $params[] = $estado_filter;
}

if ($cliente_filter) {
    $where_conditions[] = 'p.id_cliente = ?';
    $params[] = $cliente_filter;
}

if ($fecha_desde) {
    $where_conditions[] = 'DATE(p.created_at) >= ?';
    $params[] = $fecha_desde;
}

if ($fecha_hasta) {
    $where_conditions[] = 'DATE(p.created_at) <= ?';
    $params[] = $fecha_hasta;
}

if ($search) {
    $where_conditions[] = '(p.numero_pedido LIKE ? OR c.nombre LIKE ? OR c.email LIKE ?)';
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_sql = implode(' AND ', $where_conditions);

// Obtener pedidos
try {
    $stmt = $conn->prepare("
       SELECT p.*, c.nombre as cliente_nombre, c.email as cliente_email, c.telefono as cliente_telefono,
       de.nombre_destinatario, de.calle_numero, de.ciudad, de.estado as estado_direccion,
               COUNT(pd.id) as total_items
        FROM pedidos p
        LEFT JOIN clientes c ON p.id_cliente = c.id
        LEFT JOIN direcciones_envio de ON p.id_direccion_envio = de.id
        LEFT JOIN pedido_detalles pd ON p.id = pd.id_pedido
        WHERE $where_sql
        GROUP BY p.id
        ORDER BY p.created_at DESC
        LIMIT 50
    ");
    $stmt->execute($params);
    $pedidos = $stmt->fetchAll();
} catch (Exception $e) {
    $pedidos = [];
    $error = 'Error al cargar pedidos: ' . $e->getMessage();
}

// Obtener estadísticas
$stats = [];
try {
    // Pedidos por estado (CORREGIDO - excluye cancelados y devueltos)
    $stmt = $conn->query("
        SELECT estado, COUNT(*) as cantidad, SUM(total) as monto_total
        FROM pedidos 
        WHERE activo = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY estado
        ORDER BY cantidad DESC
    ");
    $stats['por_estado'] = $stmt->fetchAll();
    
    // Totales generales (CORREGIDO - excluye cancelados y devueltos)
    $stmt = $conn->query("
        SELECT 
            COUNT(CASE WHEN estado NOT IN ('cancelado', 'devuelto') THEN 1 END) as total_pedidos,
            COALESCE(SUM(CASE WHEN estado NOT IN ('cancelado', 'devuelto') THEN total ELSE 0 END), 0) as ingresos_total,
            COALESCE(AVG(CASE WHEN estado NOT IN ('cancelado', 'devuelto') THEN total END), 0) as ticket_promedio,
            SUM(CASE WHEN estado IN ('pendiente', 'confirmado') THEN 1 ELSE 0 END) as pendientes
        FROM pedidos 
        WHERE activo = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stats['generales'] = $stmt->fetch();
    
} catch (Exception $e) {
    $stats = ['por_estado' => [], 'generales' => ['total_pedidos' => 0, 'ingresos_total' => 0, 'ticket_promedio' => 0, 'pendientes' => 0]];
}

// Obtener clientes para filtros
try {
    $stmt = $conn->query("SELECT id, nombre, email FROM clientes WHERE activo = 1 ORDER BY nombre");
    $clientes = $stmt->fetchAll();
} catch (Exception $e) {
    $clientes = [];
}

// Si es ver detalle, obtener datos del pedido
$pedido_detalle = null;
$pedido_items = [];
$notification_history = [];
if ($action === 'ver' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        $stmt = $conn->prepare("
            SELECT p.*, c.nombre as cliente_nombre, c.email as cliente_email, c.telefono as cliente_telefono,
                   de.nombre_destinatario, de.telefono_contacto, de.calle_numero, de.colonia, 
                   de.ciudad, de.estado, de.codigo_postal, de.referencias
            FROM pedidos p
            LEFT JOIN clientes c ON p.id_cliente = c.id
            LEFT JOIN direcciones_envio de ON p.id_direccion_envio = de.id
            WHERE p.id = ? AND p.activo = 1
        ");
        $stmt->execute([$id]);
        $pedido_detalle = $stmt->fetch();
        
        if ($pedido_detalle) {
            // Obtener items del pedido
            $stmt = $conn->prepare("
                SELECT pd.*, p.imagen, p.clave_producto
                FROM pedido_detalles pd
                LEFT JOIN productos p ON pd.id_producto = p.id
                WHERE pd.id_pedido = ?
                ORDER BY pd.id
            ");
            $stmt->execute([$id]);
            $pedido_items = $stmt->fetchAll();
            
            // ✅ NUEVO: Obtener historial de notificaciones
            try {
                $notification_service = new OrderNotificationService($conn);
                $notification_history = $notification_service->getOrderNotificationHistory($id);
            } catch (Exception $e) {
                error_log("Error obteniendo historial de notificaciones: " . $e->getMessage());
                $notification_history = [];
            }
        }
    } catch (Exception $e) {
        $error = 'Error al cargar detalle del pedido';
    }
}

// Estados disponibles
$estados_pedido = [
    'pendiente' => ['label' => 'Pendiente', 'color' => 'warning', 'icon' => 'clock'],
    'confirmado' => ['label' => 'Confirmado', 'color' => 'info', 'icon' => 'check-circle'],
    'procesando' => ['label' => 'Procesando', 'color' => 'primary', 'icon' => 'cog'],
    'enviado' => ['label' => 'Enviado', 'color' => 'secondary', 'icon' => 'truck'],
    'en_transito' => ['label' => 'En Tránsito', 'color' => 'dark', 'icon' => 'route'],
    'entregado' => ['label' => 'Entregado', 'color' => 'success', 'icon' => 'check-square'],
    'cancelado' => ['label' => 'Cancelado', 'color' => 'danger', 'icon' => 'times-circle'],
    'devuelto' => ['label' => 'Devuelto', 'color' => 'warning', 'icon' => 'undo']
];

// Obtener productos para crear pedido manual
$productos_disponibles = [];
if ($action === 'crear') {
    try {
        $stmt = $conn->query("
            SELECT id, clave_producto, nombre, precio, cantidad_etiquetas 
            FROM productos 
            WHERE activo = 1 AND cantidad_etiquetas > 0 
            ORDER BY nombre 
            LIMIT 100
        ");
        $productos_disponibles = $stmt->fetchAll();
    } catch (Exception $e) {
        $productos_disponibles = [];
    }
}

// ✅ NUEVO: Obtener estadísticas de notificaciones
$notification_stats = [];
if ($action === 'ver' && $pedido_detalle) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                notification_type,
                COUNT(*) as total_attempts,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful,
                MAX(created_at) as last_attempt
            FROM order_notifications_log 
            WHERE order_id = ? AND method = 'email'
            GROUP BY notification_type
        ");
        $stmt->execute([$pedido_detalle['id']]);
        $notification_stats = $stmt->fetchAll();
    } catch (Exception $e) {
        $notification_stats = [];
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Pedidos - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .admin-sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .admin-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            padding: 20px;
        }
        .status-badge {
            font-size: 0.85em;
            padding: 0.4em 0.8em;
            border-radius: 25px;
        }
        .pedido-item {
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }
        .pedido-item:hover {
            background-color: #f8f9fa;
            border-left-color: #667eea;
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
        }
        .btn-custom {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            border-radius: 25px;
            padding: 0.5rem 1.5rem;
        }
        .btn-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            color: white;
        }
        .notification-history {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 10px;
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-2 admin-sidebar p-3">
                <h4 class="text-white mb-4">
                    <i class="fas fa-store"></i> Novedades Ashley
                </h4>
                <ul class="nav flex-column">
                    <li class="nav-item mb-2">
                        <a class="nav-link text-white" href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item mb-2">
                        <a class="nav-link text-white active" href="pedidos.php">
                            <i class="fas fa-shopping-cart"></i> Pedidos
                        </a>
                    </li>
                    <li class="nav-item mb-2">
                        <a class="nav-link text-white" href="productos.php">
                            <i class="fas fa-box"></i> Productos
                        </a>
                    </li>
                    <li class="nav-item mb-2">
                        <a class="nav-link text-white" href="clientes.php">
                            <i class="fas fa-users"></i> Clientes
                        </a>
                    </li>
                    <li class="nav-item mb-2">
                        <a class="nav-link text-white" href="configuracion.php">
                            <i class="fas fa-cog"></i> Configuración
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link text-white" href="logout.php">
                            <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                        </a>
                    </li>
                </ul>
            </nav>

            <!-- Contenido principal -->
            <main class="col-md-10 p-4">
                <!-- Alertas -->
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($action === 'ver' && $pedido_detalle): ?>
                    <!-- Vista de detalle del pedido -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2><i class="fas fa-eye"></i> Detalle del Pedido #<?= htmlspecialchars($pedido_detalle['numero_pedido']) ?></h2>
                        <a href="pedidos.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Volver
                        </a>
                    </div>

                    <div class="row">
                        <!-- Información del pedido -->
                        <div class="col-lg-8">
                            <div class="admin-card">
                                <h5><i class="fas fa-info-circle"></i> Información General</h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <p><strong>Número:</strong> <?= htmlspecialchars($pedido_detalle['numero_pedido']) ?></p>
                                        <p><strong>Cliente:</strong> <?= htmlspecialchars($pedido_detalle['cliente_nombre']) ?></p>
                                        <p><strong>Email:</strong> <?= htmlspecialchars($pedido_detalle['cliente_email']) ?></p>
                                        <p><strong>Fecha:</strong> <?= date('d/m/Y H:i', strtotime($pedido_detalle['created_at'])) ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>Estado:</strong> 
                                            <span class="badge bg-<?= $estados_pedido[$pedido_detalle['estado']]['color'] ?>">
                                                <i class="fas fa-<?= $estados_pedido[$pedido_detalle['estado']]['icon'] ?>"></i>
                                                <?= $estados_pedido[$pedido_detalle['estado']]['label'] ?>
                                            </span>
                                        </p>
                                        <p><strong>Total:</strong> $<?= number_format($pedido_detalle['total'], 2) ?></p>
                                        <p><strong>Método de pago:</strong> <?= htmlspecialchars($pedido_detalle['metodo_pago_usado']) ?></p>
                                    </div>
                                </div>

                                <?php if ($pedido_detalle['numero_seguimiento']): ?>
                                    <div class="alert alert-info">
                                        <strong>Seguimiento:</strong> <?= htmlspecialchars($pedido_detalle['numero_seguimiento']) ?>
                                        <?php if ($pedido_detalle['paqueteria']): ?>
                                            <br><strong>Paquetería:</strong> <?= htmlspecialchars($pedido_detalle['paqueteria']) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Productos del pedido -->
                            <div class="admin-card">
                                <h5><i class="fas fa-shopping-bag"></i> Productos</h5>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Producto</th>
                                                <th>Cantidad</th>
                                                <th>Precio Unit.</th>
                                                <th>Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pedido_items as $item): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars($item['nombre_producto']) ?></strong><br>
                                                        <small class="text-muted"><?= htmlspecialchars($item['descripcion_producto']) ?></small>
                                                    </td>
                                                    <td><?= $item['cantidad'] ?></td>
                                                    <td>$<?= number_format($item['precio_unitario'], 2) ?></td>
                                                    <td><strong>$<?= number_format($item['subtotal'], 2) ?></strong></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th colspan="3">Subtotal:</th>
                                                <th>$<?= number_format($pedido_detalle['subtotal'], 2) ?></th>
                                            </tr>
                                            <tr>
                                                <th colspan="3">IVA:</th>
                                                <th>$<?= number_format($pedido_detalle['impuestos'], 2) ?></th>
                                            </tr>
                                            <tr>
                                                <th colspan="3">Envío:</th>
                                                <th>$<?= number_format($pedido_detalle['costo_envio'], 2) ?></th>
                                            </tr>
                                            <tr class="table-primary">
                                                <th colspan="3">TOTAL:</th>
                                                <th>$<?= number_format($pedido_detalle['total'], 2) ?></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Panel lateral -->
                        <div class="col-lg-4">
                            <!-- Actualizar estado -->
                            <div class="admin-card">
                                <h6><i class="fas fa-edit"></i> Actualizar Estado</h6>
                                <form method="POST">
                                    <input type="hidden" name="action" value="update_estado">
                                    <input type="hidden" name="id_pedido" value="<?= $pedido_detalle['id'] ?>">
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Estado:</label>
                                        <select name="nuevo_estado" class="form-select" required>
                                            <?php foreach ($estados_pedido as $estado_key => $estado_info): ?>
                                                <option value="<?= $estado_key ?>" <?= $pedido_detalle['estado'] === $estado_key ? 'selected' : '' ?>>
                                                    <?= $estado_info['label'] ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Número de seguimiento:</label>
                                        <input type="text" name="numero_seguimiento" class="form-control" 
                                               value="<?= htmlspecialchars($pedido_detalle['numero_seguimiento'] ?? '') ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Paquetería:</label>
                                        <input type="text" name="paqueteria" class="form-control" 
                                               value="<?= htmlspecialchars($pedido_detalle['paqueteria'] ?? '') ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Notas internas:</label>
                                        <textarea name="notas_internas" class="form-control" rows="3"><?= htmlspecialchars($pedido_detalle['notas_internas'] ?? '') ?></textarea>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-custom w-100">
                                        <i class="fas fa-save"></i> Actualizar
                                    </button>
                                </form>
                            </div>

                            <!-- ✅ NUEVA SECCIÓN: Gestión de Notificaciones -->
                            <div class="admin-card">
                                <h6><i class="fas fa-envelope"></i> Notificaciones</h6>
                                
                                <?php if (count($notification_history) > 0): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Historial de Emails:</label>
                                        <div class="notification-history">
                                            <?php 
                                            $type_labels = [
                                                'order_confirmation' => '✅ Confirmación',
                                                'status_update' => '🔄 Cambio Estado', 
                                                'shipping_notification' => '🚚 Envío',
                                                'trigger_order_created' => '⚙️ Trigger Creado',
                                                'trigger_status_changed' => '⚙️ Trigger Estado'
                                            ];
                                            foreach (array_slice($notification_history, 0, 10) as $notification): 
                                            ?>
                                                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                                    <div>
                                                        <small class="fw-bold">
                                                            <?= $type_labels[$notification['notification_type']] ?? $notification['notification_type'] ?>
                                                        </small><br>
                                                        <small class="text-muted">
                                                            <?= date('d/m/Y H:i', strtotime($notification['created_at'])) ?>
                                                        </small>
                                                    </div>
                                                    <div>
                                                        <?php if ($notification['success']): ?>
                                                            <span class="badge bg-success">Enviado</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger">Error</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Botones para reenviar notificaciones -->
                                    <div class="d-grid gap-2">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="resend_notification">
                                            <input type="hidden" name="id_pedido" value="<?= $pedido_detalle['id'] ?>">
                                            <input type="hidden" name="notification_type" value="order_confirmation">
                                            <button type="submit" class="btn btn-outline-success btn-sm w-100">
                                                <i class="fas fa-redo"></i> Reenviar Confirmación
                                            </button>
                                        </form>
                                        
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="resend_notification">
                                            <input type="hidden" name="id_pedido" value="<?= $pedido_detalle['id'] ?>">
                                            <input type="hidden" name="notification_type" value="status_update">
                                            <button type="submit" class="btn btn-outline-info btn-sm w-100">
                                                <i class="fas fa-redo"></i> Reenviar Estado Actual
                                            </button>
                                        </form>
                                        
                                        <?php if ($pedido_detalle['numero_seguimiento']): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="resend_notification">
                                                <input type="hidden" name="id_pedido" value="<?= $pedido_detalle['id'] ?>">
                                                <input type="hidden" name="notification_type" value="shipping_notification">
                                                <button type="submit" class="btn btn-outline-warning btn-sm w-100">
                                                    <i class="fas fa-redo"></i> Reenviar Seguimiento
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                    
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <small>
                                            <i class="fas fa-info-circle"></i>
                                            No hay historial de notificaciones para este pedido.
                                        </small>
                                    </div>
                                    
                                    <!-- Botón para enviar primera notificación -->
                                    <form method="POST">
                                        <input type="hidden" name="action" value="resend_notification">
                                        <input type="hidden" name="id_pedido" value="<?= $pedido_detalle['id'] ?>">
                                        <input type="hidden" name="notification_type" value="order_confirmation">
                                        <button type="submit" class="btn btn-primary btn-sm w-100">
                                            <i class="fas fa-paper-plane"></i> Enviar Confirmación
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <?php if (count($notification_stats) > 0): ?>
                                    <div class="mt-3">
                                        <small class="text-muted">
                                            <strong>Estadísticas:</strong><br>
                                            <?php foreach ($notification_stats as $stat): ?>
                                                <?= $type_labels[$stat['notification_type']] ?? $stat['notification_type'] ?>: 
                                                <?= $stat['successful'] ?>/<?= $stat['total_attempts'] ?> exitosos<br>
                                            <?php endforeach; ?>
                                        </small>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Información de envío -->
                            <?php if ($pedido_detalle['nombre_destinatario']): ?>
                                <div class="admin-card">
                                    <h6><i class="fas fa-map-marker-alt"></i> Dirección de Envío</h6>
                                    <p class="mb-1"><strong><?= htmlspecialchars($pedido_detalle['nombre_destinatario']) ?></strong></p>
                                    <p class="mb-1"><?= htmlspecialchars($pedido_detalle['calle_numero']) ?></p>
                                    <?php if ($pedido_detalle['colonia']): ?>
                                        <p class="mb-1"><?= htmlspecialchars($pedido_detalle['colonia']) ?></p>
                                    <?php endif; ?>
                                    <p class="mb-1"><?= htmlspecialchars($pedido_detalle['ciudad']) ?>, <?= htmlspecialchars($pedido_detalle['estado']) ?></p>
                                    <?php if ($pedido_detalle['codigo_postal']): ?>
                                        <p class="mb-1">CP: <?= htmlspecialchars($pedido_detalle['codigo_postal']) ?></p>
                                    <?php endif; ?>
                                    <?php if ($pedido_detalle['telefono_contacto']): ?>
                                        <p class="mb-0"><strong>Tel:</strong> <?= htmlspecialchars($pedido_detalle['telefono_contacto']) ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Vista principal de pedidos -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2><i class="fas fa-shopping-cart"></i> Gestión de Pedidos</h2>
                        <a href="pedidos.php?action=crear" class="btn btn-custom">
                            <i class="fas fa-plus"></i> Nuevo Pedido
                        </a>
                    </div>

                    <!-- Estadísticas rápidas -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="stats-card">
                                <h3><?= $stats['generales']['total_pedidos'] ?></h3>
                                <p class="mb-0">Total Pedidos (30 días)</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stats-card">
                                <h3>$<?= number_format($stats['generales']['ingresos_total'], 2) ?></h3>
                                <p class="mb-0">Ingresos</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stats-card">
                                <h3>$<?= number_format($stats['generales']['ticket_promedio'], 2) ?></h3>
                                <p class="mb-0">Ticket Promedio</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stats-card">
                                <h3><?= $stats['generales']['pendientes'] ?></h3>
                                <p class="mb-0">Pendientes</p>
                            </div>
                        </div>
                    </div>

                    <!-- Filtros -->
                    <div class="admin-card">
                        <h5><i class="fas fa-filter"></i> Filtros</h5>
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Estado:</label>
                                <select name="estado" class="form-select">
                                    <option value="">Todos los estados</option>
                                    <?php foreach ($estados_pedido as $estado_key => $estado_info): ?>
                                        <option value="<?= $estado_key ?>" <?= $estado_filter === $estado_key ? 'selected' : '' ?>>
                                            <?= $estado_info['label'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Cliente:</label>
                                <select name="cliente" class="form-select">
                                    <option value="">Todos los clientes</option>
                                    <?php foreach ($clientes as $cliente): ?>
                                        <option value="<?= $cliente['id'] ?>" <?= $cliente_filter == $cliente['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cliente['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Desde:</label>
                                <input type="date" name="fecha_desde" class="form-control" value="<?= htmlspecialchars($fecha_desde) ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Hasta:</label>
                                <input type="date" name="fecha_hasta" class="form-control" value="<?= htmlspecialchars($fecha_hasta) ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-custom w-100">
                                    <i class="fas fa-search"></i> Filtrar
                                </button>
                            </div>
                        </form>
                        
                        <div class="row mt-3">
                            <div class="col-md-10">
                                <input type="text" name="search" class="form-control" placeholder="Buscar por número de pedido, cliente o email..." 
                                       value="<?= htmlspecialchars($search) ?>" onkeypress="if(event.key==='Enter') this.form.submit()">
                            </div>
                            <div class="col-md-2">
                                <a href="pedidos.php" class="btn btn-outline-secondary w-100">
                                    <i class="fas fa-times"></i> Limpiar
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Lista de pedidos -->
                    <div class="admin-card">
                        <h5><i class="fas fa-list"></i> Pedidos</h5>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Número</th>
                                        <th>Cliente</th>
                                        <th>Fecha</th>
                                        <th>Estado</th>
                                        <th>Total</th>
                                        <th>Items</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($pedidos)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4">
                                                <i class="fas fa-search fa-2x text-muted mb-2"></i>
                                                <p class="text-muted">No se encontraron pedidos</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($pedidos as $pedido): ?>
                                            <tr class="pedido-item">
                                                <td>
                                                    <strong><?= htmlspecialchars($pedido['numero_pedido']) ?></strong>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($pedido['cliente_nombre']) ?><br>
                                                    <small class="text-muted"><?= htmlspecialchars($pedido['cliente_email']) ?></small>
                                                </td>
                                                <td>
                                                    <?= date('d/m/Y', strtotime($pedido['created_at'])) ?><br>
                                                    <small class="text-muted"><?= date('H:i', strtotime($pedido['created_at'])) ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge status-badge bg-<?= $estados_pedido[$pedido['estado']]['color'] ?>">
                                                        <i class="fas fa-<?= $estados_pedido[$pedido['estado']]['icon'] ?>"></i>
                                                        <?= $estados_pedido[$pedido['estado']]['label'] ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <strong>$<?= number_format($pedido['total'], 2) ?></strong>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?= $pedido['total_items'] ?> items</span>
                                                </td>
                                                <td>
                                                    <a href="pedidos.php?action=ver&id=<?= $pedido['id'] ?>" class="btn btn-sm btn-outline-primary" title="Ver detalle">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // ✅ NUEVO: Mostrar confirmación antes de reenviar notificaciones
        document.querySelectorAll('form input[name="action"][value="resend_notification"]').forEach(input => {
            input.closest('form').addEventListener('submit', function(e) {
                const notificationType = this.querySelector('input[name="notification_type"]').value;
                const typeNames = {
                    'order_confirmation': 'confirmación de pedido',
                    'status_update': 'actualización de estado',
                    'shipping_notification': 'información de seguimiento'
                };
                
                const typeName = typeNames[notificationType] || notificationType;
                
                if (!confirm(`¿Estás seguro de que quieres reenviar la notificación de ${typeName}?`)) {
                    e.preventDefault();
                }
            });
        });

        // Auto-refresh cada 30 segundos en la vista principal
        <?php if ($action !== 'ver'): ?>
        setTimeout(function() {
            if (!document.hidden) {
                location.reload();
            }
        }, 30000);
        <?php endif; ?>
    </script>
</body>
</html>