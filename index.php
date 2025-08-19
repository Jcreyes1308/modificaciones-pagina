<?php
// index.php - Sistema inteligente de categorías automáticas por temporada - FINAL COMPLETO
session_start();
require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

// ============= SISTEMA INTELIGENTE DE TEMPORADAS =============

// Función para obtener la temporada actual (basada en mes)
function obtenerTemporadaActual($conn) {
    try {
        $mes_actual = date('n'); // 1-12 (sin ceros iniciales)
        $stmt = $conn->prepare("
            SELECT * FROM temporadas 
            WHERE mes_numero = ? AND activo = 1
            LIMIT 1
        ");
        $stmt->execute([$mes_actual]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Error obteniendo temporada: " . $e->getMessage());
        return null;
    }
}

// Función para obtener productos de temporada actual
function obtenerProductosTemporadaActual($conn, $limite = 8) {
    try {
        $mes_actual = strtolower(date('F')); // agosto, septiembre, etc.
        $meses_espanol = [
            'january' => 'enero', 'february' => 'febrero', 'march' => 'marzo',
            'april' => 'abril', 'may' => 'mayo', 'june' => 'junio',
            'july' => 'julio', 'august' => 'agosto', 'september' => 'septiembre',
            'october' => 'octubre', 'november' => 'noviembre', 'december' => 'diciembre'
        ];
        $mes_espanol = $meses_espanol[$mes_actual] ?? 'agosto';
        
        $limite = intval($limite);
        $stmt = $conn->prepare("
            SELECT p.*, c.nombre as categoria_nombre, c.color_categoria, c.icono
            FROM productos p
            JOIN categorias c ON p.categoria_id = c.id
            WHERE (c.temporada_asociada = ? OR c.temporada_asociada = 'siempre') 
            AND p.activo = 1
            ORDER BY p.es_destacado DESC, p.created_at DESC
            LIMIT $limite
        ");
        $stmt->execute([$mes_espanol]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error obteniendo productos de temporada: " . $e->getMessage());
        return [];
    }
}

// Función para obtener productos más vendidos (simulamos con productos destacados por ahora)
function obtenerProductosMasVendidos($conn, $limite = 8) {
    try {
        $limite = intval($limite);
        $stmt = $conn->prepare("
            SELECT 
                p.id, p.nombre, p.precio, p.cantidad_etiquetas, p.descripcion, p.es_destacado,
                c.nombre as categoria_nombre,
                0 as total_vendido
            FROM productos p
            LEFT JOIN categorias c ON p.categoria_id = c.id
            WHERE p.activo = 1
            ORDER BY p.es_destacado DESC, p.created_at DESC
            LIMIT $limite
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error obteniendo productos más vendidos: " . $e->getMessage());
        return [];
    }
}

// Obtener datos para la página
$temporada_actual = obtenerTemporadaActual($conn);
$productos_temporada = obtenerProductosTemporadaActual($conn);
$productos_mas_vendidos = obtenerProductosMasVendidos($conn);

// Obtener categorías para el menú
try {
    $stmt = $conn->query("SELECT * FROM categorias WHERE activo = 1 ORDER BY nombre LIMIT 8");
    $categorias = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error obteniendo categorías: " . $e->getMessage());
    $categorias = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Novedades Ashley - Descubre lo nuevo, siente la diferencia</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        /* Sección de temporada - COLOR UNIFORME */
        .temporada-section {
            background: linear-gradient(135deg, #667eea 0%, rgba(102, 126, 234, 0.8) 100%);
            color: white;
            padding: 80px 0;
            position: relative;
            overflow: hidden;
        }
        
        .temporada-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="stars" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="10" cy="10" r="1" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23stars)"/></svg>');
        }
        
        .temporada-content {
            position: relative;
            z-index: 1;
        }
        
        .product-card {
            transition: all 0.3s ease;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-radius: 15px;
            overflow: hidden;
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
        
        .best-seller-badge {
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
        
        .temporada-badge {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .section-title {
            position: relative;
            text-align: center;
            margin-bottom: 50px;
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 3px;
            background: linear-gradient(45deg, #667eea, #764ba2);
            border-radius: 2px;
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
        
        .catalog-cta {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 20px;
            padding: 60px 20px;
            text-align: center;
            margin: 80px 0;
        }
        
        .catalog-btn {
            background: linear-gradient(45deg, #28a745, #20c997);
            border: none;
            border-radius: 30px;
            padding: 15px 40px;
            font-size: 1.2rem;
            font-weight: bold;
            color: white;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .catalog-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(40, 167, 69, 0.3);
            color: white;
        }
        
        .navbar-brand {
            font-weight: bold;
            font-size: 1.5rem;
        }
        
        .footer {
            background: linear-gradient(135deg, #343a40 0%, #495057 100%);
            color: white;
            padding: 40px 0;
            margin-top: 80px;
        }
        
        /* Estilos del carrusel */
        .carousel-control-prev,
        .carousel-control-next {
            width: 5%;
            opacity: 0.8;
        }
        
        .carousel-control-prev:hover,
        .carousel-control-next:hover {
            opacity: 1;
        }
        
        .carousel-control-prev {
            left: -50px;
        }
        
        .carousel-control-next {
            right: -50px;
        }
        
        .carousel-indicators button {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin: 0 5px;
            border: 2px solid rgba(255,255,255,0.5);
            background: transparent;
        }
        
        .carousel-indicators button.active {
            background: rgba(255,255,255,0.9);
            border-color: rgba(255,255,255,0.9);
        }
        
        @media (max-width: 992px) {
            .carousel-control-prev {
                left: -20px;
            }
            .carousel-control-next {
                right: -20px;
            }
        }
        
        @media (max-width: 768px) {
            .temporada-section {
                padding: 40px 0;
            }
            .product-card {
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
                        <a class="nav-link active" href="index.php">Inicio</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            Categorías
                        </a>
                        <ul class="dropdown-menu">
                            <?php foreach ($categorias as $categoria): ?>
                                <li><a class="dropdown-item" href="productos.php?categoria=<?= $categoria['id'] ?>">
                                    <i class="<?= $categoria['icono'] ?>"></i> <?= htmlspecialchars($categoria['nombre']) ?>
                                </a></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="productos.php">Productos</a>
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

    <!-- Banner de Bienvenida SIEMPRE VISIBLE -->
    <section class="temporada-section">
        <div class="temporada-content">
            <div class="container text-center">
                <h1 class="display-4 mb-4">Bienvenido a Novedades Ashley</h1>
                <p class="lead mb-5">Descubre lo nuevo, siente la diferencia</p>
                
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <div class="d-flex align-items-center justify-content-center">
                            <i class="fas fa-star fa-2x me-3" style="color: #ffd700;"></i>
                            <div>
                                <strong>Productos de Calidad</strong><br>
                                <small>Ropa americana y sneakers</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="d-flex align-items-center justify-content-center">
                            <i class="fas fa-crown fa-2x me-3" style="color: #ff6b6b;"></i>
                            <div>
                                <strong>Mayoreo y Menudeo</strong><br>
                                <small>Precios especiales</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="d-flex align-items-center justify-content-center">
                            <i class="fas fa-shipping-fast fa-2x me-3" style="color: #28a745;"></i>
                            <div>
                                <strong>Envío Nacional</strong><br>
                                <small>A toda la República</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="d-flex align-items-center justify-content-center">
                            <i class="fas fa-heart fa-2x me-3" style="color: #e91e63;"></i>
                            <div>
                                <strong>Atención Personal</strong><br>
                                <small>Servicio confiable</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Sección de Temporada (SI HAY TEMPORADA ACTIVA) -->
    <?php if ($temporada_actual): ?>
    <section class="py-5" style="background: linear-gradient(135deg, #667eea 0%, rgba(102, 126, 234, 0.8) 100%); color: white;">
        <div class="container text-center">
            <h2 class="mb-3">
                <i class="<?= htmlspecialchars($temporada_actual['icono_tema']) ?> me-3"></i>
                <?= htmlspecialchars($temporada_actual['titulo_banner']) ?>
            </h2>
            <p class="lead mb-5"><?= htmlspecialchars($temporada_actual['subtitulo_banner']) ?></p>
            
            <!-- Productos de temporada -->
            <?php if (count($productos_temporada) > 0): ?>
                <div class="position-relative">
                    <!-- Carrusel de productos -->
                    <div id="productosTemporada" class="carousel slide" data-bs-ride="carousel">
                        <div class="carousel-inner">
                            <?php 
                            $chunks = array_chunk($productos_temporada, 4); // 4 productos por slide
                            foreach ($chunks as $index => $chunk): 
                            ?>
                                <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                                    <div class="row justify-content-center">
                                        <?php foreach ($chunk as $producto): ?>
                                            <div class="col-lg-3 col-md-6 mb-4">
                                                <div class="card product-card h-100">
                                                    <div class="temporada-badge position-absolute" style="top: 10px; left: 10px; z-index: 2;">
                                                        <i class="<?= $producto['icono'] ?>"></i> <?= htmlspecialchars($producto['categoria_nombre']) ?>
                                                    </div>
                                                    <div class="card-img-top bg-light d-flex align-items-center justify-content-center" style="height: 180px;">
                                                        <i class="fas fa-image fa-3x text-muted"></i>
                                                    </div>
                                                    <div class="card-body text-dark">
                                                        <h6 class="card-title"><?= htmlspecialchars($producto['nombre']) ?></h6>
                                                        <p class="card-text small"><?= htmlspecialchars(substr($producto['descripcion'] ?? '', 0, 60)) ?>...</p>
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
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Controles del carrusel -->
                        <?php if (count($chunks) > 1): ?>
                            <button class="carousel-control-prev" type="button" data-bs-target="#productosTemporada" data-bs-slide="prev">
                                <div class="bg-dark rounded-circle p-2" style="width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;">
                                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                </div>
                                <span class="visually-hidden">Anterior</span>
                            </button>
                            <button class="carousel-control-next" type="button" data-bs-target="#productosTemporada" data-bs-slide="next">
                                <div class="bg-dark rounded-circle p-2" style="width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;">
                                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                </div>
                                <span class="visually-hidden">Siguiente</span>
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Indicadores -->
                    <?php if (count($chunks) > 1): ?>
                        <div class="text-center mt-4">
                            <div class="carousel-indicators position-relative" style="margin: 0;">
                                <?php foreach ($chunks as $index => $chunk): ?>
                                    <button type="button" data-bs-target="#productosTemporada" data-bs-slide-to="<?= $index ?>" 
                                            class="<?= $index === 0 ? 'active' : '' ?>" style="background: rgba(255,255,255,0.7);"></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-info">
                    <h5>¡Próximamente productos especiales de <?= htmlspecialchars($temporada_actual['mes_nombre']) ?>!</h5>
                    <p>Estamos preparando una selección increíble para ti.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- Productos Más Vendidos -->
    <?php if (count($productos_mas_vendidos) > 0): ?>
    <section class="py-5" id="productos-destacados">
        <div class="container">
            <h2 class="section-title">
                <i class="fas fa-fire text-danger"></i> Productos Destacados
            </h2>
            <p class="text-center text-muted mb-5">Los mejores productos de nuestro catálogo</p>
            
            <div class="row">
                <?php foreach ($productos_mas_vendidos as $index => $producto): ?>
                    <div class="col-lg-3 col-md-6 mb-4">
                        <div class="card product-card h-100">
                            <?php if ($producto['es_destacado'] ?? false): ?>
                                <div class="best-seller-badge">
                                    <i class="fas fa-star"></i> Destacado
                                </div>
                            <?php endif; ?>
                            
                            <div class="card-img-top bg-light d-flex align-items-center justify-content-center" style="height: 200px;">
                                <i class="fas fa-image fa-3x text-muted"></i>
                            </div>
                            <div class="card-body">
                                <small class="text-muted"><?= htmlspecialchars($producto['categoria_nombre'] ?? 'Sin categoría') ?></small>
                                <h6 class="card-title"><?= htmlspecialchars($producto['nombre']) ?></h6>
                                <p class="card-text small"><?= htmlspecialchars(substr($producto['descripcion'] ?? '', 0, 80)) ?>...</p>
                                
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
        </div>
    </section>
    <?php endif; ?>

    <!-- Call to Action - Catálogo Completo -->
    <section class="catalog-cta">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8 text-center">
                    <h2 class="mb-4">
                        <i class="fas fa-th-large fa-2x text-primary mb-3 d-block"></i>
                        ¿Buscas algo específico?
                    </h2>
                    <p class="lead mb-4">
                        Explora nuestro catálogo de ropa americana, sneakers, artículos de fiesta y papelería. 
                        Precios especiales para mayoristas y emprendedores.
                    </p>
                    <a href="productos.php" class="btn catalog-btn">
                        <i class="fas fa-search me-2"></i> Ver Nuestros Productos
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-md-4">
                    <h5><i class="fas fa-crown me-2"></i> Novedades Ashley</h5>
                    <p><strong>"Descubre lo nuevo, siente la diferencia"</strong></p>
                    <p>Tu tienda de confianza para ropa americana, sneakers, artículos de fiesta y papelería. Especialistas en mayoreo.</p>
                </div>
                <div class="col-md-4">
                    <h5>Enlaces Rápidos</h5>
                    <ul class="list-unstyled">
                        <li class="mb-2"><a href="index.php" class="text-light text-decoration-none">🏠 Inicio</a></li>
                        <li class="mb-2"><a href="productos.php" class="text-light text-decoration-none">👕 Nuestros Productos</a></li>
                        <li class="mb-2"><a href="#" onclick="mostrarCategorias()" class="text-light text-decoration-none">📂 Categorías</a></li>
                        <li class="mb-2"><a href="#" onclick="mostrarMayoreo()" class="text-light text-decoration-none">📦 Información Mayoreo</a></li>
                        <?php if ($temporada_actual): ?>
                        <li class="mb-2">
                            <span class="text-light">
                                <i class="<?= htmlspecialchars($temporada_actual['icono_tema']) ?>"></i> 
                                Temporada: <?= htmlspecialchars($temporada_actual['mes_nombre']) ?>
                            </span>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="col-md-4">
                    <h5>Contacto</h5>
                    <p class="mb-2"><i class="fas fa-envelope me-2"></i> noe.cruzb91@gmail.com</p>
                    <p class="mb-2"><i class="fas fa-whatsapp me-2"></i> WhatsApp: Próximamente</p>
                    <p class="mb-2"><i class="fas fa-map-marker-alt me-2"></i> CDMX, México</p>
                    <p class="mb-2"><i class="fas fa-crown me-2"></i> Especialistas en Mayoreo</p>
                    <div class="mt-3">
                        <small class="text-muted">Público objetivo: Mayoristas y emprendedores (25+ años)</small>
                    </div>
                </div>
            </div>
            <hr class="my-4">
            <div class="text-center">
                <p class="mb-0">&copy; <?= date('Y') ?> Novedades Ashley. "Descubre lo nuevo, siente la diferencia"</p>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Carrito JS -->
    <script src="assets/js/carrito.js"></script>
    
    <script>
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
        
        // Función para mostrar categorías
        function mostrarCategorias() {
            // Abrir el dropdown
            const dropdown = document.querySelector('.dropdown-toggle[data-bs-toggle="dropdown"]');
            if (dropdown) {
                const dropdownInstance = new bootstrap.Dropdown(dropdown);
                dropdownInstance.show();
            }
        }
        
        // Función para ir a sección
        function irASeccion(seccionId) {
            const elemento = document.getElementById(seccionId);
            if (elemento) {
                elemento.scrollIntoView({ 
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        }
        
        // Función para mostrar contacto (modal con información real)
        function mostrarContacto() {
            alert('📞 Contacto - Novedades Ashley:\n\n' +
                  '✨ "Descubre lo nuevo, siente la diferencia"\n\n' +
                  '📧 Email: noe.cruzb91@gmail.com\n' +
                  '📱 WhatsApp: Próximamente\n' +
                  '📍 Ubicación: CDMX, México\n' +
                  '👔 Responsable: Noé Cruz Barragán\n\n' +
                  '💼 Especialistas en:\n' +
                  '• Ropa americana\n' +
                  '• Sneakers\n' +
                  '• Artículos de fiesta\n' +
                  '• Papelería\n\n' +
                  '📦 Mayoreo y Menudeo disponible\n' +
                  '🎯 Público objetivo: Mayoristas y emprendedores');
        }
        
        // Función para mostrar información de mayoreo
        function mostrarMayoreo() {
            alert('📦 Información para Mayoristas - Novedades Ashley:\n\n' +
                  '🎯 Cliente ideal: Mayoristas y emprendedores\n' +
                  '👥 Edad: 25 años en adelante\n' +
                  '📍 Cobertura: CDMX y toda la República Mexicana\n\n' +
                  '💰 Beneficios del mayoreo:\n' +
                  '• Precios especiales por volumen\n' +
                  '• Atención personalizada\n' +
                  '• Envíos a nivel nacional\n' +
                  '• Productos de calidad garantizada\n\n' +
                  '📞 Para más información contacta:\n' +
                  '📧 noe.cruzb91@gmail.com\n\n' +
                  '✨ "Descubre lo nuevo, siente la diferencia"');
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
                }, index * 100);
            });
            
            // Agregar smooth scroll a todos los enlaces internos
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                });
            });
            
            // Auto-play del carrusel (opcional)
            const carousel = document.getElementById('productosTemporada');
            if (carousel) {
                const bsCarousel = new bootstrap.Carousel(carousel, {
                    interval: 5000, // 5 segundos
                    wrap: true
                });
            }
        });
    </script>
</body>
</html>