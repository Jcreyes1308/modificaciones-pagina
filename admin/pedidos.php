<?php
// admin/pedidos.php - Gestión de Pedidos con funcionalidades completas CORREGIDO
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

require_once '../config/database.php';
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
                // Verificar que el pedido existe
                $stmt = $conn->prepare("SELECT id, estado FROM pedidos WHERE id = ? AND activo = 1");
                $stmt->execute([$id_pedido]);
                $pedido_actual = $stmt->fetch();
                
                if (!$pedido_actual) {
                    $error = 'Pedido no encontrado';
                } else {
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
                        // Insertar en el historial manualmente si es necesario
                        if ($pedido_actual['estado'] !== $nuevo_estado) {
                            try {
                                $stmt_historial = $conn->prepare("
                                    INSERT INTO pedido_estados_historial 
                                    (id_pedido, estado_anterior, estado_nuevo, comentarios, usuario_cambio) 
                                    VALUES (?, ?, ?, ?, ?)
                                ");
                                $stmt_historial->execute([
                                    $id_pedido, 
                                    $pedido_actual['estado'], 
                                    $nuevo_estado, 
                                    $notas_internas ?: 'Actualización manual desde admin', 
                                    $_SESSION['admin_nombre'] ?? 'Admin'
                                ]);
                            } catch (Exception $e) {
                                // Log del error pero no fallar la actualización principal
                                error_log("Error al insertar historial: " . $e->getMessage());
                            }
                        }
                        
                        $success = 'Estado del pedido actualizado exitosamente';
                        
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
                    
                    $success = "Pedido {$numero_pedido} creado exitosamente";
                } else {
                    $error = 'No se especificaron productos válidos';
                }
            } catch (Exception $e) {
                $error = 'Error al crear pedido: ' . $e->getMessage();
            }
        } else {
            $error = 'Cliente y productos son requeridos';
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
    // Pedidos por estado
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
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Pedidos - Admin</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        .sidebar {
            background: linear-gradient(180deg, #2c3e50 0%, #34495e 100%);
            min-height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            z-index: 1000;
        }
        
        .main-content {
            margin-left: 250px;
            padding: 20px;
            background: #f8f9fa;
            min-height: 100vh;
        }
        
        .sidebar .nav-link {
            color: #ecf0f1;
            padding: 15px 20px;
            transition: all 0.3s ease;
        }
        
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: rgba(52, 152, 219, 0.2);
            color: #3498db;
            border-left: 4px solid #3498db;
        }
        
        .admin-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 25px;
            transition: all 0.3s ease;
        }
        
        .admin-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .pedido-card {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .pedido-card:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .estado-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.9rem;
        }
        
        .btn-admin {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
            color: white;
            border-radius: 10px;
            padding: 8px 16px;
            transition: all 0.3s ease;
        }
        
        .btn-admin:hover {
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .stats-row {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .pedido-timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .pedido-timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #dee2e6;
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -25px;
            top: 5px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #007bff;
        }
        
        .producto-selector {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .producto-item {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .producto-item:hover {
            background: #f8f9fa;
            border-color: #007bff;
        }
        
        .producto-item.selected {
            background: #e3f2fd;
            border-color: #2196f3;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="p-4 text-center border-bottom">
            <h4 class="text-white mb-0">
                <i class="fas fa-crown"></i> Admin Panel
            </h4>
        </div>
        
        <nav class="nav flex-column">
            <a class="nav-link" href="dashboard.php">
                <i class="fas fa-tachometer-alt me-2"></i> Dashboard
            </a>
            <a class="nav-link" href="productos.php">
                <i class="fas fa-box me-2"></i> Productos
            </a>
            <a class="nav-link" href="categorias.php">
                <i class="fas fa-tags me-2"></i> Categorías
            </a>
            <a class="nav-link active" href="pedidos.php">
                <i class="fas fa-shopping-cart me-2"></i> Pedidos
            </a>
            <a class="nav-link" href="clientes.php">
                <i class="fas fa-users me-2"></i> Clientes
            </a>
            <hr class="text-muted">
            <a class="nav-link" href="../index.php" target="_blank">
                <i class="fas fa-external-link-alt me-2"></i> Ver Tienda
            </a>
            <a class="nav-link" href="logout.php">
                <i class="fas fa-sign-out-alt me-2"></i> Cerrar Sesión
            </a>
        </nav>
    </div>
    
    <!-- Contenido principal -->
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>
                <i class="fas fa-shopping-cart text-primary"></i> 
                <?= $action === 'ver' ? 'Detalle del Pedido' : ($action === 'crear' ? 'Crear Pedido Manual' : 'Gestión de Pedidos') ?>
            </h2>
            
            <?php if ($action !== 'ver' && $action !== 'crear'): ?>
                <a href="pedidos.php?action=crear" class="btn btn-admin">
                    <i class="fas fa-plus"></i> Crear Pedido Manual
                </a>
            <?php endif; ?>
        </div>
        
        <!-- Estadísticas -->
        <?php if ($action !== 'ver' && $action !== 'crear'): ?>
            <div class="stats-row">
                <div class="row text-center">
                    <div class="col-md-3">
                        <h3><?= number_format($stats['generales']['total_pedidos']) ?></h3>
                        <p>🛒 Total Pedidos (30 días)</p>
                    </div>
                    <div class="col-md-3">
                        <h3>$<?= number_format($stats['generales']['ingresos_total'], 0) ?></h3>
                        <p>💰 Ingresos Generados</p>
                    </div>
                    <div class="col-md-3">
                        <h3>$<?= number_format($stats['generales']['ticket_promedio'], 0) ?></h3>
                        <p>📊 Ticket Promedio</p>
                    </div>
                    <div class="col-md-3">
                        <h3><?= number_format($stats['generales']['pendientes']) ?></h3>
                        <p>⏰ Pedidos Pendientes</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Alertas -->
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($action === 'ver' && $pedido_detalle): ?>
            <!-- Detalle del pedido -->
            <div class="row">
                <div class="col-lg-8">
                    <!-- Información del pedido -->
                    <div class="admin-card">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5>
                                <i class="fas fa-file-invoice"></i> 
                                Pedido <?= htmlspecialchars($pedido_detalle['numero_pedido']) ?>
                            </h5>
                            <?php 
                            // DEBUG: Mostrar información del estado
                            $estado_real = $pedido_detalle['estado'];
                            echo "<!-- DEBUG DETALLE: Estado desde BD: '$estado_real' -->";
                            
                            // Verificar si el estado existe en el array
                            if (!isset($estados_pedido[$estado_real])) {
                                echo "<!-- ERROR DETALLE: Estado '$estado_real' no existe en estados_pedido -->";
                                echo "<!-- Estados disponibles: " . implode(', ', array_keys($estados_pedido)) . " -->";
                            }
                            
                            $estado_actual = $pedido_detalle['estado'] ?? 'pendiente';
                            $estado_info = $estados_pedido[$estado_actual] ?? $estados_pedido['pendiente'];
                            ?>
                            <span class="estado-badge bg-<?= $estado_info['color'] ?>">
                                <i class="fas fa-<?= $estado_info['icon'] ?>"></i>
                                <?= $estado_info['label'] ?>
                                <!-- DEBUG DETALLE: Mostrando '<?= $estado_actual ?>' para '<?= $estado_real ?>' -->
                            </span>
                        </div>
                        
                        <!-- Información del cliente -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h6><i class="fas fa-user"></i> Cliente</h6>
                                <p class="mb-1"><strong><?= htmlspecialchars($pedido_detalle['cliente_nombre']) ?></strong></p>
                                <p class="mb-1"><?= htmlspecialchars($pedido_detalle['cliente_email']) ?></p>
                                <p class="mb-0"><?= htmlspecialchars($pedido_detalle['cliente_telefono']) ?></p>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="fas fa-map-marker-alt"></i> Dirección de Envío</h6>
                                <?php if ($pedido_detalle['nombre_destinatario']): ?>
                                    <p class="mb-1"><?= htmlspecialchars($pedido_detalle['nombre_destinatario']) ?></p>
                                    <p class="mb-1"><?= htmlspecialchars($pedido_detalle['calle_numero']) ?></p>
                                    <p class="mb-1"><?= htmlspecialchars($pedido_detalle['colonia']) ?>, <?= htmlspecialchars($pedido_detalle['ciudad']) ?></p>
                                    <p class="mb-0"><?= htmlspecialchars($pedido_detalle['estado']) ?> - <?= htmlspecialchars($pedido_detalle['codigo_postal']) ?></p>
                                <?php else: ?>
                                    <p class="text-muted">Sin dirección especificada</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Items del pedido -->
                        <h6><i class="fas fa-list"></i> Productos</h6>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
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
                                                <div class="d-flex align-items-center">
                                                    <?php if ($item['imagen']): ?>
                                                        <img src="../assets/images/products/<?= htmlspecialchars($item['imagen']) ?>" 
                                                             alt="<?= htmlspecialchars($item['nombre_producto']) ?>" 
                                                             style="width: 40px; height: 40px; object-fit: cover; border-radius: 5px; margin-right: 10px;">
                                                    <?php else: ?>
                                                        <div style="width: 40px; height: 40px; background: #f8f9fa; border-radius: 5px; margin-right: 10px; display: flex; align-items: center; justify-content: center;">
                                                            <i class="fas fa-image text-muted"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <strong><?= htmlspecialchars($item['nombre_producto']) ?></strong>
                                                        <?php if ($item['clave_producto']): ?>
                                                            <br><small class="text-muted"><?= htmlspecialchars($item['clave_producto']) ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?= $item['cantidad'] ?></td>
                                            <td>$<?= number_format($item['precio_unitario'], 2) ?></td>
                                            <td><strong>$<?= number_format($item['subtotal'], 2) ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Totales -->
                        <div class="row justify-content-end">
                            <div class="col-md-4">
                                <table class="table table-sm">
                                    <tr>
                                        <td>Subtotal:</td>
                                        <td class="text-end">$<?= number_format($pedido_detalle['subtotal'], 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td>Impuestos:</td>
                                        <td class="text-end">$<?= number_format($pedido_detalle['impuestos'], 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td>Envío:</td>
                                        <td class="text-end">$<?= number_format($pedido_detalle['costo_envio'], 2) ?></td>
                                    </tr>
                                    <?php if ($pedido_detalle['descuentos'] > 0): ?>
                                        <tr>
                                            <td>Descuentos:</td>
                                            <td class="text-end text-success">-$<?= number_format($pedido_detalle['descuentos'], 2) ?></td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr class="table-dark">
                                        <td><strong>Total:</strong></td>
                                        <td class="text-end"><strong>$<?= number_format($pedido_detalle['total'], 2) ?></strong></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <!-- Actualizar estado -->
                    <div class="admin-card">
                        <h6><i class="fas fa-edit"></i> Actualizar Estado</h6>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="update_estado">
                            <input type="hidden" name="id_pedido" value="<?= $pedido_detalle['id'] ?>">
                            
                            <div class="mb-3">
                                <label class="form-label">Nuevo Estado</label>
                                <select class="form-select" name="nuevo_estado" required>
                                    <?php foreach ($estados_pedido as $valor => $info): ?>
                                        <option value="<?= $valor ?>" <?= $pedido_detalle['estado'] === $valor ? 'selected' : '' ?>>
                                            <?= $info['label'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Número de Seguimiento</label>
                                <input type="text" class="form-control" name="numero_seguimiento" 
                                       value="<?= htmlspecialchars($pedido_detalle['numero_seguimiento'] ?? '') ?>" 
                                       placeholder="ABC123456789">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Paquetería</label>
                                <select class="form-select" name="paqueteria">
                                    <option value="">Seleccionar paquetería</option>
                                    <option value="DHL" <?= $pedido_detalle['paqueteria'] === 'DHL' ? 'selected' : '' ?>>DHL</option>
                                    <option value="FedEx" <?= $pedido_detalle['paqueteria'] === 'FedEx' ? 'selected' : '' ?>>FedEx</option>
                                    <option value="Estafeta" <?= $pedido_detalle['paqueteria'] === 'Estafeta' ? 'selected' : '' ?>>Estafeta</option>
                                    <option value="Correos de México" <?= $pedido_detalle['paqueteria'] === 'Correos de México' ? 'selected' : '' ?>>Correos de México</option>
                                    <option value="Paquete Express" <?= $pedido_detalle['paqueteria'] === 'Paquete Express' ? 'selected' : '' ?>>Paquete Express</option>
                                    <option value="Entrega Personal" <?= $pedido_detalle['paqueteria'] === 'Entrega Personal' ? 'selected' : '' ?>>Entrega Personal</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Notas Internas</label>
                                <textarea class="form-control" name="notas_internas" rows="3" 
                                          placeholder="Notas para uso interno del equipo"><?= htmlspecialchars($pedido_detalle['notas_internas'] ?? '') ?></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-admin w-100">
                                <i class="fas fa-save"></i> Actualizar Estado
                            </button>
                        </form>
                    </div>
                    
                    <!-- Información adicional -->
                    <div class="admin-card">
                        <h6><i class="fas fa-info-circle"></i> Información Adicional</h6>
                        
                        <div class="mb-3">
                            <strong>Fecha de Pedido:</strong><br>
                            <?= date('d/m/Y H:i', strtotime($pedido_detalle['created_at'])) ?>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Método de Pago:</strong><br>
                            <?= htmlspecialchars($pedido_detalle['metodo_pago_usado'] ?? 'No especificado') ?>
                        </div>
                        
                        <?php if ($pedido_detalle['referencia_pago']): ?>
                            <div class="mb-3">
                                <strong>Referencia de Pago:</strong><br>
                                <?= htmlspecialchars($pedido_detalle['referencia_pago']) ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($pedido_detalle['notas_cliente']): ?>
                            <div class="mb-3">
                                <strong>Notas del Cliente:</strong><br>
                                <div class="bg-light p-2 rounded">
                                    <?= nl2br(htmlspecialchars($pedido_detalle['notas_cliente'])) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($pedido_detalle['fecha_estimada_entrega']): ?>
                            <div class="mb-3">
                                <strong>Fecha Estimada de Entrega:</strong><br>
                                <?= date('d/m/Y', strtotime($pedido_detalle['fecha_estimada_entrega'])) ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($pedido_detalle['fecha_entregado']): ?>
                            <div class="mb-3">
                                <strong>Fecha de Entrega:</strong><br>
                                <span class="text-success">
                                    <?= date('d/m/Y H:i', strtotime($pedido_detalle['fecha_entregado'])) ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="d-grid">
                        <a href="pedidos.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Volver a Lista
                        </a>
                    </div>
                </div>
            </div>
            
        <?php elseif ($action === 'crear'): ?>
            <!-- Crear pedido manual -->
            <div class="admin-card">
                <h5><i class="fas fa-plus"></i> Crear Pedido Manual</h5>
                
                <form method="POST" id="crearPedidoForm">
                    <input type="hidden" name="action" value="crear_pedido_manual">
                    <input type="hidden" name="productos" id="productos_json">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Cliente *</label>
                                <select class="form-select" name="id_cliente" required>
                                    <option value="">Seleccionar cliente</option>
                                    <?php foreach ($clientes as $cliente): ?>
                                        <option value="<?= $cliente['id'] ?>">
                                            <?= htmlspecialchars($cliente['nombre']) ?> - <?= htmlspecialchars($cliente['email']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Método de Pago</label>
                                <select class="form-select" name="metodo_pago">
                                    <option value="efectivo">Efectivo</option>
                                    <option value="tarjeta">Tarjeta</option>
                                    <option value="transferencia">Transferencia</option>
                                    <option value="paypal">PayPal</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Costo de Envío</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" class="form-control" name="costo_envio" step="0.01" min="0" value="0">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Notas del Cliente</label>
                                <textarea class="form-control" name="notas_cliente" rows="3" 
                                          placeholder="Instrucciones especiales del cliente"></textarea>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Seleccionar Productos</label>
                            <div class="mb-3">
                                <input type="text" class="form-control" id="buscar_producto" 
                                       placeholder="Buscar producto por nombre o clave...">
                            </div>
                            
                            <div class="producto-selector" id="lista_productos">
                                <?php foreach ($productos_disponibles as $producto): ?>
                                    <div class="producto-item" data-id="<?= $producto['id'] ?>" 
                                         data-nombre="<?= htmlspecialchars($producto['nombre']) ?>"
                                         data-precio="<?= $producto['precio'] ?>" 
                                         data-stock="<?= $producto['cantidad_etiquetas'] ?>"
                                         data-clave="<?= htmlspecialchars($producto['clave_producto']) ?>">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?= htmlspecialchars($producto['nombre']) ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($producto['clave_producto']) ?></small>
                                            </div>
                                            <div class="text-end">
                                                <div><strong>$<?= number_format($producto['precio'], 2) ?></strong></div>
                                                <small class="text-muted">Stock: <?= $producto['cantidad_etiquetas'] ?></small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Productos seleccionados -->
                    <div class="mt-4">
                        <h6><i class="fas fa-shopping-cart"></i> Productos Seleccionados</h6>
                        <div id="productos_seleccionados">
                            <p class="text-muted">No hay productos seleccionados</p>
                        </div>
                        
                        <!-- Resumen del pedido -->
                        <div class="row justify-content-end mt-3">
                            <div class="col-md-4">
                                <div class="bg-light p-3 rounded">
                                    <div class="d-flex justify-content-between">
                                        <span>Subtotal:</span>
                                        <span id="subtotal_display">$0.00</span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>IVA (16%):</span>
                                        <span id="iva_display">$0.00</span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Envío:</span>
                                        <span id="envio_display">$0.00</span>
                                    </div>
                                    <hr>
                                    <div class="d-flex justify-content-between">
                                        <strong>Total:</strong>
                                        <strong id="total_display">$0.00</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-admin" id="btn_crear_pedido" disabled>
                            <i class="fas fa-save"></i> Crear Pedido
                        </button>
                        <a href="pedidos.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                    </div>
                </form>
            </div>
            
        <?php else: ?>
            <!-- Lista de pedidos -->
            
            <!-- Filtros -->
            <div class="admin-card">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Buscar:</label>
                        <input type="text" class="form-control" name="search" 
                               value="<?= htmlspecialchars($search) ?>" placeholder="Número, cliente...">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Estado:</label>
                        <select class="form-select" name="estado">
                            <option value="">Todos</option>
                            <?php foreach ($estados_pedido as $valor => $info): ?>
                                <option value="<?= $valor ?>" <?= $estado_filter === $valor ? 'selected' : '' ?>>
                                    <?= $info['label'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Cliente:</label>
                        <select class="form-select" name="cliente">
                            <option value="">Todos</option>
                            <?php foreach ($clientes as $cliente): ?>
                                <option value="<?= $cliente['id'] ?>" <?= $cliente_filter == $cliente['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cliente['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Desde:</label>
                        <input type="date" class="form-control" name="fecha_desde" value="<?= $fecha_desde ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Hasta:</label>
                        <input type="date" class="form-control" name="fecha_hasta" value="<?= $fecha_hasta ?>">
                    </div>
                    
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="submit" class="btn btn-admin w-100">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </form>
                
                <?php if ($search || $estado_filter || $cliente_filter || $fecha_desde || $fecha_hasta): ?>
                    <div class="mt-3">
                        <a href="pedidos.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i> Limpiar filtros
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Lista de pedidos -->
            <div class="admin-card">
                <?php if (count($pedidos) > 0): ?>
                    <?php foreach ($pedidos as $pedido): ?>
                        <div class="pedido-card">
                            <div class="row align-items-center">
                                <div class="col-md-3">
                                    <h6 class="mb-1">
                                        <i class="fas fa-receipt"></i> 
                                        <?= htmlspecialchars($pedido['numero_pedido']) ?>
                                    </h6>
                                    <small class="text-muted">
                                        <?= date('d/m/Y H:i', strtotime($pedido['created_at'])) ?>
                                    </small>
                                </div>
                                
                                <div class="col-md-3">
                                    <strong><?= htmlspecialchars($pedido['cliente_nombre']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($pedido['cliente_email']) ?></small>
                                </div>
                                
                                <div class="col-md-2 text-center">
                                    <?php 
                                    // DEBUG: Mostrar información del estado LISTA
                                    $estado_real = $pedido['estado'];
                                    echo "<!-- DEBUG LISTA: Estado desde BD: '$estado_real' -->";
                                    
                                    // Verificar si el estado existe en el array
                                    if (!isset($estados_pedido[$estado_real])) {
                                        echo "<!-- ERROR LISTA: Estado '$estado_real' no existe en estados_pedido -->";
                                        echo "<!-- Estados disponibles: " . implode(', ', array_keys($estados_pedido)) . " -->";
                                    }
                                    
                                    $estado_actual = $pedido['estado'] ?? 'pendiente';
                                    $estado_info = $estados_pedido[$estado_actual] ?? $estados_pedido['pendiente'];
                                    ?>
                                    <span class="estado-badge bg-<?= $estado_info['color'] ?>">
                                        <i class="fas fa-<?= $estado_info['icon'] ?>"></i>
                                        <?= $estado_info['label'] ?>
                                        <!-- DEBUG LISTA: Mostrando '<?= $estado_actual ?>' para '<?= $estado_real ?>' -->
                                    </span>
                                </div>
                                
                                <div class="col-md-2 text-center">
                                    <h6 class="mb-0">$<?= number_format($pedido['total'], 2) ?></h6>
                                    <small class="text-muted"><?= $pedido['total_items'] ?> items</small>
                                </div>
                                
                                <div class="col-md-2 text-end">
                                    <div class="btn-group" role="group">
                                        <a href="pedidos.php?action=ver&id=<?= $pedido['id'] ?>" 
                                           class="btn btn-outline-primary btn-sm" title="Ver detalle">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($pedido['estado'] !== 'cancelado' && $pedido['estado'] !== 'entregado'): ?>
                                            <button type="button" class="btn btn-outline-warning btn-sm" 
                                                    onclick="cambiarEstadoRapido(<?= $pedido['id'] ?>, '<?= $pedido['numero_pedido'] ?>')" 
                                                    title="Cambiar estado">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="mt-3 text-muted">
                        Mostrando <?= count($pedidos) ?> pedido(s)
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                        <h5>No hay pedidos encontrados</h5>
                        <p class="text-muted">
                            <?php if ($search || $estado_filter || $cliente_filter || $fecha_desde || $fecha_hasta): ?>
                                No hay pedidos que coincidan con los filtros seleccionados.
                            <?php else: ?>
                                Aún no se han realizado pedidos en la tienda.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Estadísticas por estado -->
            <?php if (count($stats['por_estado']) > 0): ?>
                <div class="admin-card">
                    <h6><i class="fas fa-chart-pie"></i> Resumen por Estado (Últimos 30 días)</h6>
                    <div class="row">
                        <?php foreach ($stats['por_estado'] as $stat): ?>
                            <?php 
                            $estado_stat = $stat['estado'] ?? 'pendiente';
                            $estado_info_stat = $estados_pedido[$estado_stat] ?? $estados_pedido['pendiente'];
                            ?>
                            <div class="col-md-3 mb-3">
                                <div class="text-center p-3 border rounded">
                                    <h4 class="text-<?= $estado_info_stat['color'] ?>">
                                        <?= $stat['cantidad'] ?>
                                    </h4>
                                    <small><?= $estado_info_stat['label'] ?></small><br>
                                    <small class="text-muted">$<?= number_format($stat['monto_total'], 0) ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Modal para cambio rápido de estado -->
    <div class="modal fade" id="cambioEstadoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Cambiar Estado del Pedido</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="form-cambio-estado">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_estado">
                        <input type="hidden" name="id_pedido" id="modal_id_pedido">
                        
                        <p>Cambiar estado del pedido <strong id="modal_numero_pedido"></strong>:</p>
                        
                        <div class="mb-3">
                            <label class="form-label">Nuevo Estado</label>
                            <select class="form-select" name="nuevo_estado" required>
                                <?php foreach ($estados_pedido as $valor => $info): ?>
                                    <option value="<?= $valor ?>"><?= $info['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Notas (opcional)</label>
                            <textarea class="form-control" name="notas_internas" rows="2" 
                                      placeholder="Comentarios sobre el cambio de estado"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-admin">
                            <i class="fas fa-save"></i> Actualizar Estado
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Variables globales
        let productosSeleccionados = [];
        
        // Función para cambio rápido de estado
        function cambiarEstadoRapido(idPedido, numeroPedido) {
            document.getElementById('modal_id_pedido').value = idPedido;
            document.getElementById('modal_numero_pedido').textContent = numeroPedido;
            new bootstrap.Modal(document.getElementById('cambioEstadoModal')).show();
        }
        
        // === FUNCIONES PARA CREAR PEDIDO MANUAL ===
        
        // Buscar productos
        document.getElementById('buscar_producto')?.addEventListener('input', function() {
            const termino = this.value.toLowerCase();
            const productos = document.querySelectorAll('.producto-item');
            
            productos.forEach(producto => {
                const nombre = producto.dataset.nombre.toLowerCase();
                const clave = producto.dataset.clave.toLowerCase();
                
                if (nombre.includes(termino) || clave.includes(termino)) {
                    producto.style.display = 'block';
                } else {
                    producto.style.display = 'none';
                }
            });
        });
        
        // Seleccionar producto
        document.querySelectorAll('.producto-item').forEach(item => {
            item.addEventListener('click', function() {
                const id = parseInt(this.dataset.id);
                const nombre = this.dataset.nombre;
                const precio = parseFloat(this.dataset.precio);
                const stock = parseInt(this.dataset.stock);
                const clave = this.dataset.clave;
                
                // Verificar si ya está seleccionado
                const existe = productosSeleccionados.find(p => p.id === id);
                if (existe) {
                    alert('Este producto ya está seleccionado');
                    return;
                }
                
                // Agregar a la lista
                productosSeleccionados.push({
                    id: id,
                    nombre: nombre,
                    precio: precio,
                    cantidad: 1,
                    stock: stock,
                    clave: clave
                });
                
                this.classList.add('selected');
                actualizarProductosSeleccionados();
            });
        });
        
        // Actualizar lista de productos seleccionados
        function actualizarProductosSeleccionados() {
            const container = document.getElementById('productos_seleccionados');
            
            if (productosSeleccionados.length === 0) {
                container.innerHTML = '<p class="text-muted">No hay productos seleccionados</p>';
                document.getElementById('btn_crear_pedido').disabled = true;
                actualizarTotales();
                return;
            }
            
            let html = '<div class="table-responsive"><table class="table table-sm">';
            html += '<thead><tr><th>Producto</th><th>Precio</th><th>Cantidad</th><th>Subtotal</th><th>Acciones</th></tr></thead><tbody>';
            
            productosSeleccionados.forEach((producto, index) => {
                const subtotal = producto.precio * producto.cantidad;
                html += `
                    <tr>
                        <td>
                            <strong>${producto.nombre}</strong><br>
                            <small class="text-muted">${producto.clave}</small>
                        </td>
                        <td>${producto.precio.toFixed(2)}</td>
                        <td>
                            <div class="input-group input-group-sm" style="width: 120px;">
                                <button class="btn btn-outline-secondary" type="button" onclick="cambiarCantidad(${index}, -1)">-</button>
                                <input type="number" class="form-control text-center" value="${producto.cantidad}" 
                                       min="1" max="${producto.stock}" onchange="cambiarCantidadDirecta(${index}, this.value)">
                                <button class="btn btn-outline-secondary" type="button" onclick="cambiarCantidad(${index}, 1)">+</button>
                            </div>
                            <small class="text-muted">Stock: ${producto.stock}</small>
                        </td>
                        <td><strong>${subtotal.toFixed(2)}</strong></td>
                        <td>
                            <button class="btn btn-outline-danger btn-sm" onclick="eliminarProducto(${index})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            html += '</tbody></table></div>';
            container.innerHTML = html;
            
            document.getElementById('btn_crear_pedido').disabled = false;
            actualizarTotales();
        }
        
        // Cambiar cantidad
        function cambiarCantidad(index, delta) {
            const producto = productosSeleccionados[index];
            const nuevaCantidad = producto.cantidad + delta;
            
            if (nuevaCantidad >= 1 && nuevaCantidad <= producto.stock) {
                producto.cantidad = nuevaCantidad;
                actualizarProductosSeleccionados();
            }
        }
        
        // Cambiar cantidad directamente
        function cambiarCantidadDirecta(index, nuevaCantidad) {
            nuevaCantidad = parseInt(nuevaCantidad);
            const producto = productosSeleccionados[index];
            
            if (nuevaCantidad >= 1 && nuevaCantidad <= producto.stock) {
                producto.cantidad = nuevaCantidad;
                actualizarTotales();
            } else {
                actualizarProductosSeleccionados(); // Restaurar valor anterior
            }
        }
        
        // Eliminar producto
        function eliminarProducto(index) {
            const producto = productosSeleccionados[index];
            
            // Remover clase selected del elemento
            document.querySelectorAll('.producto-item').forEach(item => {
                if (parseInt(item.dataset.id) === producto.id) {
                    item.classList.remove('selected');
                }
            });
            
            productosSeleccionados.splice(index, 1);
            actualizarProductosSeleccionados();
        }
        
        // Actualizar totales
        function actualizarTotales() {
            const subtotal = productosSeleccionados.reduce((sum, p) => sum + (p.precio * p.cantidad), 0);
            const iva = subtotal * 0.16;
            const envio = parseFloat(document.querySelector('input[name="costo_envio"]')?.value || 0);
            const total = subtotal + iva + envio;
            
            document.getElementById('subtotal_display').textContent = `${subtotal.toFixed(2)}`;
            document.getElementById('iva_display').textContent = `${iva.toFixed(2)}`;
            document.getElementById('envio_display').textContent = `${envio.toFixed(2)}`;
            document.getElementById('total_display').textContent = `${total.toFixed(2)}`;
        }
        
        // Actualizar totales cuando cambie el costo de envío
        document.querySelector('input[name="costo_envio"]')?.addEventListener('input', actualizarTotales);
        
        // Enviar formulario de crear pedido
        document.getElementById('crearPedidoForm')?.addEventListener('submit', function(e) {
            if (productosSeleccionados.length === 0) {
                e.preventDefault();
                alert('Debe seleccionar al menos un producto');
                return;
            }
            
            // Convertir productos a JSON
            document.getElementById('productos_json').value = JSON.stringify(productosSeleccionados);
            
            // Mostrar loading
            const btn = document.getElementById('btn_crear_pedido');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creando...';
            btn.disabled = true;
            
            // Restaurar si hay error
            setTimeout(() => {
                if (btn.disabled) {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            }, 10000);
        });
        
        // Auto-submit en cambios de filtros
        document.querySelectorAll('select[name="estado"], select[name="cliente"]').forEach(select => {
            select.addEventListener('change', function() {
                this.form.submit();
            });
        });
        
        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            // Animar cards
            const cards = document.querySelectorAll('.admin-card, .pedido-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.4s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 50);
            });
            
            // Auto-ocultar alertas
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    if (alert.classList.contains('show')) {
                        alert.classList.remove('show');
                        setTimeout(() => alert.remove(), 150);
                    }
                });
            }, 5000);
        });
        
        // Validación de formularios
        document.getElementById('form-cambio-estado')?.addEventListener('submit', function(e) {
            const estado = this.querySelector('select[name="nuevo_estado"]').value;
            if (!estado) {
                e.preventDefault();
                alert('Debe seleccionar un estado');
                return;
            }
            
            // Mostrar loading
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Actualizando...';
            btn.disabled = true;
            
            // Restaurar si hay error
            setTimeout(() => {
                if (btn.disabled) {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            }, 5000);
        });
    </script>
</body>
</html>