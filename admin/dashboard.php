<?php
// admin/dashboard.php - Dashboard del administrador CORREGIDO
session_start();

// Verificar que está logueado como admin
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

require_once '../config/database.php';
$database = new Database();
$conn = $database->getConnection();

// Obtener estadísticas CORREGIDAS
$stats = [];
try {
    // Total productos
    $stmt = $conn->query("SELECT COUNT(*) as total FROM productos WHERE activo = 1");
    $stats['productos'] = $stmt->fetch()['total'];
    
    // Total clientes
    $stmt = $conn->query("SELECT COUNT(*) as total FROM clientes WHERE activo = 1");
    $stats['clientes'] = $stmt->fetch()['total'];
    
    // Total pedidos (solo exitosos - excluye cancelados y devueltos)
    $stmt = $conn->query("SELECT COUNT(*) as total FROM pedidos WHERE activo = 1 AND estado NOT IN ('cancelado', 'devuelto')");
    $stats['pedidos'] = $stmt->fetch()['total'];
    
    // Ingresos totales (solo de pedidos exitosos - excluye cancelados y devueltos)
    $stmt = $conn->query("SELECT COALESCE(SUM(total), 0) as ingresos FROM pedidos WHERE activo = 1 AND estado NOT IN ('cancelado', 'devuelto')");
    $stats['ingresos'] = $stmt->fetch()['ingresos'];
    
    // Productos con stock bajo
    $stmt = $conn->query("SELECT COUNT(*) as total FROM productos WHERE cantidad_etiquetas < 5 AND activo = 1");
    $stats['stock_bajo'] = $stmt->fetch()['total'];
    
    // Pedidos pendientes
    $stmt = $conn->query("SELECT COUNT(*) as total FROM pedidos WHERE estado IN ('pendiente', 'confirmado') AND activo = 1");
    $stats['pedidos_pendientes'] = $stmt->fetch()['total'];
    
} catch (Exception $e) {
    error_log("Error obteniendo estadísticas: " . $e->getMessage());
    $stats = [
        'productos' => 0,
        'clientes' => 0, 
        'pedidos' => 0,
        'ingresos' => 0,
        'stock_bajo' => 0,
        'pedidos_pendientes' => 0
    ];
}

// Obtener productos más vendidos CORREGIDO
$productos_populares = [];
try {
    $stmt = $conn->query("
        SELECT p.nombre, COALESCE(SUM(pd.cantidad), 0) as total_vendido
        FROM productos p
        LEFT JOIN pedido_detalles pd ON p.id = pd.id_producto
        LEFT JOIN pedidos pe ON pd.id_pedido = pe.id
        WHERE p.activo = 1 AND (pe.id IS NULL OR (pe.activo = 1 AND pe.estado NOT IN ('cancelado', 'devuelto')))
        GROUP BY p.id, p.nombre
        HAVING total_vendido > 0
        ORDER BY total_vendido DESC
        LIMIT 5
    ");
    $productos_populares = $stmt->fetchAll();
} catch (Exception $e) {
    $productos_populares = [];
}

// Obtener pedidos recientes CORREGIDO (incluye todos los estados para el historial)
$pedidos_recientes = [];
try {
    $stmt = $conn->query("
        SELECT p.numero_pedido, p.total, p.estado, p.created_at, c.nombre as cliente_nombre
        FROM pedidos p
        LEFT JOIN clientes c ON p.id_cliente = c.id
        WHERE p.activo = 1
        ORDER BY p.created_at DESC
        LIMIT 5
    ");
    $pedidos_recientes = $stmt->fetchAll();
} catch (Exception $e) {
    $pedidos_recientes = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Admin Novedades Ashley</title>
    
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
            border-radius: 0;
            transition: all 0.3s ease;
        }
        
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: rgba(52, 152, 219, 0.2);
            color: #3498db;
            border-left: 4px solid #3498db;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            height: 100%;
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
        
        .stat-products { color: #3498db; }
        .stat-clients { color: #2ecc71; }
        .stat-orders { color: #f39c12; }
        .stat-income { color: #e74c3c; }
        .stat-stock { color: #9b59b6; }
        .stat-pending { color: #e67e22; }
        
        .admin-header {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .quick-actions {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .action-btn {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
            border-radius: 10px;
            color: white;
            padding: 15px 20px;
            margin: 5px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
        }
        
        .recent-orders {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .status-pendiente { background: #fff3cd; color: #856404; }
        .status-confirmado { background: #d1ecf1; color: #0c5460; }
        .status-procesando { background: #cce5ff; color: #004085; }
        .status-enviado { background: #e2e3e5; color: #383d41; }
        .status-en_transito { background: #d6d8db; color: #1e2125; }
        .status-entregado { background: #d4edda; color: #155724; }
        .status-cancelado { background: #f8d7da; color: #721c24; }
        .status-devuelto { background: #fff3cd; color: #856404; }
        
        .alert-info-custom {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 15px;
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
            <small class="text-muted">Novedades Ashley</small>
        </div>
        
        <nav class="nav flex-column">
            <a class="nav-link active" href="dashboard.php">
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
            <a class="nav-link" href="reportes.php">
                <i class="fas fa-chart-bar me-2"></i> Reportes
            </a>
            <a class="nav-link" href="configuracion.php">
                <i class="fas fa-cog me-2"></i> Configuración
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
        <!-- Header -->
        <div class="admin-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="mb-1">
                        <i class="fas fa-tachometer-alt text-primary"></i> Dashboard
                    </h2>
                    <p class="text-muted mb-0">Bienvenido, <?= htmlspecialchars($_SESSION['admin_nombre']) ?></p>
                </div>
                <div class="col-md-4 text-md-end">
                    <span class="badge bg-success p-2">
                        <i class="fas fa-circle"></i> Sistema Activo
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Alerta informativa sobre los cálculos -->
        <div class="alert alert-info-custom mb-4">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Nota:</strong> Las estadísticas de ingresos y pedidos excluyen automáticamente los pedidos cancelados y devueltos para mostrar datos precisos del rendimiento real.
                </div>
                <div class="col-md-4 text-md-end">
                    <small><i class="fas fa-sync-alt"></i> Actualizado en tiempo real</small>
                </div>
            </div>
        </div>
        
        <!-- Estadísticas -->
        <div class="row g-4 mb-4">
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card">
                    <div class="stat-number stat-products"><?= number_format($stats['productos']) ?></div>
                    <div class="text-muted">
                        <i class="fas fa-box"></i> Productos
                    </div>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card">
                    <div class="stat-number stat-clients"><?= number_format($stats['clientes']) ?></div>
                    <div class="text-muted">
                        <i class="fas fa-users"></i> Clientes
                    </div>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card">
                    <div class="stat-number stat-orders"><?= number_format($stats['pedidos']) ?></div>
                    <div class="text-muted">
                        <i class="fas fa-shopping-cart"></i> Pedidos Exitosos
                    </div>
                    <small class="text-muted">(excluye cancelados)</small>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card">
                    <div class="stat-number stat-income">$<?= number_format($stats['ingresos'], 0) ?></div>
                    <div class="text-muted">
                        <i class="fas fa-dollar-sign"></i> Ingresos Reales
                    </div>
                    <small class="text-muted">(excluye cancelados)</small>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card">
                    <div class="stat-number stat-stock"><?= number_format($stats['stock_bajo']) ?></div>
                    <div class="text-muted">
                        <i class="fas fa-exclamation-triangle"></i> Stock Bajo
                    </div>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="stat-card">
                    <div class="stat-number stat-pending"><?= number_format($stats['pedidos_pendientes']) ?></div>
                    <div class="text-muted">
                        <i class="fas fa-clock"></i> Pendientes
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row g-4">
            <!-- Acciones rápidas -->
            <div class="col-lg-4">
                <div class="quick-actions">
                    <h5 class="mb-3">
                        <i class="fas fa-bolt text-warning"></i> Acciones Rápidas
                    </h5>
                    
                    <div class="d-grid gap-2">
                        <a href="productos.php?action=add" class="action-btn">
                            <i class="fas fa-plus"></i> Agregar Producto
                        </a>
                        <a href="pedidos.php?estado=pendiente" class="action-btn">
                            <i class="fas fa-eye"></i> Ver Pedidos Pendientes
                        </a>
                        <a href="productos.php?filter=stock_bajo" class="action-btn">
                            <i class="fas fa-exclamation-triangle"></i> Revisar Stock
                        </a>
                        <a href="reportes.php" class="action-btn">
                            <i class="fas fa-chart-line"></i> Ver Reportes
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Productos más vendidos -->
            <div class="col-lg-4">
                <div class="recent-orders">
                    <h5 class="mb-3">
                        <i class="fas fa-fire text-danger"></i> Productos Populares
                    </h5>
                    
                    <?php if (count($productos_populares) > 0): ?>
                        <?php foreach ($productos_populares as $producto): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-light rounded">
                                <span><?= htmlspecialchars($producto['nombre']) ?></span>
                                <span class="badge bg-primary"><?= $producto['total_vendido'] ?> vendidos</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-3">
                            <i class="fas fa-box fa-2x text-muted mb-2"></i>
                            <p class="text-muted mb-0">No hay ventas registradas aún</p>
                            <small class="text-muted">Los productos aparecerán aquí cuando se realicen ventas exitosas</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Pedidos recientes -->
            <div class="col-lg-4">
                <div class="recent-orders">
                    <h5 class="mb-3">
                        <i class="fas fa-clock text-info"></i> Pedidos Recientes
                    </h5>
                    
                    <?php if (count($pedidos_recientes) > 0): ?>
                        <?php foreach ($pedidos_recientes as $pedido): ?>
                            <div class="mb-3 p-2 border-bottom">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?= htmlspecialchars($pedido['numero_pedido']) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($pedido['cliente_nombre']) ?></small>
                                    </div>
                                    <div class="text-end">
                                        <div>$<?= number_format($pedido['total'], 2) ?></div>
                                        <span class="status-badge status-<?= $pedido['estado'] ?>">
                                            <?= ucfirst(str_replace('_', ' ', $pedido['estado'])) ?>
                                        </span>
                                    </div>
                                </div>
                                <small class="text-muted">
                                    <i class="fas fa-calendar"></i> <?= date('d/m/Y H:i', strtotime($pedido['created_at'])) ?>
                                </small>
                            </div>
                        <?php endforeach; ?>
                        
                        <div class="text-center mt-3">
                            <a href="pedidos.php" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-eye"></i> Ver Todos los Pedidos
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-3">
                            <i class="fas fa-shopping-cart fa-2x text-muted mb-2"></i>
                            <p class="text-muted mb-0">No hay pedidos registrados aún</p>
                            <small class="text-muted">Los pedidos aparecerán aquí cuando los clientes realicen compras</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Animación de entrada para las estadísticas
        document.addEventListener('DOMContentLoaded', function() {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(30px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
            
            // Efecto de actualización en tiempo real (simulado)
            setInterval(function() {
                const activeIndicator = document.querySelector('.badge.bg-success');
                if (activeIndicator) {
                    activeIndicator.style.animation = 'pulse 1s ease-in-out';
                    setTimeout(() => {
                        activeIndicator.style.animation = '';
                    }, 1000);
                }
            }, 30000);
        });
        
        // Actualizar estadísticas cada 5 minutos (opcional)
        setInterval(function() {
            // Aquí podrías hacer una llamada AJAX para actualizar las estadísticas
            console.log('Verificando actualizaciones...');
        }, 300000); // 5 minutos
        
        // Estilo CSS para la animación pulse
        const style = document.createElement('style');
        style.textContent = `
            @keyframes pulse {
                0% { opacity: 1; }
                50% { opacity: 0.7; }
                100% { opacity: 1; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>