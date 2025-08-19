<?php
// checkout.php - Sistema de checkout completo
session_start();
require_once 'config/database.php';

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

// Obtener métodos de pago del usuario
$metodos_pago = [];
try {
    $stmt = $conn->prepare("
        SELECT * FROM metodos_pago 
        WHERE id_cliente = ? AND activo = 1 
        ORDER BY es_principal DESC, created_at DESC
    ");
    $stmt->execute([$_SESSION['usuario_id']]);
    $metodos_pago = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error obteniendo métodos de pago: " . $e->getMessage());
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
        
        .address-card, .payment-card {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .address-card:hover, .payment-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.1);
        }
        
        .address-card.selected, .payment-card.selected {
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
            justify-content: between;
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
        
        .progress-bar {
            background: linear-gradient(45deg, #667eea, #764ba2);
            height: 6px;
            border-radius: 3px;
        }
        
        .envio-gratis-banner {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
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
                    <p class="lead">Completa tu pedido de forma segura y rápida</p>
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
                        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addAddressModal">
                            <i class="fas fa-plus"></i> Agregar Nueva Dirección
                        </button>
                    </div>
                </div>

                <!-- Paso 3: Método de Pago -->
                <div class="checkout-step">
                    <div class="step-header">
                        <div class="step-number" id="step-3-number">3</div>
                        <div>
                            <h4 class="mb-0">Método de Pago</h4>
                            <p class="text-muted mb-0">Selecciona cómo quieres pagar</p>
                        </div>
                    </div>
                    
                    <?php if (count($metodos_pago) > 0): ?>
                        <div class="payment-methods-list">
                            <?php foreach ($metodos_pago as $metodo): ?>
                                <div class="payment-card" data-payment-id="<?= $metodo['id'] ?>" 
                                     onclick="selectPayment(<?= $metodo['id'] ?>)"
                                     <?= $metodo['es_principal'] ? 'id="default-payment"' : '' ?>>
                                    
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-credit-card fa-2x me-3 text-primary"></i>
                                            <div>
                                                <div class="d-flex align-items-center mb-1">
                                                    <h6 class="mb-0 me-2">
                                                        <?= htmlspecialchars($metodo['nombre_tarjeta']) ?> 
                                                        •••• <?= htmlspecialchars($metodo['ultimos_4_digitos']) ?>
                                                    </h6>
                                                    <?php if ($metodo['es_principal']): ?>
                                                        <span class="principal-badge">Principal</span>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?= htmlspecialchars($metodo['nombre_titular']) ?> | 
                                                    Exp: <?= $metodo['mes_expiracion'] ?>/<?= $metodo['ano_expiracion'] ?>
                                                </small>
                                            </div>
                                        </div>
                                        
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" 
                                                   name="metodo_pago" value="<?= $metodo['id'] ?>"
                                                   id="pay_<?= $metodo['id'] ?>"
                                                   <?= $metodo['es_principal'] ? 'checked' : '' ?>>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>No tienes métodos de pago registrados</strong><br>
                            Necesitas agregar al menos un método de pago para continuar.
                        </div>
                    <?php endif; ?>
                    
                    <div class="text-end mt-3">
                        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                            <i class="fas fa-plus"></i> Agregar Método de Pago
                        </button>
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
                            onclick="finalizarCompra()" 
                            <?= (count($direcciones) === 0 || count($metodos_pago) === 0) ? 'disabled' : '' ?>>
                        <i class="fas fa-lock"></i> Finalizar Compra Segura
                    </button>
                    
                    <div class="text-center mt-3">
                        <small class="text-muted">
                            <i class="fas fa-shield-alt text-success"></i>
                            Compra 100% segura y protegida
                        </small>
                    </div>
                    
                    <!-- Métodos de pago aceptados -->
                    <div class="text-center mt-4">
                        <h6>Aceptamos:</h6>
                        <div class="d-flex justify-content-center gap-2">
                            <i class="fab fa-cc-visa fa-2x text-primary"></i>
                            <i class="fab fa-cc-mastercard fa-2x text-warning"></i>
                            <i class="fab fa-cc-amex fa-2x text-info"></i>
                            <i class="fas fa-university fa-2x text-secondary"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para agregar dirección (simplificado) -->
    <div class="modal fade" id="addAddressModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Agregar Método de Pago</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Para una experiencia completa, ve a tu perfil para gestionar métodos de pago.</p>
                    <a href="perfil.php" class="btn btn-primary w-100">
                        <i class="fas fa-credit-card"></i> Ir a Mi Perfil
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Variables globales
        let selectedAddressId = <?= count($direcciones) > 0 ? ($direcciones[0]['es_principal'] ? $direcciones[0]['id'] : $direcciones[0]['id']) : 'null' ?>;
        let selectedPaymentId = <?= count($metodos_pago) > 0 ? ($metodos_pago[0]['es_principal'] ? $metodos_pago[0]['id'] : $metodos_pago[0]['id']) : 'null' ?>;
        let appliedCoupon = null;
        let orderTotals = {
            subtotal: <?= $subtotal ?>,
            discount: 0,
            shipping: <?= $costo_envio ?>,
            tax: <?= $iva ?>,
            total: <?= $total ?>
        };

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

        // Función para seleccionar método de pago
        function selectPayment(paymentId) {
            // Remover selección anterior
            document.querySelectorAll('.payment-card').forEach(card => {
                card.classList.remove('selected');
                card.querySelector('input[type="radio"]').checked = false;
            });
            
            // Seleccionar nuevo método
            const selectedCard = document.querySelector(`[data-payment-id="${paymentId}"]`);
            selectedCard.classList.add('selected');
            selectedCard.querySelector('input[type="radio"]').checked = true;
            
            selectedPaymentId = paymentId;
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
            
            // Paso 3: Método de pago
            const step3 = document.getElementById('step-3-number');
            if (selectedPaymentId) {
                step3.classList.add('completed');
            }
            
            // Habilitar/deshabilitar botón de finalizar
            const btnFinalizar = document.getElementById('btn-finalizar');
            if (selectedAddressId && selectedPaymentId) {
                btnFinalizar.disabled = false;
                btnFinalizar.innerHTML = '<i class="fas fa-lock"></i> Finalizar Compra Segura';
            } else {
                btnFinalizar.disabled = true;
                btnFinalizar.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Completa todos los pasos';
            }
        }

        // Función para finalizar compra
        async function finalizarCompra() {
            if (!selectedAddressId || !selectedPaymentId) {
                alert('Por favor completa todos los pasos antes de continuar');
                return;
            }
            
            // Confirmación final
            if (!confirm('¿Estás seguro de que quieres finalizar esta compra?')) {
                return;
            }
            
            const btnFinalizar = document.getElementById('btn-finalizar');
            const originalText = btnFinalizar.innerHTML;
            
            try {
                // Mostrar loading
                btnFinalizar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';
                btnFinalizar.disabled = true;
                
                // Preparar datos del pedido
                const orderData = {
                    action: 'create_order',
                    address_id: selectedAddressId,
                    payment_id: selectedPaymentId,
                    coupon_code: appliedCoupon ? appliedCoupon.codigo : null,
                    order_notes: document.getElementById('order-notes').value,
                    totals: orderTotals
                };
                
                const formData = new FormData();
                Object.keys(orderData).forEach(key => {
                    if (key === 'totals') {
                        formData.append(key, JSON.stringify(orderData[key]));
                    } else {
                        formData.append(key, orderData[key]);
                    }
                });
                
                const response = await fetch('api/checkout.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Redirigir a página de confirmación
                    window.location.href = `checkout_confirmacion.php?order_id=${data.data.order_id}&numero_pedido=${data.data.numero_pedido}`;
                } else {
                    throw new Error(data.message || 'Error al procesar el pedido');
                }
                
            } catch (error) {
                console.error('Error:', error);
                alert('Error al procesar el pedido: ' + error.message);
                
                // Restaurar botón
                btnFinalizar.innerHTML = originalText;
                btnFinalizar.disabled = false;
            }
        }

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            // Seleccionar dirección principal por defecto
            <?php if (count($direcciones) > 0): ?>
                const defaultAddress = document.getElementById('default-address');
                if (defaultAddress) {
                    defaultAddress.classList.add('selected');
                }
            <?php endif; ?>
            
            // Seleccionar método de pago principal por defecto
            <?php if (count($metodos_pago) > 0): ?>
                const defaultPayment = document.getElementById('default-payment');
                if (defaultPayment) {
                    defaultPayment.classList.add('selected');
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
        });

        // Función para manejar errores de red
        window.addEventListener('offline', function() {
            alert('Se perdió la conexión a internet. Por favor verifica tu conexión antes de finalizar la compra.');
        });

        // Prevenir salida accidental de la página
        window.addEventListener('beforeunload', function(e) {
            if (selectedAddressId && selectedPaymentId) {
                e.preventDefault();
                e.returnValue = '';
                return 'Tienes una compra en proceso. ¿Estás seguro de que quieres salir?';
            }
        });
    </script>
</body>
</html>Agregar Dirección Rápida</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Para una experiencia completa, ve a tu perfil para gestionar direcciones.</p>
                    <a href="perfil.php" class="btn btn-primary w-100">
                        <i class="fas fa-user"></i> Ir a Mi Perfil
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para agregar método de pago (simplificado) -->
    <div class="modal fade" id="addPaymentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">