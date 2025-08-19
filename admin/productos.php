<?php
// admin/productos.php - Gestión de productos CON IMÁGENES
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

// Función para subir imagen
function subirImagen($archivo, $clave_producto) {
    $upload_dir = '../assets/images/products/';
    
    // Crear directorio si no existe
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Validar archivo
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($archivo['type'], $allowed_types)) {
        throw new Exception('Tipo de archivo no permitido. Solo JPG, PNG, GIF, WEBP');
    }
    
    if ($archivo['size'] > $max_size) {
        throw new Exception('El archivo es muy grande. Máximo 5MB');
    }
    
    // Generar nombre único
    $extension = pathinfo($archivo['name'], PATHINFO_EXTENSION);
    $nombre_archivo = $clave_producto . '_' . time() . '.' . $extension;
    $ruta_destino = $upload_dir . $nombre_archivo;
    
    // Mover archivo
    if (!move_uploaded_file($archivo['tmp_name'], $ruta_destino)) {
        throw new Exception('Error al subir el archivo');
    }
    
    return $nombre_archivo;
}

// Función para eliminar imagen
function eliminarImagen($nombre_archivo) {
    if ($nombre_archivo) {
        $ruta = '../assets/images/products/' . $nombre_archivo;
        if (file_exists($ruta)) {
            unlink($ruta);
        }
    }
}

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_product') {
        $clave_producto = trim($_POST['clave_producto'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $precio = floatval($_POST['precio'] ?? 0);
        $cantidad_etiquetas = intval($_POST['cantidad_etiquetas'] ?? 0);
        $categoria_id = intval($_POST['categoria_id'] ?? 0);
        $proveedor = trim($_POST['proveedor'] ?? '');
        $precio_compra = floatval($_POST['precio_compra'] ?? 0);
        $es_destacado = isset($_POST['es_destacado']) ? 1 : 0;
        
        if (empty($clave_producto) || empty($nombre) || $precio <= 0) {
            $error = 'Clave, nombre y precio son requeridos';
        } else {
            try {
                // Verificar que la clave no existe
                $stmt = $conn->prepare("SELECT id FROM productos WHERE clave_producto = ?");
                $stmt->execute([$clave_producto]);
                if ($stmt->fetch()) {
                    $error = 'La clave del producto ya existe';
                } else {
                    // Procesar imagen
                    $imagen = null;
                    if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
                        try {
                            $imagen = subirImagen($_FILES['imagen'], $clave_producto);
                        } catch (Exception $e) {
                            $error = 'Error con la imagen: ' . $e->getMessage();
                        }
                    }
                    
                    if (!$error) {
                        // Calcular total inventario
                        $total_inventario = $cantidad_etiquetas * $precio_compra;
                        
                        $stmt = $conn->prepare("
                            INSERT INTO productos (clave_producto, nombre, descripcion, precio, cantidad_etiquetas, categoria_id, proveedor, precio_compra, total_inventario, es_destacado, imagen) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$clave_producto, $nombre, $descripcion, $precio, $cantidad_etiquetas, $categoria_id, $proveedor, $precio_compra, $total_inventario, $es_destacado, $imagen]);
                        
                        $success = 'Producto agregado exitosamente';
                    }
                }
            } catch (Exception $e) {
                $error = 'Error al agregar producto: ' . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'update_product') {
        $id = intval($_POST['id'] ?? 0);
        $clave_producto = trim($_POST['clave_producto'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $precio = floatval($_POST['precio'] ?? 0);
        $cantidad_etiquetas = intval($_POST['cantidad_etiquetas'] ?? 0);
        $categoria_id = intval($_POST['categoria_id'] ?? 0);
        $proveedor = trim($_POST['proveedor'] ?? '');
        $precio_compra = floatval($_POST['precio_compra'] ?? 0);
        $es_destacado = isset($_POST['es_destacado']) ? 1 : 0;
        
        if ($id <= 0 || empty($nombre) || $precio <= 0) {
            $error = 'Datos inválidos para actualizar';
        } else {
            try {
                // Obtener imagen actual
                $stmt = $conn->prepare("SELECT imagen FROM productos WHERE id = ?");
                $stmt->execute([$id]);
                $producto_actual = $stmt->fetch();
                $imagen_actual = $producto_actual['imagen'] ?? null;
                
                // Procesar nueva imagen si se subió
                $nueva_imagen = $imagen_actual;
                if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
                    try {
                        $nueva_imagen = subirImagen($_FILES['imagen'], $clave_producto);
                        // Eliminar imagen anterior
                        if ($imagen_actual) {
                            eliminarImagen($imagen_actual);
                        }
                    } catch (Exception $e) {
                        $error = 'Error con la imagen: ' . $e->getMessage();
                    }
                }
                
                // Eliminar imagen si se solicitó
                if (isset($_POST['eliminar_imagen']) && $imagen_actual) {
                    eliminarImagen($imagen_actual);
                    $nueva_imagen = null;
                }
                
                if (!$error) {
                    $total_inventario = $cantidad_etiquetas * $precio_compra;
                    
                    $stmt = $conn->prepare("
                        UPDATE productos SET 
                        clave_producto = ?, nombre = ?, descripcion = ?, precio = ?, 
                        cantidad_etiquetas = ?, categoria_id = ?, proveedor = ?, 
                        precio_compra = ?, total_inventario = ?, es_destacado = ?, imagen = ?,
                        updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$clave_producto, $nombre, $descripcion, $precio, $cantidad_etiquetas, $categoria_id, $proveedor, $precio_compra, $total_inventario, $es_destacado, $nueva_imagen, $id]);
                    
                    $success = 'Producto actualizado exitosamente';
                }
            } catch (Exception $e) {
                $error = 'Error al actualizar producto: ' . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'delete_product') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                // Obtener imagen para eliminarla
                $stmt = $conn->prepare("SELECT imagen FROM productos WHERE id = ?");
                $stmt->execute([$id]);
                $producto = $stmt->fetch();
                
                // Eliminar imagen del servidor
                if ($producto && $producto['imagen']) {
                    eliminarImagen($producto['imagen']);
                }
                
                // Eliminar producto (soft delete)
                $stmt = $conn->prepare("UPDATE productos SET activo = 0 WHERE id = ?");
                $stmt->execute([$id]);
                $success = 'Producto eliminado exitosamente';
            } catch (Exception $e) {
                $error = 'Error al eliminar producto: ' . $e->getMessage();
            }
        }
    }
}

// Obtener filtros
$filter = $_GET['filter'] ?? '';
$search = trim($_GET['search'] ?? '');
$categoria_filter = $_GET['categoria'] ?? '';

// Construir consulta
$where_conditions = ['p.activo = 1'];
$params = [];

if ($search) {
    $where_conditions[] = '(p.nombre LIKE ? OR p.clave_producto LIKE ? OR p.descripcion LIKE ?)';
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if ($categoria_filter) {
    $where_conditions[] = 'p.categoria_id = ?';
    $params[] = $categoria_filter;
}

if ($filter === 'stock_bajo') {
    $where_conditions[] = 'p.cantidad_etiquetas < 5';
} elseif ($filter === 'destacados') {
    $where_conditions[] = 'p.es_destacado = 1';
}

$where_sql = implode(' AND ', $where_conditions);

// Obtener productos
try {
    $stmt = $conn->prepare("
        SELECT p.*, c.nombre as categoria_nombre
        FROM productos p
        LEFT JOIN categorias c ON p.categoria_id = c.id
        WHERE $where_sql
        ORDER BY p.updated_at DESC, p.nombre ASC
    ");
    $stmt->execute($params);
    $productos = $stmt->fetchAll();
} catch (Exception $e) {
    $productos = [];
    $error = 'Error al cargar productos: ' . $e->getMessage();
}

// Obtener categorías para los filtros
try {
    $stmt = $conn->query("SELECT * FROM categorias WHERE activo = 1 ORDER BY nombre");
    $categorias = $stmt->fetchAll();
} catch (Exception $e) {
    $categorias = [];
}

// Si es agregar/editar, obtener datos del producto específico
$producto_edit = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        $stmt = $conn->prepare("SELECT * FROM productos WHERE id = ? AND activo = 1");
        $stmt->execute([$id]);
        $producto_edit = $stmt->fetch();
    } catch (Exception $e) {
        $error = 'Error al cargar producto para editar';
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Productos - Admin</title>
    
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
        
        .product-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        
        .product-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .stock-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .stock-high { background: #d4edda; color: #155724; }
        .stock-medium { background: #fff3cd; color: #856404; }
        .stock-low { background: #f8d7da; color: #721c24; }
        
        .btn-admin {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
            color: white;
        }
        
        .btn-admin:hover {
            color: white;
            transform: translateY(-2px);
        }
        
        /* Estilos para la carga de imagen */
        .image-upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 40px;
            text-align: center;
            background: #f8f9fa;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .image-upload-area:hover {
            border-color: #667eea;
            background: #f0f8ff;
        }
        
        .image-upload-area.dragover {
            border-color: #28a745;
            background: #f0fff0;
        }
        
        .image-preview {
            max-width: 200px;
            max-height: 200px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .current-image {
            position: relative;
            display: inline-block;
        }
        
        .remove-image-btn {
            position: absolute;
            top: -10px;
            right: -10px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .product-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
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
            <a class="nav-link active" href="productos.php">
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
                <i class="fas fa-box text-primary"></i> 
                <?= $action === 'add' ? 'Agregar Producto' : ($action === 'edit' ? 'Editar Producto' : 'Gestión de Productos') ?>
            </h2>
            
            <?php if ($action !== 'add' && $action !== 'edit'): ?>
                <a href="productos.php?action=add" class="btn btn-admin">
                    <i class="fas fa-plus"></i> Agregar Producto
                </a>
            <?php endif; ?>
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
        
        <?php if ($action === 'add' || $action === 'edit'): ?>
            <!-- Formulario de producto -->
            <div class="product-card">
                <form method="POST" enctype="multipart/form-data" id="productForm">
                    <input type="hidden" name="action" value="<?= $action === 'edit' ? 'update_product' : 'add_product' ?>">
                    <?php if ($action === 'edit' && $producto_edit): ?>
                        <input type="hidden" name="id" value="<?= $producto_edit['id'] ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <!-- Sección de imagen -->
                        <div class="col-md-4 mb-4">
                            <label class="form-label">Imagen del Producto</label>
                            
                            <?php if ($action === 'edit' && $producto_edit && $producto_edit['imagen']): ?>
                                <!-- Imagen actual -->
                                <div class="current-image mb-3" id="currentImageContainer">
                                    <img src="../assets/images/products/<?= htmlspecialchars($producto_edit['imagen']) ?>" 
                                         alt="Imagen actual" class="image-preview">
                                    <button type="button" class="remove-image-btn" onclick="removeCurrentImage()" title="Eliminar imagen">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <input type="hidden" name="eliminar_imagen" id="eliminar_imagen" value="">
                            <?php endif; ?>
                            
                            <!-- Área de carga -->
                            <div class="image-upload-area" onclick="document.getElementById('imagen').click()" 
                                 ondrop="dropHandler(event)" ondragover="dragOverHandler(event)" ondragleave="dragLeaveHandler(event)">
                                <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                                <h5>Subir Imagen</h5>
                                <p class="text-muted mb-2">Haz clic aquí o arrastra una imagen</p>
                                <small class="text-muted">JPG, PNG, GIF, WEBP (máx. 5MB)</small>
                                
                                <input type="file" name="imagen" id="imagen" accept="image/*" style="display: none;" onchange="previewImage(event)">
                            </div>
                            
                            <!-- Vista previa de nueva imagen -->
                            <div id="imagePreview" class="mt-3" style="display: none;">
                                <img id="previewImg" class="image-preview">
                                <button type="button" class="btn btn-outline-danger btn-sm mt-2" onclick="clearImagePreview()">
                                    <i class="fas fa-times"></i> Cancelar
                                </button>
                            </div>
                        </div>
                        
                        <!-- Información del producto -->
                        <div class="col-md-8">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Clave del Producto *</label>
                                    <input type="text" class="form-control" name="clave_producto" 
                                           value="<?= $producto_edit ? htmlspecialchars($producto_edit['clave_producto']) : '' ?>" 
                                           required maxlength="20" placeholder="Ej: PROD001">
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Categoría</label>
                                    <select class="form-select" name="categoria_id">
                                        <option value="">Sin categoría</option>
                                        <?php foreach ($categorias as $categoria): ?>
                                            <option value="<?= $categoria['id'] ?>" 
                                                    <?= ($producto_edit && $producto_edit['categoria_id'] == $categoria['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($categoria['nombre']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-12 mb-3">
                                    <label class="form-label">Nombre del Producto *</label>
                                    <input type="text" class="form-control" name="nombre" 
                                           value="<?= $producto_edit ? htmlspecialchars($producto_edit['nombre']) : '' ?>" 
                                           required maxlength="200" placeholder="Nombre descriptivo del producto">
                                </div>
                                
                                <div class="col-12 mb-3">
                                    <label class="form-label">Descripción</label>
                                    <textarea class="form-control" name="descripcion" rows="3" maxlength="100" 
                                              placeholder="Descripción breve del producto"><?= $producto_edit ? htmlspecialchars($producto_edit['descripcion']) : '' ?></textarea>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Precio de Venta *</label>
                                    <div class="input-group">
                                        <span class="input-group-text">$</span>
                                        <input type="number" class="form-control" name="precio" step="0.01" min="0" 
                                               value="<?= $producto_edit ? $producto_edit['precio'] : '' ?>" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Precio de Compra</label>
                                    <div class="input-group">
                                        <span class="input-group-text">$</span>
                                        <input type="number" class="form-control" name="precio_compra" step="0.01" min="0" 
                                               value="<?= $producto_edit ? $producto_edit['precio_compra'] : '' ?>">
                                    </div>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Stock (Cantidad)</label>
                                    <input type="number" class="form-control" name="cantidad_etiquetas" min="0" 
                                           value="<?= $producto_edit ? $producto_edit['cantidad_etiquetas'] : '' ?>" 
                                           placeholder="0">
                                </div>
                                
                                <div class="col-12 mb-3">
                                    <label class="form-label">Proveedor</label>
                                    <input type="text" class="form-control" name="proveedor" 
                                           value="<?= $producto_edit ? htmlspecialchars($producto_edit['proveedor']) : '' ?>" 
                                           maxlength="100" placeholder="Nombre del proveedor">
                                </div>
                                
                                <div class="col-12 mb-4">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" name="es_destacado" id="es_destacado"
                                               <?= ($producto_edit && $producto_edit['es_destacado']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="es_destacado">
                                            <strong>Producto destacado</strong> (aparecerá en la página principal)
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-admin">
                            <i class="fas fa-save"></i> <?= $action === 'edit' ? 'Actualizar' : 'Guardar' ?> Producto
                        </button>
                        <a href="productos.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Cancelar
                        </a>
                    </div>
                </form>
            </div>
            
        <?php else: ?>
            <!-- Lista de productos -->
            
            <!-- Filtros -->
            <div class="product-card mb-4">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Buscar:</label>
                        <input type="text" class="form-control" name="search" 
                               value="<?= htmlspecialchars($search) ?>" placeholder="Nombre, clave...">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Categoría:</label>
                        <select class="form-select" name="categoria">
                            <option value="">Todas las categorías</option>
                            <?php foreach ($categorias as $categoria): ?>
                                <option value="<?= $categoria['id'] ?>" <?= $categoria_filter == $categoria['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($categoria['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Filtro:</label>
                        <select class="form-select" name="filter">
                            <option value="">Todos</option>
                            <option value="stock_bajo" <?= $filter === 'stock_bajo' ? 'selected' : '' ?>>Stock bajo</option>
                            <option value="destacados" <?= $filter === 'destacados' ? 'selected' : '' ?>>Destacados</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-admin w-100">
                            <i class="fas fa-search"></i> Filtrar
                        </button>
                    </div>
                </form>
                
                <?php if ($search || $categoria_filter || $filter): ?>
                    <div class="mt-3">
                        <a href="productos.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i> Limpiar filtros
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Tabla de productos -->
            <div class="product-card">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Imagen</th>
                                <th>Clave</th>
                                <th>Nombre</th>
                                <th>Categoría</th>
                                <th>Precio</th>
                                <th>Stock</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($productos) > 0): ?>
                                <?php foreach ($productos as $producto): ?>
                                    <tr>
                                        <td>
                                            <?php if ($producto['imagen']): ?>
                                                <img src="../assets/images/products/<?= htmlspecialchars($producto['imagen']) ?>" 
                                                     alt="<?= htmlspecialchars($producto['nombre']) ?>" class="product-image">
                                            <?php else: ?>
                                                <div class="bg-light rounded d-flex align-items-center justify-content-center product-image">
                                                    <i class="fas fa-image text-muted"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?= htmlspecialchars($producto['clave_producto']) ?></code></td>
                                        <td>
                                            <strong><?= htmlspecialchars($producto['nombre']) ?></strong>
                                            <?php if ($producto['es_destacado']): ?>
                                                <span class="badge bg-warning ms-1">
                                                    <i class="fas fa-star"></i> Destacado
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($producto['descripcion']): ?>
                                                <br><small class="text-muted"><?= htmlspecialchars($producto['descripcion']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($producto['categoria_nombre'] ?? 'Sin categoría') ?></td>
                                        <td><strong>$<?= number_format($producto['precio'], 2) ?></strong></td>
                                        <td>
                                            <?php
                                            $stock = $producto['cantidad_etiquetas'];
                                            $stock_class = $stock > 10 ? 'stock-high' : ($stock > 0 ? 'stock-medium' : 'stock-low');
                                            ?>
                                            <span class="stock-badge <?= $stock_class ?>">
                                                <?= $stock ?> unidades
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-success">Activo</span>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <a href="productos.php?action=edit&id=<?= $producto['id'] ?>" 
                                                   class="btn btn-outline-primary btn-sm" title="Editar">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button type="button" class="btn btn-outline-danger btn-sm" 
                                                        onclick="deleteProduct(<?= $producto['id'] ?>, '<?= htmlspecialchars($producto['nombre']) ?>')" 
                                                        title="Eliminar">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <i class="fas fa-box fa-3x text-muted mb-3"></i>
                                        <h5>No hay productos encontrados</h5>
                                        <p class="text-muted">
                                            <?php if ($search || $categoria_filter || $filter): ?>
                                                No hay productos que coincidan con los filtros seleccionados.
                                            <?php else: ?>
                                                Comienza agregando tu primer producto.
                                            <?php endif; ?>
                                        </p>
                                        <?php if (!($search || $categoria_filter || $filter)): ?>
                                            <a href="productos.php?action=add" class="btn btn-admin">
                                                <i class="fas fa-plus"></i> Agregar Primer Producto
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (count($productos) > 0): ?>
                    <div class="mt-3 text-muted">
                        Mostrando <?= count($productos) ?> producto(s)
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal de confirmación para eliminar -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirmar Eliminación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>¿Estás seguro de que quieres eliminar el producto <strong id="product-name"></strong>?</p>
                    <p class="text-muted">Esta acción eliminará también la imagen asociada y no se puede deshacer.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <form method="POST" style="display: inline;" id="delete-form">
                        <input type="hidden" name="action" value="delete_product">
                        <input type="hidden" name="id" id="delete-product-id">
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
        // Función para eliminar producto
        function deleteProduct(id, name) {
            document.getElementById('product-name').textContent = name;
            document.getElementById('delete-product-id').value = id;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
        
        // Auto-submit en cambios de filtros
        document.querySelectorAll('select[name="categoria"], select[name="filter"]').forEach(select => {
            select.addEventListener('change', function() {
                this.form.submit();
            });
        });
        
        // === FUNCIONES PARA MANEJO DE IMÁGENES ===
        
        // Vista previa de imagen
        function previewImage(event) {
            const file = event.target.files[0];
            if (file) {
                // Validar tamaño (5MB)
                if (file.size > 5 * 1024 * 1024) {
                    alert('El archivo es muy grande. Máximo 5MB permitido.');
                    event.target.value = '';
                    return;
                }
                
                // Validar tipo
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Tipo de archivo no permitido. Solo JPG, PNG, GIF, WEBP.');
                    event.target.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('previewImg').src = e.target.result;
                    document.getElementById('imagePreview').style.display = 'block';
                    document.querySelector('.image-upload-area').style.display = 'none';
                };
                reader.readAsDataURL(file);
            }
        }
        
        // Limpiar vista previa
        function clearImagePreview() {
            document.getElementById('imagen').value = '';
            document.getElementById('imagePreview').style.display = 'none';
            document.querySelector('.image-upload-area').style.display = 'block';
        }
        
        // Eliminar imagen actual
        function removeCurrentImage() {
            if (confirm('¿Estás seguro de que quieres eliminar la imagen actual?')) {
                document.getElementById('currentImageContainer').style.display = 'none';
                document.getElementById('eliminar_imagen').value = '1';
            }
        }
        
        // === DRAG AND DROP ===
        
        function dragOverHandler(event) {
            event.preventDefault();
            event.currentTarget.classList.add('dragover');
        }
        
        function dragLeaveHandler(event) {
            event.currentTarget.classList.remove('dragover');
        }
        
        function dropHandler(event) {
            event.preventDefault();
            event.currentTarget.classList.remove('dragover');
            
            const files = event.dataTransfer.files;
            if (files.length > 0) {
                const file = files[0];
                
                // Validar que es una imagen
                if (file.type.startsWith('image/')) {
                    // Asignar el archivo al input
                    const input = document.getElementById('imagen');
                    input.files = files;
                    
                    // Disparar evento change para mostrar preview
                    const changeEvent = new Event('change', { bubbles: true });
                    input.dispatchEvent(changeEvent);
                } else {
                    alert('Solo se permiten archivos de imagen.');
                }
            }
        }
        
        // Prevenir comportamiento por defecto del navegador
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            document.addEventListener(eventName, preventDefaults, false);
            document.body.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        // === VALIDACIÓN DEL FORMULARIO ===
        
        document.getElementById('productForm')?.addEventListener('submit', function(e) {
            const clave = document.querySelector('input[name="clave_producto"]').value.trim();
            const nombre = document.querySelector('input[name="nombre"]').value.trim();
            const precio = document.querySelector('input[name="precio"]').value;
            
            if (!clave || !nombre || !precio || precio <= 0) {
                e.preventDefault();
                alert('Por favor completa todos los campos requeridos correctamente.');
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
        
        // === ANIMACIONES ===
        
        document.addEventListener('DOMContentLoaded', function() {
            // Animar cards
            const cards = document.querySelectorAll('.product-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.4s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html>