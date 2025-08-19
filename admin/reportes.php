<?php
// admin/reportes.php - Sistema completo de reportes y análisis
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
$action = $_GET['action'] ?? 'dashboard';

// Obtener parámetros de filtro
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01'); // Primer día del mes actual
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d'); // Día actual
$periodo = $_GET['periodo'] ?? 'mes_actual';

// Ajustar fechas según período seleccionado
switch ($periodo) {
    case 'hoy':
        $fecha_inicio = $fecha_fin = date('Y-m-d');
        break;
    case 'ayer':
        $fecha_inicio = $fecha_fin = date('Y-m-d', strtotime('-1 day'));
        break;
    case 'semana_actual':
        $fecha_inicio = date('Y-m-d', strtotime('monday this week'));
        $fecha_fin = date('Y-m-d');
        break;
    case 'semana_pasada':
        $fecha_inicio = date('Y-m-d', strtotime('monday last week'));
        $fecha_fin = date('Y-m-d', strtotime('sunday last week'));
        break;
    case 'mes_actual':
        $fecha_inicio = date('Y-m-01');
        $fecha_fin = date('Y-m-d');
        break;
    case 'mes_pasado':
        $fecha_inicio = date('Y-m-01', strtotime('first day of last month'));
        $fecha_fin = date('Y-m-t', strtotime('last day of last month'));
        break;
    case 'ultimos_30_dias':
        $fecha_inicio = date('Y-m-d', strtotime('-30 days'));
        $fecha_fin = date('Y-m-d');
        break;
    case 'ano_actual':
        $fecha_inicio = date('Y-01-01');
        $fecha_fin = date('Y-m-d');
        break;
}

// === FUNCIONES PARA OBTENER DATOS ===

function obtenerVentasPorDia($conn, $fecha_inicio, $fecha_fin) {
    try {
        $stmt = $conn->prepare("
            SELECT DATE(created_at) as fecha, 
                   COUNT(*) as pedidos,
                   SUM(total) as ingresos
            FROM pedidos 
            WHERE DATE(created_at) BETWEEN ? AND ? 
            AND activo = 1 
            AND estado NOT IN ('cancelado', 'devuelto')
            GROUP BY DATE(created_at)
            ORDER BY fecha ASC
        ");
        $stmt->execute([$fecha_inicio, $fecha_fin]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function obtenerVentasPorCategoria($conn, $fecha_inicio, $fecha_fin) {
    try {
        $stmt = $conn->prepare("
            SELECT c.nombre as categoria,
                   c.color_categoria,
                   COUNT(DISTINCT p.id) as pedidos,
                   SUM(pd.cantidad) as productos_vendidos,
                   SUM(pd.subtotal) as ingresos
            FROM pedidos p
            JOIN pedido_detalles pd ON p.id = pd.id_pedido
            JOIN productos pr ON pd.id_producto = pr.id
            LEFT JOIN categorias c ON pr.categoria_id = c.id
            WHERE DATE(p.created_at) BETWEEN ? AND ?
            AND p.activo = 1 
            AND p.estado NOT IN ('cancelado', 'devuelto')
            GROUP BY c.id, c.nombre, c.color_categoria
            ORDER BY ingresos DESC
        ");
        $stmt->execute([$fecha_inicio, $fecha_fin]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function obtenerProductosMasVendidos($conn, $fecha_inicio, $fecha_fin, $limit = 10) {
    try {
        $stmt = $conn->prepare("
            SELECT pr.nombre,
                   pr.clave_producto,
                   pr.precio,
                   pr.imagen,
                   c.nombre as categoria,
                   SUM(pd.cantidad) as cantidad_vendida,
                   SUM(pd.subtotal) as ingresos_generados
            FROM pedidos p
            JOIN pedido_detalles pd ON p.id = pd.id_pedido
            JOIN productos pr ON pd.id_producto = pr.id
            LEFT JOIN categorias c ON pr.categoria_id = c.id
            WHERE DATE(p.created_at) BETWEEN ? AND ?
            AND p.activo = 1 
            AND p.estado NOT IN ('cancelado', 'devuelto')
            GROUP BY pr.id
            ORDER BY cantidad_vendida DESC
            LIMIT ?
        ");
        $stmt->execute([$fecha_inicio, $fecha_fin, $limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function obtenerEstadisticasGenerales($conn, $fecha_inicio, $fecha_fin) {
    try {
        // Estadísticas principales
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_pedidos,
                SUM(CASE WHEN estado NOT IN ('cancelado', 'devuelto') THEN total ELSE 0 END) as ingresos_totales,
                AVG(CASE WHEN estado NOT IN ('cancelado', 'devuelto') THEN total END) as ticket_promedio,
                COUNT(DISTINCT id_cliente) as clientes_unicos,
                SUM(CASE WHEN estado = 'cancelado' THEN 1 ELSE 0 END) as pedidos_cancelados,
                SUM(CASE WHEN estado = 'devuelto' THEN 1 ELSE 0 END) as pedidos_devueltos,
                SUM(CASE WHEN estado = 'entregado' THEN 1 ELSE 0 END) as pedidos_completados
            FROM pedidos 
            WHERE DATE(created_at) BETWEEN ? AND ? 
            AND activo = 1
        ");
        $stmt->execute([$fecha_inicio, $fecha_fin]);
        $stats = $stmt->fetch();
        
        // Productos más vendidos (cantidad)
        $stmt = $conn->prepare("
            SELECT SUM(pd.cantidad) as productos_vendidos
            FROM pedidos p
            JOIN pedido_detalles pd ON p.id = pd.id_pedido
            WHERE DATE(p.created_at) BETWEEN ? AND ?
            AND p.activo = 1 
            AND p.estado NOT IN ('cancelado', 'devuelto')
        ");
        $stmt->execute([$fecha_inicio, $fecha_fin]);
        $productos_stats = $stmt->fetch();
        $stats['productos_vendidos'] = $productos_stats['productos_vendidos'] ?: 0;
        
        return $stats;
    } catch (Exception $e) {
        return [
            'total_pedidos' => 0,
            'ingresos_totales' => 0,
            'ticket_promedio' => 0,
            'clientes_unicos' => 0,
            'pedidos_cancelados' => 0,
            'pedidos_devueltos' => 0,
            'pedidos_completados' => 0,
            'productos_vendidos' => 0
        ];
    }
}

function obtenerTopClientes($conn, $fecha_inicio, $fecha_fin, $limit = 10) {
    try {
        $stmt = $conn->prepare("
            SELECT c.nombre,
                   c.email,
                   COUNT(p.id) as total_pedidos,
                   SUM(p.total) as total_gastado,
                   AVG(p.total) as promedio_pedido,
                   MAX(p.created_at) as ultima_compra
            FROM clientes c
            JOIN pedidos p ON c.id = p.id_cliente
            WHERE DATE(p.created_at) BETWEEN ? AND ?
            AND p.activo = 1 
            AND p.estado NOT IN ('cancelado', 'devuelto')
            GROUP BY c.id
            ORDER BY total_gastado DESC
            LIMIT ?
        ");
        $stmt->execute([$fecha_inicio, $fecha_fin, $limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function obtenerInventarioBajo($conn) {
    try {
        $stmt = $conn->query("
            SELECT p.nombre,
                   p.clave_producto,
                   p.cantidad_etiquetas,
                   p.precio,
                   c.nombre as categoria,
                   p.precio_compra,
                   (p.cantidad_etiquetas * p.precio_compra) as valor_inventario
            FROM productos p
            LEFT JOIN categorias c ON p.categoria_id = c.id
            WHERE p.activo = 1 
            AND p.cantidad_etiquetas <= 5
            ORDER BY p.cantidad_etiquetas ASC, p.nombre ASC
        ");
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function obtenerMetodosPageMasUsados($conn, $fecha_inicio, $fecha_fin) {
    try {
        $stmt = $conn->prepare("
            SELECT metodo_pago_usado,
                   COUNT(*) as veces_usado,
                   SUM(total) as total_procesado
            FROM pedidos 
            WHERE DATE(created_at) BETWEEN ? AND ?
            AND activo = 1 
            AND estado NOT IN ('cancelado', 'devuelto')
            AND metodo_pago_usado IS NOT NULL
            GROUP BY metodo_pago_usado
            ORDER BY veces_usado DESC
        ");
        $stmt->execute([$fecha_inicio, $fecha_fin]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// Obtener todos los datos
$ventas_por_dia = obtenerVentasPorDia($conn, $fecha_inicio, $fecha_fin);
$ventas_por_categoria = obtenerVentasPorCategoria($conn, $fecha_inicio, $fecha_fin);
$productos_mas_vendidos = obtenerProductosMasVendidos($conn, $fecha_inicio, $fecha_fin);
$estadisticas_generales = obtenerEstadisticasGenerales($conn, $fecha_inicio, $fecha_fin);
$top_clientes = obtenerTopClientes($conn, $fecha_inicio, $fecha_fin);
$inventario_bajo = obtenerInventarioBajo($conn);
$metodos_pago = obtenerMetodosPageMasUsados($conn, $fecha_inicio, $fecha_fin);

// Procesar datos para gráficos
$ventas_labels = [];
$ventas_data_pedidos = [];
$ventas_data_ingresos = [];

foreach ($ventas_por_dia as $venta) {
    $ventas_labels[] = date('d/m', strtotime($venta['fecha']));
    $ventas_data_pedidos[] = $venta['pedidos'];
    $ventas_data_ingresos[] = round($venta['ingresos'], 2);
}

$categorias_labels = [];
$categorias_data = [];
$categorias_colors = [];

foreach ($ventas_por_categoria as $categoria) {
    $categorias_labels[] = $categoria['categoria'] ?: 'Sin categoría';
    $categorias_data[] = round($categoria['ingresos'], 2);
    $categorias_colors[] = $categoria['color_categoria'] ?: '#007bff';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes y Análisis - Admin</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    
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
        
        .report-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 25px;
            transition: all 0.3s ease;
        }
        
        .report-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            height: 100%;
            text-align: center;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .stat-ingresos { color: #28a745; }
        .stat-pedidos { color: #007bff; }
        .stat-clientes { color: #17a2b8; }
        .stat-productos { color: #ffc107; }
        .stat-ticket { color: #6f42c1; }
        .stat-cancelados { color: #dc3545; }
        
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
        
        .chart-container {
            position: relative;
            height: 400px;
            margin: 20px 0;
        }
        
        .table-container {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .period-selector {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .product-mini {
            display: flex;
            align-items: center;
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        
        .product-mini:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .product-image {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 6px;
            margin-right: 15px;
        }
        
        .nav-tabs .nav-link {
            border-radius: 10px 10px 0 0;
            border: none;
            color: #667eea;
            font-weight: bold;
        }
        
        .nav-tabs .nav-link.active {
            background: #667eea;
            color: white;
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
            .chart-container {
                height: 300px;
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
            <a class="nav-link" href="pedidos.php">
                <i class="fas fa-shopping-cart me-2"></i> Pedidos
            </a>
            <a class="nav-link" href="clientes.php">
                <i class="fas fa-users me-2"></i> Clientes
            </a>
            <a class="nav-link active" href="reportes.php">
                <i class="fas fa-chart-bar me-2"></i> Reportes
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
                <i class="fas fa-chart-bar text-primary"></i> 
                Reportes y Análisis
            </h2>
            
            <div class="d-flex gap-2">
                <button onclick="exportarReporte()" class="btn btn-admin">
                    <i class="fas fa-download"></i> Exportar
                </button>
                <button onclick="window.print()" class="btn btn-outline-secondary">
                    <i class="fas fa-print"></i> Imprimir
                </button>
            </div>
        </div>
        
        <!-- Selector de período -->
        <div class="period-selector">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label text-white">Período Rápido:</label>
                    <select class="form-select" name="periodo" onchange="this.form.submit()">
                        <option value="hoy" <?= $periodo === 'hoy' ? 'selected' : '' ?>>Hoy</option>
                        <option value="ayer" <?= $periodo === 'ayer' ? 'selected' : '' ?>>Ayer</option>
                        <option value="semana_actual" <?= $periodo === 'semana_actual' ? 'selected' : '' ?>>Esta semana</option>
                        <option value="semana_pasada" <?= $periodo === 'semana_pasada' ? 'selected' : '' ?>>Semana pasada</option>
                        <option value="mes_actual" <?= $periodo === 'mes_actual' ? 'selected' : '' ?>>Este mes</option>
                        <option value="mes_pasado" <?= $periodo === 'mes_pasado' ? 'selected' : '' ?>>Mes pasado</option>
                        <option value="ultimos_30_dias" <?= $periodo === 'ultimos_30_dias' ? 'selected' : '' ?>>Últimos 30 días</option>
                        <option value="ano_actual" <?= $periodo === 'ano_actual' ? 'selected' : '' ?>>Este año</option>
                        <option value="personalizado" <?= $periodo === 'personalizado' ? 'selected' : '' ?>>Personalizado</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label text-white">Fecha Inicio:</label>
                    <input type="date" class="form-control" name="fecha_inicio" value="<?= $fecha_inicio ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label text-white">Fecha Fin:</label>
                    <input type="date" class="form-control" name="fecha_fin" value="<?= $fecha_fin ?>">
                </div>
                
                <div class="col-md-3">
                    <button type="submit" class="btn btn-light w-100">
                        <i class="fas fa-filter"></i> Aplicar Filtros
                    </button>
                </div>
            </form>
            
            <div class="mt-3 text-center">
                <strong>Período: <?= date('d/m/Y', strtotime($fecha_inicio)) ?> - <?= date('d/m/Y', strtotime($fecha_fin)) ?></strong>
            </div>
        </div>
        
        <!-- Estadísticas principales -->
        <div class="row g-4 mb-4">
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card">
                    <div class="stat-number stat-ingresos">$<?= number_format($estadisticas_generales['ingresos_totales'], 0) ?></div>
                    <div class="text-muted">
                        <i class="fas fa-dollar-sign"></i> Ingresos Totales
                    </div>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card">
                    <div class="stat-number stat-pedidos"><?= number_format($estadisticas_generales['total_pedidos']) ?></div>
                    <div class="text-muted">
                        <i class="fas fa-shopping-cart"></i> Pedidos Totales
                    </div>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card">
                    <div class="stat-number stat-clientes"><?= number_format($estadisticas_generales['clientes_unicos']) ?></div>
                    <div class="text-muted">
                        <i class="fas fa-users"></i> Clientes Únicos
                    </div>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card">
                    <div class="stat-number stat-productos"><?= number_format($estadisticas_generales['productos_vendidos']) ?></div>
                    <div class="text-muted">
                        <i class="fas fa-box"></i> Productos Vendidos
                    </div>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card">
                    <div class="stat-number stat-ticket">$<?= number_format($estadisticas_generales['ticket_promedio'], 0) ?></div>
                    <div class="text-muted">
                        <i class="fas fa-receipt"></i> Ticket Promedio
                    </div>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card">
                    <div class="stat-number stat-cancelados"><?= $estadisticas_generales['pedidos_completados'] ?></div>
                    <div class="text-muted">
                        <i class="fas fa-check-circle"></i> Completados
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Gráficos y análisis -->
        <div class="row">
            <div class="col-lg-8">
                <!-- Gráfico de ventas por día -->
                <div class="report-card">
                    <h6><i class="fas fa-chart-line"></i> Tendencia de Ventas</h6>
                    <div class="chart-container">
                        <canvas id="ventasChart"></canvas>
                    </div>
                </div>
                
                <!-- Gráfico de ventas por categoría -->
                <div class="report-card">
                    <h6><i class="fas fa-chart-pie"></i> Ventas por Categoría</h6>
                    <div class="chart-container">
                        <canvas id="categoriasChart"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <!-- Top productos -->
                <div class="report-card">
                    <h6><i class="fas fa-trophy"></i> Productos Más Vendidos</h6>
                    <div class="table-container">
                        <?php if (count($productos_mas_vendidos) > 0): ?>
                            <?php foreach (array_slice($productos_mas_vendidos, 0, 10) as $index => $producto): ?>
                                <div class="product-mini">
                                    <div class="badge bg-primary me-2"><?= $index + 1 ?></div>
                                    <?php if ($producto['imagen']): ?>
                                        <img src="../assets/images/products/<?= htmlspecialchars($producto['imagen']) ?>" 
                                             alt="<?= htmlspecialchars($producto['nombre']) ?>" class="product-image">
                                    <?php else: ?>
                                        <div class="product-image bg-light d-flex align-items-center justify-content-center">
                                            <i class="fas fa-image text-muted"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex-grow-1">
                                        <strong><?= htmlspecialchars($producto['nombre']) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($producto['clave_producto']) ?></small><br>
                                        <span class="text-success"><?= $producto['cantidad_vendida'] ?> vendidos</span>
                                        <span class="text-primary ms-2">$<?= number_format($producto['ingresos_generados'], 0) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted text-center">No hay datos disponibles</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Top clientes -->
                <div class="report-card">
                    <h6><i class="fas fa-star"></i> Mejores Clientes</h6>
                    <div class="table-container">
                        <?php if (count($top_clientes) > 0): ?>
                            <?php foreach (array_slice($top_clientes, 0, 10) as $index => $cliente): ?>
                                <div class="product-mini">
                                    <div class="badge bg-success me-2"><?= $index + 1 ?></div>
                                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" 
                                         style="width: 40px; height: 40px; font-weight: bold;">
                                        <?= strtoupper(substr($cliente['nombre'], 0, 2)) ?>
                                    </div>
                                    <div class="flex-grow-1">
                                        <strong><?= htmlspecialchars($cliente['nombre']) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($cliente['email']) ?></small><br>
                                        <span class="text-success"><?= $cliente['total_pedidos'] ?> pedidos</span>
                                        <span class="text-primary ms-2">$<?= number_format($cliente['total_gastado'], 0) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted text-center">No hay datos disponibles</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Pestañas adicionales -->
        <div class="report-card">
            <ul class="nav nav-tabs" id="reportTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="inventario-tab" data-bs-toggle="tab" data-bs-target="#inventario" type="button" role="tab">
                        <i class="fas fa-warehouse"></i> Inventario Bajo
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="metodos-pago-tab" data-bs-toggle="tab" data-bs-target="#metodos-pago" type="button" role="tab">
                        <i class="fas fa-credit-card"></i> Métodos de Pago
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="estadisticas-detalladas-tab" data-bs-toggle="tab" data-bs-target="#estadisticas-detalladas" type="button" role="tab">
                        <i class="fas fa-chart-bar"></i> Estadísticas Detalladas
                    </button>
                </li>
            </ul>
            
            <div class="tab-content mt-3" id="reportTabContent">
                <!-- Inventario Bajo -->
                <div class="tab-pane fade show active" id="inventario" role="tabpanel">
                    <h6><i class="fas fa-exclamation-triangle text-warning"></i> Productos con Stock Bajo (≤5 unidades)</h6>
                    <?php if (count($inventario_bajo) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Producto</th>
                                        <th>Categoría</th>
                                        <th>Stock Actual</th>
                                        <th>Precio Venta</th>
                                        <th>Costo Unitario</th>
                                        <th>Valor en Inventario</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inventario_bajo as $producto): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($producto['nombre']) ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($producto['clave_producto']) ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($producto['categoria'] ?: 'Sin categoría') ?></td>
                                            <td>
                                                <span class="badge bg-<?= $producto['cantidad_etiquetas'] == 0 ? 'danger' : ($producto['cantidad_etiquetas'] <= 2 ? 'warning' : 'info') ?>">
                                                    <?= $producto['cantidad_etiquetas'] ?> unidades
                                                </span>
                                            </td>
                                            <td>$<?= number_format($producto['precio'], 2) ?></td>
                                            <td>$<?= number_format($producto['precio_compra'] ?: 0, 2) ?></td>
                                            <td>$<?= number_format($producto['valor_inventario'] ?: 0, 2) ?></td>
                                            <td>
                                                <?php if ($producto['cantidad_etiquetas'] == 0): ?>
                                                    <span class="badge bg-danger">Sin Stock</span>
                                                <?php elseif ($producto['cantidad_etiquetas'] <= 2): ?>
                                                    <span class="badge bg-warning">Crítico</span>
                                                <?php else: ?>
                                                    <span class="badge bg-info">Bajo</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="alert alert-warning mt-3">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Total productos con stock bajo:</strong> <?= count($inventario_bajo) ?> | 
                            <strong>Valor total en riesgo:</strong> $<?= number_format(array_sum(array_column($inventario_bajo, 'valor_inventario')), 2) ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> ¡Excelente! Todos los productos tienen stock adecuado.
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Métodos de Pago -->
                <div class="tab-pane fade" id="metodos-pago" role="tabpanel">
                    <h6><i class="fas fa-chart-pie"></i> Análisis de Métodos de Pago</h6>
                    <?php if (count($metodos_pago) > 0): ?>
                        <div class="row">
                            <div class="col-md-6">
                                <canvas id="metodosPagoChart"></canvas>
                            </div>
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Método</th>
                                                <th>Usos</th>
                                                <th>Total Procesado</th>
                                                <th>%</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $total_transacciones = array_sum(array_column($metodos_pago, 'veces_usado'));
                                            foreach ($metodos_pago as $metodo): 
                                                $porcentaje = $total_transacciones > 0 ? round(($metodo['veces_usado'] / $total_transacciones) * 100, 1) : 0;
                                            ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($metodo['metodo_pago_usado']) ?></td>
                                                    <td><span class="badge bg-primary"><?= $metodo['veces_usado'] ?></span></td>
                                                    <td>$<?= number_format($metodo['total_procesado'], 2) ?></td>
                                                    <td><?= $porcentaje ?>%</td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="text-muted text-center py-4">No hay datos de métodos de pago en el período seleccionado</p>
                    <?php endif; ?>
                </div>
                
                <!-- Estadísticas Detalladas -->
                <div class="tab-pane fade" id="estadisticas-detalladas" role="tabpanel">
                    <h6><i class="fas fa-calculator"></i> Análisis Detallado</h6>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="bg-light p-3 rounded mb-3">
                                <h6>📈 Rendimiento de Ventas</h6>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td>Pedidos Completados:</td>
                                        <td><strong><?= $estadisticas_generales['pedidos_completados'] ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td>Pedidos Cancelados:</td>
                                        <td><strong class="text-danger"><?= $estadisticas_generales['pedidos_cancelados'] ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td>Pedidos Devueltos:</td>
                                        <td><strong class="text-warning"><?= $estadisticas_generales['pedidos_devueltos'] ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td>Tasa de Éxito:</td>
                                        <td><strong class="text-success">
                                            <?php 
                                            $tasa_exito = $estadisticas_generales['total_pedidos'] > 0 
                                                ? round(($estadisticas_generales['pedidos_completados'] / $estadisticas_generales['total_pedidos']) * 100, 1) 
                                                : 0;
                                            echo $tasa_exito;
                                            ?>%
                                        </strong></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="bg-light p-3 rounded mb-3">
                                <h6>💰 Análisis Financiero</h6>
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <td>Ingreso Promedio por Día:</td>
                                        <td><strong>$<?php 
                                            $dias = max(1, (strtotime($fecha_fin) - strtotime($fecha_inicio)) / (60*60*24) + 1);
                                            echo number_format($estadisticas_generales['ingresos_totales'] / $dias, 2);
                                        ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td>Pedidos por Día:</td>
                                        <td><strong><?= number_format($estadisticas_generales['total_pedidos'] / $dias, 1) ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td>Productos por Pedido:</td>
                                        <td><strong><?php 
                                            echo $estadisticas_generales['total_pedidos'] > 0 
                                                ? number_format($estadisticas_generales['productos_vendidos'] / $estadisticas_generales['total_pedidos'], 1)
                                                : 0;
                                        ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td>Gasto por Cliente:</td>
                                        <td><strong>$<?php 
                                            echo $estadisticas_generales['clientes_unicos'] > 0 
                                                ? number_format($estadisticas_generales['ingresos_totales'] / $estadisticas_generales['clientes_unicos'], 2)
                                                : 0;
                                        ?></strong></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="bg-light p-3 rounded">
                                <h6>📊 Comparativa con Períodos Anteriores</h6>
                                <div class="row text-center">
                                    <div class="col-md-3">
                                        <div class="p-2">
                                            <h5 class="text-primary"><?= count($ventas_por_dia) ?></h5>
                                            <small>Días con Ventas</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="p-2">
                                            <h5 class="text-success"><?= count($ventas_por_categoria) ?></h5>
                                            <small>Categorías Vendidas</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="p-2">
                                            <h5 class="text-info"><?= count($productos_mas_vendidos) ?></h5>
                                            <small>Productos Diferentes</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="p-2">
                                            <h5 class="text-warning"><?= count($top_clientes) ?></h5>
                                            <small>Clientes Activos</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // === CONFIGURACIÓN DE GRÁFICOS ===
        
        // Gráfico de ventas por día
        const ventasCtx = document.getElementById('ventasChart').getContext('2d');
        new Chart(ventasCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($ventas_labels) ?>,
                datasets: [{
                    label: 'Ingresos ($)',
                    data: <?= json_encode($ventas_data_ingresos) ?>,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    yAxisID: 'y'
                }, {
                    label: 'Pedidos',
                    data: <?= json_encode($ventas_data_pedidos) ?>,
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    borderWidth: 2,
                    fill: false,
                    tension: 0.4,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: true,
                        text: 'Tendencia de Ventas e Ingresos'
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Ingresos ($)'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Número de Pedidos'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                }
            }
        });
        
        // Gráfico de categorías
        const categoriasCtx = document.getElementById('categoriasChart').getContext('2d');
        new Chart(categoriasCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($categorias_labels) ?>,
                datasets: [{
                    data: <?= json_encode($categorias_data) ?>,
                    backgroundColor: <?= json_encode($categorias_colors) ?>,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                    },
                    title: {
                        display: true,
                        text: 'Distribución de Ingresos por Categoría'
                    }
                }
            }
        });
        
        // Gráfico de métodos de pago
        <?php if (count($metodos_pago) > 0): ?>
        const metodosPagoCtx = document.getElementById('metodosPagoChart').getContext('2d');
        new Chart(metodosPagoCtx, {
            type: 'pie',
            data: {
                labels: <?= json_encode(array_column($metodos_pago, 'metodo_pago_usado')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($metodos_pago, 'veces_usado')) ?>,
                    backgroundColor: [
                        '#FF6384',
                        '#36A2EB',
                        '#FFCE56',
                        '#4BC0C0',
                        '#9966FF',
                        '#FF9F40',
                        '#FF6384',
                        '#C9CBCF'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                    title: {
                        display: true,
                        text: 'Distribución de Métodos de Pago'
                    }
                }
            }
        });
        <?php endif; ?>
        
        // === FUNCIONES DE EXPORTACIÓN ===
        
        function exportarReporte() {
            const exportarMenu = `
                <div class="dropdown-menu show position-absolute" style="top: 100%; right: 0; z-index: 1050;">
                    <h6 class="dropdown-header">Exportar Reporte</h6>
                    <a class="dropdown-item" href="#" onclick="exportarCSV()">
                        <i class="fas fa-file-csv"></i> Exportar CSV
                    </a>
                    <a class="dropdown-item" href="#" onclick="exportarExcel()">
                        <i class="fas fa-file-excel"></i> Exportar Excel
                    </a>
                    <a class="dropdown-item" href="#" onclick="exportarPDF()">
                        <i class="fas fa-file-pdf"></i> Exportar PDF
                    </a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="#" onclick="exportarGraficos()">
                        <i class="fas fa-chart-bar"></i> Exportar Gráficos
                    </a>
                </div>
            `;
            
            // Crear modal para mostrar opciones
            const modalHTML = `
                <div class="modal fade" id="exportModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Exportar Reporte</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="d-grid gap-2">
                                    <button class="btn btn-outline-success" onclick="exportarCSV()">
                                        <i class="fas fa-file-csv"></i> Exportar como CSV
                                    </button>
                                    <button class="btn btn-outline-primary" onclick="exportarExcel()">
                                        <i class="fas fa-file-excel"></i> Exportar como Excel
                                    </button>
                                    <button class="btn btn-outline-danger" onclick="exportarPDF()">
                                        <i class="fas fa-file-pdf"></i> Exportar como PDF
                                    </button>
                                    <hr>
                                    <button class="btn btn-outline-info" onclick="exportarGraficos()">
                                        <i class="fas fa-image"></i> Exportar Gráficos como Imagen
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Agregar modal al DOM si no existe
            if (!document.getElementById('exportModal')) {
                document.body.insertAdjacentHTML('beforeend', modalHTML);
            }
            
            new bootstrap.Modal(document.getElementById('exportModal')).show();
        }
        
        function exportarCSV() {
            // Crear datos CSV
            let csvContent = "data:text/csv;charset=utf-8,";
            csvContent += "Reporte de Ventas - Período: <?= $fecha_inicio ?> a <?= $fecha_fin ?>\n\n";
            
            // Estadísticas generales
            csvContent += "ESTADÍSTICAS GENERALES\n";
            csvContent += "Métrica,Valor\n";
            csvContent += "Ingresos Totales,$<?= $estadisticas_generales['ingresos_totales'] ?>\n";
            csvContent += "Pedidos Totales,<?= $estadisticas_generales['total_pedidos'] ?>\n";
            csvContent += "Clientes Únicos,<?= $estadisticas_generales['clientes_unicos'] ?>\n";
            csvContent += "Productos Vendidos,<?= $estadisticas_generales['productos_vendidos'] ?>\n";
            csvContent += "Ticket Promedio,$<?= $estadisticas_generales['ticket_promedio'] ?>\n\n";
            
            // Productos más vendidos
            csvContent += "PRODUCTOS MÁS VENDIDOS\n";
            csvContent += "Posición,Producto,Cantidad Vendida,Ingresos\n";
            <?php foreach ($productos_mas_vendidos as $index => $producto): ?>
            csvContent += "<?= $index + 1 ?>,<?= addslashes($producto['nombre']) ?>,<?= $producto['cantidad_vendida'] ?>,$<?= $producto['ingresos_generados'] ?>\n";
            <?php endforeach; ?>
            
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", "reporte_ventas_<?= date('Y-m-d') ?>.csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            bootstrap.Modal.getInstance(document.getElementById('exportModal')).hide();
        }
        
        function exportarExcel() {
            alert('Funcionalidad de Excel en desarrollo. Use CSV como alternativa.');
            bootstrap.Modal.getInstance(document.getElementById('exportModal')).hide();
        }
        
        function exportarPDF() {
            window.print();
            bootstrap.Modal.getInstance(document.getElementById('exportModal')).hide();
        }
        
        function exportarGraficos() {
            const ventasCanvas = document.getElementById('ventasChart');
            const categoriasCanvas = document.getElementById('categoriasChart');
            
            // Exportar gráfico de ventas
            const ventasLink = document.createElement('a');
            ventasLink.download = 'grafico_ventas_<?= date('Y-m-d') ?>.png';
            ventasLink.href = ventasCanvas.toDataURL();
            ventasLink.click();
            
            // Exportar gráfico de categorías
            setTimeout(() => {
                const categoriasLink = document.createElement('a');
                categoriasLink.download = 'grafico_categorias_<?= date('Y-m-d') ?>.png';
                categoriasLink.href = categoriasCanvas.toDataURL();
                categoriasLink.click();
            }, 500);
            
            bootstrap.Modal.getInstance(document.getElementById('exportModal')).hide();
        }
        
        // === INICIALIZACIÓN ===
        
        document.addEventListener('DOMContentLoaded', function() {
            // Animar cards
            const cards = document.querySelectorAll('.report-card, .stat-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.4s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 50);
            });
            
            // Configurar cambio automático de período personalizado
            const periodoSelect = document.querySelector('select[name="periodo"]');
            const fechaInicio = document.querySelector('input[name="fecha_inicio"]');
            const fechaFin = document.querySelector('input[name="fecha_fin"]');
            
            if (periodoSelect && fechaInicio && fechaFin) {
                [fechaInicio, fechaFin].forEach(input => {
                    input.addEventListener('change', function() {
                        periodoSelect.value = 'personalizado';
                    });
                });
            }
        });
        
        // === ESTILOS PARA IMPRESIÓN ===
        
        const printStyles = `
            <style media="print">
                .sidebar { display: none !important; }
                .main-content { margin-left: 0 !important; }
                .btn { display: none !important; }
                .nav-tabs { display: none !important; }
                .tab-content > .tab-pane { display: block !important; }
                .chart-container { height: 300px !important; }
                body { font-size: 12px !important; }
                .report-card { break-inside: avoid; }
                @page { margin: 1cm; }
            </style>
        `;
        
        document.head.insertAdjacentHTML('beforeend', printStyles);
    </script>
</body>
</html>