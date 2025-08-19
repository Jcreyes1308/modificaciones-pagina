<?php
// checkout_confirmacion.php - Página de confirmación del pedido
session_start();
require_once 'config/database.php';

// Verificar que el usuario esté logueado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

$order_id = intval($_GET['order_id'] ?? 0);
$numero_pedido = $_GET['numero_pedido'] ?? '';

if (!$order_id || !$numero_pedido) {
    header('Location: index.php');
    exit();
}

$database = new Database();
$conn = $database->getConnection();

// Obtener detalles del pedido
$pedido = null;
$pedido_items = [];

try {
    // Obtener información del pedido
    $stmt = $conn->prepare("
        SELECT 
            p.*,
            de.nombre_direccion,
            de.nombre_destinatario,
            de.calle_numero,
            de.colonia,
            de.ciudad,
            de.estado as estado_direccion,
            de.codigo_postal,
            de.telefono_contacto,
            mp.nombre_tarjeta,
            mp.ultimos_4_digitos
        FROM pedidos p
        LEFT JOIN direcciones_envio de ON p.id_direccion_envio = de.id
        LEFT JOIN metodos_pago mp ON p.id_metodo_pago = mp.id
        WHERE p.id = ? AND p.id_cliente = ?
    ");
    $stmt->execute([$order_id, $_SESSION['usuario_id']]);
    $pedido = $stmt->fetch();
    
    if (!$pedido) {
        header('Location: mis_pedidos.php');
        exit();
    }
    
    // Obtener items del pedido
    $stmt = $conn->prepare("
        SELECT * FROM pedido_detalles 
        WHERE id_pedido = ? 
        ORDER BY id
    ");
    $stmt->execute([$order_id]);
    $pedido_items = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Error obteniendo pedido: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

// Obtener configuración para el email
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
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>¡Pedido Confirmado! - Novedades Ashley</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        .success-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 80px 0;
            text-align: center;
        }
        
        .success-icon {
            width: 120px;
            height: 120px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        .order-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            padding: 30px;
            margin-bottom: 30px;
        }
        
        .order-summary {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 25px;
        }
        
        .status-badge {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.9rem;
        }
        
        .next-steps {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border-radius: 15px;
            padding: 30px;
            margin: 30px 0;
        }
        
        .step-item {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .step-number {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 15px;
        }
        
        .btn-action {
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .contact-info {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .share-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: 20px 0;
        }
        
        .share-btn {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .share-btn:hover {
            transform: scale(1.1);
            color: white;
        }
        
        .facebook { background: #3b5998; }
        .twitter { background: #1da1f2; }
        .whatsapp { background: #25d366; }
        
        .tracking-preview {
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <!-- Header de éxito -->
    <section class="success-header">
        <div class="container">
            <div class="success-icon">
                <i class="fas fa-check fa-4x"></i>
            </div>
            
            <h1 class="display-4 mb-3">¡Pedido Confirmado!</h1>
            <p class="lead mb-4">Gracias por tu compra. Tu pedido ha sido procesado exitosamente.</p>
            
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="bg-white text-dark rounded p-4">
                        <h3 class="text-success mb-0"><?= htmlspecialchars($pedido['numero_pedido']) ?></h3>
                        <p class="mb-0">Número de Pedido</p>
                        <small class="text-muted">
                            Creado el <?= date('d/m/Y \a \l\a\s H:i', strtotime($pedido['created_at'])) ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="container my-5">
        <div class="row">
            <!-- Detalles del pedido -->
            <div class="col-lg-8">
                <!-- Información general -->
                <div class="order-card">
                    <h4 class="mb-4">
                        <i class="fas fa-receipt text-success"></i> Detalles del Pedido
                    </h4>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <strong>Estado del Pedido:</strong><br>
                            <span class="status-badge">
                                <i class="fas fa-clock"></i> Pendiente de Confirmación
                            </span>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <strong>Total Pagado:</strong><br>
                            <h4 class="text-success mb-0">$<?= number_format($pedido['total'], 2) ?></h4>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <strong>Método de Pago:</strong><br>
                            <?= htmlspecialchars($pedido['metodo_pago_usado']) ?>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <strong>Fecha Estimada de Entrega:</strong><br>
                            <?php
                            $fecha_estimada = date('d/m/Y', strtotime($pedido['created_at'] . ' +3 days'));
                            echo $fecha_estimada;
                            ?>
                        </div>
                    </div>
                </div>

                <!-- Dirección de envío -->
                <div class="order-card">
                    <h5 class="mb-3">
                        <i class="fas fa-map-marker-alt text-info"></i> Dirección de Envío
                    </h5>
                    
                    <div class="row">
                        <div class="col-md-8">
                            <strong><?= htmlspecialchars($pedido['nombre_destinatario']) ?></strong><br>
                            <?= htmlspecialchars($pedido['calle_numero']) ?><br>
                            <?php if ($pedido['colonia']): ?>
                                <?= htmlspecialchars($pedido['colonia']) ?>, 
                            <?php endif; ?>
                            <?= htmlspecialchars($pedido['ciudad']) ?>, <?= htmlspecialchars($pedido['estado_direccion']) ?>
                            <?php if ($pedido['codigo_postal']): ?>
                                - CP <?= htmlspecialchars($pedido['codigo_postal']) ?>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($pedido['telefono_contacto']): ?>
                            <div class="col-md-4">
                                <strong>Teléfono de Contacto:</strong><br>
                                <?= htmlspecialchars($pedido['telefono_contacto']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Productos ordenados -->
                <div class="order-card">
                    <h5 class="mb-3">
                        <i class="fas fa-list text-primary"></i> Productos Ordenados
                    </h5>
                    
                    <?php foreach ($pedido_items as $item): ?>
                        <div class="row align-items-center py-3 border-bottom">
                            <div class="col-md-6">
                                <h6 class="mb-1"><?= htmlspecialchars($item['nombre_producto']) ?></h6>
                                <?php if ($item['descripcion_producto']): ?>
                                    <small class="text-muted"><?= htmlspecialchars($item['descripcion_producto']) ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-2 text-center">
                                <span class="badge bg-light text-dark">
                                    Qty: <?= $item['cantidad'] ?>
                                </span>
                            </div>
                            <div class="col-md-2 text-center">
                                $<?= number_format($item['precio_unitario'], 2) ?>
                            </div>
                            <div class="col-md-2 text-end">
                                <strong>$<?= number_format($item['subtotal'], 2) ?></strong>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Información de seguimiento -->
                <div class="order-card">
                    <h5 class="mb-3">
                        <i class="fas fa-truck text-warning"></i> Seguimiento del Envío
                    </h5>
                    
                    <div class="tracking-preview">
                        <i class="fas fa-box fa-3x text-muted mb-3"></i>
                        <h6>Tu pedido está siendo preparado</h6>
                        <p class="text-muted mb-3">
                            Recibirás un número de seguimiento una vez que tu pedido sea enviado.
                        </p>
                        <small class="text-muted">
                            <i class="fas fa-info-circle"></i> 
                            Te enviaremos actualizaciones por email y SMS
                        </small>
                    </div>
                </div>
            </div>

            <!-- Panel lateral -->
            <div class="col-lg-4">
                <!-- Resumen de totales -->
                <div class="order-summary">
                    <h5 class="mb-3">Resumen de Pago</h5>
                    
                    <div class="d-flex justify-content-between mb-2">
                        <span>Subtotal:</span>
                        <span>$<?= number_format($pedido['subtotal'], 2) ?></span>
                    </div>
                    
                    <?php if ($pedido['descuentos'] > 0): ?>
                        <div class="d-flex justify-content-between mb-2 text-success">
                            <span>Descuentos:</span>
                            <span>-$<?= number_format($pedido['descuentos'], 2) ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="d-flex justify-content-between mb-2">
                        <span>Envío:</span>
                        <span>
                            <?php if ($pedido['costo_envio'] > 0): ?>
                                $<?= number_format($pedido['costo_envio'], 2) ?>
                            <?php else: ?>
                                <span class="text-success">GRATIS</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    
                    <div class="d-flex justify-content-between mb-3">
                        <span>IVA:</span>
                        <span>$<?= number_format($pedido['impuestos'], 2) ?></span>
                    </div>
                    
                    <hr>
                    
                    <div class="d-flex justify-content-between">
                        <strong>Total Pagado:</strong>
                        <strong class="text-success">$<?= number_format($pedido['total'], 2) ?></strong>
                    </div>
                </div>

                <!-- Próximos pasos -->
                <div class="next-steps">
                    <h5 class="mb-4">
                        <i class="fas fa-route"></i> ¿Qué sigue?
                    </h5>
                    
                    <div class="step-item">
                        <div class="step-number">1</div>
                        <div>
                            <strong>Confirmación</strong><br>
                            <small>Revisaremos tu pedido y confirmaremos el pago</small>
                        </div>
                    </div>
                    
                    <div class="step-item">
                        <div class="step-number">2</div>
                        <div>
                            <strong>Preparación</strong><br>
                            <small>Empacamos cuidadosamente tus productos</small>
                        </div>
                    </div>
                    
                    <div class="step-item">
                        <div class="step-number">3</div>
                        <div>
                            <strong>Envío</strong><br>
                            <small>Te enviamos el número de seguimiento</small>
                        </div>
                    </div>
                    
                    <div class="step-item">
                        <div class="step-number">4</div>
                        <div>
                            <strong>Entrega</strong><br>
                            <small>Recibes tu pedido en la dirección indicada</small>
                        </div>
                    </div>
                </div>

                <!-- Contacto de soporte -->
                <div class="contact-info">
                    <h6>
                        <i class="fas fa-headset"></i> ¿Necesitas Ayuda?
                    </h6>
                    <p class="mb-2">Nuestro equipo está aquí para ayudarte:</p>
                    <p class="mb-1">
                        <i class="fas fa-envelope"></i> 
                        <?= htmlspecialchars($config['email_contacto'] ?? 'contacto@novedadesashley.com') ?>
                    </p>
                    <p class="mb-0">
                        <i class="fas fa-phone"></i> 
                        <?= htmlspecialchars($config['telefono_contacto'] ?? '558-422-6977') ?>
                    </p>
                </div>

                <!-- Acciones rápidas -->
                <div class="d-grid gap-2">
                    <a href="mis_pedidos.php" class="btn btn-primary btn-action">
                        <i class="fas fa-history"></i> Ver Mis Pedidos
                    </a>
                    
                    <button class="btn btn-outline-success btn-action" onclick="descargarFactura()">
                        <i class="fas fa-download"></i> Descargar Factura
                    </button>
                    
                    <a href="productos.php" class="btn btn-outline-primary btn-action">
                        <i class="fas fa-shopping-bag"></i> Seguir Comprando
                    </a>
                </div>

                <!-- Compartir -->
                <div class="text-center mt-4">
                    <h6>¡Comparte tu experiencia!</h6>
                    <div class="share-buttons">
                        <a href="#" class="share-btn facebook" onclick="shareOnFacebook()">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="#" class="share-btn twitter" onclick="shareOnTwitter()">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="#" class="share-btn whatsapp" onclick="shareOnWhatsApp()">
                            <i class="fab fa-whatsapp"></i>
                        </a>
                    </div>
                </div>
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
    
    <script>
        // Función para descargar factura
        function descargarFactura() {
            alert('Descargando factura del pedido <?= $pedido['numero_pedido'] ?>\n\n' +
                  'La factura será enviada a tu email y estará disponible\n' +
                  'en la sección "Mis Pedidos" una vez procesada.\n\n' +
                  'Estado: Función en desarrollo');
        }
        
        // Funciones para compartir
        function shareOnFacebook() {
            const url = encodeURIComponent(window.location.href);
            const text = encodeURIComponent('¡Acabo de hacer una compra increíble en Novedades Ashley! 🛍️');
            window.open(`https://www.facebook.com/sharer/sharer.php?u=${url}&quote=${text}`, '_blank', 'width=600,height=400');
        }
        
        function shareOnTwitter() {
            const url = encodeURIComponent(window.location.href);
            const text = encodeURIComponent('¡Acabo de hacer una compra increíble en @NovedadesAshley! 🛍️ #Compras #NovedadesAshley');
            window.open(`https://twitter.com/intent/tweet?text=${text}&url=${url}`, '_blank', 'width=600,height=400');
        }
        
        function shareOnWhatsApp() {
            const text = encodeURIComponent('¡Hola! Acabo de hacer una compra en Novedades Ashley y quería compartirte esta tienda increíble: ' + window.location.origin);
            window.open(`https://wa.me/?text=${text}`, '_blank');
        }
        
        // Mostrar notificación de confirmación
        document.addEventListener('DOMContentLoaded', function() {
            // Animación de entrada
            const elements = document.querySelectorAll('.order-card, .order-summary, .next-steps');
            elements.forEach((element, index) => {
                element.style.opacity = '0';
                element.style.transform = 'translateY(30px)';
                setTimeout(() => {
                    element.style.transition = 'all 0.6s ease';
                    element.style.opacity = '1';
                    element.style.transform = 'translateY(0)';
                }, index * 100);
            });
            
            // Enviar email de confirmación (simulado)
            setTimeout(() => {
                if (confirm('¿Quieres recibir actualizaciones por WhatsApp sobre tu pedido?')) {
                    // Aquí implementarías la lógica para suscribir a notificaciones
                    alert('¡Perfecto! Te enviaremos actualizaciones importantes sobre tu pedido.');
                }
            }, 3000);
            
            // Auto-scroll suave al inicio
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
        
        // Función para imprimir el pedido
        function imprimirPedido() {
            window.print();
        }
        
        // Agregar evento para ctrl+p
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                imprimirPedido();
            }
        });
        
        // Evitar que el usuario salga accidentalmente
        let hasUserConfirmed = false;
        
        // Después de 10 segundos, asumir que el usuario ha visto la confirmación
        setTimeout(() => {
            hasUserConfirmed = true;
        }, 10000);
        
        window.addEventListener('beforeunload', function(e) {
            if (!hasUserConfirmed) {
                e.preventDefault();
                e.returnValue = '';
                return '¿Estás seguro de que quieres salir? Asegúrate de guardar tu número de pedido.';
            }
        });
        
        // Función para copiar número de pedido
        function copyOrderNumber() {
            const orderNumber = '<?= $pedido['numero_pedido'] ?>';
            
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(orderNumber).then(() => {
                    alert('Número de pedido copiado al portapapeles');
                });
            } else {
                // Fallback para navegadores más antiguos
                const textArea = document.createElement('textarea');
                textArea.value = orderNumber;
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                try {
                    document.execCommand('copy');
                    alert('Número de pedido copiado al portapapeles');
                } catch (err) {
                    console.error('Error al copiar: ', err);
                }
                document.body.removeChild(textArea);
            }
        }
        
        // Agregar evento click al número de pedido para copiarlo
        document.addEventListener('DOMContentLoaded', function() {
            const orderNumberElement = document.querySelector('h3.text-success');
            if (orderNumberElement) {
                orderNumberElement.style.cursor = 'pointer';
                orderNumberElement.title = 'Click para copiar número de pedido';
                orderNumberElement.addEventListener('click', copyOrderNumber);
            }
        });
    </script>
</body>
</html>