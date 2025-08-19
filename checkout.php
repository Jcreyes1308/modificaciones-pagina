<?php
// checkout.php - Sistema de checkout integrado con Stripe
session_start();
require_once 'config/database.php';
require_once 'config/stripe_config.php';

// Verificar que el usuario esté logueado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php?redirect=checkout.php');
    exit();
}

$database = new Database();
$conn = $database->getConnection();

// Verificar que hay items en el carrito
$carrito_items = [];
$carrito_total = 0;

try {
    // Obtener items del carrito desde BD (usuario registrado)
    $stmt = $conn->prepare("
        SELECT 
            c.id as carrito_id,
            c.cantidad,
            p.id as producto_id,
            p.nombre,
            p.precio,
            p.cantidad_etiquetas,
            p.clave_producto,
            cat.nombre as categoria_nombre
        FROM carrito_compras c
        INNER JOIN productos p ON c.id_producto = p.id
        INNER JOIN categorias cat ON p.categoria_id = cat.id
        WHERE c.id_cliente = ? AND p.activo = 1
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$_SESSION['usuario_id']]);
    $carrito_items = $stmt->fetchAll();
    
    // Calcular total
    foreach ($carrito_items as $item) {
        $carrito_total += $item['precio'] * $item['cantidad'];
    }
    
} catch (Exception $e) {
    error_log("Error obteniendo carrito: " . $e->getMessage());
}

// Si no hay items, redirigir
if (empty($carrito_items)) {
    header('Location: carrito.php?mensaje=carrito_vacio');
    exit();
}

// Obtener direcciones del usuario
$direcciones = [];
try {
    $stmt = $conn->prepare("
        SELECT * FROM direcciones_envio 
        WHERE id_cliente = ? AND activo = 1 
        ORDER BY es_principal DESC, created_at DESC
    ");
    $stmt->execute([$_SESSION['usuario_id']]);
    $direcciones = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error obteniendo direcciones: " . $e->getMessage());
}

// Obtener cupones disponibles
$cupones_disponibles = [];
try {
    $stmt = $conn->prepare("
        SELECT * FROM cupones 
        WHERE activo = 1 
        AND fecha_inicio <= CURDATE() 
        AND fecha_fin >= CURDATE()
        AND (limite_usos IS NULL OR usos_actuales < limite_usos)
        AND ? >= COALESCE(monto_minimo, 0)
        ORDER BY valor_descuento DESC
    ");
    $stmt->execute([$carrito_total]);
    $cupones_disponibles = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error obteniendo cupones: " . $e->getMessage());
}

// Obtener configuración del sistema
$config = [];
try {
    $stmt = $conn->query("SELECT clave, valor FROM configuracion_sistema");
    $config_result = $stmt->fetchAll();
    foreach ($config_result as $row) {
        $config[$row['clave']] = $row['valor'];
    }
} catch (Exception $e) {
    error_log("Error obteniendo configuración: " . $e->getMessage());
}

// Calcular costos
$subtotal = $carrito_total;
$descuento_aplicado = 0;
$costo_envio = floatval($config['costo_envio_local'] ?? 50);
$iva_porcentaje = floatval($config['iva_porcentaje'] ?? 16) / 100;
$envio_gratis_minimo = floatval($config['envio_gratis_minimo'] ?? 1000);

// Envío gratis si supera el mínimo
if ($subtotal >= $envio_gratis_minimo) {
    $costo_envio = 0;
}

$iva = ($subtotal - $descuento_aplicado) * $iva_porcentaje;
$total = $subtotal - $descuento_aplicado + $costo_envio + $iva;

// Validar configuración de Stripe
$stripe_config_valid = false;
$stripe_config = null;
try {
    $stripe_config = getStripeConfig();
    validateStripeConfig();
    $stripe_config_valid = true;
} catch (Exception $e) {
    error_log("Error configuración Stripe: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - Finalizar Compra | Novedades Ashley</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <!-- Stripe JS -->
    <script src="https://js.stripe.com/v3/"></script>
    
    <style>
        .checkout-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 0;
        }
        
        .checkout-step {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            padding: 30px;
            margin-bottom: 30px;
            transition: all 0.3s ease;
        }
        
        .checkout-step:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .step-header {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .step-number {
            width: 40px;
            height: 40px;
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 15px;
        }
        
        .step-number.completed {
            background: linear-gradient(45deg, #28a745, #20c997);
        }
        
        .address-card {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .address-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.1);
        }
        
        .address-card.selected {
            border-color: #28a745;
            background: #f8fff9;
        }
        
        .principal-badge {
            background: #28a745;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: bold;
        }
        
        .order-summary {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 30px;
            position: sticky;
            top: 20px;
        }
        
        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #dee2e6;
        }
        
        .summary-item:last-child {
            border-bottom: none;
            font-weight: bold;
            font-size: 1.1rem;
        }
        
        .btn-checkout {
            background: linear-gradient(45deg, #28a745, #20c997);
            border: none;
            border-radius: 10px;
            padding: 15px;
            font-weight: bold;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            color: white;
        }
        
        .btn-checkout:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.4);
            color: white;
        }
        
        .btn-checkout:disabled {
            background: #6c757d;
            transform: none;
            box-shadow: none;
            cursor: not-allowed;
        }
        
        .cart-item {
            padding: 15px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .cart-item:last-child {
            border-bottom: none;
        }
        
        .coupon-input {
            border-radius: 10px 0 0 10px;
            border-right: none;
        }
        
        .coupon-btn {
            border-radius: 0 10px 10px 0;
            border-left: none;
        }
        
        .envio-gratis-banner {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        /* Estilos para Stripe Elements */
        .stripe-payment-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 25px;
            margin-top: 20px;
        }
        
        .stripe-element {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .stripe-element:hover,
        .stripe-element:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .stripe-element.StripeElement--invalid {
            border-color: #dc3545;
        }
        
        .stripe-element.StripeElement--complete {
            border-color: #28a745;
        }
        
        .stripe-error {
            color: #dc3545;
            font-size: 0.875rem;
            margin-top: 5px;
        }
        
        .stripe-success {
            color: #28a745;
            font-size: 0.875rem;
            margin-top: 5px;
        }
        
        .payment-method-icons {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-top: 15px;
        }
        
        .stripe-mode-badge {
            background: #17a2b8;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: bold;
            margin-left: 10px;
        }
        
        .stripe-mode-badge.production {
            background: #28a745;
        }
        
        .stripe-mode-badge.sandbox {
            background: #ffc107;
            color: #212529;
        }
        
        @media (max-width: 768px) {
            .checkout-step {
                padding: 20px;
            }
            
            .order-summary {
                position: relative;
                top: 0;
                margin-top: 30px;
            }
        }
    </style>
</head>
<body>
    <!-- Navegación -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-crown me-2"></i> Novedades Ashley
            </a>
            
            <!-- Indicador de progreso -->
            <div class="d-none d-md-flex align-items-center text-light">
                <span class="me-3">Carrito</span>
                <i class="fas fa-chevron-right me-3 text-muted"></i>
                <span class="me-3 fw-bold">Checkout</span>
                <i class="fas fa-chevron-right me-3 text-muted"></i>
                <span class="text-muted">Confirmación</span>
            </div>
            
            <a href="carrito.php" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left"></i> Volver al Carrito
            </a>
        </div>
    </nav>

    <!-- Header -->
    <section class="checkout-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="display-6 mb-3">
                        <i class="fas fa-credit-card me-3"></i> Finalizar Compra
                    </h1>
                    <p class="lead">
                        Completa tu pedido de forma segura con Stripe
                        <?php if ($stripe_config_valid): ?>
                            <span class="stripe-mode-badge <?= strtolower(STRIPE_MODE === 'PRODUCCIÓN' ? 'production' : 'sandbox') ?>">
                                <?= STRIPE_MODE ?>
                            </span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="bg-white text-dark rounded p-3 d-inline-block">
                        <h4 class="mb-0">$<?= number_format($total, 2) ?></h4>
                        <small>Total a Pagar</small>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="container my-5">
        <div class="row">
            <!-- Pasos del checkout -->
            <div class="col-lg-8">
                <!-- Envío gratis banner -->
                <?php if ($subtotal >= $envio_gratis_minimo): ?>
                    <div class="envio-gratis-banner">
                        <i class="fas fa-shipping-fast fa-2x mb-2"></i>
                        <h5 class="mb-1">¡Felicidades! Tienes envío GRATIS</h5>
                        <p class="mb-0">Tu compra supera los $<?= number_format($envio_gratis_minimo, 0) ?></p>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>¡Falta poco para envío gratis!</strong>
                        Agrega $<?= number_format($envio_gratis_minimo - $subtotal, 2) ?> más y obtén envío gratuito.
                    </div>
                <?php endif; ?>

                <!-- Paso 1: Revisar Pedido -->
                <div class="checkout-step">
                    <div class="step-header">
                        <div class="step-number completed">1</div>
                        <div>
                            <h4 class="mb-0">Revisar tu Pedido</h4>
                            <p class="text-muted mb-0"><?= count($carrito_items) ?> artículo(s) en tu carrito</p>
                        </div>
                    </div>
                    
                    <div class="cart-items">
                        <?php foreach ($carrito_items as $item): ?>
                            <div class="cart-item">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <h6 class="mb-1"><?= htmlspecialchars($item['nombre']) ?></h6>
                                        <small class="text-muted">
                                            <?= htmlspecialchars($item['categoria_nombre']) ?> | 
                                            Código: <?= htmlspecialchars($item['clave_producto']) ?>
                                        </small>
                                    </div>
                                    <div class="col-md-2 text-center">
                                        <span class="badge bg-light text-dark">
                                            Qty: <?= $item['cantidad'] ?>
                                        </span>
                                    </div>
                                    <div class="col-md-2 text-center">
                                        $<?= number_format($item['precio'], 2) ?>
                                    </div>
                                    <div class="col-md-2 text-end">
                                        <strong>$<?= number_format($item['precio'] * $item['cantidad'], 2) ?></strong>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="text-end mt-3">
                        <a href="carrito.php" class="btn btn-outline-primary">
                            <i class="fas fa-edit"></i> Modificar Carrito
                        </a>
                    </div>
                </div>

                <!-- Paso 2: Dirección de Envío -->
                <div class="checkout-step">
                    <div class="step-header">
                        <div class="step-number" id="step-2-number">2</div>
                        <div>
                            <h4 class="mb-0">Dirección de Envío</h4>
                            <p class="text-muted mb-0">Selecciona dónde quieres recibir tu pedido</p>
                        </div>
                    </div>
                    
                    <?php if (count($direcciones) > 0): ?>
                        <div class="addresses-list">
                            <?php foreach ($direcciones as $index => $direccion): ?>
                                <div class="address-card" data-address-id="<?= $direccion['id'] ?>" 
                                     onclick="selectAddress(<?= $direccion['id'] ?>)"
                                     <?= $direccion['es_principal'] ? 'id="default-address"' : '' ?>>
                                    
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center mb-2">
                                                <h6 class="mb-0 me-2"><?= htmlspecialchars($direccion['nombre_direccion']) ?></h6>
                                                <?php if ($direccion['es_principal']): ?>
                                                    <span class="principal-badge">Principal</span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <p class="mb-1">
                                                <strong><?= htmlspecialchars($direccion['nombre_destinatario']) ?></strong><br>
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
                                        
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" 
                                                   name="direccion_envio" value="<?= $direccion['id'] ?>"
                                                   id="addr_<?= $direccion['id'] ?>"
                                                   <?= $direccion['es_principal'] ? 'checked' : '' ?>>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>No tienes direcciones registradas</strong><br>
                            Necesitas agregar al menos una dirección para continuar.
                        </div>
                    <?php endif; ?>
                    
                    <div class="text-end mt-3">
                        <a href="perfil.php" class="btn btn-outline-primary">
                            <i class="fas fa-plus"></i> Agregar Nueva Dirección
                        </a>
                    </div>
                </div>

                <!-- Paso 3: Método de Pago con Stripe -->
                <div class="checkout-step">
                    <div class="step-header">
                        <div class="step-number" id="step-3-number">3</div>
                        <div>
                            <h4 class="mb-0">Método de Pago</h4>
                            <p class="text-muted mb-0">Pago seguro procesado por Stripe</p>
                        </div>
                    </div>
                    
                    <div class="stripe-payment-section">
                        <div class="row">
                            <div class="col-md-6">
                                <label for="card-element" class="form-label">
                                    <i class="fas fa-credit-card"></i> Información de la Tarjeta
                                </label>
                                <div id="card-element" class="stripe-element">
                                    <!-- Stripe Elements insertará aquí los campos de la tarjeta -->
                                </div>
                                <div id="card-errors" class="stripe-error" role="alert"></div>
                                <div id="card-success" class="stripe-success" style="display: none;"></div>
                            </div>
                            <div class="col-md-6">
                                <h6>Métodos de pago aceptados:</h6>
                                <div class="payment-method-icons">
                                    <i class="fab fa-cc-visa fa-2x text-primary" title="Visa"></i>
                                    <i class="fab fa-cc-mastercard fa-2x text-warning" title="Mastercard"></i>
                                    <i class="fab fa-cc-amex fa-2x text-info" title="American Express"></i>
                                </div>
                                
                                <?php if (STRIPE_MODE !== 'PRODUCCIÓN'): ?>
                                    <div class="mt-3">
                                        <small class="text-info">
                                            <i class="fas fa-info-circle"></i>
                                            <strong>Modo de prueba:</strong><br>
                                            Usa: 4242 4242 4242 4242<br>
                                            CVV: cualquier 3 dígitos<br>
                                            Fecha: cualquier fecha futura
                                        </small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Paso 4: Cupón de Descuento (Opcional) -->
                <div class="checkout-step">
                    <div class="step-header">
                        <div class="step-number" id="step-4-number">4</div>
                        <div>
                            <h4 class="mb-0">Cupón de Descuento</h4>
                            <p class="text-muted mb-0">¿Tienes un código de descuento?</p>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="input-group">
                                <input type="text" class="form-control coupon-input" 
                                       id="coupon-code" placeholder="Ingresa tu código">
                                <button class="btn btn-outline-primary coupon-btn" 
                                        type="button" onclick="applyCoupon()">
                                    <i class="fas fa-tag"></i> Aplicar
                                </button>
                            </div>
                            <div id="coupon-message" class="mt-2"></div>
                        </div>
                        
                        <?php if (count($cupones_disponibles) > 0): ?>
                            <div class="col-md-6">
                                <h6>Cupones disponibles:</h6>
                                <?php foreach (array_slice($cupones_disponibles, 0, 3) as $cupon): ?>
                                    <div class="small text-success mb-1 coupon-suggestion" 
                                         onclick="useCoupon('<?= $cupon['codigo'] ?>')">
                                        <i class="fas fa-tag"></i> 
                                        <strong><?= htmlspecialchars($cupon['codigo']) ?></strong> - 
                                        <?php if ($cupon['tipo_descuento'] === 'porcentaje'): ?>
                                            <?= $cupon['valor_descuento'] ?>% de descuento
                                        <?php else: ?>
                                            $<?= number_format($cupon['valor_descuento'], 2) ?> de descuento
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Paso 5: Notas del Pedido (Opcional) -->
                <div class="checkout-step">
                    <div class="step-header">
                        <div class="step-number">5</div>
                        <div>
                            <h4 class="mb-0">Notas del Pedido</h4>
                            <p class="text-muted mb-0">Información adicional (opcional)</p>
                        </div>
                    </div>
                    
                    <textarea class="form-control" id="order-notes" rows="3" 
                              placeholder="Instrucciones especiales de entrega, referencias adicionales, etc."></textarea>
                </div>
            </div>

            <!-- Resumen del pedido -->
            <div class="col-lg-4">
                <div class="order-summary">
                    <h4 class="mb-4">
                        <i class="fas fa-receipt"></i> Resumen del Pedido
                    </h4>
                    
                    <div class="summary-item">
                        <span>Subtotal (<?= count($carrito_items) ?> artículos):</span>
                        <span id="summary-subtotal">$<?= number_format($subtotal, 2) ?></span>
                    </div>
                    
                    <div class="summary-item" id="discount-row" style="display: none;">
                        <span>Descuento:</span>
                        <span class="text-success" id="summary-discount">-$0.00</span>
                    </div>
                    
                    <div class="summary-item">
                        <span>Envío:</span>
                        <span id="summary-shipping">
                            <?php if ($costo_envio > 0): ?>
                                $<?= number_format($costo_envio, 2) ?>
                            <?php else: ?>
                                <span class="text-success">GRATIS</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    
                    <div class="summary-item">
                        <span>IVA (<?= $config['iva_porcentaje'] ?>%):</span>
                        <span id="summary-tax">$<?= number_format($iva, 2) ?></span>
                    </div>
                    
                    <hr>
                    
                    <div class="summary-item">
                        <span>Total:</span>
                        <span class="text-success" id="summary-total">$<?= number_format($total, 2) ?></span>
                    </div>
                    
                    <button class="btn btn-checkout w-100 mt-4" id="btn-finalizar" 
                            onclick="procesarPagoStripe()" disabled>
                        <i class="fas fa-spinner fa-spin"></i> Cargando Stripe...
                    </button>
                    
                    <div class="text-center mt-3">
                        <small class="text-muted">
                            <i class="fas fa-shield-alt text-success"></i>
                            Compra 100% segura protegida por Stripe
                        </small>
                    </div>
                    
                    <!-- Información de seguridad -->
                    <div class="text-center mt-4">
                        <img src="https://img.shields.io/badge/Secured%20by-Stripe-626cd9?style=flat&logo=stripe" alt="Secured by Stripe" class="img-fluid">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Variables globales
        let selectedAddressId = <?= count($direcciones) > 0 ? ($direcciones[0]['es_principal'] ? $direcciones[0]['id'] : $direcciones[0]['id']) : 'null' ?>;
        let appliedCoupon = null;
        let orderTotals = {
            subtotal: <?= $subtotal ?>,
            discount: 0,
            shipping: <?= $costo_envio ?>,
            tax: <?= $iva ?>,
            total: <?= $total ?>
        };

        // Variables de Stripe
        let stripe = null;
        let elements = null;
        let cardElement = null;
        let paymentProcessing = false;
        let stripeLoaded = false;

        // Inicializar cuando la página se carga
        document.addEventListener('DOMContentLoaded', async function() {
            console.log('Iniciando configuración de Stripe...');
            
            try {
                // Obtener clave pública de Stripe
                const response = await fetch('api/stripe_checkout.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=get_public_key'
                });
                
                const data = await response.json();
                console.log('Respuesta API:', data);
                
                if (data.success) {
                    // Inicializar Stripe con la clave pública
                    stripe = Stripe(data.data.public_key);
                    console.log('Stripe inicializado con clave:', data.data.public_key.substring(0, 20) + '...');
                    
                    // Crear Elements
                    elements = stripe.elements({
                        locale: 'es'
                    });
                    
                    // Crear elemento de tarjeta
                    cardElement = elements.create('card', {
                        style: {
                            base: {
                                fontSize: '16px',
                                color: '#424770',
                                '::placeholder': {
                                    color: '#aab7c4',
                                },
                                fontFamily: 'system-ui, -apple-system, sans-serif',
                            },
                            invalid: {
                                color: '#dc3545',
                                iconColor: '#dc3545'
                            },
                            complete: {
                                color: '#28a745',
                                iconColor: '#28a745'
                            }
                        },
                        hidePostalCode: false
                    });
                    
                    // Montar elemento en el DOM
                    cardElement.mount('#card-element');
                    console.log('Elemento de tarjeta montado');
                    
                    // Manejar errores en tiempo real
                    cardElement.on('change', function(event) {
                        const displayError = document.getElementById('card-errors');
                        const displaySuccess = document.getElementById('card-success');
                        
                        if (event.error) {
                            displayError.textContent = event.error.message;
                            displaySuccess.style.display = 'none';
                        } else {
                            displayError.textContent = '';
                            if (event.complete) {
                                displaySuccess.textContent = '✓ Información de tarjeta válida';
                                displaySuccess.style.display = 'block';
                            } else {
                                displaySuccess.style.display = 'none';
                            }
                        }
                        updateStepStatus();
                    });
                    
                    stripeLoaded = true;
                    console.log('Stripe cargado exitosamente en modo:', data.data.environment);
                } else {
                    throw new Error(data.message);
                }
            } catch (error) {
                console.error('Error inicializando Stripe:', error);
                showError('Error inicializando sistema de pagos: ' + error.message);
                
                // Mostrar botón con error
                const btnFinalizar = document.getElementById('btn-finalizar');
                btnFinalizar.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Error de configuración';
                btnFinalizar.disabled = true;
            }
            
            // Configuración inicial
            setupInitialState();
        });

        function setupInitialState() {
            // Seleccionar dirección principal por defecto
            <?php if (count($direcciones) > 0): ?>
                const defaultAddress = document.getElementById('default-address');
                if (defaultAddress) {
                    defaultAddress.classList.add('selected');
                }
            <?php endif; ?>
            
            // Actualizar estado inicial
            updateStepStatus();
            
            // Animaciones de entrada
            const steps = document.querySelectorAll('.checkout-step');
            steps.forEach((step, index) => {
                step.style.opacity = '0';
                step.style.transform = 'translateY(30px)';
                setTimeout(() => {
                    step.style.transition = 'all 0.6s ease';
                    step.style.opacity = '1';
                    step.style.transform = 'translateY(0)';
                }, index * 100);
            });
            
            // Event listeners para sugerencias de cupones
            document.querySelectorAll('.coupon-suggestion').forEach(suggestion => {
                suggestion.style.cursor = 'pointer';
                suggestion.addEventListener('mouseenter', function() {
                    this.style.textDecoration = 'underline';
                });
                suggestion.addEventListener('mouseleave', function() {
                    this.style.textDecoration = 'none';
                });
            });
            
            // Enter key en campo de cupón
            document.getElementById('coupon-code').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    applyCoupon();
                }
            });
        }

        // Función para seleccionar dirección
        function selectAddress(addressId) {
            // Remover selección anterior
            document.querySelectorAll('.address-card').forEach(card => {
                card.classList.remove('selected');
                card.querySelector('input[type="radio"]').checked = false;
            });
            
            // Seleccionar nueva dirección
            const selectedCard = document.querySelector(`[data-address-id="${addressId}"]`);
            selectedCard.classList.add('selected');
            selectedCard.querySelector('input[type="radio"]').checked = true;
            
            selectedAddressId = addressId;
            updateStepStatus();
        }

        // Función para aplicar cupón
        async function applyCoupon() {
            const couponCode = document.getElementById('coupon-code').value.trim();
            const messageDiv = document.getElementById('coupon-message');
            
            if (!couponCode) {
                showCouponMessage('Por favor ingresa un código de cupón', 'error');
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'validate_coupon');
                formData.append('coupon_code', couponCode);
                formData.append('order_total', orderTotals.subtotal);
                
                const response = await fetch('api/checkout.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    appliedCoupon = data.data;
                    orderTotals.discount = appliedCoupon.descuento_aplicado;
                    updateOrderSummary();
                    showCouponMessage(`¡Cupón aplicado! Descuento: ${appliedCoupon.descuento_aplicado.toFixed(2)}`, 'success');
                    
                    // Deshabilitar input y botón
                    document.getElementById('coupon-code').disabled = true;
                    document.querySelector('.coupon-btn').innerHTML = '<i class="fas fa-check"></i> Aplicado';
                    document.querySelector('.coupon-btn').disabled = true;
                } else {
                    showCouponMessage(data.message, 'error');
                }
                
            } catch (error) {
                console.error('Error:', error);
                showCouponMessage('Error al validar el cupón', 'error');
            }
        }

        // Función para usar cupón sugerido
        function useCoupon(couponCode) {
            document.getElementById('coupon-code').value = couponCode;
            applyCoupon();
        }

        // Función para mostrar mensaje de cupón
        function showCouponMessage(message, type) {
            const messageDiv = document.getElementById('coupon-message');
            const className = type === 'success' ? 'text-success' : 'text-danger';
            messageDiv.innerHTML = `<small class="${className}"><i class="fas fa-${type === 'success' ? 'check' : 'exclamation-triangle'}"></i> ${message}</small>`;
        }

        // Función para actualizar el resumen del pedido
        function updateOrderSummary() {
            const taxableAmount = orderTotals.subtotal - orderTotals.discount;
            orderTotals.tax = taxableAmount * <?= $iva_porcentaje ?>;
            orderTotals.total = taxableAmount + orderTotals.shipping + orderTotals.tax;
            
            document.getElementById('summary-subtotal').textContent = `${orderTotals.subtotal.toFixed(2)}`;
            document.getElementById('summary-tax').textContent = `${orderTotals.tax.toFixed(2)}`;
            document.getElementById('summary-total').textContent = `${orderTotals.total.toFixed(2)}`;
            
            if (orderTotals.discount > 0) {
                document.getElementById('discount-row').style.display = 'flex';
                document.getElementById('summary-discount').textContent = `-${orderTotals.discount.toFixed(2)}`;
            }
        }

        // Función para actualizar el estado de los pasos
        function updateStepStatus() {
            // Paso 2: Dirección
            const step2 = document.getElementById('step-2-number');
            if (selectedAddressId) {
                step2.classList.add('completed');
            }
            
            // Paso 3: Stripe
            const step3 = document.getElementById('step-3-number');
            if (stripeLoaded && selectedAddressId) {
                step3.classList.add('completed');
            }
            
            // Habilitar/deshabilitar botón de finalizar
            const btnFinalizar = document.getElementById('btn-finalizar');
            
            if (!stripeLoaded) {
                btnFinalizar.disabled = true;
                btnFinalizar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cargando Stripe...';
            } else if (!selectedAddressId) {
                btnFinalizar.disabled = true;
                btnFinalizar.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Selecciona una dirección';
            } else if (paymentProcessing) {
                btnFinalizar.disabled = true;
                btnFinalizar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando pago...';
            } else {
                btnFinalizar.disabled = false;
                btnFinalizar.innerHTML = '<i class="fas fa-lock"></i> Pagar con Stripe';
            }
        }

        // Función principal para procesar pago con Stripe
        async function procesarPagoStripe() {
            if (!stripe || !cardElement || !selectedAddressId || paymentProcessing) {
                showError('Por favor completa todos los pasos antes de continuar');
                return;
            }
            
            // Confirmar intención de compra
            if (!confirm('¿Estás seguro de que quieres finalizar esta compra?')) {
                return;
            }
            
            paymentProcessing = true;
            updateStepStatus();
            
            try {
                console.log('Iniciando proceso de pago...');
                
                // Paso 1: Crear Payment Intent
                const paymentIntentResponse = await fetch('api/stripe_checkout.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'create_payment_intent',
                        amount: orderTotals.total,
                        currency: 'mxn',
                        order_data: JSON.stringify({
                            items: <?= json_encode($carrito_items) ?>,
                            totals: orderTotals
                        })
                    })
                });
                
                const paymentIntentData = await paymentIntentResponse.json();
                console.log('Payment Intent creado:', paymentIntentData);
                
                if (!paymentIntentData.success) {
                    throw new Error(paymentIntentData.message);
                }
                
                // Paso 2: Confirmar pago con Stripe
                console.log('Confirmando pago con Stripe...');
                const {error, paymentIntent} = await stripe.confirmCardPayment(
                    paymentIntentData.data.client_secret,
                    {
                        payment_method: {
                            card: cardElement,
                            billing_details: {
                                name: 'Cliente de Novedades Ashley'
                            }
                        }
                    }
                );
                
                if (error) {
                    console.error('Error en confirmación de pago:', error);
                    throw new Error(error.message);
                }
                
                console.log('Pago confirmado:', paymentIntent);
                
                if (paymentIntent.status === 'succeeded') {
                    // Paso 3: Confirmar el pedido en nuestro sistema
                    console.log('Confirmando pedido en sistema...');
                    const confirmResponse = await fetch('api/stripe_checkout.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            action: 'confirm_payment',
                            payment_intent_id: paymentIntent.id,
                            address_id: selectedAddressId,
                            order_notes: document.getElementById('order-notes').value,
                            coupon_code: appliedCoupon ? appliedCoupon.codigo : '',
                            totals: JSON.stringify(orderTotals)
                        })
                    });
                    
                    const confirmData = await confirmResponse.json();
                    console.log('Pedido confirmado:', confirmData);
                    
                    if (confirmData.success) {
                        // Redirigir a página de confirmación
                        console.log('Redirigiendo a confirmación...');
                        window.location.href = `checkout_confirmacion.php?order_id=${confirmData.data.order_id}&numero_pedido=${confirmData.data.numero_pedido}&stripe_payment_intent=${paymentIntent.id}`;
                    } else {
                        throw new Error(confirmData.message);
                    }
                } else {
                    throw new Error('El pago no pudo ser procesado completamente');
                }
                
            } catch (error) {
                console.error('Error procesando pago:', error);
                showError('Error procesando el pago: ' + error.message);
                paymentProcessing = false;
                updateStepStatus();
            }
        }

        // Función para mostrar errores
        function showError(message) {
            const errorDiv = document.getElementById('card-errors');
            if (errorDiv) {
                errorDiv.textContent = message;
            } else {
                alert(message);
            }
        }

        // Función para manejar errores de red
        window.addEventListener('offline', function() {
            alert('Se perdió la conexión a internet. Por favor verifica tu conexión antes de procesar el pago.');
        });

        // Prevenir salida accidental de la página
        window.addEventListener('beforeunload', function(e) {
            if (paymentProcessing) {
                e.preventDefault();
                e.returnValue = '';
                return 'Hay un pago en proceso. ¿Estás seguro de que quieres salir?';
            }
        });
    </script>
</body>
</html>