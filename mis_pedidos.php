<?php
// mis_pedidos.php - Página de pedidos del usuario REAL
session_start();

// Verificar que el usuario esté logueado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php?redirect=mis_pedidos.php');
    exit();
}

require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

// Parámetros de filtrado
$filtro_estado = $_GET['estado'] ?? '';
$pagina = intval($_GET['pagina'] ?? 1);
$items_por_pagina = 10;
$offset = ($pagina - 1) * $items_por_pagina;

// Obtener pedidos del usuario
$pedidos = [];
$stats = [
    'total_pedidos' => 0,
    'pedidos_completados' => 0,
    'pedidos_pendientes' => 0,
    'total_gastado' => 0.00
];

try {
    // Construir consulta con filtros
    $where_clause = "WHERE p.id_cliente = ? AND p.activo = 1";
    $params = [$_SESSION['usuario_id']];
    
    if (!empty($filtro_estado)) {
        $where_clause .= " AND p.estado = ?";
        $params[] = $filtro_estado;
    }
    
    // Obtener pedidos principales
    $sql = "
        SELECT 
            p.id,
            p.numero_pedido,
            p.estado,
            p.subtotal,
            p.impuestos,
            p.costo_envio,
            p.descuentos,
            p.total,
            p.metodo_pago_usado,
            p.numero_seguimiento,
            p.paqueteria,
            p.fecha_estimada_entrega,
            p.fecha_entregado,
            p.created_at,
            p.updated_at,
            de.nombre_direccion,
            de.nombre_destinatario,
            de.calle_numero,
            de.ciudad,
            de.estado as estado_direccion,
            mp.nombre_tarjeta,
            mp.ultimos_4_digitos
        FROM pedidos p
        LEFT JOIN direcciones_envio de ON p.id_direccion_envio = de.id
        LEFT JOIN metodos_pago mp ON p.id_metodo_pago = mp.id
        $where_clause
        ORDER BY p.created_at DESC
        LIMIT $items_por_pagina OFFSET $offset
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $pedidos_raw = $stmt->fetchAll();
    
    // Obtener detalles de cada pedido
    foreach ($pedidos_raw as $pedido) {
        // Obtener items del pedido
        $stmt_items = $conn->prepare("
            SELECT 
                pd.nombre_producto,
                pd.descripcion_producto,
                pd.cantidad,
                pd.precio_unitario,
                pd.subtotal,
                p.clave_producto,
                p.id as producto_id
            FROM pedido_detalles pd
            LEFT JOIN productos p ON pd.id_producto = p.id
            WHERE pd.id_pedido = ?
            ORDER BY pd.id
        ");
        $stmt_items->execute([$pedido['id']]);
        $items = $stmt_items->fetchAll();
        
        // Obtener historial de estados
        $stmt_historial = $conn->prepare("
            SELECT 
                estado_anterior,
                estado_nuevo,
                comentarios,
                usuario_cambio,
                created_at
            FROM pedido_estados_historial 
            WHERE id_pedido = ? 
            ORDER BY created_at ASC
        ");
        $stmt_historial->execute([$pedido['id']]);
        $historial = $stmt_historial->fetchAll();
        
        $pedido['items'] = $items;
        $pedido['historial'] = $historial;
        $pedidos[] = $pedido;
    }
    
    // Obtener estadísticas generales
    $stmt_stats = $conn->prepare("
        SELECT 
            COUNT(CASE WHEN estado NOT IN ('cancelado', 'devuelto') THEN 1 END) as total_pedidos,
            SUM(CASE WHEN estado = 'entregado' THEN 1 ELSE 0 END) as pedidos_completados,
            SUM(CASE WHEN estado IN ('pendiente', 'confirmado', 'procesando', 'enviado', 'en_transito') THEN 1 ELSE 0 END) as pedidos_pendientes,
            COALESCE(SUM(CASE WHEN estado = 'entregado' THEN total ELSE 0 END), 0) as total_gastado
        FROM pedidos 
        WHERE id_cliente = ? AND activo = 1
    ");
    $stmt_stats->execute([$_SESSION['usuario_id']]);
    $stats_result = $stmt_stats->fetch();
    
    if ($stats_result) {
        $stats = [
            'total_pedidos' => intval($stats_result['total_pedidos']),
            'pedidos_completados' => intval($stats_result['pedidos_completados']),
            'pedidos_pendientes' => intval($stats_result['pedidos_pendientes']),
            'total_gastado' => floatval($stats_result['total_gastado'])
        ];
    }
    
    // Obtener total de registros para paginación
    $stmt_total = $conn->prepare("SELECT COUNT(*) as total FROM pedidos p $where_clause");
    $stmt_total->execute($params);
    $total_registros = $stmt_total->fetch()['total'];
    $total_paginas = ceil($total_registros / $items_por_pagina);
    
} catch (Exception $e) {
    error_log("Error obteniendo pedidos: " . $e->getMessage());
    $pedidos = [];
}

// Función para obtener el estado legible y su configuración
function obtenerEstadoInfo($estado) {
    $estados = [
        'pendiente' => [
            'texto' => 'Pendiente de Confirmación', 
            'clase' => 'warning', 
            'icono' => 'clock',
            'descripcion' => 'Tu pedido está siendo revisado'
        ],
        'confirmado' => [
            'texto' => 'Confirmado', 
            'clase' => 'info', 
            'icono' => 'check-circle',
            'descripcion' => 'Pedido confirmado, preparando envío'
        ],
        'procesando' => [
            'texto' => 'Procesando', 
            'clase' => 'info', 
            'icono' => 'cog',
            'descripcion' => 'Empacando tu pedido'
        ],
        'enviado' => [
            'texto' => 'Enviado', 
            'clase' => 'primary', 
            'icono' => 'shipping-fast',
            'descripcion' => 'Tu pedido está en camino'
        ],
        'en_transito' => [
            'texto' => 'En Tránsito', 
            'clase' => 'primary', 
            'icono' => 'truck',
            'descripcion' => 'En ruta a tu dirección'
        ],
        'entregado' => [
            'texto' => 'Entregado', 
            'clase' => 'success', 
            'icono' => 'check-circle',
            'descripcion' => 'Pedido entregado exitosamente'
        ],
        'cancelado' => [
            'texto' => 'Cancelado', 
            'clase' => 'danger', 
            'icono' => 'times-circle',
            'descripcion' => 'Pedido cancelado'
        ],
        'devuelto' => [
            'texto' => 'Devuelto', 
            'clase' => 'secondary', 
            'icono' => 'undo',
            'descripcion' => 'Producto devuelto'
        ]
    ];
    
    return $estados[$estado] ?? [
        'texto' => 'Desconocido', 
        'clase' => 'secondary', 
        'icono' => 'question',
        'descripcion' => 'Estado no reconocido'
    ];
}

// Función para obtener estados únicos para el filtro
try {
    $stmt_estados = $conn->prepare("
        SELECT DISTINCT estado 
        FROM pedidos 
        WHERE id_cliente = ? AND activo = 1 
        ORDER BY 
            CASE estado
                WHEN 'pendiente' THEN 1
                WHEN 'confirmado' THEN 2
                WHEN 'procesando' THEN 3
                WHEN 'enviado' THEN 4
                WHEN 'en_transito' THEN 5
                WHEN 'entregado' THEN 6
                WHEN 'cancelado' THEN 7
                WHEN 'devuelto' THEN 8
                ELSE 9
            END
    ");
    $stmt_estados->execute([$_SESSION['usuario_id']]);
    $estados_disponibles = $stmt_estados->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $estados_disponibles = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Pedidos - Novedades Ashley</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        .orders-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 60px 0 40px 0;
        }
        
        .orders-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            padding: 25px;
            margin-bottom: 25px;
            transition: all 0.3s ease;
            border-left: 5px solid #667eea;
        }
        
        .orders-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            transform: translateY(-3px);
        }
        
        .stat-card {
            background: linear-gradient(45deg, #f8f9fa, #e9ecef);
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            height: 100%;
            border: none;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
        }
        
        .order-header {
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .order-number {
            font-size: 1.2rem;
            font-weight: bold;
            color: #333;
        }
        
        .order-date {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .order-total {
            font-size: 1.3rem;
            font-weight: bold;
            color: #28a745;
        }
        
        .status-badge {
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.85rem;
            border: none;
        }
        
        .item-row {
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .item-row:last-child {
            border-bottom: none;
        }
        
        .empty-orders {
            text-align: center;
            padding: 80px 20px;
        }
        
        .empty-orders i {
            font-size: 4rem;
            color: #6c757d;
            margin-bottom: 20px;
        }
        
        .btn-action {
            border-radius: 20px;
            padding: 8px 20px;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .btn-action:hover {
            transform: translateY(-2px);
        }
        
        .filter-section {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .breadcrumb {
            background: none;
            margin-bottom: 0;
        }
        
        .tracking-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .timeline::before {
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
            left: -8px;
            top: 5px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #6c757d;
            border: 3px solid white;
            box-shadow: 0 0 0 2px #dee2e6;
        }
        
        .timeline-item.active::before {
            background: #28a745;
            box-shadow: 0 0 0 2px #28a745;
        }
        
        @media (max-width: 768px) {
            .orders-header {
                padding: 40px 0 20px 0;
            }
            
            .orders-card {
                padding: 20px;
                margin-bottom: 20px;
            }
            
            .order-header {
                text-align: center;
            }
            
            .stat-card {
                margin-bottom: 15px;
            }
        }
    </style>
</head>
<body>
    <!-- Navegación -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-crown me-2"></i> Novedades Ashley
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">Inicio</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="productos.php">Productos</a>
                    </li>
                </ul>
                
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="carrito.php">
                            <i class="fas fa-shopping-cart"></i> Carrito
                            <span class="badge bg-danger d-none" id="cart-count">0</span>
                        </a>
                    </li>
                    
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?= htmlspecialchars($_SESSION['usuario_nombre']) ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="perfil.php">Mi Perfil</a></li>
                            <li><a class="dropdown-item active" href="mis_pedidos.php">Mis Pedidos</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="#" onclick="cerrarSesion()">Cerrar Sesión</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Header de pedidos -->
    <section class="orders-header">
        <div class="container">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php" class="text-light">Inicio</a></li>
                    <li class="breadcrumb-item"><a href="perfil.php" class="text-light">Mi Perfil</a></li>
                    <li class="breadcrumb-item active text-light">Mis Pedidos</li>
                </ol>
            </nav>
            
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="display-5 mb-3">
                        <i class="fas fa-history me-3"></i> Mis Pedidos
                    </h1>
                    <p class="lead">Revisa el estado y detalles de todos tus pedidos</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="bg-white text-dark rounded p-3 d-inline-block">
                        <h3 class="mb-0"><?= $stats['total_pedidos'] ?></h3>
                        <small>Pedidos Total</small>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Estadísticas -->
    <section class="py-4 bg-light">
        <div class="container">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-number"><?= $stats['total_pedidos'] ?></div>
                        <small class="text-muted"><i class="fas fa-shopping-bag"></i> Total Pedidos</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-number"><?= $stats['pedidos_completados'] ?></div>
                        <small class="text-muted"><i class="fas fa-check-circle"></i> Completados</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-number"><?= $stats['pedidos_pendientes'] ?></div>
                        <small class="text-muted"><i class="fas fa-clock"></i> Pendientes</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-number">$<?= number_format($stats['total_gastado'], 0) ?></div>
                        <small class="text-muted"><i class="fas fa-dollar-sign"></i> Total Gastado</small>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contenido de pedidos -->
    <section class="py-5">
        <div class="container">
            <?php if (count($estados_disponibles) > 0): ?>
                <!-- Filtros -->
                <div class="filter-section">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5 class="mb-3 mb-md-0">
                                <i class="fas fa-filter"></i> Filtrar Pedidos
                            </h5>
                        </div>
                        <div class="col-md-4">
                            <form method="GET" class="d-flex">
                                <select name="estado" class="form-select me-2" onchange="this.form.submit()">
                                    <option value="">Todos los estados</option>
                                    <?php foreach ($estados_disponibles as $estado): ?>
                                        <?php $estado_info = obtenerEstadoInfo($estado); ?>
                                        <option value="<?= $estado ?>" <?= $filtro_estado === $estado ? 'selected' : '' ?>>
                                            <?= $estado_info['texto'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($filtro_estado): ?>
                                    <a href="mis_pedidos.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-times"></i>
                                    </a>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (count($pedidos) > 0): ?>
                <!-- Lista de pedidos -->
                <div id="pedidos-container">
                    <?php foreach ($pedidos as $pedido): ?>
                        <?php $estado_info = obtenerEstadoInfo($pedido['estado']); ?>
                        <div class="orders-card" data-estado="<?= $pedido['estado'] ?>">
                            <div class="order-header">
                                <div class="row align-items-center">
                                    <div class="col-md-4">
                                        <div class="order-number"><?= htmlspecialchars($pedido['numero_pedido']) ?></div>
                                        <div class="order-date">
                                            <i class="fas fa-calendar"></i> 
                                            <?= date('d/m/Y H:i', strtotime($pedido['created_at'])) ?>
                                        </div>
                                        <?php if ($pedido['updated_at'] != $pedido['created_at']): ?>
                                            <small class="text-muted">
                                                Actualizado: <?= date('d/m/Y H:i', strtotime($pedido['updated_at'])) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-4 text-center">
                                        <span class="badge bg-<?= $estado_info['clase'] ?> status-badge">
                                            <i class="fas fa-<?= $estado_info['icono'] ?>"></i> 
                                            <?= $estado_info['texto'] ?>
                                        </span>
                                        <div class="mt-1">
                                            <small class="text-muted"><?= $estado_info['descripcion'] ?></small>
                                        </div>
                                    </div>
                                    <div class="col-md-4 text-md-end">
                                        <div class="order-total">$<?= number_format($pedido['total'], 2) ?></div>
                                        <small class="text-muted"><?= count($pedido['items']) ?> artículo(s)</small>
                                        <?php if ($pedido['descuentos'] > 0): ?>
                                            <div><small class="text-success">Descuento: -$<?= number_format($pedido['descuentos'], 2) ?></small></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Información de envío y pago -->
                            <?php if ($pedido['nombre_direccion'] || $pedido['nombre_tarjeta']): ?>
                                <div class="row mb-3">
                                    <?php if ($pedido['nombre_direccion']): ?>
                                        <div class="col-md-6">
                                            <h6><i class="fas fa-map-marker-alt text-info"></i> Dirección de Envío:</h6>
                                            <p class="mb-1">
                                                <strong><?= htmlspecialchars($pedido['nombre_destinatario']) ?></strong><br>
                                                <?= htmlspecialchars($pedido['calle_numero']) ?><br>
                                                <?= htmlspecialchars($pedido['ciudad']) ?>, <?= htmlspecialchars($pedido['estado_direccion']) ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($pedido['nombre_tarjeta']): ?>
                                        <div class="col-md-6">
                                            <h6><i class="fas fa-credit-card text-success"></i> Método de Pago:</h6>
                                            <p class="mb-1">
                                                <?= htmlspecialchars($pedido['nombre_tarjeta']) ?> 
                                                ••••<?= htmlspecialchars($pedido['ultimos_4_digitos']) ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Información de envío -->
                            <?php if ($pedido['numero_seguimiento'] || $pedido['fecha_estimada_entrega']): ?>
                                <div class="tracking-info">
                                    <h6><i class="fas fa-truck"></i> Información de Envío:</h6>
                                    <div class="row">
                                        <?php if ($pedido['numero_seguimiento']): ?>
                                            <div class="col-md-6">
                                                <strong>Número de seguimiento:</strong><br>
                                                <code><?= htmlspecialchars($pedido['numero_seguimiento']) ?></code>
                                                <?php if ($pedido['paqueteria']): ?>
                                                    <small class="text-muted d-block">Paquetería: <?= htmlspecialchars($pedido['paqueteria']) ?></small>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($pedido['fecha_estimada_entrega']): ?>
                                            <div class="col-md-6">
                                                <strong>Fecha estimada de entrega:</strong><br>
                                                <?= date('d/m/Y', strtotime($pedido['fecha_estimada_entrega'])) ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($pedido['fecha_entregado']): ?>
                                            <div class="col-md-6">
                                                <strong>Fecha de entrega:</strong><br>
                                                <span class="text-success">
                                                    <?= date('d/m/Y H:i', strtotime($pedido['fecha_entregado'])) ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Detalles del pedido -->
                            <div class="order-details">
                                <h6 class="mb-3"><i class="fas fa-list"></i> Artículos del pedido:</h6>
                                
                                <?php foreach ($pedido['items'] as $item): ?>
                                    <div class="item-row">
                                        <div class="row align-items-center">
                                            <div class="col-md-6">
                                                <strong><?= htmlspecialchars($item['nombre_producto']) ?></strong>
                                                <?php if ($item['descripcion_producto']): ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars($item['descripcion_producto']) ?></small>
                                                <?php endif; ?>
                                                <?php if ($item['clave_producto']): ?>
                                                    <br><small class="text-muted">Código: <?= htmlspecialchars($item['clave_producto']) ?></small>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-md-2 text-center">
                                                <span class="badge bg-light text-dark">
                                                    Qty: <?= $item['cantidad'] ?>
                                                </span>
                                            </div>
                                            <div class="col-md-2 text-center">
                                                $<?= number_format($item['precio_unitario'], 2) ?> c/u
                                            </div>
                                            <div class="col-md-2 text-end">
                                                <strong>$<?= number_format($item['subtotal'], 2) ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                
                                <!-- Resumen de totales -->
                                <div class="row mt-3 pt-3 border-top">
                                    <div class="col-md-8"></div>
                                    <div class="col-md-4">
                                        <div class="d-flex justify-content-between">
                                            <span>Subtotal:</span>
                                            <span>$<?= number_format($pedido['subtotal'], 2) ?></span>
                                        </div>
                                        <?php if ($pedido['descuentos'] > 0): ?>
                                            <div class="d-flex justify-content-between text-success">
                                                <span>Descuentos:</span>
                                                <span>-$<?= number_format($pedido['descuentos'], 2) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($pedido['costo_envio'] > 0): ?>
                                            <div class="d-flex justify-content-between">
                                                <span>Envío:</span>
                                                <span>$<?= number_format($pedido['costo_envio'], 2) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($pedido['impuestos'] > 0): ?>
                                            <div class="d-flex justify-content-between">
                                                <span>IVA:</span>
                                                <span>$<?= number_format($pedido['impuestos'], 2) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <hr>
                                        <div class="d-flex justify-content-between">
                                            <strong>Total:</strong>
                                            <strong class="text-success">$<?= number_format($pedido['total'], 2) ?></strong>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Historial de estados (si existe) -->
                            <?php if (count($pedido['historial']) > 1): ?>
                                <div class="mt-4">
                                    <h6><i class="fas fa-history"></i> Historial del Pedido:</h6>
                                    <div class="timeline">
                                        <?php foreach ($pedido['historial'] as $index => $cambio): ?>
                                            <div class="timeline-item <?= $index === count($pedido['historial']) - 1 ? 'active' : '' ?>">
                                                <div class="d-flex justify-content-between">
                                                    <div>
                                                        <strong><?= obtenerEstadoInfo($cambio['estado_nuevo'])['texto'] ?></strong>
                                                        <?php if ($cambio['comentarios']): ?>
                                                            <div class="text-muted small"><?= htmlspecialchars($cambio['comentarios']) ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?= date('d/m/Y H:i', strtotime($cambio['created_at'])) ?>
                                                    </small>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Acciones del pedido -->
                            <div class="order-actions mt-4 pt-3 border-top">
                                <div class="d-flex justify-content-between align-items-center flex-wrap">
                                    <div class="mb-2 mb-md-0">
                                        <small class="text-muted">
                                            <i class="fas fa-info-circle"></i> 
                                            ID del pedido: #<?= $pedido['id'] ?>
                                        </small>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <button class="btn btn-outline-primary btn-action btn-sm" 
                                                onclick="verDetallesPedido(<?= $pedido['id'] ?>)">
                                            <i class="fas fa-eye"></i> Ver Detalles
                                        </button>
                                        
                                        <?php if ($pedido['estado'] === 'entregado'): ?>
                                            <button class="btn btn-outline-success btn-action btn-sm" 
                                                    onclick="descargarFactura(<?= $pedido['id'] ?>)">
                                                <i class="fas fa-download"></i> Factura
                                            </button>
                                            <button class="btn btn-outline-warning btn-action btn-sm" 
                                                    onclick="recomprarPedido(<?= $pedido['id'] ?>)">
                                                <i class="fas fa-redo"></i> Volver a Comprar
                                            </button>
                                            <button class="btn btn-outline-info btn-action btn-sm" 
                                                    onclick="dejarResena(<?= $pedido['id'] ?>)">
                                                <i class="fas fa-star"></i> Reseña
                                            </button>
                                        <?php elseif (in_array($pedido['estado'], ['pendiente', 'confirmado'])): ?>
                                            <button class="btn btn-outline-warning btn-action btn-sm" 
                                                    onclick="editarPedido(<?= $pedido['id'] ?>)">
                                                <i class="fas fa-edit"></i> Editar
                                            </button>
                                            <button class="btn btn-outline-danger btn-action btn-sm" 
                                                    onclick="cancelarPedido(<?= $pedido['id'] ?>)">
                                                <i class="fas fa-times"></i> Cancelar
                                            </button>
                                        <?php elseif (in_array($pedido['estado'], ['procesando', 'enviado', 'en_transito'])): ?>
                                            <?php if ($pedido['numero_seguimiento']): ?>
                                                <button class="btn btn-outline-info btn-action btn-sm" 
                                                        onclick="rastrearPedido('<?= $pedido['numero_seguimiento'] ?>', '<?= $pedido['paqueteria'] ?>')">
                                                    <i class="fas fa-map-marker-alt"></i> Rastrear
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn btn-outline-secondary btn-action btn-sm" 
                                                    onclick="contactarSoporte(<?= $pedido['id'] ?>)">
                                                <i class="fas fa-headset"></i> Soporte
                                            </button>
                                        <?php endif; ?>
                                        
                                        <!-- Botón de recompra siempre disponible -->
                                        <button class="btn btn-outline-primary btn-action btn-sm" 
                                                onclick="recomprarPedido(<?= $pedido['id'] ?>)">
                                            <i class="fas fa-shopping-cart"></i> Recomprar
                                        </button>
                                        
                                        <!-- NUEVO: Botón de factura para todos los pedidos completados -->
                                        <?php if (in_array($pedido['estado'], ['entregado', 'confirmado', 'procesando', 'enviado', 'en_transito'])): ?>
                                            <button class="btn btn-outline-secondary btn-action btn-sm" 
                                                    onclick="verFactura(<?= $pedido['id'] ?>)">
                                                <i class="fas fa-file-invoice"></i> Ver Factura
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Paginación -->
                <?php if ($total_paginas > 1): ?>
                    <nav aria-label="Paginación de pedidos" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <!-- Página anterior -->
                            <?php if ($pagina > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?pagina=<?= $pagina - 1 ?><?= $filtro_estado ? '&estado=' . $filtro_estado : '' ?>">
                                        <i class="fas fa-chevron-left"></i> Anterior
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <!-- Números de página -->
                            <?php 
                            $inicio = max(1, $pagina - 2);
                            $fin = min($total_paginas, $pagina + 2);
                            
                            if ($inicio > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?pagina=1<?= $filtro_estado ? '&estado=' . $filtro_estado : '' ?>">1</a>
                                </li>
                                <?php if ($inicio > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $inicio; $i <= $fin; $i++): ?>
                                <li class="page-item <?= $i === $pagina ? 'active' : '' ?>">
                                    <a class="page-link" href="?pagina=<?= $i ?><?= $filtro_estado ? '&estado=' . $filtro_estado : '' ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($fin < $total_paginas): ?>
                                <?php if ($fin < $total_paginas - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="?pagina=<?= $total_paginas ?><?= $filtro_estado ? '&estado=' . $filtro_estado : '' ?>"><?= $total_paginas ?></a>
                                </li>
                            <?php endif; ?>
                            
                            <!-- Página siguiente -->
                            <?php if ($pagina < $total_paginas): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?pagina=<?= $pagina + 1 ?><?= $filtro_estado ? '&estado=' . $filtro_estado : '' ?>">
                                        Siguiente <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    
                    <div class="text-center text-muted">
                        Mostrando <?= ($pagina - 1) * $items_por_pagina + 1 ?> - <?= min($pagina * $items_por_pagina, $total_registros) ?> 
                        de <?= $total_registros ?> pedidos
                    </div>
                <?php endif; ?>
                
            <?php else: ?>
                <!-- Sin pedidos -->
                <div class="empty-orders">
                    <i class="fas fa-shopping-bag"></i>
                    <h3>
                        <?php if ($filtro_estado): ?>
                            No tienes pedidos con estado "<?= obtenerEstadoInfo($filtro_estado)['texto'] ?>"
                        <?php else: ?>
                            No tienes pedidos aún
                        <?php endif; ?>
                    </h3>
                    <p class="text-muted mb-4">
                        <?php if ($filtro_estado): ?>
                            Intenta cambiar el filtro o explorar nuestros productos.
                        <?php else: ?>
                            ¡Es un buen momento para explorar nuestros productos y hacer tu primera compra!
                        <?php endif; ?>
                    </p>
                    
                    <div class="d-flex justify-content-center gap-3 flex-wrap">
                        <?php if ($filtro_estado): ?>
                            <a href="mis_pedidos.php" class="btn btn-outline-primary">
                                <i class="fas fa-filter me-2"></i> Ver Todos los Pedidos
                            </a>
                        <?php endif; ?>
                        <a href="productos.php" class="btn btn-primary">
                            <i class="fas fa-shopping-cart me-2"></i> Explorar Productos
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-dark text-light py-4">
        <div class="container text-center">
            <p class="mb-0">
                <a href="index.php" class="text-light text-decoration-none">
                    <i class="fas fa-crown me-2"></i> Novedades Ashley
                </a>
                - "Descubre lo nuevo, siente la diferencia"
            </p>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Carrito JS -->
    <script src="assets/js/carrito.js"></script>
    
    <script>
        // Función para ver detalles del pedido
        function verDetallesPedido(idPedido) {
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.innerHTML = `
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-receipt"></i> Detalles del Pedido #${idPedido}
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="text-center">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Cargando...</span>
                                </div>
                                <p class="mt-2">Cargando detalles del pedido...</p>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();
            
            setTimeout(() => {
                modal.querySelector('.modal-body').innerHTML = `
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle"></i> Información Completa del Pedido</h6>
                        <p>Esta función mostraría información detallada como:</p>
                        <ul>
                            <li>Historial completo de estados</li>
                            <li>Información de facturación</li>
                            <li>Detalles de envío</li>
                            <li>Método de pago utilizado</li>
                            <li>Comunicaciones relacionadas</li>
                        </ul>
                        <p class="mb-0"><strong>Estado:</strong> Por implementar con API específica</p>
                    </div>
                `;
            }, 1500);
            
            modal.addEventListener('hidden.bs.modal', () => {
                modal.remove();
            });
        }
        
        // Función para descargar factura (ACTUALIZADA)
        function descargarFactura(idPedido) {
            // Abrir factura en nueva ventana
            window.open(`generar_factura.php?order_id=${idPedido}&download=view`, 
                        '_blank', 
                        'width=800,height=900,scrollbars=yes,resizable=yes');
        }

        // Nueva función para ver factura
        function verFactura(idPedido) {
            // Abrir factura en nueva ventana para visualización
            window.open(`generar_factura.php?order_id=${idPedido}&download=view`, 
                        '_blank', 
                        'width=900,height=1000,scrollbars=yes,resizable=yes,toolbar=yes');
        }

        // Función para imprimir factura directamente
        function imprimirFactura(idPedido) {
            // Abrir factura en modo impresión
            window.open(`generar_factura.php?order_id=${idPedido}&download=print`, 
                        '_blank', 
                        'width=800,height=900,scrollbars=yes,resizable=yes');
        }
        
        // Función para cancelar pedido
        function cancelarPedido(idPedido) {
            const motivo = prompt('¿Por qué deseas cancelar este pedido?\n(Opcional - nos ayuda a mejorar)');
            
            if (motivo !== null) {
                if (confirm('¿Estás seguro de que quieres cancelar este pedido?\n\nEsta acción no se puede deshacer.')) {
                    alert(`Cancelando pedido #${idPedido}...\n\n` +
                          'Esta función:\n' +
                          '• Cambiaría el estado a "Cancelado"\n' +
                          '• Procesaría el reembolso si aplica\n' +
                          '• Enviaría notificación por email\n' +
                          `• Registraría el motivo: "${motivo}"\n\n` +
                          'Estado: Por implementar');
                }
            }
        }
        
        // Función para rastrear pedido
        function rastrearPedido(numeroSeguimiento, paqueteria = '') {
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.innerHTML = `
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-truck"></i> Rastrear Envío
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-info">
                                <h6>Información de Rastreo</h6>
                                <p><strong>Número de seguimiento:</strong> <code>${numeroSeguimiento}</code></p>
                                ${paqueteria ? `<p><strong>Paquetería:</strong> ${paqueteria}</p>` : ''}
                                <p>Esta función abriría el sitio web de la paquetería para rastrear el envío en tiempo real.</p>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                            <button type="button" class="btn btn-primary" onclick="window.open('https://www.google.com/search?q=rastrear+${numeroSeguimiento}', '_blank')">
                                <i class="fas fa-external-link-alt"></i> Abrir Rastreador
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();
            
            modal.addEventListener('hidden.bs.modal', () => {
                modal.remove();
            });
        }
        
        // Función para contactar soporte
        function contactarSoporte(idPedido) {
            alert(`Contactando soporte para el pedido #${idPedido}\n\n` +
                  'Esta función abriría:\n' +
                  '• Chat en vivo con soporte\n' +
                  '• Formulario de contacto pre-llenado\n' +
                  '• WhatsApp Business con contexto\n' +
                  '• Email automático al equipo de atención\n\n' +
                  'Estado: Por implementar');
        }
        
        // Función para dejar reseña
        function dejarResena(idPedido) {
            alert(`Sistema de reseñas para pedido #${idPedido}\n\n` +
                  'Esta función permitiría:\n' +
                  '• Calificar cada producto (1-5 estrellas)\n' +
                  '• Escribir comentarios detallados\n' +
                  '• Subir fotos del producto recibido\n' +
                  '• Recomendar o no el producto\n\n' +
                  'Estado: Por implementar');
        }
        
        // Función para editar pedido
        function editarPedido(idPedido) {
            alert(`Editar pedido #${idPedido}\n\n` +
                  'Esta función permitiría:\n' +
                  '• Cambiar dirección de envío\n' +
                  '• Modificar método de pago\n' +
                  '• Agregar notas especiales\n' +
                  '• Actualizar información de contacto\n\n' +
                  'Nota: Solo disponible para pedidos pendientes de confirmación\n\n' +
                  'Estado: Por implementar');
        }
        
        // Función para cerrar sesión - CORREGIDA
async function cerrarSesion() {
    if (!confirm('¿Estás seguro de que quieres cerrar sesión?')) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'logout');
        
        const response = await fetch('api/auth.php', {
            method: 'POST',
            body: formData
        });
        
        // NUEVO: Verificar que la respuesta sea válida
        if (!response.ok) {
            throw new Error(`Error HTTP: ${response.status}`);
        }
        
        // NUEVO: Verificar que sea JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('El servidor no devolvió JSON válido');
        }
        
        const data = await response.json();
        
        if (data.success) {
            alert('Sesión cerrada correctamente');
            // CORREGIDO: Redirigir al index, no recargar
            window.location.href = 'index.php';
        } else {
            alert('Error al cerrar sesión: ' + data.message);
        }
        
    } catch (error) {
        console.error('Error completo:', error);
        
        // NUEVO: Manejo de errores más específico
        if (error.name === 'TypeError' && error.message.includes('Failed to fetch')) {
            alert('Error de conexión. Verifica tu internet.');
        } else if (error.message.includes('JSON')) {
            alert('Error del servidor. Intenta nuevamente.');
        } else {
            alert('Error al cerrar sesión: ' + error.message);
        }
    }
}
        
        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            // Actualizar contador del carrito
            if (typeof actualizarContadorCarrito === 'function') {
                actualizarContadorCarrito();
            }
            
            // Animación de entrada para las cards
            const cards = document.querySelectorAll('.orders-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(30px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
            
            // Animación para las estadísticas
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.4s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 50);
            });
        });
    </script>
</body>
</html>