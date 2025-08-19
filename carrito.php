<?php
// carrito.php - Página del carrito de compras - CORREGIDA
session_start();
require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carrito de Compras - Tienda Multicategoría</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        .cart-item {
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            transition: box-shadow 0.3s ease;
        }
        .cart-item:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .quantity-input {
            max-width: 80px;
        }
        .price-display {
            font-size: 1.2rem;
            font-weight: bold;
            color: #28a745;
        }
        .empty-cart {
            text-align: center;
            padding: 60px 20px;
        }
        .empty-cart i {
            font-size: 4rem;
            color: #6c757d;
            margin-bottom: 20px;
        }
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }
        .cart-summary {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 25px;
            position: sticky;
            top: 20px;
        }
    </style>
</head>
<body>
    <!-- Navegación -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-store"></i> Tienda Multicategoría
            </a>
            
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="index.php">
                    <i class="fas fa-arrow-left"></i> Seguir Comprando
                </a>
            </div>
        </div>
    </nav>

    <div class="container my-5">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4">
                    <i class="fas fa-shopping-cart"></i> Tu Carrito de Compras
                </h1>
            </div>
        </div>

        <!-- Loading -->
        <div class="loading" id="loading">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Cargando...</span>
            </div>
            <p class="mt-2">Cargando carrito...</p>
        </div>

        <!-- Carrito vacío -->
        <div class="empty-cart d-none" id="empty-cart">
            <i class="fas fa-shopping-cart"></i>
            <h3>Tu carrito está vacío</h3>
            <p class="text-muted">¡Agrega algunos productos y vuelve aquí!</p>
            <a href="index.php" class="btn btn-primary btn-lg">
                <i class="fas fa-shopping-bag"></i> Ir de Compras
            </a>
        </div>

        <!-- Contenido del carrito -->
        <div class="row d-none" id="cart-content">
            <div class="col-lg-8">
                <div id="cart-items">
                    <!-- Los items se cargarán aquí dinámicamente -->
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="cart-summary">
                    <h4>Resumen del Pedido</h4>
                    <hr>
                    
                    <div class="d-flex justify-content-between mb-2">
                        <span>Subtotal:</span>
                        <span id="subtotal">$0.00</span>
                    </div>
                    
                    <div class="d-flex justify-content-between mb-2">
                        <span>Envío:</span>
                        <span id="envio">Calculado en checkout</span>
                    </div>
                    
                    <div class="d-flex justify-content-between mb-2">
                        <span>IVA (16%):</span>
                        <span id="iva">$0.00</span>
                    </div>
                    
                    <hr>
                    
                    <div class="d-flex justify-content-between mb-4">
                        <strong>Total:</strong>
                        <strong class="price-display" id="total">$0.00</strong>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button class="btn btn-success btn-lg" onclick="procederAlCheckout()">
                            <i class="fas fa-credit-card"></i> Proceder al Pago
                        </button>
                        <button class="btn btn-outline-primary" onclick="limpiarCarrito()">
                            <i class="fas fa-trash"></i> Vaciar Carrito
                        </button>
                    </div>
                    
                    <div class="mt-4">
                        <h6><i class="fas fa-shield-alt text-success"></i> Compra Segura</h6>
                        <small class="text-muted">
                            Tu información está protegida con encriptación SSL
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Variables globales
        let carritoData = null;
        
        // Cargar carrito al iniciar la página
        document.addEventListener('DOMContentLoaded', function() {
            cargarCarrito();
        });
        
        // Función para cargar el carrito
        async function cargarCarrito() {
            try {
                document.getElementById('loading').style.display = 'block';
                
                const response = await fetch('api/carrito.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=obtener'
                });
                
                const data = await response.json();
                
                if (data.success) {
                    carritoData = data.data;
                    mostrarCarrito();
                } else {
                    console.error('Error al cargar carrito:', data.message);
                    mostrarCarritoVacio();
                }
                
            } catch (error) {
                console.error('Error:', error);
                mostrarCarritoVacio();
            } finally {
                document.getElementById('loading').style.display = 'none';
            }
        }
        
        // Función para mostrar el carrito
        function mostrarCarrito() {
            if (!carritoData || carritoData.items.length === 0) {
                mostrarCarritoVacio();
                return;
            }
            
            document.getElementById('empty-cart').classList.add('d-none');
            document.getElementById('cart-content').classList.remove('d-none');
            
            const cartItemsContainer = document.getElementById('cart-items');
            cartItemsContainer.innerHTML = '';
            
            carritoData.items.forEach(item => {
                const itemHtml = crearItemHTML(item);
                cartItemsContainer.innerHTML += itemHtml;
            });
            
            actualizarResumen();
        }
        
        // Función para mostrar carrito vacío
        function mostrarCarritoVacio() {
            document.getElementById('empty-cart').classList.remove('d-none');
            document.getElementById('cart-content').classList.add('d-none');
        }
        
        // Función para crear HTML de un item
        function crearItemHTML(item) {
            const identificador = item.id_carrito ? `data-id-carrito="${item.id_carrito}"` : `data-key="${item.key}"`;
            const imagenSrc = item.imagen ? `assets/images/products/${item.imagen}` : 'https://via.placeholder.com/100x100?text=Sin+Imagen';
            
            return `
                <div class="cart-item" ${identificador}>
                    <div class="row align-items-center">
                        <div class="col-md-2">
                            <img src="${imagenSrc}" alt="${item.nombre}" class="img-fluid rounded" style="max-height: 80px;">
                        </div>
                        <div class="col-md-4">
                            <h6 class="mb-1">${item.nombre}</h6>
                            <small class="text-muted">${item.tipo === 'producto' ? 'Producto' : 'Paquete'}</small>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">Cantidad:</label>
                            <input type="number" class="form-control quantity-input" 
                                   value="${item.cantidad}" min="1" max="99"
                                   onchange="actualizarCantidad(this, '${item.id_carrito || item.key}', '${item.id_carrito ? 'bd' : 'sesion'}')">
                        </div>
                        <div class="col-md-2 text-center">
                            <div class="price-display">$${item.precio.toFixed(2)}</div>
                            <small class="text-muted">c/u</small>
                        </div>
                        <div class="col-md-1 text-center">
                            <div class="price-display">$${item.subtotal.toFixed(2)}</div>
                            <small class="text-muted">subtotal</small>
                        </div>
                        <div class="col-md-1 text-center">
                            <button class="btn btn-outline-danger btn-sm" 
                                    onclick="eliminarItem('${item.id_carrito || item.key}', '${item.id_carrito ? 'bd' : 'sesion'}')"
                                    title="Eliminar">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }
        
        // Función para actualizar cantidad
        async function actualizarCantidad(input, identificador, tipo) {
            const cantidad = parseInt(input.value);
            
            if (cantidad <= 0) {
                input.value = 1;
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'actualizar');
                formData.append('cantidad', cantidad);
                
                if (tipo === 'bd') {
                    formData.append('id_carrito', identificador);
                } else {
                    formData.append('key', identificador);
                }
                
                const response = await fetch('api/carrito.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Recargar carrito para obtener nuevos totales
                    cargarCarrito();
                } else {
                    alert('Error: ' + data.message);
                    input.value = input.defaultValue; // Restaurar valor anterior
                }
                
            } catch (error) {
                console.error('Error:', error);
                alert('Error al actualizar cantidad');
                input.value = input.defaultValue;
            }
        }
        
        // Función para eliminar item
        async function eliminarItem(identificador, tipo) {
            if (!confirm('¿Estás seguro de que quieres eliminar este artículo?')) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'eliminar');
                
                if (tipo === 'bd') {
                    formData.append('id_carrito', identificador);
                } else {
                    formData.append('key', identificador);
                }
                
                const response = await fetch('api/carrito.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    cargarCarrito(); // Recargar carrito
                } else {
                    alert('Error: ' + data.message);
                }
                
            } catch (error) {
                console.error('Error:', error);
                alert('Error al eliminar artículo');
            }
        }
        
        // Función para actualizar resumen
        function actualizarResumen() {
            const subtotal = carritoData.total;
            const iva = subtotal * 0.16;
            const total = subtotal + iva;
            
            document.getElementById('subtotal').textContent = `$${subtotal.toFixed(2)}`;
            document.getElementById('iva').textContent = `$${iva.toFixed(2)}`;
            document.getElementById('total').textContent = `$${total.toFixed(2)}`;
        }
        
        // Función para proceder al checkout - CORREGIDA
        function procederAlCheckout() {
            if (!carritoData || carritoData.items.length === 0) {
                alert('Tu carrito está vacío');
                return;
            }
            
            // Verificar si el usuario está logueado
            fetch('api/auth.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=check_session'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data && data.data.logueado) {
                    // Usuario logueado - redirigir a checkout
                    window.location.href = 'checkout.php';
                } else {
                    // Usuario no logueado - preguntar si quiere loguearse
                    if (confirm('Para proceder al checkout necesitas iniciar sesión.\n\n¿Quieres iniciar sesión ahora?')) {
                        window.location.href = 'login.php?redirect=checkout.php';
                    }
                }
            })
            .catch(error => {
                console.error('Error verificando sesión:', error);
                // En caso de error, intentar ir a checkout de todas formas
                window.location.href = 'checkout.php';
            });
        }
        
        // Función para limpiar carrito
        async function limpiarCarrito() {
            if (!confirm('¿Estás seguro de que quieres vaciar todo el carrito?')) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'limpiar');
                
                const response = await fetch('api/carrito.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    cargarCarrito(); // Recargar carrito
                    alert('Carrito vaciado correctamente');
                } else {
                    alert('Error: ' + data.message);
                }
                
            } catch (error) {
                console.error('Error:', error);
                alert('Error al vaciar carrito');
            }
        }
    </script>
</body>
</html>