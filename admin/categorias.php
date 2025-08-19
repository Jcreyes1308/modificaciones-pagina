<?php
// admin/categorias.php - Gestión de Categorías y Temporadas
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
    
    if ($action === 'add_categoria') {
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $temporada_asociada = $_POST['temporada_asociada'] ?? 'siempre';
        $color_categoria = $_POST['color_categoria'] ?? '#007bff';
        $icono = $_POST['icono'] ?? 'fas fa-box';
        
        if (empty($nombre)) {
            $error = 'El nombre de la categoría es requerido';
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO categorias (nombre, descripcion, temporada_asociada, color_categoria, icono) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$nombre, $descripcion, $temporada_asociada, $color_categoria, $icono]);
                $success = 'Categoría agregada exitosamente';
            } catch (Exception $e) {
                $error = 'Error al agregar categoría: ' . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'update_categoria') {
        $id = intval($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $temporada_asociada = $_POST['temporada_asociada'] ?? 'siempre';
        $color_categoria = $_POST['color_categoria'] ?? '#007bff';
        $icono = $_POST['icono'] ?? 'fas fa-box';
        
        if ($id <= 0 || empty($nombre)) {
            $error = 'Datos inválidos para actualizar';
        } else {
            try {
                $stmt = $conn->prepare("UPDATE categorias SET nombre = ?, descripcion = ?, temporada_asociada = ?, color_categoria = ?, icono = ? WHERE id = ?");
                $stmt->execute([$nombre, $descripcion, $temporada_asociada, $color_categoria, $icono, $id]);
                $success = 'Categoría actualizada exitosamente';
            } catch (Exception $e) {
                $error = 'Error al actualizar categoría: ' . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'delete_categoria') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $conn->prepare("UPDATE categorias SET activo = 0 WHERE id = ?");
                $stmt->execute([$id]);
                $success = 'Categoría eliminada exitosamente';
            } catch (Exception $e) {
                $error = 'Error al eliminar categoría: ' . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'add_temporada') {
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $mes_numero = intval($_POST['mes_numero'] ?? 0);
        $mes_nombre = $_POST['mes_nombre'] ?? '';
        $keyword = strtolower(trim($_POST['keyword'] ?? ''));
        $fecha_inicio = $_POST['fecha_inicio'] ?? '';
        $fecha_fin = $_POST['fecha_fin'] ?? '';
        $titulo_banner = trim($_POST['titulo_banner'] ?? '');
        $subtitulo_banner = trim($_POST['subtitulo_banner'] ?? '');
        $color_tema = $_POST['color_tema'] ?? '#007bff';
        $icono_tema = $_POST['icono_tema'] ?? 'fas fa-star';
        $prioridad = intval($_POST['prioridad'] ?? 0);
        
        if (empty($nombre) || $mes_numero <= 0 || empty($keyword)) {
            $error = 'Nombre, mes y keyword son requeridos para la temporada';
        } else {
            try {
                $stmt = $conn->prepare("
                    INSERT INTO temporadas (nombre, descripcion, mes_numero, mes_nombre, keyword, fecha_inicio, fecha_fin, titulo_banner, subtitulo_banner, color_tema, icono_tema, prioridad) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$nombre, $descripcion, $mes_numero, $mes_nombre, $keyword, $fecha_inicio, $fecha_fin, $titulo_banner, $subtitulo_banner, $color_tema, $icono_tema, $prioridad]);
                $success = 'Temporada agregada exitosamente';
            } catch (Exception $e) {
                $error = 'Error al agregar temporada: ' . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'update_temporada') {
        $id = intval($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $mes_numero = intval($_POST['mes_numero'] ?? 0);
        $mes_nombre = $_POST['mes_nombre'] ?? '';
        $keyword = strtolower(trim($_POST['keyword'] ?? ''));
        $fecha_inicio = $_POST['fecha_inicio'] ?? '';
        $fecha_fin = $_POST['fecha_fin'] ?? '';
        $titulo_banner = trim($_POST['titulo_banner'] ?? '');
        $subtitulo_banner = trim($_POST['subtitulo_banner'] ?? '');
        $color_tema = $_POST['color_tema'] ?? '#007bff';
        $icono_tema = $_POST['icono_tema'] ?? 'fas fa-star';
        $prioridad = intval($_POST['prioridad'] ?? 0);
        
        if ($id <= 0 || empty($nombre)) {
            $error = 'Datos inválidos para actualizar temporada';
        } else {
            try {
                $stmt = $conn->prepare("
                    UPDATE temporadas SET nombre = ?, descripcion = ?, mes_numero = ?, mes_nombre = ?, keyword = ?, fecha_inicio = ?, fecha_fin = ?, titulo_banner = ?, subtitulo_banner = ?, color_tema = ?, icono_tema = ?, prioridad = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$nombre, $descripcion, $mes_numero, $mes_nombre, $keyword, $fecha_inicio, $fecha_fin, $titulo_banner, $subtitulo_banner, $color_tema, $icono_tema, $prioridad, $id]);
                $success = 'Temporada actualizada exitosamente';
            } catch (Exception $e) {
                $error = 'Error al actualizar temporada: ' . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'delete_temporada') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $conn->prepare("UPDATE temporadas SET activo = 0 WHERE id = ?");
                $stmt->execute([$id]);
                $success = 'Temporada eliminada exitosamente';
            } catch (Exception $e) {
                $error = 'Error al eliminar temporada: ' . $e->getMessage();
            }
        }
    }
}

// Obtener categorías
try {
    $stmt = $conn->query("SELECT * FROM categorias WHERE activo = 1 ORDER BY nombre");
    $categorias = $stmt->fetchAll();
} catch (Exception $e) {
    $categorias = [];
    $error = 'Error al cargar categorías: ' . $e->getMessage();
}

// Obtener temporadas
try {
    $stmt = $conn->query("SELECT * FROM temporadas WHERE activo = 1 ORDER BY mes_numero");
    $temporadas = $stmt->fetchAll();
} catch (Exception $e) {
    $temporadas = [];
}

// Obtener estadísticas
$stats = [
    'total_categorias' => count($categorias),
    'total_temporadas' => count($temporadas)
];

// Si es editar, obtener datos específicos
$categoria_edit = null;
$temporada_edit = null;

if ($action === 'edit_categoria' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        $stmt = $conn->prepare("SELECT * FROM categorias WHERE id = ? AND activo = 1");
        $stmt->execute([$id]);
        $categoria_edit = $stmt->fetch();
    } catch (Exception $e) {
        $error = 'Error al cargar categoría';
    }
}

if ($action === 'edit_temporada' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        $stmt = $conn->prepare("SELECT * FROM temporadas WHERE id = ? AND activo = 1");
        $stmt->execute([$id]);
        $temporada_edit = $stmt->fetch();
    } catch (Exception $e) {
        $error = 'Error al cargar temporada';
    }
}

// Meses del año
$meses = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];

// Iconos comunes
$iconos_comunes = [
    'fas fa-box' => 'Caja General',
    'fas fa-tshirt' => 'Ropa',
    'fas fa-shoe-prints' => 'Zapatos',
    'fas fa-party-horn' => 'Fiesta',
    'fas fa-pen' => 'Papelería',
    'fas fa-palette' => 'Cosméticos',
    'fas fa-gem' => 'Accesorios',
    'fas fa-flag' => 'Patrios',
    'fas fa-gift' => 'Regalos',
    'fas fa-heart' => 'San Valentín',
    'fas fa-female' => 'Día Madres',
    'fas fa-male' => 'Día Padre',
    'fas fa-ghost' => 'Halloween',
    'fas fa-star' => 'Destacados',
    'fas fa-crown' => 'Premium',
    'fas fa-fire' => 'Ofertas',
    'fas fa-graduation-cap' => 'Escolar',
    'fas fa-sun' => 'Verano',
    'fas fa-snowflake' => 'Invierno'
];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categorías y Temporadas - Admin</title>
    
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
        
        .category-card, .season-card {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .category-card:hover, .season-card:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .category-preview {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            color: white;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .season-preview {
            display: inline-block;
            padding: 10px 20px;
            border-radius: 25px;
            color: white;
            font-weight: bold;
            margin-bottom: 10px;
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
        
        .color-picker-preview {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 2px solid #ddd;
            display: inline-block;
            margin-left: 10px;
            cursor: pointer;
        }
        
        .icon-selector {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(60px, 1fr));
            gap: 10px;
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 8px;
            background: #f8f9fa;
        }
        
        .icon-option {
            padding: 10px;
            text-align: center;
            border: 2px solid #ddd;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
        }
        
        .icon-option:hover {
            border-color: #667eea;
            background: #f0f8ff;
        }
        
        .icon-option.selected {
            border-color: #28a745;
            background: #d4edda;
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
        
        .stats-row {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
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
            <a class="nav-link active" href="categorias.php">
                <i class="fas fa-tags me-2"></i> Categorías
            </a>
            <a class="nav-link" href="pedidos.php">
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
                <i class="fas fa-tags text-primary"></i> 
                Gestión de Categorías y Temporadas
            </h2>
        </div>
        
        <!-- Estadísticas -->
        <div class="stats-row">
            <div class="row text-center">
                <div class="col-md-6">
                    <h3><?= $stats['total_categorias'] ?></h3>
                    <p>📂 Categorías Activas</p>
                </div>
                <div class="col-md-6">
                    <h3><?= $stats['total_temporadas'] ?></h3>
                    <p>🗓️ Temporadas Configuradas</p>
                </div>
            </div>
        </div>
        
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
        
        <!-- Pestañas -->
        <ul class="nav nav-tabs mb-4" id="managementTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="categories-tab" data-bs-toggle="tab" data-bs-target="#categories" type="button" role="tab">
                    <i class="fas fa-tags"></i> Categorías
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="seasons-tab" data-bs-toggle="tab" data-bs-target="#seasons" type="button" role="tab">
                    <i class="fas fa-calendar-alt"></i> Temporadas
                </button>
            </li>
        </ul>

        <div class="tab-content" id="managementTabContent">
            <!-- TAB CATEGORÍAS -->
            <div class="tab-pane fade show active" id="categories" role="tabpanel">
                <div class="row">
                    <!-- Formulario de categorías -->
                    <div class="col-lg-4">
                        <div class="admin-card">
                            <h5>
                                <i class="fas fa-plus"></i> 
                                <?= $categoria_edit ? 'Editar Categoría' : 'Agregar Categoría' ?>
                            </h5>
                            
                            <form method="POST" id="categoryForm">
                                <input type="hidden" name="action" value="<?= $categoria_edit ? 'update_categoria' : 'add_categoria' ?>">
                                <?php if ($categoria_edit): ?>
                                    <input type="hidden" name="id" value="<?= $categoria_edit['id'] ?>">
                                <?php endif; ?>
                                
                                <div class="mb-3">
                                    <label class="form-label">Nombre *</label>
                                    <input type="text" class="form-control" name="nombre" 
                                           value="<?= $categoria_edit ? htmlspecialchars($categoria_edit['nombre']) : '' ?>" 
                                           required maxlength="100">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Descripción</label>
                                    <textarea class="form-control" name="descripcion" rows="3"><?= $categoria_edit ? htmlspecialchars($categoria_edit['descripcion']) : '' ?></textarea>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Temporada Asociada</label>
                                    <select class="form-select" name="temporada_asociada">
                                        <option value="siempre" <?= ($categoria_edit && $categoria_edit['temporada_asociada'] === 'siempre') ? 'selected' : '' ?>>Siempre disponible</option>
                                        <option value="enero" <?= ($categoria_edit && $categoria_edit['temporada_asociada'] === 'enero') ? 'selected' : '' ?>>Enero</option>
                                        <option value="febrero" <?= ($categoria_edit && $categoria_edit['temporada_asociada'] === 'febrero') ? 'selected' : '' ?>>Febrero</option>
                                        <option value="marzo" <?= ($categoria_edit && $categoria_edit['temporada_asociada'] === 'marzo') ? 'selected' : '' ?>>Marzo</option>
                                        <option value="abril" <?= ($categoria_edit && $categoria_edit['temporada_asociada'] === 'abril') ? 'selected' : '' ?>>Abril</option>
                                        <option value="mayo" <?= ($categoria_edit && $categoria_edit['temporada_asociada'] === 'mayo') ? 'selected' : '' ?>>Mayo</option>
                                        <option value="junio" <?= ($categoria_edit && $categoria_edit['temporada_asociada'] === 'junio') ? 'selected' : '' ?>>Junio</option>
                                        <option value="julio" <?= ($categoria_edit && $categoria_edit['temporada_asociada'] === 'julio') ? 'selected' : '' ?>>Julio</option>
                                        <option value="agosto" <?= ($categoria_edit && $categoria_edit['temporada_asociada'] === 'agosto') ? 'selected' : '' ?>>Agosto</option>
                                        <option value="septiembre" <?= ($categoria_edit && $categoria_edit['temporada_asociada'] === 'septiembre') ? 'selected' : '' ?>>Septiembre</option>
                                        <option value="octubre" <?= ($categoria_edit && $categoria_edit['temporada_asociada'] === 'octubre') ? 'selected' : '' ?>>Octubre</option>
                                        <option value="noviembre" <?= ($categoria_edit && $categoria_edit['temporada_asociada'] === 'noviembre') ? 'selected' : '' ?>>Noviembre</option>
                                        <option value="diciembre" <?= ($categoria_edit && $categoria_edit['temporada_asociada'] === 'diciembre') ? 'selected' : '' ?>>Diciembre</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Color de Categoría</label>
                                    <div class="d-flex align-items-center">
                                        <input type="color" class="form-control form-control-color" name="color_categoria" 
                                               value="<?= $categoria_edit ? $categoria_edit['color_categoria'] : '#007bff' ?>" 
                                               style="width: 60px;">
                                        <span class="ms-2">Vista previa del color</span>
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="form-label">Icono</label>
                                    <input type="hidden" name="icono" id="selected-icon-cat" value="<?= $categoria_edit ? $categoria_edit['icono'] : 'fas fa-box' ?>">
                                    <div class="icon-selector" id="icon-selector-cat">
                                        <?php foreach ($iconos_comunes as $icono => $descripcion): ?>
                                            <div class="icon-option <?= ($categoria_edit && $categoria_edit['icono'] === $icono) ? 'selected' : '' ?>" 
                                                 data-icon="<?= $icono ?>" onclick="selectIcon('cat', '<?= $icono ?>')">
                                                <i class="<?= $icono ?>"></i><br>
                                                <small><?= $descripcion ?></small>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-admin">
                                        <i class="fas fa-save"></i> <?= $categoria_edit ? 'Actualizar' : 'Guardar' ?> Categoría
                                    </button>
                                    <?php if ($categoria_edit): ?>
                                        <a href="categorias.php" class="btn btn-secondary">
                                            <i class="fas fa-times"></i> Cancelar
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Lista de categorías -->
                    <div class="col-lg-8">
                        <div class="admin-card">
                            <h5><i class="fas fa-list"></i> Categorías Existentes (<?= count($categorias) ?>)</h5>
                            
                            <?php if (count($categorias) > 0): ?>
                                <?php foreach ($categorias as $categoria): ?>
                                    <div class="category-card">
                                        <div class="row align-items-center">
                                            <div class="col-md-8">
                                                <div class="category-preview" style="background: <?= $categoria['color_categoria'] ?>;">
                                                    <i class="<?= $categoria['icono'] ?>"></i> <?= htmlspecialchars($categoria['nombre']) ?>
                                                </div>
                                                <p class="mb-1"><?= htmlspecialchars($categoria['descripcion']) ?></p>
                                                <small class="text-muted">
                                                    <i class="fas fa-calendar"></i> Temporada: <?= ucfirst($categoria['temporada_asociada']) ?>
                                                </small>
                                            </div>
                                            <div class="col-md-4 text-end">
                                                <a href="categorias.php?action=edit_categoria&id=<?= $categoria['id'] ?>" 
                                                   class="btn btn-outline-primary btn-sm">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button class="btn btn-outline-danger btn-sm" 
                                                        onclick="deleteCategory(<?= $categoria['id'] ?>, '<?= htmlspecialchars($categoria['nombre']) ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-tags fa-3x text-muted mb-3"></i>
                                    <p>No hay categorías creadas aún</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB TEMPORADAS -->
            <div class="tab-pane fade" id="seasons" role="tabpanel">
                <div class="row">
                    <!-- Formulario de temporadas -->
                    <div class="col-lg-5">
                        <div class="admin-card">
                            <h5>
                                <i class="fas fa-plus"></i> 
                                <?= $temporada_edit ? 'Editar Temporada' : 'Agregar Temporada' ?>
                            </h5>
                            
                            <form method="POST" id="seasonForm">
                                <input type="hidden" name="action" value="<?= $temporada_edit ? 'update_temporada' : 'add_temporada' ?>">
                                <?php if ($temporada_edit): ?>
                                    <input type="hidden" name="id" value="<?= $temporada_edit['id'] ?>">
                                <?php endif; ?>
                                
                                <div class="row">
                                    <div class="col-12 mb-3">
                                        <label class="form-label">Nombre de la Temporada *</label>
                                        <input type="text" class="form-control" name="nombre" 
                                               value="<?= $temporada_edit ? htmlspecialchars($temporada_edit['nombre']) : '' ?>" 
                                               required maxlength="100" placeholder="Ej: Navidad 2024">
                                    </div>
                                    
                                    <div class="col-12 mb-3">
                                        <label class="form-label">Descripción</label>
                                        <textarea class="form-control" name="descripcion" rows="2" 
                                                  placeholder="Descripción de la temporada"><?= $temporada_edit ? htmlspecialchars($temporada_edit['descripcion']) : '' ?></textarea>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Mes Principal *</label>
                                        <select class="form-select" name="mes_numero" required>
                                            <option value="">Seleccionar mes</option>
                                            <?php foreach ($meses as $num => $nombre): ?>
                                                <option value="<?= $num ?>" 
                                                        <?= ($temporada_edit && $temporada_edit['mes_numero'] == $num) ? 'selected' : '' ?>>
                                                    <?= $nombre ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Nombre del Mes</label>
                                        <input type="text" class="form-control" name="mes_nombre" 
                                               value="<?= $temporada_edit ? htmlspecialchars($temporada_edit['mes_nombre']) : '' ?>" 
                                               placeholder="Se llena automáticamente">
                                    </div>
                                    
                                    <div class="col-12 mb-3">
                                        <label class="form-label">Keyword (para categorías) *</label>
                                        <input type="text" class="form-control" name="keyword" 
                                               value="<?= $temporada_edit ? htmlspecialchars($temporada_edit['keyword']) : '' ?>" 
                                               required placeholder="Ej: navidad, agosto, septiembre">
                                        <small class="text-muted">Palabra clave que conecta con las categorías</small>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Fecha Inicio</label>
                                        <input type="date" class="form-control" name="fecha_inicio" 
                                               value="<?= $temporada_edit ? $temporada_edit['fecha_inicio'] : '' ?>">
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Fecha Fin</label>
                                        <input type="date" class="form-control" name="fecha_fin" 
                                               value="<?= $temporada_edit ? $temporada_edit['fecha_fin'] : '' ?>">
                                    </div>
                                    
                                    <div class="col-12 mb-3">
                                        <label class="form-label">Título del Banner</label>
                                        <input type="text" class="form-control" name="titulo_banner" 
                                               value="<?= $temporada_edit ? htmlspecialchars($temporada_edit['titulo_banner']) : '' ?>" 
                                               placeholder="Ej: 🎄 Especial Navidad 2024">
                                    </div>
                                    
                                    <div class="col-12 mb-3">
                                        <label class="form-label">Subtítulo del Banner</label>
                                        <input type="text" class="form-control" name="subtitulo_banner" 
                                               value="<?= $temporada_edit ? htmlspecialchars($temporada_edit['subtitulo_banner']) : '' ?>" 
                                               placeholder="Ej: Encuentra todo para hacer mágica tu navidad">
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Color del Tema</label>
                                        <input type="color" class="form-control form-control-color" name="color_tema" 
                                               value="<?= $temporada_edit ? $temporada_edit['color_tema'] : '#007bff' ?>">
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Prioridad</label>
                                        <input type="number" class="form-control" name="prioridad" 
                                               value="<?= $temporada_edit ? $temporada_edit['prioridad'] : '0' ?>" 
                                               min="0" max="10">
                                        <small class="text-muted">0 = baja, 10 = alta prioridad</small>
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="form-label">Icono del Tema</label>
                                    <input type="hidden" name="icono_tema" id="selected-icon-season" value="<?= $temporada_edit ? $temporada_edit['icono_tema'] : 'fas fa-star' ?>">
                                    <div class="icon-selector" id="icon-selector-season">
                                        <?php foreach ($iconos_comunes as $icono => $descripcion): ?>
                                            <div class="icon-option <?= ($temporada_edit && $temporada_edit['icono_tema'] === $icono) ? 'selected' : '' ?>" 
                                                 data-icon="<?= $icono ?>" onclick="selectIcon('season', '<?= $icono ?>')">
                                                <i class="<?= $icono ?>"></i><br>
                                                <small><?= $descripcion ?></small>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-admin">
                                        <i class="fas fa-save"></i> <?= $temporada_edit ? 'Actualizar' : 'Guardar' ?> Temporada
                                    </button>
                                    <?php if ($temporada_edit): ?>
                                        <a href="categorias.php#seasons" class="btn btn-secondary">
                                            <i class="fas fa-times"></i> Cancelar
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Lista de temporadas -->
                    <div class="col-lg-7">
                        <div class="admin-card">
                            <h5><i class="fas fa-calendar-alt"></i> Temporadas Configuradas (<?= count($temporadas) ?>)</h5>
                            
                            <?php if (count($temporadas) > 0): ?>
                                <?php foreach ($temporadas as $temporada): ?>
                                    <div class="season-card">
                                        <div class="row align-items-center">
                                            <div class="col-md-8">
                                                <div class="season-preview" style="background: <?= $temporada['color_tema'] ?>;">
                                                    <i class="<?= $temporada['icono_tema'] ?>"></i> <?= htmlspecialchars($temporada['nombre']) ?>
                                                </div>
                                                
                                                <?php if ($temporada['titulo_banner']): ?>
                                                    <h6 class="mb-1"><?= htmlspecialchars($temporada['titulo_banner']) ?></h6>
                                                <?php endif; ?>
                                                
                                                <?php if ($temporada['subtitulo_banner']): ?>
                                                    <p class="mb-1 text-muted"><?= htmlspecialchars($temporada['subtitulo_banner']) ?></p>
                                                <?php endif; ?>
                                                
                                                <small class="text-muted">
                                                    <i class="fas fa-calendar"></i> <?= $meses[$temporada['mes_numero']] ?? 'N/A' ?> 
                                                    | <i class="fas fa-key"></i> <?= htmlspecialchars($temporada['keyword']) ?>
                                                    | <i class="fas fa-sort-numeric-up"></i> Prioridad: <?= $temporada['prioridad'] ?>
                                                </small><br>
                                                
                                                <?php if ($temporada['fecha_inicio'] && $temporada['fecha_fin']): ?>
                                                    <small class="text-info">
                                                        <i class="fas fa-clock"></i> 
                                                        <?= date('d/m/Y', strtotime($temporada['fecha_inicio'])) ?> - 
                                                        <?= date('d/m/Y', strtotime($temporada['fecha_fin'])) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-md-4 text-end">
                                                <a href="categorias.php?action=edit_temporada&id=<?= $temporada['id'] ?>#seasons" 
                                                   class="btn btn-outline-primary btn-sm">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button class="btn btn-outline-danger btn-sm" 
                                                        onclick="deleteSeason(<?= $temporada['id'] ?>, '<?= htmlspecialchars($temporada['nombre']) ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-calendar-alt fa-3x text-muted mb-3"></i>
                                    <p>No hay temporadas configuradas aún</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modales de confirmación -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirmar Eliminación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>¿Estás seguro de que quieres eliminar <strong id="item-name"></strong>?</p>
                    <p class="text-muted">Esta acción no se puede deshacer.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <form method="POST" style="display: inline;" id="delete-form">
                        <input type="hidden" name="action" id="delete-action">
                        <input type="hidden" name="id" id="delete-id">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Eliminar
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Función para seleccionar icono
        function selectIcon(type, iconClass) {
            // Remover selección anterior
            document.querySelectorAll(`#icon-selector-${type} .icon-option`).forEach(option => {
                option.classList.remove('selected');
            });
            
            // Seleccionar nuevo icono
            event.target.closest('.icon-option').classList.add('selected');
            document.getElementById(`selected-icon-${type}`).value = iconClass;
        }
        
        // Auto-completar nombre del mes
        document.querySelector('select[name="mes_numero"]').addEventListener('change', function() {
            const meses = {
                1: 'Enero', 2: 'Febrero', 3: 'Marzo', 4: 'Abril',
                5: 'Mayo', 6: 'Junio', 7: 'Julio', 8: 'Agosto',
                9: 'Septiembre', 10: 'Octubre', 11: 'Noviembre', 12: 'Diciembre'
            };
            
            const mesNumero = this.value;
            if (mesNumero && meses[mesNumero]) {
                document.querySelector('input[name="mes_nombre"]').value = meses[mesNumero];
                
                // Auto-completar keyword si está vacío
                const keywordInput = document.querySelector('input[name="keyword"]');
                if (!keywordInput.value) {
                    keywordInput.value = meses[mesNumero].toLowerCase();
                }
            }
        });
        
        // Función para eliminar categoría
        function deleteCategory(id, name) {
            document.getElementById('item-name').textContent = `la categoría "${name}"`;
            document.getElementById('delete-action').value = 'delete_categoria';
            document.getElementById('delete-id').value = id;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
        
        // Función para eliminar temporada
        function deleteSeason(id, name) {
            document.getElementById('item-name').textContent = `la temporada "${name}"`;
            document.getElementById('delete-action').value = 'delete_temporada';
            document.getElementById('delete-id').value = id;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
        
        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            // Si hay hash en la URL, activar la pestaña correspondiente
            if (window.location.hash === '#seasons') {
                const seasonsTab = new bootstrap.Tab(document.getElementById('seasons-tab'));
                seasonsTab.show();
            }
            
            // Animación de entrada
            const cards = document.querySelectorAll('.admin-card, .category-card, .season-card');
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
        document.getElementById('categoryForm').addEventListener('submit', function(e) {
            const nombre = this.querySelector('input[name="nombre"]').value.trim();
            if (!nombre) {
                e.preventDefault();
                alert('El nombre de la categoría es requerido');
                return;
            }
        });
        
        document.getElementById('seasonForm').addEventListener('submit', function(e) {
            const nombre = this.querySelector('input[name="nombre"]').value.trim();
            const mesNumero = this.querySelector('select[name="mes_numero"]').value;
            const keyword = this.querySelector('input[name="keyword"]').value.trim();
            
            if (!nombre || !mesNumero || !keyword) {
                e.preventDefault();
                alert('Nombre, mes y keyword son requeridos para la temporada');
                return;
            }
        });
    </script>
</body>
</html>