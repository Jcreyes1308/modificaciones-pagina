<?php
// productos.php - Catálogo completo con filtros
session_start();
require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

// Obtener filtros de la URL
$categoria_filtro = $_GET['categoria'] ?? '';
$busqueda = trim($_GET['buscar'] ?? '');
$orden = $_GET['orden'] ?? 'nombre';
$precio_min = floatval($_GET['precio_min'] ?? 0);
$precio_max = floatval($_GET['precio_max'] ?? 0);

// Validar orden
$ordenes_validos = ['nombre', 'precio_asc', 'precio_desc', 'stock_desc', 'destacados'];
if (!in_array($orden, $ordenes_validos)) {
    $orden = 'nombre';
}

// Construir consulta con filtros
$where_conditions = ['p.activo = 1'];
$params = [];

if ($categoria_filtro) {
    $where_conditions[] = 'p.categoria_id = ?';
    $params[] = $categoria_filtro;
}

if ($busqueda) {
    $where_conditions[] = '(p.nombre LIKE ? OR p.descripcion LIKE ? OR p.clave_producto LIKE ?)';
    $buscar_param = '%' . $busqueda . '%';
    $params[] = $buscar_param;
    $params[] = $buscar_param;
    $params[] = $buscar_param;
}

if ($precio_min > 0) {
    $where_conditions[] = 'p.precio >= ?';
    $params[] = $precio_min;
}

if ($precio_max > 0) {
    $where_conditions[] = 'p.precio <= ?';
    $params[] = $precio_max;
}

// Determinar ORDER BY
$order_sql = '';
switch ($orden) {
    case 'precio_asc':
        $order_sql = 'ORDER BY p.precio ASC';
        break;
    case 'precio_desc':
        $order_sql = 'ORDER BY p.precio DESC';
        break;
    case 'stock_desc':
        $order_sql = 'ORDER BY p.cantidad_etiquetas DESC';
        break;
    case 'destacados':
        $order_sql = 'ORDER BY p.es_destacado DESC, p.precio ASC';
        break;
    default:
        $order_sql = 'ORDER BY p.nombre ASC';
        break;
}

// Ejecutar consulta principal
$where_sql = implode(' AND ', $where_conditions);
$sql = "
    SELECT 
        p.id, p.clave_producto, p.nombre, p.descripcion, p.precio, 
        p.cantidad_etiquetas, p.es_destacado,
        c.nombre as categoria_nombre, c.color_categoria, c.icono
    FROM productos p
    LEFT JOIN categorias c ON p.categoria_id = c.id
    WHERE $where_sql
    $order_sql
";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $productos = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error en consulta de productos: " . $e->getMessage());
    $productos = [];
}

// Obtener categorías para filtros
try {
    $stmt = $conn->query("SELECT * FROM categorias WHERE activo = 1 ORDER BY nombre");
    $categorias = $stmt->fetchAll();
} catch (Exception $e) {
    $categorias = [];
}

// Obtener estadísticas para mostrar
$total_productos = count($productos);
$categoria_actual = '';
if ($categoria_filtro) {
    foreach ($categorias as $cat) {
        if ($cat['id'] == $categoria_filtro) {
            $categoria_actual = $cat['nombre'];
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo Completo - Novedades Ashley</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        .catalog-header {
            background: linear-gradient(135deg, #667eea 0%, rgba(102, 126, 234, 0.8) 100%);
            color: white;
            padding: 60px 0 40px 0;
        }
        
        .filters-section {
            background: #f8f9fa;
            padding: 20px 0;
            border-bottom: 1px solid #dee2e6;
        }
        
        .product-card {
            transition: all 0.3s ease;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-radius: 15px;
            overflow: hidden;
            height: 100%;
        }
        
        .product-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .price-badge {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 1.1rem;
        }
        
        .destacado-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: linear-gradient(45deg, #ff6b6b, #ffa726);
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
            z-index: 2;
        }
        
        .categoria-badge {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .btn-add-cart {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
            border-radius: 25px;
            padding: 10px 20px;
            font-weight: bold;
            transition: all 0.3s ease;
            color: white;
        }
        
        .btn-add-cart:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
        }
        
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .no-results {
            text-align: center;
            padding: 80px 20px;
        }
        
        .no-results i {
            font-size: 4rem;
            color: #6c757d;
            margin-bottom: 20px;
        }
        
        .stats-bar {
            background: white;
            padding: 15px 0;
            border-bottom: 1px solid #dee2e6;
        }
        
        .breadcrumb {
            background: none;
            margin-bottom: 0;
        }
        
        @media (max-width: 768px) {
            .catalog-header {
                padding: 40px 0 20px 0;
            }
            .filter-card {
                margin-bottom: 20px;
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
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            Categorías
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="productos.php">
                                <i class="fas fa-th-large"></i> Todos los Productos
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <?php foreach ($categorias as $categoria): ?>
                                <li><a class="dropdown-item" href="productos.php?categoria=<?= $categoria['id'] ?>">
                                    <i class="<?= $categoria['icono'] ?>"></i> <?= htmlspecialchars($categoria['nombre']) ?>
                                </a></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="productos.php">Productos</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#" onclick="mostrarContacto()">Contacto</a>
                    </li>
                </ul>
                
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="carrito.php">
                            <i class="fas fa-shopping-cart"></i> Carrito
                            <span class="badge bg-danger d-none" id="cart-count">0</span>
                        </a>
                    </li>
                    
                    <?php if (isset($_SESSION['usuario_id'])): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                                <i class="fas fa-user"></i> <?= htmlspecialchars($_SESSION['usuario_nombre']) ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="perfil.php">Mi Perfil</a></li>
                                <li><a class="dropdown-item" href="mis_pedidos.php">Mis Pedidos</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="#" onclick="cerrarSesion()">Cerrar Sesión</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="login.php">Iniciar Sesión</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="registro.php">Registrarse</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Header del catálogo -->
    <section class="catalog-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php" class="text-light">Inicio</a></li>
                            <li class="breadcrumb-item active text-light">Catálogo</li>
                            <?php if ($categoria_actual): ?>
                                <li class="breadcrumb-item active text-light"><?= htmlspecialchars($categoria_actual) ?></li>
                            <?php endif; ?>
                        </ol>
                    </nav>
                    
                    <h1 class="display-5 mb-3">
                        <i class="fas fa-box-open me-3"></i>
                        <?php if ($categoria_actual): ?>
                            <?= htmlspecialchars($categoria_actual) ?>
                        <?php else: ?>
                            Catálogo Completo
                        <?php endif; ?>
                    </h1>
                    
                    <p class="lead">
                        <?php if ($busqueda): ?>
                            Resultados para: "<strong><?= htmlspecialchars($busqueda) ?></strong>"
                        <?php elseif ($categoria_actual): ?>
                            Explora nuestra selección de <?= htmlspecialchars(strtolower($categoria_actual)) ?>
                        <?php else: ?>
                            Descubre todos nuestros productos de calidad
                        <?php endif; ?>
                    </p>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="bg-white text-dark rounded p-3 d-inline-block">
                        <h3 class="mb-0"><?= $total_productos ?></h3>
                        <small>Productos encontrados</small>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Filtros -->
    <section class="filters-section">
        <div class="container">
            <form method="GET" action="productos.php" id="filtrosForm">
                <div class="row">
                    <div class="col-md-3">
                        <label class="form-label">🔍 Buscar:</label>
                        <input type="text" class="form-control" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Nombre, código...">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">📂 Categoría:</label>
                        <select class="form-select" name="categoria">
                            <option value="">Todas</option>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $categoria_filtro == $cat['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">💰 Precio mín:</label>
                        <input type="number" class="form-control" name="precio_min" value="<?= $precio_min ?: '' ?>" placeholder="$0" min="0" step="0.01">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">💰 Precio máx:</label>
                        <input type="number" class="form-control" name="precio_max" value="<?= $precio_max ?: '' ?>" placeholder="$9999" min="0" step="0.01">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">🔄 Ordenar:</label>
                        <select class="form-select" name="orden">
                            <option value="nombre" <?= $orden == 'nombre' ? 'selected' : '' ?>>A-Z</option>
                            <option value="precio_asc" <?= $orden == 'precio_asc' ? 'selected' : '' ?>>Precio: Menor</option>
                            <option value="precio_desc" <?= $orden == 'precio_desc' ? 'selected' : '' ?>>Precio: Mayor</option>
                            <option value="stock_desc" <?= $orden == 'stock_desc' ? 'selected' : '' ?>>Más Stock</option>
                            <option value="destacados" <?= $orden == 'destacados' ? 'selected' : '' ?>>Destacados</option>
                        </select>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
                
                <?php if ($categoria_filtro || $busqueda || $precio_min || $precio_max): ?>
                    <div class="mt-3">
                        <a href="productos.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Limpiar Filtros
                        </a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </section>

    <!-- Productos -->
    <section class="py-5">
        <div class="container">
            <?php if (count($productos) > 0): ?>
                <div class="row">
                    <?php foreach ($productos as $producto): ?>
                        <div class="col-lg-3 col-md-4 col-sm-6 mb-4">
                            <div class="card product-card">
                                <?php if ($producto['es_destacado']): ?>
                                    <div class="destacado-badge">
                                        <i class="fas fa-star"></i> Destacado
                                    </div>
                                <?php endif; ?>
                                
                                <div class="card-img-top bg-light d-flex align-items-center justify-content-center" style="height: 200px;">
                                    <i class="fas fa-image fa-3x text-muted"></i>
                                </div>
                                
                                <div class="card-body">
                                    <div class="categoria-badge mb-2">
                                        <i class="<?= $producto['icono'] ?>"></i> <?= htmlspecialchars($producto['categoria_nombre']) ?>
                                    </div>
                                    
                                    <h6 class="card-title"><?= htmlspecialchars($producto['nombre']) ?></h6>
                                    <p class="card-text small text-muted">
                                        <?= htmlspecialchars(substr($producto['descripcion'], 0, 80)) ?>...
                                    </p>
                                    <p class="small text-muted mb-2">
                                        <strong>Código:</strong> <?= htmlspecialchars($producto['clave_producto']) ?>
                                    </p>
                                    
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <span class="price-badge">$<?= number_format($producto['precio'], 2) ?></span>
                                        <small class="text-muted">Stock: <?= $producto['cantidad_etiquetas'] ?></small>
                                    </div>
                                    
                                    <?php if ($producto['cantidad_etiquetas'] > 0): ?>
                                        <button class="btn btn-add-cart w-100" onclick="agregarAlCarrito(<?= $producto['id'] ?>)">
                                            <i class="fas fa-cart-plus"></i> Agregar al Carrito
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-secondary w-100" disabled>
                                            <i class="fas fa-times"></i> Sin Stock
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Paginación (para implementar después) -->
                <div class="text-center mt-5">
                    <p class="text-muted">Mostrando <?= count($productos) ?> productos</p>
                </div>
                
            <?php else: ?>
                <!-- Sin resultados -->
                <div class="no-results">
                    <i class="fas fa-search"></i>
                    <h3>No se encontraron productos</h3>
                    <p class="text-muted mb-4">
                        <?php if ($busqueda): ?>
                            No hay productos que coincidan con "<strong><?= htmlspecialchars($busqueda) ?></strong>"
                        <?php elseif ($categoria_filtro): ?>
                            No hay productos en la categoría "<?= htmlspecialchars($categoria_actual) ?>"
                        <?php else: ?>
                            No hay productos disponibles con los filtros seleccionados.
                        <?php endif; ?>
                    </p>
                    <a href="productos.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left me-2"></i> Ver Todos los Productos
                    </a>
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
        // Auto-submit del formulario cuando cambian los selectores
        document.querySelectorAll('#filtrosForm select').forEach(select => {
            select.addEventListener('change', function() {
                document.getElementById('filtrosForm').submit();
            });
        });
        
        // Función para mostrar contacto
        function mostrarContacto() {
            alert('📞 Contacto - Novedades Ashley:\n\n' +
                  '✨ "Descubre lo nuevo, siente la diferencia"\n\n' +
                  '📧 Email: noe.cruzb91@gmail.com\n' +
                  '📱 WhatsApp: Próximamente\n' +
                  '📍 Ubicación: CDMX, México\n\n' +
                  '💼 Especialistas en mayoreo y menudeo');
        }
        
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
                    window.location.reload();
                } else {
                    alert('Error al cerrar sesión: ' + data.message);
                }
                
            } catch (error) {
                console.error('Error:', error);
                alert('Error al cerrar sesión');
            }
        }
        
        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            // Actualizar contador del carrito
            if (typeof actualizarContadorCarrito === 'function') {
                actualizarContadorCarrito();
            }
            
            // Animación de entrada para las cards
            const cards = document.querySelectorAll('.product-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(30px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 50);
            });
        });
    </script>
</body>
</html>