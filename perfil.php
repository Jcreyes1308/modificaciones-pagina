<?php
// perfil.php - Página de perfil del usuario COMPLETA con verificaciones y estilos bonitos
session_start();

// Verificar que el usuario esté logueado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php?redirect=perfil.php');
    exit();
}

require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

$success = '';
$error = '';

// Obtener datos actuales del usuario
try {
    $stmt = $conn->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmt->execute([$_SESSION['usuario_id']]);
    $usuario = $stmt->fetch();
    
    if (!$usuario) {
        session_destroy();
        header('Location: login.php');
        exit();
    }
} catch (Exception $e) {
    $error = 'Error al cargar perfil: ' . $e->getMessage();
    $usuario = [];
}

// Obtener métodos de pago del usuario
$metodos_pago = [];
try {
    $stmt = $conn->prepare("SELECT * FROM metodos_pago WHERE id_cliente = ? AND activo = 1 ORDER BY es_principal DESC, created_at DESC");
    $stmt->execute([$_SESSION['usuario_id']]);
    $metodos_pago = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error obteniendo métodos de pago: " . $e->getMessage());
}

// Obtener direcciones del usuario
$direcciones = [];
try {
    $stmt = $conn->prepare("SELECT * FROM direcciones_envio WHERE id_cliente = ? AND activo = 1 ORDER BY es_principal DESC, created_at DESC");
    $stmt->execute([$_SESSION['usuario_id']]);
    $direcciones = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error obteniendo direcciones: " . $e->getMessage());
}

// Procesar formularios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $nombre = trim($_POST['nombre'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        
        if (empty($nombre)) {
            $error = 'El nombre es requerido';
        } else {
            try {
                $stmt = $conn->prepare("UPDATE clientes SET nombre = ?, telefono = ?, direccion = ? WHERE id = ?");
                $stmt->execute([$nombre, $telefono, $direccion, $_SESSION['usuario_id']]);
                
                $_SESSION['usuario_nombre'] = $nombre;
                $success = 'Perfil actualizado correctamente';
                
                $usuario['nombre'] = $nombre;
                $usuario['telefono'] = $telefono;
                $usuario['direccion'] = $direccion;
                
            } catch (Exception $e) {
                $error = 'Error al actualizar perfil: ' . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = 'Todos los campos de contraseña son requeridos';
        } elseif (strlen($new_password) < 6) {
            $error = 'La nueva contraseña debe tener al menos 6 caracteres';
        } elseif ($new_password !== $confirm_password) {
            $error = 'La nueva contraseña y su confirmación no coinciden';
        } elseif (!password_verify($current_password, $usuario['password'])) {
            $error = 'La contraseña actual es incorrecta';
        } else {
            try {
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE clientes SET password = ? WHERE id = ?");
                $stmt->execute([$new_password_hash, $_SESSION['usuario_id']]);
                
                $success = 'Contraseña actualizada correctamente';
                
            } catch (Exception $e) {
                $error = 'Error al cambiar contraseña: ' . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'add_payment_method') {
        $tipo = $_POST['tipo_tarjeta'] ?? 'tarjeta_credito';
        $numero_tarjeta = preg_replace('/\s+/', '', $_POST['numero_tarjeta'] ?? '');
        $mes_exp = $_POST['mes_expiracion'] ?? '';
        $ano_exp = $_POST['ano_expiracion'] ?? '';
        $nombre_titular = trim($_POST['nombre_titular'] ?? '');
        $banco = trim($_POST['banco_emisor'] ?? '');
        $es_principal = isset($_POST['es_principal']) ? 1 : 0;
        
        if (empty($numero_tarjeta) || empty($mes_exp) || empty($ano_exp) || empty($nombre_titular)) {
            $error = 'Todos los campos de la tarjeta son requeridos';
        } elseif (strlen($numero_tarjeta) < 13 || strlen($numero_tarjeta) > 19) {
            $error = 'Número de tarjeta inválido';
        } else {
            try {
                $ultimos_4 = substr($numero_tarjeta, -4);
                $nombre_tarjeta = '';
                
                if (preg_match('/^4/', $numero_tarjeta)) {
                    $nombre_tarjeta = 'Visa';
                } elseif (preg_match('/^5[1-5]/', $numero_tarjeta)) {
                    $nombre_tarjeta = 'Mastercard';
                } elseif (preg_match('/^3[47]/', $numero_tarjeta)) {
                    $nombre_tarjeta = 'American Express';
                } else {
                    $nombre_tarjeta = 'Tarjeta';
                }
                
                if ($es_principal) {
                    $stmt = $conn->prepare("UPDATE metodos_pago SET es_principal = 0 WHERE id_cliente = ?");
                    $stmt->execute([$_SESSION['usuario_id']]);
                }
                
                $stmt = $conn->prepare("
                    INSERT INTO metodos_pago (id_cliente, tipo, nombre_tarjeta, ultimos_4_digitos, mes_expiracion, ano_expiracion, nombre_titular, banco_emisor, es_principal) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$_SESSION['usuario_id'], $tipo, $nombre_tarjeta, $ultimos_4, $mes_exp, $ano_exp, $nombre_titular, $banco, $es_principal]);
                
                $success = 'Método de pago agregado correctamente';
                
                $stmt = $conn->prepare("SELECT * FROM metodos_pago WHERE id_cliente = ? AND activo = 1 ORDER BY es_principal DESC, created_at DESC");
                $stmt->execute([$_SESSION['usuario_id']]);
                $metodos_pago = $stmt->fetchAll();
                
            } catch (Exception $e) {
                $error = 'Error al agregar método de pago: ' . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'add_address') {
        $nombre_direccion = trim($_POST['nombre_direccion'] ?? '');
        $nombre_destinatario = trim($_POST['nombre_destinatario'] ?? '');
        $telefono_contacto = trim($_POST['telefono_contacto'] ?? '');
        $calle_numero = trim($_POST['calle_numero'] ?? '');
        $colonia = trim($_POST['colonia'] ?? '');
        $ciudad = trim($_POST['ciudad'] ?? '');
        $estado = trim($_POST['estado'] ?? '');
        $codigo_postal = trim($_POST['codigo_postal'] ?? '');
        $referencias = trim($_POST['referencias'] ?? '');
        $es_principal = isset($_POST['es_principal_dir']) ? 1 : 0;
        
        if (empty($nombre_direccion) || empty($nombre_destinatario) || empty($calle_numero) || empty($ciudad) || empty($estado)) {
            $error = 'Los campos marcados son requeridos';
        } else {
            try {
                if ($es_principal) {
                    $stmt = $conn->prepare("UPDATE direcciones_envio SET es_principal = 0 WHERE id_cliente = ?");
                    $stmt->execute([$_SESSION['usuario_id']]);
                }
                
                $stmt = $conn->prepare("
                    INSERT INTO direcciones_envio (id_cliente, nombre_direccion, nombre_destinatario, telefono_contacto, calle_numero, colonia, ciudad, estado, codigo_postal, referencias, es_principal) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$_SESSION['usuario_id'], $nombre_direccion, $nombre_destinatario, $telefono_contacto, $calle_numero, $colonia, $ciudad, $estado, $codigo_postal, $referencias, $es_principal]);
                
                $success = 'Dirección agregada correctamente';
                
                $stmt = $conn->prepare("SELECT * FROM direcciones_envio WHERE id_cliente = ? AND activo = 1 ORDER BY es_principal DESC, created_at DESC");
                $stmt->execute([$_SESSION['usuario_id']]);
                $direcciones = $stmt->fetchAll();
                
            } catch (Exception $e) {
                $error = 'Error al agregar dirección: ' . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'delete_payment' && isset($_POST['id_metodo'])) {
        try {
            $stmt = $conn->prepare("UPDATE metodos_pago SET activo = 0 WHERE id = ? AND id_cliente = ?");
            $stmt->execute([$_POST['id_metodo'], $_SESSION['usuario_id']]);
            $success = 'Método de pago eliminado';
            
            $stmt = $conn->prepare("SELECT * FROM metodos_pago WHERE id_cliente = ? AND activo = 1 ORDER BY es_principal DESC, created_at DESC");
            $stmt->execute([$_SESSION['usuario_id']]);
            $metodos_pago = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = 'Error al eliminar método de pago';
        }
    }
    
    elseif ($action === 'delete_address' && isset($_POST['id_direccion'])) {
        try {
            $stmt = $conn->prepare("UPDATE direcciones_envio SET activo = 0 WHERE id = ? AND id_cliente = ?");
            $stmt->execute([$_POST['id_direccion'], $_SESSION['usuario_id']]);
            $success = 'Dirección eliminada';
            
            $stmt = $conn->prepare("SELECT * FROM direcciones_envio WHERE id_cliente = ? AND activo = 1 ORDER BY es_principal DESC, created_at DESC");
            $stmt->execute([$_SESSION['usuario_id']]);
            $direcciones = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = 'Error al eliminar dirección';
        }
    }
}

// Obtener estadísticas del usuario
$stats = [
    'pedidos_total' => 0,
    'pedidos_pendientes' => 0,
    'total_gastado' => 0,
    'items_carrito' => 0
];

try {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(cantidad), 0) as total FROM carrito_compras WHERE id_cliente = ?");
    $stmt->execute([$_SESSION['usuario_id']]);
    $result = $stmt->fetch();
    $stats['items_carrito'] = $result['total'];
} catch (Exception $e) {
    error_log("Error obteniendo estadísticas: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Perfil - Novedades Ashley</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 60px 0 40px 0;
        }
        
        .profile-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            padding: 30px;
            margin-bottom: 30px;
            transition: all 0.3s ease;
        }
        
        .profile-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .avatar {
            width: 120px;
            height: 120px;
            background: linear-gradient(45deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: white;
            margin: 0 auto 20px auto;
        }
        
        .payment-card, .address-card {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            position: relative;
            transition: all 0.3s ease;
        }
        
        .payment-card:hover, .address-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.1);
        }
        
        .payment-card.principal, .address-card.principal {
            border-color: #28a745;
            background: #f8fff9;
        }
        
        .principal-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #28a745;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: bold;
        }
        
        .card-brand {
            font-weight: bold;
            color: #667eea;
        }
        
        .card-visa { color: #1a1f71; }
        .card-mastercard { color: #eb001b; }
        .card-amex { color: #006fcf; }
        
        .btn-update {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
            border-radius: 10px;
            padding: 12px 25px;
            font-weight: bold;
            transition: all 0.3s ease;
            color: white;
        }
        
        .btn-update:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
        }
        
        .section-title {
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .modal-content {
            border-radius: 15px;
        }
        
        .form-control {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
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
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: none;
            color: #6c757d;
            cursor: pointer;
        }
        
        .breadcrumb {
            background: none;
            margin-bottom: 0;
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
        
        /* ===== ESTILOS PARA VERIFICACIONES ===== */
        .verification-section {
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .verification-section:hover {
            border-color: #667eea;
            box-shadow: 0 2px 10px rgba(102, 126, 234, 0.1);
        }

        .status-verified {
            background: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
            border-color: #ffeaa7;
        }

        .verification-badge {
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .badge-verified {
            background: #28a745;
            color: white;
        }

        .badge-required {
            background: #dc3545;
            color: white;
        }

        .history-item {
            display: flex;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #f0f0f0;
        }

        .history-item:last-child {
            border-bottom: none;
        }

        .history-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 1.2rem;
        }

        .history-success {
            background: #d4edda;
            color: #155724;
        }

        .history-failed {
            background: #f8d7da;
            color: #721c24;
        }

        .history-sent {
            background: #cce5ff;
            color: #004085;
        }
        
        @media (max-width: 768px) {
            .profile-header {
                padding: 40px 0 20px 0;
            }
            
            .profile-card {
                padding: 20px;
                margin-bottom: 20px;
            }
            
            .avatar {
                width: 80px;
                height: 80px;
                font-size: 2rem;
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
                            <li><a class="dropdown-item active" href="perfil.php">Mi Perfil</a></li>
                            <li><a class="dropdown-item" href="mis_pedidos.php">Mis Pedidos</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="#" onclick="cerrarSesion()">Cerrar Sesión</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Header del perfil -->
    <section class="profile-header">
        <div class="container">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php" class="text-light">Inicio</a></li>
                    <li class="breadcrumb-item active text-light">Mi Perfil</li>
                </ol>
            </nav>
            
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="display-5 mb-3">
                        <i class="fas fa-user-circle me-3"></i> Mi Perfil
                    </h1>
                    <p class="lead">Gestiona tu información personal, métodos de pago y direcciones</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="avatar">
                        <?= strtoupper(substr($usuario['nombre'] ?? 'U', 0, 1)) ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contenido del perfil -->
    <section class="py-5">
        <div class="container">
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

            <div class="row">
                <!-- Panel izquierdo - Información del usuario -->
                <div class="col-lg-4">
                    <!-- Información básica -->
                    <div class="profile-card">
                        <div class="text-center mb-4">
                            <div class="avatar" style="margin: 0 auto 20px auto;">
                                <?= strtoupper(substr($usuario['nombre'] ?? 'U', 0, 1)) ?>
                            </div>
                            <h4><?= htmlspecialchars($usuario['nombre'] ?? '') ?></h4>
                            <p class="text-muted"><?= htmlspecialchars($usuario['email'] ?? '') ?></p>
                            <small class="text-muted">
                                Miembro desde: <?= date('d/m/Y', strtotime($usuario['created_at'] ?? 'now')) ?>
                            </small>
                        </div>
                        
                        <div class="info-item">
                            <strong><i class="fas fa-phone me-2"></i> Teléfono:</strong><br>
                            <span class="text-muted">
                                <?= $usuario['telefono'] ? htmlspecialchars($usuario['telefono']) : 'No especificado' ?>
                            </span>
                        </div>
                        
                        <div class="info-item">
                            <strong><i class="fas fa-shield-alt me-2"></i> Estado:</strong><br>
                            <span class="badge bg-success">Cuenta Activa</span>
                        </div>
                    </div>

                    <!-- Estadísticas -->
                    <div class="profile-card">
                        <h5 class="section-title">
                            <i class="fas fa-chart-bar"></i> Mis Estadísticas
                        </h5>
                        
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="stat-card">
                                    <div class="stat-number"><?= $stats['pedidos_total'] ?></div>
                                    <small class="text-muted">Pedidos Total</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-card">
                                    <div class="stat-number"><?= $stats['items_carrito'] ?></div>
                                    <small class="text-muted">En Carrito</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-card">
                                    <div class="stat-number">$<?= number_format($stats['total_gastado'], 0) ?></div>
                                    <small class="text-muted">Total Gastado</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-card">
                                    <div class="stat-number"><?= count($metodos_pago) ?></div>
                                    <small class="text-muted">Métodos Pago</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Panel derecho - Pestañas de gestión -->
                <div class="col-lg-8">
                    <!-- Pestañas de navegación -->
                    <ul class="nav nav-tabs mb-4" id="profileTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="personal-tab" data-bs-toggle="tab" data-bs-target="#personal" type="button" role="tab">
                                <i class="fas fa-user"></i> Información Personal
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="payment-tab" data-bs-toggle="tab" data-bs-target="#payment" type="button" role="tab">
                                <i class="fas fa-credit-card"></i> Métodos de Pago
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="addresses-tab" data-bs-toggle="tab" data-bs-target="#addresses" type="button" role="tab">
                                <i class="fas fa-map-marker-alt"></i> Direcciones
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="verification-tab" data-bs-toggle="tab" data-bs-target="#verification" type="button" role="tab">
                                <i class="fas fa-shield-check"></i> Verificaciones
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button" role="tab">
                                <i class="fas fa-shield-alt"></i> Seguridad
                            </button>
                        </li>
                    </ul>

                    <!-- Contenido de las pestañas -->
                    <div class="tab-content" id="profileTabContent">
                        <!-- Información Personal -->
                        <div class="tab-pane fade show active" id="personal" role="tabpanel">
                            <div class="profile-card">
                                <h5 class="section-title">
                                    <i class="fas fa-edit"></i> Editar Información Personal
                                </h5>
                                
                                <form method="POST" action="" id="profileForm">
                                    <input type="hidden" name="action" value="update_profile">
                                    
                                    <div class="row">
                                        <div class="col-12 mb-3">
                                            <label for="nombre" class="form-label">
                                                <i class="fas fa-user"></i> Nombre Completo *
                                            </label>
                                            <input type="text" class="form-control" id="nombre" name="nombre" 
                                                   value="<?= htmlspecialchars($usuario['nombre'] ?? '') ?>" 
                                                   required maxlength="100">
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label for="email_display" class="form-label">
                                                <i class="fas fa-envelope"></i> Correo Electrónico
                                            </label>
                                            <input type="email" class="form-control" id="email_display" 
                                                   value="<?= htmlspecialchars($usuario['email'] ?? '') ?>" 
                                                   readonly disabled>
                                            <small class="text-muted">
                                                Para cambiar tu email, contacta al administrador
                                            </small>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label for="telefono" class="form-label">
                                                <i class="fas fa-phone"></i> Teléfono
                                            </label>
                                            <input type="tel" class="form-control" id="telefono" name="telefono" 
                                                   value="<?= htmlspecialchars($usuario['telefono'] ?? '') ?>" 
                                                   maxlength="20" placeholder="555-0123">
                                        </div>
                                        
                                        <div class="col-12 mb-4">
                                            <label for="direccion" class="form-label">
                                                <i class="fas fa-map-marker-alt"></i> Dirección General
                                            </label>
                                            <textarea class="form-control" id="direccion" name="direccion" 
                                                      rows="3" maxlength="200" 
                                                      placeholder="Tu dirección general..."><?= htmlspecialchars($usuario['direccion'] ?? '') ?></textarea>
                                            <small class="text-muted">Nota: También puedes gestionar direcciones específicas en la pestaña "Direcciones"</small>
                                        </div>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-update">
                                        <i class="fas fa-save"></i> Actualizar Información
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Métodos de Pago -->
                        <div class="tab-pane fade" id="payment" role="tabpanel">
                            <div class="profile-card">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h5 class="section-title mb-0">
                                        <i class="fas fa-credit-card"></i> Mis Métodos de Pago
                                    </h5>
                                    <button class="btn btn-update" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                                        <i class="fas fa-plus"></i> Agregar Tarjeta
                                    </button>
                                </div>
                                
                                <?php if (count($metodos_pago) > 0): ?>
                                    <?php foreach ($metodos_pago as $metodo): ?>
                                        <div class="payment-card <?= $metodo['es_principal'] ? 'principal' : '' ?>">
                                            <?php if ($metodo['es_principal']): ?>
                                                <div class="principal-badge">
                                                    <i class="fas fa-star"></i> Principal
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="row align-items-center">
                                                <div class="col-md-8">
                                                    <div class="d-flex align-items-center mb-2">
                                                        <i class="fas fa-credit-card fa-2x me-3 text-primary"></i>
                                                        <div>
                                                            <h6 class="mb-0 card-brand card-<?= strtolower($metodo['nombre_tarjeta']) ?>">
                                                                <?= htmlspecialchars($metodo['nombre_tarjeta']) ?> 
                                                                •••• <?= htmlspecialchars($metodo['ultimos_4_digitos']) ?>
                                                            </h6>
                                                            <small class="text-muted">
                                                                <?= htmlspecialchars($metodo['nombre_titular']) ?>
                                                            </small>
                                                        </div>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-6">
                                                            <small class="text-muted">
                                                                <strong>Expira:</strong> <?= $metodo['mes_expiracion'] ?>/<?= $metodo['ano_expiracion'] ?>
                                                            </small>
                                                        </div>
                                                        <div class="col-6">
                                                            <small class="text-muted">
                                                                <strong>Banco:</strong> <?= htmlspecialchars($metodo['banco_emisor'] ?? 'No especificado') ?>
                                                            </small>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-4 text-end">
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="delete_payment">
                                                        <input type="hidden" name="id_metodo" value="<?= $metodo['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm" 
                                                                onclick="return confirm('¿Eliminar este método de pago?')">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-credit-card fa-3x text-muted mb-3"></i>
                                        <h5>No tienes métodos de pago registrados</h5>
                                        <p class="text-muted">Agrega una tarjeta para realizar compras más rápido</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Direcciones -->
                        <div class="tab-pane fade" id="addresses" role="tabpanel">
                            <div class="profile-card">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h5 class="section-title mb-0">
                                        <i class="fas fa-map-marker-alt"></i> Mis Direcciones de Envío
                                    </h5>
                                    <button class="btn btn-update" data-bs-toggle="modal" data-bs-target="#addAddressModal">
                                        <i class="fas fa-plus"></i> Agregar Dirección
                                    </button>
                                </div>
                                
                                <?php if (count($direcciones) > 0): ?>
                                    <?php foreach ($direcciones as $direccion): ?>
                                        <div class="address-card <?= $direccion['es_principal'] ? 'principal' : '' ?>">
                                            <?php if ($direccion['es_principal']): ?>
                                                <div class="principal-badge">
                                                    <i class="fas fa-star"></i> Principal
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="row align-items-center">
                                                <div class="col-md-8">
                                                    <div class="d-flex align-items-center mb-2">
                                                        <i class="fas fa-home fa-2x me-3 text-success"></i>
                                                        <div>
                                                            <h6 class="mb-0"><?= htmlspecialchars($direccion['nombre_direccion']) ?></h6>
                                                            <small class="text-muted"><?= htmlspecialchars($direccion['nombre_destinatario']) ?></small>
                                                        </div>
                                                    </div>
                                                    <p class="mb-1">
                                                        <?= htmlspecialchars($direccion['calle_numero']) ?><br>
                                                        <?php if ($direccion['colonia']): ?>
                                                            <?= htmlspecialchars($direccion['colonia']) ?>, 
                                                        <?php endif; ?>
                                                        <?= htmlspecialchars($direccion['ciudad']) ?>, <?= htmlspecialchars($direccion['estado']) ?>
                                                        <?php if ($direccion['codigo_postal']): ?>
                                                            - CP <?= htmlspecialchars($direccion['codigo_postal']) ?>
                                                        <?php endif; ?>
                                                    </p>
                                                    <?php if ($direccion['telefono_contacto']): ?>
                                                        <small class="text-muted">
                                                            <i class="fas fa-phone"></i> <?= htmlspecialchars($direccion['telefono_contacto']) ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-md-4 text-end">
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="delete_address">
                                                        <input type="hidden" name="id_direccion" value="<?= $direccion['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm" 
                                                                onclick="return confirm('¿Eliminar esta dirección?')">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-map-marker-alt fa-3x text-muted mb-3"></i>
                                        <h5>No tienes direcciones registradas</h5>
                                        <p class="text-muted">Agrega direcciones para facilitar tus envíos</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- ===== NUEVA PESTAÑA DE VERIFICACIONES ===== -->
                        <div class="tab-pane fade" id="verification" role="tabpanel">
                            <div class="profile-card">
                                <h5 class="section-title">
                                    <i class="fas fa-shield-check"></i> Verificar tu Información
                                </h5>
                                <p class="text-muted mb-4">
                                    Verifica tu email y teléfono para mayor seguridad y para recibir notificaciones importantes.
                                </p>
                                
                                <!-- Estado de verificaciones -->
                                <div class="row mb-4" id="verification-status">
                                    <!-- Se carga dinámicamente -->
                                </div>
                                
                                <!-- Email Verification -->
                                <div class="verification-section mb-4" id="email-verification-section">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div>
                                            <h6><i class="fas fa-envelope"></i> Verificación de Email</h6>
                                            <small class="text-muted">Confirma tu dirección de correo electrónico</small>
                                        </div>
                                        <div id="email-status-badge">
                                            <!-- Badge dinámico -->
                                        </div>
                                    </div>
                                    
                                    <div id="email-verification-form" class="d-none">
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle"></i> 
                                            <strong>Te enviaremos un código de 6 dígitos a:</strong>
                                            <br><span id="email-display"></span>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <button type="button" class="btn btn-primary w-100" onclick="sendEmailVerification()">
                                                    <i class="fas fa-paper-plane"></i> Enviar Código por Email
                                                </button>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <div class="input-group">
                                                    <input type="text" class="form-control" id="email-code" placeholder="Código de 6 dígitos" maxlength="6">
                                                    <button class="btn btn-success" type="button" onclick="verifyEmailCode()">
                                                        <i class="fas fa-check"></i> Verificar
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div id="email-verification-message" class="mt-2"></div>
                                    </div>
                                </div>
                                
                                <!-- Phone Verification -->
                                <div class="verification-section mb-4" id="phone-verification-section">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div>
                                            <h6><i class="fas fa-mobile-alt"></i> Verificación de Teléfono</h6>
                                            <small class="text-muted">Confirma tu número de teléfono</small>
                                        </div>
                                        <div id="phone-status-badge">
                                            <!-- Badge dinámico -->
                                        </div>
                                    </div>
                                    
                                    <div id="phone-verification-form" class="d-none">
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle"></i> 
                                            <strong>Elige cómo recibir tu código para:</strong>
                                            <br><span id="phone-display"></span>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-4 mb-3">
                                                <button type="button" class="btn btn-outline-primary w-100" onclick="sendSMSVerification()">
                                                    <i class="fas fa-sms"></i> SMS
                                                </button>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <button type="button" class="btn btn-outline-success w-100" onclick="sendWhatsAppVerification()">
                                                    <i class="fab fa-whatsapp"></i> WhatsApp
                                                </button>
                                            </div>
                                            <div class="col-md-4 mb-3">
                                                <div class="input-group">
                                                    <input type="text" class="form-control" id="phone-code" placeholder="Código" maxlength="6">
                                                    <button class="btn btn-success" type="button" onclick="verifyPhoneCode()">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div id="phone-verification-message" class="mt-2"></div>
                                    </div>
                                </div>
                                
                                <!-- Historial de Verificaciones -->
                                <div class="verification-section">
                                    <h6><i class="fas fa-history"></i> Actividad Reciente</h6>
                                    <div id="verification-history" class="mt-3">
                                        <!-- Se carga dinámicamente -->
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Seguridad -->
                        <div class="tab-pane fade" id="security" role="tabpanel">
                            <div class="profile-card">
                                <h5 class="section-title">
                                    <i class="fas fa-lock"></i> Cambiar Contraseña
                                </h5>
                                
                                <form method="POST" action="" id="passwordForm">
                                    <input type="hidden" name="action" value="change_password">
                                    
                                    <div class="row">
                                        <div class="col-12 mb-3">
                                            <label for="current_password" class="form-label">
                                                <i class="fas fa-key"></i> Contraseña Actual *
                                            </label>
                                            <div class="position-relative">
                                                <input type="password" class="form-control" id="current_password" 
                                                       name="current_password" required>
                                                <button type="button" class="password-toggle" onclick="togglePassword('current_password')">
                                                    <i class="fas fa-eye" id="current_password-icon"></i>
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label for="new_password" class="form-label">
                                                <i class="fas fa-lock"></i> Nueva Contraseña *
                                            </label>
                                            <div class="position-relative">
                                                <input type="password" class="form-control" id="new_password" 
                                                       name="new_password" required minlength="6">
                                                <button type="button" class="password-toggle" onclick="togglePassword('new_password')">
                                                    <i class="fas fa-eye" id="new_password-icon"></i>
                                                </button>
                                            </div>
                                            <small class="text-muted">Mínimo 6 caracteres</small>
                                        </div>
                                        
                                        <div class="col-md-6 mb-4">
                                            <label for="confirm_password" class="form-label">
                                                <i class="fas fa-lock"></i> Confirmar Nueva Contraseña *
                                            </label>
                                            <div class="position-relative">
                                                <input type="password" class="form-control" id="confirm_password" 
                                                       name="confirm_password" required minlength="6">
                                                <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                                                    <i class="fas fa-eye" id="confirm_password-icon"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-warning">
                                        <i class="fas fa-shield-alt"></i> Cambiar Contraseña
                                    </button>
                                </form>
                            </div>

                            <!-- Acciones de cuenta -->
                            <div class="profile-card">
                                <h5 class="section-title">
                                    <i class="fas fa-cog"></i> Acciones de Cuenta
                                </h5>
                                
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <a href="carrito.php" class="btn btn-outline-primary w-100">
                                            <i class="fas fa-shopping-cart"></i> Ver Mi Carrito
                                        </a>
                                    </div>
                                    <div class="col-md-6">
                                        <a href="mis_pedidos.php" class="btn btn-outline-info w-100">
                                            <i class="fas fa-history"></i> Historial de Pedidos
                                        </a>
                                    </div>
                                    <div class="col-md-6">
                                        <a href="productos.php" class="btn btn-outline-success w-100">
                                            <i class="fas fa-shopping-bag"></i> Continuar Comprando
                                        </a>
                                    </div>
                                    <div class="col-md-6">
                                        <button onclick="cerrarSesion()" class="btn btn-outline-danger w-100">
                                            <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Modal para agregar método de pago -->
    <div class="modal fade" id="addPaymentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-credit-card"></i> Agregar Método de Pago
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="paymentForm">
                    <input type="hidden" name="action" value="add_payment_method">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-12 mb-3">
                                <label for="tipo_tarjeta" class="form-label">Tipo de Tarjeta</label>
                                <select class="form-select" name="tipo_tarjeta" required>
                                    <option value="tarjeta_credito">Tarjeta de Crédito</option>
                                    <option value="tarjeta_debito">Tarjeta de Débito</option>
                                </select>
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label for="numero_tarjeta" class="form-label">Número de Tarjeta *</label>
                                <input type="text" class="form-control" name="numero_tarjeta" 
                                       placeholder="1234 5678 9012 3456" maxlength="19" required>
                            </div>
                            
                            <div class="col-6 mb-3">
                                <label for="mes_expiracion" class="form-label">Mes *</label>
                                <select class="form-select" name="mes_expiracion" required>
                                    <option value="">Mes</option>
                                    <?php for ($i = 1; $i <= 12; $i++): ?>
                                        <option value="<?= sprintf('%02d', $i) ?>"><?= sprintf('%02d', $i) ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="col-6 mb-3">
                                <label for="ano_expiracion" class="form-label">Año *</label>
                                <select class="form-select" name="ano_expiracion" required>
                                    <option value="">Año</option>
                                    <?php for ($i = date('Y'); $i <= date('Y') + 10; $i++): ?>
                                        <option value="<?= $i ?>"><?= $i ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label for="nombre_titular" class="form-label">Nombre del Titular *</label>
                                <input type="text" class="form-control" name="nombre_titular" 
                                       placeholder="Como aparece en la tarjeta" required>
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label for="banco_emisor" class="form-label">Banco Emisor</label>
                                <input type="text" class="form-control" name="banco_emisor" 
                                       placeholder="Ej: Banco Nacional">
                            </div>
                            
                            <div class="col-12">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="es_principal" id="es_principal">
                                    <label class="form-check-label" for="es_principal">
                                        Establecer como método principal
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-update">
                            <i class="fas fa-save"></i> Guardar Tarjeta
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para agregar dirección -->
    <div class="modal fade" id="addAddressModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-map-marker-alt"></i> Agregar Dirección
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="addressForm">
                    <input type="hidden" name="action" value="add_address">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="nombre_direccion" class="form-label">Nombre de la Dirección *</label>
                                <input type="text" class="form-control" name="nombre_direccion" 
                                       placeholder="Ej: Casa, Oficina" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="nombre_destinatario" class="form-label">Nombre del Destinatario *</label>
                                <input type="text" class="form-control" name="nombre_destinatario" 
                                       placeholder="Quien recibirá el paquete" required>
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label for="calle_numero" class="form-label">Calle y Número *</label>
                                <input type="text" class="form-control" name="calle_numero" 
                                       placeholder="Ej: Av. Insurgentes Sur 123" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="colonia" class="form-label">Colonia</label>
                                <input type="text" class="form-control" name="colonia" 
                                       placeholder="Ej: Roma Norte">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="codigo_postal" class="form-label">Código Postal</label>
                                <input type="text" class="form-control" name="codigo_postal" 
                                       placeholder="Ej: 06700" maxlength="5">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="ciudad" class="form-label">Ciudad *</label>
                                <input type="text" class="form-control" name="ciudad" 
                                       placeholder="Ej: Ciudad de México" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="estado" class="form-label">Estado *</label>
                                <select class="form-select" name="estado" required>
                                    <option value="">Seleccionar estado</option>
                                    <option value="CDMX">Ciudad de México</option>
                                    <option value="México">Estado de México</option>
                                    <option value="Jalisco">Jalisco</option>
                                    <option value="Nuevo León">Nuevo León</option>
                                    <option value="Puebla">Puebla</option>
                                    <option value="Guanajuato">Guanajuato</option>
                                    <option value="Veracruz">Veracruz</option>
                                    <option value="Michoacán">Michoacán</option>
                                    <option value="Oaxaca">Oaxaca</option>
                                    <option value="Chiapas">Chiapas</option>
                                    <option value="Guerrero">Guerrero</option>
                                    <option value="Tamaulipas">Tamaulipas</option>
                                    <option value="Baja California">Baja California</option>
                                    <option value="Sinaloa">Sinaloa</option>
                                    <option value="Coahuila">Coahuila</option>
                                    <option value="Hidalgo">Hidalgo</option>
                                    <option value="Sonora">Sonora</option>
                                    <option value="San Luis Potosí">San Luis Potosí</option>
                                    <option value="Tabasco">Tabasco</option>
                                    <option value="Yucatán">Yucatán</option>
                                    <option value="Querétaro">Querétaro</option>
                                    <option value="Morelos">Morelos</option>
                                    <option value="Durango">Durango</option>
                                    <option value="Zacatecas">Zacatecas</option>
                                    <option value="Quintana Roo">Quintana Roo</option>
                                    <option value="Tlaxcala">Tlaxcala</option>
                                    <option value="Aguascalientes">Aguascalientes</option>
                                    <option value="Nayarit">Nayarit</option>
                                    <option value="Campeche">Campeche</option>
                                    <option value="Baja California Sur">Baja California Sur</option>
                                    <option value="Colima">Colima</option>
                                </select>
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label for="telefono_contacto" class="form-label">Teléfono de Contacto</label>
                                <input type="tel" class="form-control" name="telefono_contacto" 
                                       placeholder="Para coordinación de entrega">
                            </div>
                            
                            <div class="col-12 mb-3">
                                <label for="referencias" class="form-label">Referencias</label>
                                <textarea class="form-control" name="referencias" rows="3"
                                         placeholder="Ej: Casa azul, entre calles X y Y, edificio 3 piso 2"></textarea>
                            </div>
                            
                            <div class="col-12">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="es_principal_dir" id="es_principal_dir">
                                    <label class="form-check-label" for="es_principal_dir">
                                        Establecer como dirección principal
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-update">
                            <i class="fas fa-save"></i> Guardar Dirección
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

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
        // ===== FUNCIONES PARA VERIFICACIONES =====
        
        // Variables globales para verificación
        let currentVerificationType = '';

        // Cargar estado cuando se hace click en la pestaña de verificaciones
        document.addEventListener('DOMContentLoaded', function() {
            const verificationTab = document.getElementById('verification-tab');
            if (verificationTab) {
                verificationTab.addEventListener('click', function() {
                    loadVerificationStatus();
                });
            }
        });

        // Cargar estado de verificaciones
        async function loadVerificationStatus() {
            try {
                const response = await fetch('api/verification.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=get_verification_status'
                });
                
                const data = await response.json();
                
                if (data.success) {
                    updateVerificationUI(data.data);
                    loadVerificationHistory();
                } else {
                    showVerificationMessage('email-verification-message', 'Error: ' + data.message, 'error');
                }
                
            } catch (error) {
                console.error('Error:', error);
                showVerificationMessage('email-verification-message', 'Error de conexión', 'error');
            }
        }

        // Actualizar interfaz con estado de verificaciones
        function updateVerificationUI(verificationData) {
            const statusContainer = document.getElementById('verification-status');
            
            // Status cards
            statusContainer.innerHTML = `
                <div class="col-md-6">
                    <div class="verification-section ${verificationData.email_verified ? 'status-verified' : 'status-pending'}">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6><i class="fas fa-envelope"></i> Email</h6>
                                <small>${verificationData.masked_email || 'No configurado'}</small>
                            </div>
                            <span class="verification-badge ${verificationData.email_verified ? 'badge-verified' : 'badge-required'}">
                                ${verificationData.email_verified ? 'Verificado' : 'Pendiente'}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="verification-section ${verificationData.phone_verified ? 'status-verified' : 'status-pending'}">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6><i class="fas fa-mobile-alt"></i> Teléfono</h6>
                                <small>${verificationData.masked_phone || 'No configurado'}</small>
                            </div>
                            <span class="verification-badge ${verificationData.phone_verified ? 'badge-verified' : 'badge-required'}">
                                ${verificationData.phone_verified ? 'Verificado' : 'Pendiente'}
                            </span>
                        </div>
                    </div>
                </div>
            `;
            
            // Email verification section
            const emailForm = document.getElementById('email-verification-form');
            const emailBadge = document.getElementById('email-status-badge');
            const emailDisplay = document.getElementById('email-display');
            
            if (verificationData.has_email) {
                emailDisplay.textContent = verificationData.masked_email;
                
                if (verificationData.email_verified) {
                    emailBadge.innerHTML = '<span class="badge bg-success"><i class="fas fa-check"></i> Verificado</span>';
                    emailForm.classList.add('d-none');
                } else {
                    emailBadge.innerHTML = '<span class="badge bg-warning"><i class="fas fa-clock"></i> Pendiente</span>';
                    emailForm.classList.remove('d-none');
                }
            } else {
                document.getElementById('email-verification-section').innerHTML = `
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Email no configurado</strong><br>
                        Agrega tu email en la pestaña de información personal para poder verificarlo.
                    </div>
                `;
            }
            
            // Phone verification section
            const phoneForm = document.getElementById('phone-verification-form');
            const phoneBadge = document.getElementById('phone-status-badge');
            const phoneDisplay = document.getElementById('phone-display');
            
            if (verificationData.has_phone) {
                phoneDisplay.textContent = verificationData.masked_phone;
                
                if (verificationData.phone_verified) {
                    phoneBadge.innerHTML = '<span class="badge bg-success"><i class="fas fa-check"></i> Verificado</span>';
                    phoneForm.classList.add('d-none');
                } else {
                    phoneBadge.innerHTML = '<span class="badge bg-warning"><i class="fas fa-clock"></i> Pendiente</span>';
                    phoneForm.classList.remove('d-none');
                }
            } else {
                document.getElementById('phone-verification-section').innerHTML = `
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Teléfono no configurado</strong><br>
                        Agrega tu teléfono en la pestaña de información personal para poder verificarlo.
                    </div>
                `;
            }
        }

        // Enviar verificación por email
        async function sendEmailVerification() {
            const button = event.target;
            const originalText = button.innerHTML;
            
            try {
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';
                button.disabled = true;
                
                const email = '<?= htmlspecialchars($usuario['email'] ?? '') ?>';
                
                const formData = new FormData();
                formData.append('action', 'send_email_verification');
                formData.append('email', email);
                
                const response = await fetch('api/verification.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showVerificationMessage('email-verification-message', data.message, 'success');
                    document.getElementById('email-code').focus();
                } else {
                    showVerificationMessage('email-verification-message', data.message, 'error');
                }
                
            } catch (error) {
                console.error('Error:', error);
                showVerificationMessage('email-verification-message', 'Error de conexión', 'error');
            } finally {
                button.innerHTML = originalText;
                button.disabled = false;
            }
        }

        // Verificar código de email
        async function verifyEmailCode() {
            const code = document.getElementById('email-code').value.trim();
            const button = event.target;
            const originalText = button.innerHTML;
            
            if (!code || code.length !== 6) {
                showVerificationMessage('email-verification-message', 'Ingresa un código de 6 dígitos', 'error');
                return;
            }
            
            try {
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                button.disabled = true;
                
                const formData = new FormData();
                formData.append('action', 'verify_email');
                formData.append('code', code);
                
                const response = await fetch('api/verification.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showVerificationMessage('email-verification-message', data.message, 'success');
                    setTimeout(() => loadVerificationStatus(), 2000);
                } else {
                    showVerificationMessage('email-verification-message', data.message, 'error');
                }
                
            } catch (error) {
                console.error('Error:', error);
                showVerificationMessage('email-verification-message', 'Error de conexión', 'error');
            } finally {
                button.innerHTML = originalText;
                button.disabled = false;
            }
        }

        // Enviar verificación por SMS
        async function sendSMSVerification() {
            const button = event.target;
            const originalText = button.innerHTML;
            
            try {
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';
                button.disabled = true;
                
                const telefono = '<?= htmlspecialchars($usuario['telefono'] ?? '') ?>';
                
                if (!telefono) {
                    showVerificationMessage('phone-verification-message', 'Primero configura tu teléfono', 'error');
                    return;
                }
                
                const formData = new FormData();
                formData.append('action', 'send_sms_verification');
                formData.append('phone', telefono);
                
                const response = await fetch('api/verification.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showVerificationMessage('phone-verification-message', data.message, 'success');
                    currentVerificationType = 'phone_verification';
                    document.getElementById('phone-code').focus();
                } else {
                    showVerificationMessage('phone-verification-message', data.message, 'error');
                }
                
            } catch (error) {
                console.error('Error:', error);
                showVerificationMessage('phone-verification-message', 'Error de conexión', 'error');
            } finally {
                button.innerHTML = originalText;
                button.disabled = false;
            }
        }

        // Enviar verificación por WhatsApp
        async function sendWhatsAppVerification() {
            const button = event.target;
            const originalText = button.innerHTML;
            
            try {
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';
                button.disabled = true;
                
                const telefono = '<?= htmlspecialchars($usuario['telefono'] ?? '') ?>';
                
                if (!telefono) {
                    showVerificationMessage('phone-verification-message', 'Primero configura tu teléfono', 'error');
                    return;
                }
                
                const formData = new FormData();
                formData.append('action', 'send_whatsapp_verification');
                formData.append('phone', telefono);
                
                const response = await fetch('api/verification.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showVerificationMessage('phone-verification-message', data.message, 'success');
                    currentVerificationType = 'whatsapp_verification';
                    document.getElementById('phone-code').focus();
                } else {
                    showVerificationMessage('phone-verification-message', data.message, 'error');
                }
                
            } catch (error) {
                console.error('Error:', error);
                showVerificationMessage('phone-verification-message', 'Error de conexión', 'error');
            } finally {
                button.innerHTML = originalText;
                button.disabled = false;
            }
        }

        // Verificar código de teléfono
        async function verifyPhoneCode() {
            const code = document.getElementById('phone-code').value.trim();
            const button = event.target;
            const originalText = button.innerHTML;
            
            if (!code || code.length !== 6) {
                showVerificationMessage('phone-verification-message', 'Ingresa un código de 6 dígitos', 'error');
                return;
            }
            
            if (!currentVerificationType) {
                showVerificationMessage('phone-verification-message', 'Primero solicita un código', 'error');
                return;
            }
            
            try {
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                button.disabled = true;
                
                const formData = new FormData();
                formData.append('action', 'verify_phone');
                formData.append('code', code);
                formData.append('verification_type', currentVerificationType);
                
                const response = await fetch('api/verification.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showVerificationMessage('phone-verification-message', data.message, 'success');
                    setTimeout(() => {
                        loadVerificationStatus();
                        currentVerificationType = '';
                    }, 2000);
                } else {
                    showVerificationMessage('phone-verification-message', data.message, 'error');
                }
                
            } catch (error) {
                console.error('Error:', error);
                showVerificationMessage('phone-verification-message', 'Error de conexión', 'error');
            } finally {
                button.innerHTML = originalText;
                button.disabled = false;
            }
        }

        // Cargar historial de verificaciones
        async function loadVerificationHistory() {
            try {
                const response = await fetch('api/verification.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=get_verification_history'
                });
                
                const data = await response.json();
                
                if (data.success) {
                    displayVerificationHistory(data.data);
                }
                
            } catch (error) {
                console.error('Error cargando historial:', error);
            }
        }

        // Mostrar historial de verificaciones
        function displayVerificationHistory(history) {
            const historyContainer = document.getElementById('verification-history');
            
            if (!history || history.length === 0) {
                historyContainer.innerHTML = '<p class="text-muted">No hay actividad reciente</p>';
                return;
            }
            
            let historyHTML = '';
            
            history.forEach(item => {
                const date = new Date(item.created_at).toLocaleString('es-MX');
                const iconClass = item.success ? 'history-success' : 'history-failed';
                const iconName = item.success ? 'fa-check' : 'fa-times';
                const description = item.action === 'sent' ? 'Código enviado' : 
                                   item.action === 'verified' ? 'Verificación exitosa' : 'Verificación fallida';
                
                historyHTML += `
                    <div class="history-item">
                        <div class="history-icon ${iconClass}">
                            <i class="fas ${iconName}"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between">
                                <strong>${description}</strong>
                                <small class="text-muted">${date}</small>
                            </div>
                            <small class="text-muted">
                                ${item.contact_info} • ${item.method.toUpperCase()}
                            </small>
                        </div>
                    </div>
                `;
            });
            
            historyContainer.innerHTML = historyHTML;
        }

        // Mostrar mensajes de verificación
        function showVerificationMessage(containerId, message, type) {
            const container = document.getElementById(containerId);
            if (!container) return;
            
            const alertClass = type === 'success' ? 'alert-success' : 
                               type === 'error' ? 'alert-danger' : 'alert-info';
            
            const icon = type === 'success' ? 'fa-check-circle' : 
                         type === 'error' ? 'fa-exclamation-triangle' : 'fa-info-circle';
            
            container.innerHTML = `
                <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                    <i class="fas ${icon}"></i> ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            
            // Auto-ocultar después de 8 segundos
            setTimeout(() => {
                const alert = container.querySelector('.alert');
                if (alert) {
                    alert.classList.remove('show');
                    setTimeout(() => alert.remove(), 150);
                }
            }, 8000);
        }

        // Auto-formato para códigos de verificación
        document.addEventListener('DOMContentLoaded', function() {
            // Email code formatting
            const emailCodeInput = document.getElementById('email-code');
            if (emailCodeInput) {
                emailCodeInput.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/\D/g, '');
                    e.target.value = value.substring(0, 6);
                    
                    if (value.length === 6) {
                        setTimeout(() => verifyEmailCode(), 100);
                    }
                });
            }
            
            // Phone code formatting
            const phoneCodeInput = document.getElementById('phone-code');
            if (phoneCodeInput) {
                phoneCodeInput.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/\D/g, '');
                    e.target.value = value.substring(0, 6);
                    
                    if (value.length === 6 && currentVerificationType) {
                        setTimeout(() => verifyPhoneCode(), 100);
                    }
                });
            }
        });
        
        // ===== FUNCIONES ORIGINALES DEL PERFIL =====
        
        // Función para mostrar/ocultar contraseña
        function togglePassword(fieldId) {
            const passwordInput = document.getElementById(fieldId);
            const passwordIcon = document.getElementById(fieldId + '-icon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                passwordIcon.classList.remove('fa-eye');
                passwordIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                passwordIcon.classList.remove('fa-eye-slash');
                passwordIcon.classList.add('fa-eye');
            }
        }
        
        // Formatear número de tarjeta
        document.addEventListener('DOMContentLoaded', function() {
            const numeroTarjeta = document.querySelector('input[name="numero_tarjeta"]');
            if (numeroTarjeta) {
                numeroTarjeta.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/\s+/g, '').replace(/[^0-9]/gi, '');
                    let formattedValue = value.match(/.{1,4}/g)?.join(' ') || value;
                    e.target.value = formattedValue;
                });
            }
        });
        
        // Función para cerrar sesión
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
                
                const data = await response.json();
                
                if (data.success) {
                    alert('Sesión cerrada correctamente');
                    window.location.href = 'index.php';
                } else {
                    alert('Error al cerrar sesión: ' + data.message);
                }
                
            } catch (error) {
                console.error('Error:', error);
                alert('Error al cerrar sesión');
            }
        }
        
        // Validación del formulario de perfil
        document.getElementById('profileForm').addEventListener('submit', function(e) {
            const nombre = document.getElementById('nombre').value.trim();
            
            if (!nombre) {
                e.preventDefault();
                alert('El nombre es requerido');
                return;
            }
            
            // Mostrar loading
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Actualizando...';
            submitBtn.disabled = true;
            
            setTimeout(() => {
                if (submitBtn.disabled) {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            }, 5000);
        });
        
        // Validación del formulario de contraseña
        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            const currentPassword = document.getElementById('current_password').value;
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (!currentPassword || !newPassword || !confirmPassword) {
                e.preventDefault();
                alert('Todos los campos de contraseña son requeridos');
                return;
            }
            
            if (newPassword.length < 6) {
                e.preventDefault();
                alert('La nueva contraseña debe tener al menos 6 caracteres');
                return;
            }
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('La nueva contraseña y su confirmación no coinciden');
                return;
            }
        });
        
        // Validación de contraseñas coincidentes en tiempo real
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (confirmPassword.length > 0) {
                if (newPassword === confirmPassword) {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                } else {
                    this.classList.remove('is-valid');
                    this.classList.add('is-invalid');
                }
            } else {
                this.classList.remove('is-valid', 'is-invalid');
            }
        });
        
        // Formatear teléfono
        document.getElementById('telefono').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length >= 10) {
                value = value.substring(0, 10);
                value = value.replace(/(\d{3})(\d{3})(\d{4})/, '$1-$2-$3');
            }
            e.target.value = value;
        });
        
        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            // Actualizar contador del carrito
            if (typeof actualizarContadorCarrito === 'function') {
                actualizarContadorCarrito();
            }
            
            // Animación de entrada
            const cards = document.querySelectorAll('.profile-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(30px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
            
            // Auto-ocultar alertas después de 5 segundos
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    if (alert.classList.contains('show')) {
                        alert.classList.remove('show');
                        setTimeout(() => alert.remove(), 150);
                    }
                });
            }, 5000);
        });
    </script>
</body>
</html>