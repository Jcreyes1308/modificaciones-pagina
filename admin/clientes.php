<?php
// admin/clientes.php - Gestión completa de clientes
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

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_cliente') {
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $telefono = trim($_POST['telefono'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        
        if (empty($nombre) || empty($email) || empty($password)) {
            $error = 'Nombre, email y contraseña son requeridos';
        } else {
            try {
                // Verificar que el email no existe
                $stmt = $conn->prepare("SELECT id FROM clientes WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = 'El email ya está registrado';
                } else {
                    // Encriptar contraseña
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    
                    $stmt = $conn->prepare("
                        INSERT INTO clientes (nombre, email, password, telefono, direccion) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$nombre, $email, $password_hash, $telefono, $direccion]);
                    
                    $success = 'Cliente agregado exitosamente';
                }
            } catch (Exception $e) {
                $error = 'Error al agregar cliente: ' . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'update_cliente') {
        $id = intval($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        
        if ($id <= 0 || empty($nombre) || empty($email)) {
            $error = 'Datos inválidos para actualizar';
        } else {
            try {
                // Verificar que el email no existe en otro cliente
                $stmt = $conn->prepare("SELECT id FROM clientes WHERE email = ? AND id != ?");
                $stmt->execute([$email, $id]);
                if ($stmt->fetch()) {
                    $error = 'El email ya está registrado por otro cliente';
                } else {
                    // Preparar la actualización
                    if (!empty($new_password)) {
                        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("
                            UPDATE clientes SET 
                            nombre = ?, email = ?, password = ?, telefono = ?, direccion = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$nombre, $email, $password_hash, $telefono, $direccion, $id]);
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE clientes SET 
                            nombre = ?, email = ?, telefono = ?, direccion = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$nombre, $email, $telefono, $direccion, $id]);
                    }
                    
                    $success = 'Cliente actualizado exitosamente';
                }
            } catch (Exception $e) {
                $error = 'Error al actualizar cliente: ' . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'toggle_estado') {
        $id = intval($_POST['id'] ?? 0);
        $nuevo_estado = intval($_POST['nuevo_estado'] ?? 1);
        
        if ($id > 0) {
            try {
                $stmt = $conn->prepare("UPDATE clientes SET activo = ? WHERE id = ?");
                $stmt->execute([$nuevo_estado, $id]);
                
                $estado_texto = $nuevo_estado ? 'activado' : 'desactivado';
                $success = "Cliente {$estado_texto} exitosamente";
            } catch (Exception $e) {
                $error = 'Error al cambiar estado: ' . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'delete_cliente') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                // Soft delete
                $stmt = $conn->prepare("UPDATE clientes SET activo = 0 WHERE id = ?");
                $stmt->execute([$id]);
                $success = 'Cliente eliminado exitosamente';
            } catch (Exception $e) {
                $error = 'Error al eliminar cliente: ' . $e->getMessage();
            }
        }
    }
}

// Obtener filtros
$search = trim($_GET['search'] ?? '');
$estado_filter = $_GET['estado'] ?? '';
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';

// Construir consulta de clientes
$where_conditions = ['1=1']; // Siempre verdadero para facilitar las condiciones
$params = [];

if ($search) {
    $where_conditions[] = '(c.nombre LIKE ? OR c.email LIKE ? OR c.telefono LIKE ?)';
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if ($estado_filter !== '') {
    $where_conditions[] = 'c.activo = ?';
    $params[] = $estado_filter;
}

if ($fecha_desde) {
    $where_conditions[] = 'DATE(c.created_at) >= ?';
    $params[] = $fecha_desde;
}

if ($fecha_hasta) {
    $where_conditions[] = 'DATE(c.created_at) <= ?';
    $params[] = $fecha_hasta;
}

$where_sql = implode(' AND ', $where_conditions);

// Obtener clientes con estadísticas
try {
    $stmt = $conn->prepare("
        SELECT c.*, 
               COUNT(DISTINCT p.id) as total_pedidos,
               COALESCE(SUM(p.total), 0) as total_gastado,
               COUNT(DISTINCT f.id) as productos_favoritos,
               COUNT(DISTINCT cc.id) as items_carrito,
               MAX(p.created_at) as ultima_compra,
               COUNT(DISTINCT de.id) as direcciones_envio
        FROM clientes c
        LEFT JOIN pedidos p ON c.id = p.id_cliente AND p.activo = 1
        LEFT JOIN favoritos f ON c.id = f.id_cliente
        LEFT JOIN carrito_compras cc ON c.id = cc.id_cliente
        LEFT JOIN direcciones_envio de ON c.id = de.id_cliente AND de.activo = 1
        WHERE $where_sql
        GROUP BY c.id
        ORDER BY c.created_at DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    $clientes = $stmt->fetchAll();
} catch (Exception $e) {
    $clientes = [];
    $error = 'Error al cargar clientes: ' . $e->getMessage();
}

// Obtener estadísticas generales
$stats = [];
try {
    // Total clientes activos
    $stmt = $conn->query("SELECT COUNT(*) as total FROM clientes WHERE activo = 1");
    $stats['total_activos'] = $stmt->fetch()['total'];
    
    // Total clientes inactivos
    $stmt = $conn->query("SELECT COUNT(*) as total FROM clientes WHERE activo = 0");
    $stats['total_inactivos'] = $stmt->fetch()['total'];
    
    // Nuevos clientes este mes
    $stmt = $conn->query("
        SELECT COUNT(*) as total FROM clientes 
        WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) 
        AND YEAR(created_at) = YEAR(CURRENT_DATE())
    ");
    $stats['nuevos_mes'] = $stmt->fetch()['total'];
    
    // Clientes con compras
    $stmt = $conn->query("
        SELECT COUNT(DISTINCT id_cliente) as total FROM pedidos 
        WHERE activo = 1 AND estado NOT IN ('cancelado', 'devuelto')
    ");
    $stats['con_compras'] = $stmt->fetch()['total'];
    
    // Valor promedio de cliente
    $stmt = $conn->query("
        SELECT COALESCE(AVG(cliente_total), 0) as promedio
        FROM (
            SELECT SUM(total) as cliente_total 
            FROM pedidos 
            WHERE activo = 1 AND estado NOT IN ('cancelado', 'devuelto')
            GROUP BY id_cliente
        ) as totales_clientes
    ");
    $stats['valor_promedio'] = $stmt->fetch()['promedio'];
    
} catch (Exception $e) {
    $stats = [
        'total_activos' => 0,
        'total_inactivos' => 0,
        'nuevos_mes' => 0,
        'con_compras' => 0,
        'valor_promedio' => 0
    ];
}

// Si es ver detalle, obtener datos completos del cliente
$cliente_detalle = null;
$cliente_pedidos = [];
$cliente_direcciones = [];
$cliente_metodos_pago = [];
$cliente_favoritos = [];

if ($action === 'ver' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        // Datos del cliente
        $stmt = $conn->prepare("SELECT * FROM clientes WHERE id = ?");
        $stmt->execute([$id]);
        $cliente_detalle = $stmt->fetch();
        
        if ($cliente_detalle) {
            // Pedidos del cliente
            $stmt = $conn->prepare("
                SELECT p.*, COUNT(pd.id) as total_items
                FROM pedidos p
                LEFT JOIN pedido_detalles pd ON p.id = pd.id_pedido
                WHERE p.id_cliente = ? AND p.activo = 1
                GROUP BY p.id
                ORDER BY p.created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$id]);
            $cliente_pedidos = $stmt->fetchAll();
            
            // Direcciones de envío
            $stmt = $conn->prepare("
                SELECT * FROM direcciones_envio 
                WHERE id_cliente = ? AND activo = 1
                ORDER BY es_principal DESC, created_at DESC
            ");
            $stmt->execute([$id]);
            $cliente_direcciones = $stmt->fetchAll();
            
            // Métodos de pago
            $stmt = $conn->prepare("
                SELECT * FROM metodos_pago 
                WHERE id_cliente = ? AND activo = 1
                ORDER BY es_principal DESC, created_at DESC
            ");
            $stmt->execute([$id]);
            $cliente_metodos_pago = $stmt->fetchAll();
            
            // Productos favoritos
            $stmt = $conn->prepare("
                SELECT f.*, p.nombre, p.precio, p.imagen
                FROM favoritos f
                JOIN productos p ON f.id_producto = p.id
                WHERE f.id_cliente = ? AND p.activo = 1
                ORDER BY f.created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$id]);
            $cliente_favoritos = $stmt->fetchAll();
        }
    } catch (Exception $e) {
        $error = 'Error al cargar detalle del cliente';
    }
}

// Si es editar, obtener datos del cliente
$cliente_edit = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        $stmt = $conn->prepare("SELECT * FROM clientes WHERE id = ?");
        $stmt->execute([$id]);
        $cliente_edit = $stmt->fetch();
    } catch (Exception $e) {
        $error = 'Error al cargar cliente para editar';
    }
}

// Estados de clientes
$estados_cliente = [
    1 => ['label' => 'Activo', 'color' => 'success', 'icon' => 'check-circle'],
    0 => ['label' => 'Inactivo', 'color' => 'danger', 'icon' => 'times-circle']
];

// Estados de pedidos para mostrar
$estados_pedido_display = [
    'pendiente' => ['label' => 'Pendiente', 'color' => 'warning'],
    'confirmado' => ['label' => 'Confirmado', 'color' => 'info'],
    'procesando' => ['label' => 'Procesando', 'color' => 'primary'],
    'enviado' => ['label' => 'Enviado', 'color' => 'secondary'],
    'en_transito' => ['label' => 'En Tránsito', 'color' => 'dark'],
    'entregado' => ['label' => 'Entregado', 'color' => 'success'],
    'cancelado' => ['label' => 'Cancelado', 'color' => 'danger'],
    'devuelto' => ['label' => 'Devuelto', 'color' => 'warning']
];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Clientes - Admin</title>
    
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
        
        .cliente-card {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .cliente-card:hover {
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
        
        .avatar-circle {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(45deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.2rem;
        }
        
        .info-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .pedido-mini-card {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        
        .pedido-mini-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
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
            <a class="nav-link" href="pedidos.php">
                <i class="fas fa-shopping-cart me-2"></i> Pedidos
            </a>
            <a class="nav-link active" href="clientes.php">
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
                <i class="fas fa-users text-primary"></i> 
                <?= $action === 'ver' ? 'Detalle del Cliente' : ($action === 'add' || $action === 'edit' ? 'Gestionar Cliente' : 'Gestión de Clientes') ?>
            </h2>
            
            <?php if ($action !== 'ver' && $action !== 'add' && $action !== 'edit'): ?>
                <a href="clientes.php?action=add" class="btn btn-admin">
                    <i class="fas fa-user-plus"></i> Agregar Cliente
                </a>
            <?php endif; ?>
        </div>
        
        <!-- Estadísticas -->
        <?php if ($action !== 'ver' && $action !== 'add' && $action !== 'edit'): ?>
            <div class="stats-row">
                <div class="row text-center">
                    <div class="col-md-2">
                        <h3><?= number_format($stats['total_activos']) ?></h3>
                        <p>👥 Clientes Activos</p>
                    </div>
                    <div class="col-md-2">
                        <h3><?= number_format($stats['total_inactivos']) ?></h3>
                        <p>💤 Clientes Inactivos</p>
                    </div>
                    <div class="col-md-2">
                        <h3><?= number_format($stats['nuevos_mes']) ?></h3>
                        <p>🆕 Nuevos este Mes</p>
                    </div>
                    <div class="col-md-3">
                        <h3><?= number_format($stats['con_compras']) ?></h3>
                        <p>🛒 Con Compras Realizadas</p>
                    </div>
                    <div class="col-md-3">
                        <h3>$<?= number_format($stats['valor_promedio'], 0) ?></h3>
                        <p>💰 Valor Promedio por Cliente</p>
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
        
        <?php if ($action === 'ver' && $cliente_detalle): ?>
            <!-- Detalle del cliente -->
            <div class="row">
                <div class="col-lg-4">
                    <!-- Información básica -->
                    <div class="admin-card">
                        <div class="text-center mb-4">
                            <div class="avatar-circle mx-auto mb-3">
                                <?= strtoupper(substr($cliente_detalle['nombre'], 0, 2)) ?>
                            </div>
                            <h5><?= htmlspecialchars($cliente_detalle['nombre']) ?></h5>
                            <span class="estado-badge bg-<?= $estados_cliente[$cliente_detalle['activo']]['color'] ?>">
                                <i class="fas fa-<?= $estados_cliente[$cliente_detalle['activo']]['icon'] ?>"></i>
                                <?= $estados_cliente[$cliente_detalle['activo']]['label'] ?>
                            </span>
                        </div>
                        
                        <div class="info-section">
                            <h6><i class="fas fa-envelope"></i> Contacto</h6>
                            <p class="mb-1"><strong>Email:</strong> <?= htmlspecialchars($cliente_detalle['email']) ?></p>
                            <p class="mb-1"><strong>Teléfono:</strong> <?= htmlspecialchars($cliente_detalle['telefono'] ?? 'No especificado') ?></p>
                            <p class="mb-0"><strong>Registro:</strong> <?= date('d/m/Y', strtotime($cliente_detalle['created_at'])) ?></p>
                        </div>
                        
                        <?php if ($cliente_detalle['direccion']): ?>
                            <div class="info-section">
                                <h6><i class="fas fa-map-marker-alt"></i> Dirección</h6>
                                <p class="mb-0"><?= nl2br(htmlspecialchars($cliente_detalle['direccion'])) ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="d-grid gap-2">
                            <a href="clientes.php?action=edit&id=<?= $cliente_detalle['id'] ?>" class="btn btn-admin">
                                <i class="fas fa-edit"></i> Editar Cliente
                            </a>
                            <a href="clientes.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Volver a Lista
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-8">
                    <!-- Estadísticas del cliente -->
                    <div class="admin-card">
                        <h6><i class="fas fa-chart-line"></i> Resumen de Actividad</h6>
                        <div class="row text-center">
                            <div class="col-md-3">
                                <h4 class="text-primary"><?= count($cliente_pedidos) ?></h4>
                                <small>Pedidos Realizados</small>
                            </div>
                            <div class="col-md-3">
                                <h4 class="text-success">$<?= number_format(array_sum(array_column($cliente_pedidos, 'total')), 2) ?></h4>
                                <small>Total Gastado</small>
                            </div>
                            <div class="col-md-3">
                                <h4 class="text-info"><?= count($cliente_favoritos) ?></h4>
                                <small>Productos Favoritos</small>
                            </div>
                            <div class="col-md-3">
                                <h4 class="text-warning"><?= count($cliente_direcciones) ?></h4>
                                <small>Direcciones</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pestañas de información -->
                    <div class="admin-card">
                        <ul class="nav nav-tabs" id="clienteTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="pedidos-tab" data-bs-toggle="tab" data-bs-target="#pedidos" type="button" role="tab">
                                    <i class="fas fa-shopping-cart"></i> Pedidos (<?= count($cliente_pedidos) ?>)
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="direcciones-tab" data-bs-toggle="tab" data-bs-target="#direcciones" type="button" role="tab">
                                    <i class="fas fa-map-marker-alt"></i> Direcciones (<?= count($cliente_direcciones) ?>)
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="favoritos-tab" data-bs-toggle="tab" data-bs-target="#favoritos" type="button" role="tab">
                                    <i class="fas fa-heart"></i> Favoritos (<?= count($cliente_favoritos) ?>)
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="pagos-tab" data-bs-toggle="tab" data-bs-target="#pagos" type="button" role="tab">
                                    <i class="fas fa-credit-card"></i> Métodos de Pago (<?= count($cliente_metodos_pago) ?>)
                                </button>
                            </li>
                        </ul>
                        
                        <div class="tab-content mt-3" id="clienteTabContent">
                            <!-- Pedidos -->
                            <div class="tab-pane fade show active" id="pedidos" role="tabpanel">
                                <?php if (count($cliente_pedidos) > 0): ?>
                                    <?php foreach ($cliente_pedidos as $pedido): ?>
                                        <div class="pedido-mini-card">
                                            <div class="row align-items-center">
                                                <div class="col-md-4">
                                                    <strong><?= htmlspecialchars($pedido['numero_pedido']) ?></strong><br>
                                                    <small class="text-muted"><?= date('d/m/Y H:i', strtotime($pedido['created_at'])) ?></small>
                                                </div>
                                                <div class="col-md-3">
                                                    <span class="badge bg-<?= $estados_pedido_display[$pedido['estado']]['color'] ?>">
                                                        <?= $estados_pedido_display[$pedido['estado']]['label'] ?>
                                                    </span>
                                                </div>
                                                <div class="col-md-3">
                                                    <strong>$<?= number_format($pedido['total'], 2) ?></strong><br>
                                                    <small class="text-muted"><?= $pedido['total_items'] ?> items</small>
                                                </div>
                                                <div class="col-md-2 text-end">
                                                    <a href="pedidos.php?action=ver&id=<?= $pedido['id'] ?>" class="btn btn-outline-primary btn-sm">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted text-center py-3">No hay pedidos realizados</p>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Direcciones -->
                            <div class="tab-pane fade" id="direcciones" role="tabpanel">
                                <?php if (count($cliente_direcciones) > 0): ?>
                                    <?php foreach ($cliente_direcciones as $direccion): ?>
                                        <div class="pedido-mini-card">
                                            <div class="row">
                                                <div class="col-md-8">
                                                    <strong><?= htmlspecialchars($direccion['nombre_direccion']) ?></strong>
                                                    <?php if ($direccion['es_principal']): ?>
                                                        <span class="badge bg-success ms-2">Principal</span>
                                                    <?php endif; ?>
                                                    <br>
                                                    <div class="mt-2">
                                                        <strong><?= htmlspecialchars($direccion['nombre_destinatario']) ?></strong><br>
                                                        <?= htmlspecialchars($direccion['calle_numero']) ?><br>
                                                        <?= htmlspecialchars($direccion['colonia']) ?>, <?= htmlspecialchars($direccion['ciudad']) ?><br>
                                                        <?= htmlspecialchars($direccion['estado']) ?> - <?= htmlspecialchars($direccion['codigo_postal']) ?>
                                                        <?php if ($direccion['telefono_contacto']): ?>
                                                            <br><small class="text-muted">Tel: <?= htmlspecialchars($direccion['telefono_contacto']) ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <?php if ($direccion['referencias']): ?>
                                                        <small class="text-muted">
                                                            <strong>Referencias:</strong><br>
                                                            <?= htmlspecialchars($direccion['referencias']) ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted text-center py-3">No hay direcciones registradas</p>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Favoritos -->
                            <div class="tab-pane fade" id="favoritos" role="tabpanel">
                                <?php if (count($cliente_favoritos) > 0): ?>
                                    <div class="row">
                                        <?php foreach ($cliente_favoritos as $favorito): ?>
                                            <div class="col-md-6 mb-3">
                                                <div class="pedido-mini-card">
                                                    <div class="d-flex align-items-center">
                                                        <?php if ($favorito['imagen']): ?>
                                                            <img src="../assets/images/products/<?= htmlspecialchars($favorito['imagen']) ?>" 
                                                                 alt="<?= htmlspecialchars($favorito['nombre']) ?>" 
                                                                 style="width: 50px; height: 50px; object-fit: cover; border-radius: 8px; margin-right: 15px;">
                                                        <?php else: ?>
                                                            <div style="width: 50px; height: 50px; background: #f8f9fa; border-radius: 8px; margin-right: 15px; display: flex; align-items: center; justify-content: center;">
                                                                <i class="fas fa-image text-muted"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div class="flex-grow-1">
                                                            <strong><?= htmlspecialchars($favorito['nombre']) ?></strong><br>
                                                            <span class="text-success">$<?= number_format($favorito['precio'], 2) ?></span><br>
                                                            <small class="text-muted"><?= date('d/m/Y', strtotime($favorito['created_at'])) ?></small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center py-3">No hay productos favoritos</p>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Métodos de Pago -->
                            <div class="tab-pane fade" id="pagos" role="tabpanel">
                                <?php if (count($cliente_metodos_pago) > 0): ?>
                                    <?php foreach ($cliente_metodos_pago as $metodo): ?>
                                        <div class="pedido-mini-card">
                                            <div class="row align-items-center">
                                                <div class="col-md-8">
                                                    <strong><?= htmlspecialchars($metodo['nombre_tarjeta'] ?? ucfirst($metodo['tipo'])) ?></strong>
                                                    <?php if ($metodo['es_principal']): ?>
                                                        <span class="badge bg-success ms-2">Principal</span>
                                                    <?php endif; ?>
                                                    <br>
                                                    <span class="text-muted">
                                                        <?= htmlspecialchars($metodo['nombre_titular'] ?? '') ?>
                                                        <?php if ($metodo['ultimos_4_digitos']): ?>
                                                            <br>•••• •••• •••• <?= htmlspecialchars($metodo['ultimos_4_digitos']) ?>
                                                        <?php endif; ?>
                                                        <?php if ($metodo['banco_emisor']): ?>
                                                            <br><?= htmlspecialchars($metodo['banco_emisor']) ?>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                                <div class="col-md-4 text-end">
                                                    <small class="text-muted">
                                                        <?= ucfirst(str_replace('_', ' ', $metodo['tipo'])) ?>
                                                        <?php if ($metodo['mes_expiracion'] && $metodo['ano_expiracion']): ?>
                                                            <br>Exp: <?= $metodo['mes_expiracion'] ?>/<?= $metodo['ano_expiracion'] ?>
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted text-center py-3">No hay métodos de pago registrados</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
        <?php elseif ($action === 'add' || $action === 'edit'): ?>
            <!-- Formulario de cliente -->
            <div class="admin-card">
                <h5>
                    <i class="fas fa-<?= $action === 'edit' ? 'user-edit' : 'user-plus' ?>"></i> 
                    <?= $action === 'edit' ? 'Editar Cliente' : 'Agregar Nuevo Cliente' ?>
                </h5>
                
                <form method="POST">
                    <input type="hidden" name="action" value="<?= $action === 'edit' ? 'update_cliente' : 'add_cliente' ?>">
                    <?php if ($action === 'edit' && $cliente_edit): ?>
                        <input type="hidden" name="id" value="<?= $cliente_edit['id'] ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nombre Completo *</label>
                            <input type="text" class="form-control" name="nombre" 
                                   value="<?= $cliente_edit ? htmlspecialchars($cliente_edit['nombre']) : '' ?>" 
                                   required maxlength="100" placeholder="Nombre completo del cliente">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-control" name="email" 
                                   value="<?= $cliente_edit ? htmlspecialchars($cliente_edit['email']) : '' ?>" 
                                   required maxlength="100" placeholder="correo@ejemplo.com">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Teléfono</label>
                            <input type="tel" class="form-control" name="telefono" 
                                   value="<?= $cliente_edit ? htmlspecialchars($cliente_edit['telefono']) : '' ?>" 
                                   maxlength="20" placeholder="555-1234-567">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <?= $action === 'edit' ? 'Nueva Contraseña (dejar vacío para mantener actual)' : 'Contraseña *' ?>
                            </label>
                            <input type="password" class="form-control" 
                                   name="<?= $action === 'edit' ? 'new_password' : 'password' ?>" 
                                   <?= $action === 'add' ? 'required' : '' ?> 
                                   minlength="6" placeholder="Mínimo 6 caracteres">
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label class="form-label">Dirección</label>
                            <textarea class="form-control" name="direccion" rows="3" 
                                      placeholder="Dirección completa del cliente"><?= $cliente_edit ? htmlspecialchars($cliente_edit['direccion']) : '' ?></textarea>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-admin">
                            <i class="fas fa-save"></i> <?= $action === 'edit' ? 'Actualizar' : 'Crear' ?> Cliente
                        </button>
                        <a href="clientes.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                    </div>
                </form>
            </div>
            
        <?php else: ?>
            <!-- Lista de clientes -->
            
            <!-- Filtros -->
            <div class="admin-card">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Buscar:</label>
                        <input type="text" class="form-control" name="search" 
                               value="<?= htmlspecialchars($search) ?>" placeholder="Nombre, email, teléfono...">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Estado:</label>
                        <select class="form-select" name="estado">
                            <option value="">Todos</option>
                            <option value="1" <?= $estado_filter === '1' ? 'selected' : '' ?>>Activos</option>
                            <option value="0" <?= $estado_filter === '0' ? 'selected' : '' ?>>Inactivos</option>
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
                    
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-admin w-100">
                            <i class="fas fa-search"></i> Filtrar
                        </button>
                    </div>
                </form>
                
                <?php if ($search || $estado_filter !== '' || $fecha_desde || $fecha_hasta): ?>
                    <div class="mt-3">
                        <a href="clientes.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i> Limpiar filtros
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Lista de clientes -->
            <div class="admin-card">
                <?php if (count($clientes) > 0): ?>
                    <?php foreach ($clientes as $cliente): ?>
                        <div class="cliente-card">
                            <div class="row align-items-center">
                                <div class="col-md-1">
                                    <div class="avatar-circle">
                                        <?= strtoupper(substr($cliente['nombre'], 0, 2)) ?>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <strong><?= htmlspecialchars($cliente['nombre']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($cliente['email']) ?></small><br>
                                    <?php if ($cliente['telefono']): ?>
                                        <small class="text-muted"><i class="fas fa-phone"></i> <?= htmlspecialchars($cliente['telefono']) ?></small>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-md-2 text-center">
                                    <span class="estado-badge bg-<?= $estados_cliente[$cliente['activo']]['color'] ?>">
                                        <i class="fas fa-<?= $estados_cliente[$cliente['activo']]['icon'] ?>"></i>
                                        <?= $estados_cliente[$cliente['activo']]['label'] ?>
                                    </span>
                                </div>
                                
                                <div class="col-md-2 text-center">
                                    <strong><?= $cliente['total_pedidos'] ?></strong> pedidos<br>
                                    <small class="text-success">$<?= number_format($cliente['total_gastado'], 2) ?></small>
                                </div>
                                
                                <div class="col-md-2 text-center">
                                    <small class="text-muted">
                                        Registro: <?= date('d/m/Y', strtotime($cliente['created_at'])) ?><br>
                                        <?php if ($cliente['ultima_compra']): ?>
                                            Última: <?= date('d/m/Y', strtotime($cliente['ultima_compra'])) ?>
                                        <?php else: ?>
                                            Sin compras
                                        <?php endif; ?>
                                    </small>
                                </div>
                                
                                <div class="col-md-2 text-end">
                                    <div class="btn-group" role="group">
                                        <a href="clientes.php?action=ver&id=<?= $cliente['id'] ?>" 
                                           class="btn btn-outline-primary btn-sm" title="Ver detalle">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="clientes.php?action=edit&id=<?= $cliente['id'] ?>" 
                                           class="btn btn-outline-warning btn-sm" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" 
                                                onclick="toggleEstado(<?= $cliente['id'] ?>, '<?= htmlspecialchars($cliente['nombre']) ?>', <?= $cliente['activo'] ?>)" 
                                                title="<?= $cliente['activo'] ? 'Desactivar' : 'Activar' ?>">
                                            <i class="fas fa-<?= $cliente['activo'] ? 'user-times' : 'user-check' ?>"></i>
                                        </button>
                                        <?php if (!$cliente['activo']): ?>
                                            <button type="button" class="btn btn-outline-danger btn-sm" 
                                                    onclick="deleteCliente(<?= $cliente['id'] ?>, '<?= htmlspecialchars($cliente['nombre']) ?>')" 
                                                    title="Eliminar permanentemente">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="mt-3 text-muted">
                        Mostrando <?= count($clientes) ?> cliente(s)
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <h5>No hay clientes encontrados</h5>
                        <p class="text-muted">
                            <?php if ($search || $estado_filter !== '' || $fecha_desde || $fecha_hasta): ?>
                                No hay clientes que coincidan con los filtros seleccionados.
                            <?php else: ?>
                                Aún no hay clientes registrados en la tienda.
                            <?php endif; ?>
                        </p>
                        <?php if (!($search || $estado_filter !== '' || $fecha_desde || $fecha_hasta)): ?>
                            <a href="clientes.php?action=add" class="btn btn-admin">
                                <i class="fas fa-user-plus"></i> Agregar Primer Cliente
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal de confirmación para cambiar estado -->
    <div class="modal fade" id="estadoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Cambiar Estado del Cliente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>¿Estás seguro de que quieres <strong id="accion-estado"></strong> a <strong id="cliente-nombre-estado"></strong>?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <form method="POST" style="display: inline;" id="estado-form">
                        <input type="hidden" name="action" value="toggle_estado">
                        <input type="hidden" name="id" id="estado-cliente-id">
                        <input type="hidden" name="nuevo_estado" id="nuevo-estado">
                        <button type="submit" class="btn btn-warning" id="btn-confirmar-estado">
                            <i class="fas fa-user-check"></i> <span id="texto-btn-estado">Confirmar</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de confirmación para eliminar -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Eliminar Cliente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>¿Estás seguro de que quieres eliminar permanentemente al cliente <strong id="cliente-nombre-delete"></strong>?</p>
                    <p class="text-danger"><strong>Advertencia:</strong> Esta acción no se puede deshacer. Se eliminarán también todos los datos relacionados (pedidos, direcciones, métodos de pago, etc.).</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <form method="POST" style="display: inline;" id="delete-form">
                        <input type="hidden" name="action" value="delete_cliente">
                        <input type="hidden" name="id" id="delete-cliente-id">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Eliminar Permanentemente
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Función para cambiar estado del cliente
        function toggleEstado(id, nombre, estadoActual) {
            const nuevoEstado = estadoActual ? 0 : 1;
            const accion = nuevoEstado ? 'activar' : 'desactivar';
            
            document.getElementById('cliente-nombre-estado').textContent = nombre;
            document.getElementById('accion-estado').textContent = accion;
            document.getElementById('estado-cliente-id').value = id;
            document.getElementById('nuevo-estado').value = nuevoEstado;
            document.getElementById('texto-btn-estado').textContent = accion.charAt(0).toUpperCase() + accion.slice(1);
            
            const btn = document.getElementById('btn-confirmar-estado');
            btn.className = nuevoEstado ? 'btn btn-success' : 'btn btn-warning';
            btn.innerHTML = `<i class="fas fa-user-${nuevoEstado ? 'check' : 'times'}"></i> ${accion.charAt(0).toUpperCase() + accion.slice(1)}`;
            
            new bootstrap.Modal(document.getElementById('estadoModal')).show();
        }
        
        // Función para eliminar cliente
        function deleteCliente(id, nombre) {
            document.getElementById('cliente-nombre-delete').textContent = nombre;
            document.getElementById('delete-cliente-id').value = id;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
        
        // Auto-submit en cambios de filtros
        document.querySelectorAll('select[name="estado"]').forEach(select => {
            select.addEventListener('change', function() {
                this.form.submit();
            });
        });
        
        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            // Animar cards
            const cards = document.querySelectorAll('.admin-card, .cliente-card');
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
        document.querySelector('form[method="POST"]')?.addEventListener('submit', function(e) {
            const nombre = this.querySelector('input[name="nombre"]')?.value.trim();
            const email = this.querySelector('input[name="email"]')?.value.trim();
            const password = this.querySelector('input[name="password"]')?.value;
            const newPassword = this.querySelector('input[name="new_password"]')?.value;
            
            if (!nombre || !email) {
                e.preventDefault();
                alert('Nombre y email son requeridos');
                return;
            }
            
            // Validar email
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                alert('Por favor ingresa un email válido');
                return;
            }
            
            // Validar contraseña en nuevo cliente
            if (password !== undefined && password.length < 6) {
                e.preventDefault();
                alert('La contraseña debe tener al menos 6 caracteres');
                return;
            }
            
            // Validar nueva contraseña en edición
            if (newPassword && newPassword.length < 6) {
                e.preventDefault();
                alert('La nueva contraseña debe tener al menos 6 caracteres');
                return;
            }
            
            // Mostrar loading en el botón
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';
            submitBtn.disabled = true;
            
            // Restaurar si hay error
            setTimeout(() => {
                if (submitBtn.disabled) {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            }, 10000);
        });
        
        // Validar formularios de confirmación
        document.getElementById('estado-form')?.addEventListener('submit', function(e) {
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';
            btn.disabled = true;
            
            setTimeout(() => {
                if (btn.disabled) {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            }, 5000);
        });
        
        document.getElementById('delete-form')?.addEventListener('submit', function(e) {
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Eliminando...';
            btn.disabled = true;
            
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