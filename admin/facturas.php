<?php
// admin/facturas.php - Gestión de facturas desde panel admin
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

require_once '../config/database.php';
require_once '../config/config_fiscal.php';

$database = new Database();
$conn = $database->getConnection();

$success = '';
$error = '';

// Obtener filtros
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';
$cliente_filter = $_GET['cliente'] ?? '';
$search = trim($_GET['search'] ?? '');

// Construir consulta
$where_conditions = ['p.activo = 1'];
$params = [];

if ($fecha_desde) {
    $where_conditions[] = 'DATE(p.created_at) >= ?';
    $params[] = $fecha_desde;
}

if ($fecha_hasta) {
    $where_conditions[] = 'DATE(p.created_at) <= ?';
    $params[] = $fecha_hasta;
}

if ($cliente_filter) {
    $where_conditions[] = 'p.id_cliente = ?';
    $params[] = $cliente_filter;
}

if ($search) {
    $where_conditions[] = '(p.numero_pedido LIKE ? OR c.nombre LIKE ? OR c.email LIKE ?)';
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_sql = implode(' AND ', $where_conditions);

// Obtener pedidos facturables
try {
    $stmt = $conn->prepare("
        SELECT p.*, c.nombre as cliente_nombre, c.email as cliente_email,
               de.nombre_destinatario, de.ciudad, de.estado as estado_direccion
        FROM pedidos p
        INNER JOIN clientes c ON p.id_cliente = c.id
        LEFT JOIN direcciones_envio de ON p.id_direccion_envio = de.id
        WHERE $where_sql
        ORDER BY p.created_at DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    $pedidos = $stmt->fetchAll();
} catch (Exception $e) {
    $pedidos = [];
    $error = 'Error al cargar pedidos: ' . $e->getMessage();
}

// Obtener estadísticas de facturación
$stats = [];
try {
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_pedidos,
            SUM(CASE WHEN estado = 'entregado' THEN 1 ELSE 0 END) as pedidos_entregados,
            SUM(CASE WHEN estado = 'entregado' THEN total ELSE 0 END) as ingresos_facturables,
            SUM(CASE WHEN estado = 'entregado' THEN impuestos ELSE 0 END) as iva_cobrado
        FROM pedidos 
        WHERE activo = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stats = $stmt->fetch();
} catch (Exception $e) {
    $stats = ['total_pedidos' => 0, 'pedidos_entregados' => 0, 'ingresos_facturables' => 0, 'iva_cobrado' => 0];
}

// Obtener clientes para filtros
try {
    $stmt = $conn->query("
        SELECT DISTINCT c.id, c.nombre 
        FROM clientes c 
        INNER JOIN pedidos p ON c.id = p.id_cliente 
        WHERE c.activo = 1 AND p.activo = 1 
        ORDER BY c.nombre
    ");
    $clientes = $stmt->fetchAll();
} catch (Exception $e) {
    $clientes = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Facturas - Admin</title>
    
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
        
        .stats-row {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .factura-card {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .factura-card:hover {
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
        
        .config-fiscal {
            background: #fff9c4;
            border: 1px solid #f0ad4e;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
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
            <a class="nav-link" href="clientes.php">
                <i class="fas fa-users me-2"></i> Clientes
            </a>
            <a class="nav-link active" href="facturas.php">
                <i class="fas fa-file-invoice me-2"></i> Facturas
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
                <i class="fas fa-file-invoice text-primary"></i> 
                Gestión de Facturas
            </h2>
            
            <div class="d-flex gap-2">
                <button class="btn btn-admin" onclick="configurarFacturacion()">
                    <i class="fas fa-cog"></i> Configurar
                </button>
                <button class="btn btn-success" onclick="reporteFacturacion()">
                    <i class="fas fa-chart-line"></i> Reporte
                </button>
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
        
        <!-- Configuración fiscal actual -->
        <div class="config-fiscal">
            <h5><i class="fas fa-building"></i> Configuración Fiscal Actual</h5>
            <div class="row">
                <div class="col-md-6">
                    <strong>Razón Social:</strong> <?= htmlspecialchars($GLOBALS['empresa_fiscal']['razon_social']) ?><br>
                    <strong>RFC:</strong> <?= htmlspecialchars($GLOBALS['empresa_fiscal']['rfc']) ?><br>
                    <strong>Régimen:</strong> <?= htmlspecialchars($GLOBALS['empresa_fiscal']['regimen_fiscal']) ?>
                </div>
                <div class="col-md-6">
                    <strong>Serie de Facturación:</strong> <?= $GLOBALS['empresa_fiscal']['serie_factura'] ?><br>
                    <strong>Lugar de Expedición:</strong> <?= $GLOBALS['empresa_fiscal']['lugar_expedicion'] ?><br>
                    <strong>Estado:</strong> 
                    <span class="badge bg-warning">
                        <i class="fas fa-exclamation-triangle"></i> Configuración Temporal
                    </span>
                </div>
            </div>
            <div class="mt-3">
                <small class="text-muted">
                    <i class="fas fa-info-circle"></i> 
                    Esta es una configuración temporal. Actualizar con datos fiscales reales antes de producción.
                </small>
            </div>
        </div>
        
        <!-- Estadísticas -->
        <div class="stats-row">
            <div class="row text-center">
                <div class="col-md-3">
                    <h3><?= number_format($stats['total_pedidos']) ?></h3>
                    <p>📋 Total Pedidos (30 días)</p>
                </div>
                <div class="col-md-3">
                    <h3><?= number_format($stats['pedidos_entregados']) ?></h3>
                    <p>✅ Pedidos Entregados</p>
                </div>
                <div class="col-md-3">
                    <h3>$<?= number_format($stats['ingresos_facturables'], 0) ?></h3>
                    <p>💰 Ingresos Facturables</p>
                </div>
                <div class="col-md-3">
                    <h3>$<?= number_format($stats['iva_cobrado'], 0) ?></h3>
                    <p>🏛️ IVA Cobrado</p>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="admin-card">
            <h5 class="mb-3">
                <i class="fas fa-filter"></i> Filtros de Facturas
            </h5>
            
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Buscar:</label>
                    <input type="text" class="form-control" name="search" 
                           value="<?= htmlspecialchars($search) ?>" placeholder="Número, cliente...">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Cliente:</label>
                    <select class="form-select" name="cliente">
                        <option value="">Todos los clientes</option>
                        <?php foreach ($clientes as $cliente): ?>
                            <option value="<?= $cliente['id'] ?>" <?= $cliente_filter == $cliente['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cliente['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
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
            
            <?php if ($search || $cliente_filter || $fecha_desde || $fecha_hasta): ?>
                <div class="mt-3">
                    <a href="facturas.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-times"></i> Limpiar filtros
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Lista de facturas -->
        <div class="admin-card">
            <h5 class="mb-4">
                <i class="fas fa-list"></i> Pedidos Facturables
                <span class="badge bg-secondary"><?= count($pedidos) ?></span>
            </h5>
            
            <?php if (count($pedidos) > 0): ?>
                <?php foreach ($pedidos as $pedido): ?>
                    <div class="factura-card">
                        <div class="row align-items-center">
                            <div class="col-md-2">
                                <strong><?= htmlspecialchars($pedido['numero_pedido']) ?></strong><br>
                                <small class="text-muted">
                                    <?= date('d/m/Y', strtotime($pedido['created_at'])) ?>
                                </small>
                            </div>
                            
                            <div class="col-md-3">
                                <strong><?= htmlspecialchars($pedido['cliente_nombre']) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($pedido['cliente_email']) ?></small>
                            </div>
                            
                            <div class="col-md-2 text-center">
                                <?php
                                $estado_colors = [
                                    'pendiente' => 'warning',
                                    'confirmado' => 'info',
                                    'procesando' => 'primary',
                                    'enviado' => 'secondary',
                                    'en_transito' => 'dark',
                                    'entregado' => 'success',
                                    'cancelado' => 'danger',
                                    'devuelto' => 'warning'
                                ];
                                $color = $estado_colors[$pedido['estado']] ?? 'secondary';
                                ?>
                                <span class="estado-badge bg-<?= $color ?>">
                                    <?= ucfirst($pedido['estado']) ?>
                                </span>
                            </div>
                            
                            <div class="col-md-2 text-center">
                                <h6 class="mb-0">$<?= number_format($pedido['total'], 2) ?></h6>
                                <small class="text-muted">
                                    IVA: $<?= number_format($pedido['impuestos'], 2) ?>
                                </small>
                            </div>
                            
                            <div class="col-md-3 text-end">
                                <div class="btn-group" role="group">
                                    <!-- Ver factura -->
                                    <button type="button" class="btn btn-outline-primary btn-sm" 
                                            onclick="verFactura(<?= $pedido['id'] ?>)" 
                                            title="Ver factura">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    
                                    <!-- Descargar factura -->
                                    <button type="button" class="btn btn-outline-success btn-sm" 
                                            onclick="descargarFactura(<?= $pedido['id'] ?>)" 
                                            title="Descargar factura">
                                        <i class="fas fa-download"></i>
                                    </button>
                                    
                                    <!-- Enviar por email -->
                                    <button type="button" class="btn btn-outline-info btn-sm" 
                                            onclick="enviarFacturaEmail(<?= $pedido['id'] ?>, '<?= htmlspecialchars($pedido['cliente_email']) ?>')" 
                                            title="Enviar por email">
                                        <i class="fas fa-envelope"></i>
                                    </button>
                                    
                                    <!-- Imprimir -->
                                    <button type="button" class="btn btn-outline-secondary btn-sm" 
                                            onclick="imprimirFactura(<?= $pedido['id'] ?>)" 
                                            title="Imprimir">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    
                                    <?php if ($pedido['estado'] === 'entregado'): ?>
                                        <!-- Reenviar factura -->
                                        <button type="button" class="btn btn-outline-warning btn-sm" 
                                                onclick="reenviarFactura(<?= $pedido['id'] ?>)" 
                                                title="Reenviar factura">
                                            <i class="fas fa-redo"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mt-2">
                                    <small class="text-muted">
                                        Folio: <?= generarFolioFactura($pedido['id']) ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Información adicional -->
                        <div class="row mt-2">
                            <div class="col-md-6">
                                <small class="text-muted">
                                    <i class="fas fa-map-marker-alt"></i> 
                                    <?= htmlspecialchars($pedido['ciudad'] ?? 'N/A') ?>, <?= htmlspecialchars($pedido['estado_direccion'] ?? 'N/A') ?>
                                </small>
                            </div>
                            <div class="col-md-6 text-end">
                                <small class="text-muted">
                                    Subtotal: $<?= number_format($pedido['subtotal'], 2) ?> | 
                                    Envío: $<?= number_format($pedido['costo_envio'], 2) ?>
                                    <?php if ($pedido['descuentos'] > 0): ?>
                                        | Desc: -$<?= number_format($pedido['descuentos'], 2) ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="mt-3 text-muted">
                    Mostrando <?= count($pedidos) ?> pedido(s) facturable(s)
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-file-invoice fa-3x text-muted mb-3"></i>
                    <h5>No hay pedidos facturables</h5>
                    <p class="text-muted">
                        <?php if ($search || $cliente_filter || $fecha_desde || $fecha_hasta): ?>
                            No hay pedidos que coincidan con los filtros seleccionados.
                        <?php else: ?>
                            Aún no hay pedidos para facturar.
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Información importante -->
        <div class="admin-card">
            <h5 class="text-warning">
                <i class="fas fa-exclamation-triangle"></i> Información Importante
            </h5>
            <div class="alert alert-warning">
                <h6>Sistema de Facturación en Desarrollo</h6>
                <ul class="mb-0">
                    <li><strong>Estado actual:</strong> Facturas temporales para demostración</li>
                    <li><strong>RFC:</strong> Datos fiscales temporales (<?= $GLOBALS['empresa_fiscal']['rfc'] ?>)</li>
                    <li><strong>UUID:</strong> Generación local temporal (no válido SAT)</li>
                    <li><strong>Timbrado:</strong> No implementado aún (requerido para producción)</li>
                    <li><strong>PAC:</strong> Pendiente integración con Proveedor Autorizado de Certificación</li>
                </ul>
            </div>
            
            <h6 class="mt-3">Pasos para Producción:</h6>
            <ol>
                <li>Actualizar RFC real en <code>config/config_fiscal.php</code></li>
                <li>Configurar certificados SAT (.cer y .key)</li>
                <li>Contratar y configurar PAC para timbrado</li>
                <li>Implementar validaciones SAT completas</li>
                <li>Configurar respaldos de facturas</li>
                <li>Pruebas con ambiente de certificación SAT</li>
            </ol>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Función para ver factura
        function verFactura(idPedido) {
            window.open(`../generar_factura.php?order_id=${idPedido}&download=view`, 
                        '_blank', 
                        'width=900,height=1000,scrollbars=yes,resizable=yes,toolbar=yes');
        }
        
        // Función para descargar factura
        function descargarFactura(idPedido) {
            // En futuro aquí se implementaría descarga real de PDF
            alert(`Descargando factura del pedido #${idPedido}\n\n` +
                  'En producción esta función:\n' +
                  '• Generaría PDF real con librería como mPDF\n' +
                  '• Incluiría UUID válido del SAT\n' +
                  '• Tendría sello digital oficial\n' +
                  '• Cumpliría normativas fiscales\n\n' +
                  'Estado actual: Demostración');
            
            // Por ahora abrir en nueva ventana
            verFactura(idPedido);
        }
        
        // Función para enviar factura por email
        function enviarFacturaEmail(idPedido, emailCliente) {
            if (confirm(`¿Enviar factura del pedido #${idPedido} a ${emailCliente}?`)) {
                alert(`Enviando factura por email...\n\n` +
                      'Esta función implementaría:\n' +
                      '• Generación automática de PDF\n' +
                      '• Envío por email con template profesional\n' +
                      '• Registro de envío en base de datos\n' +
                      '• Confirmación de entrega\n\n' +
                      'Estado: Por implementar');
            }
        }
        
        // Función para imprimir factura
        function imprimirFactura(idPedido) {
            window.open(`../generar_factura.php?order_id=${idPedido}&download=print`, 
                        '_blank', 
                        'width=800,height=900,scrollbars=yes,resizable=yes');
        }
        
        // Función para reenviar factura
        function reenviarFactura(idPedido) {
            if (confirm(`¿Reenviar factura del pedido #${idPedido}?`)) {
                alert('Función de reenvío implementaría:\n' +
                      '• Regeneración de factura actualizada\n' +
                      '• Envío automático por email y/o SMS\n' +
                      '• Registro de reenvío en historial\n' +
                      '• Notificación al cliente\n\n' +
                      'Estado: Por implementar');
            }
        }
        
        // Función para configurar facturación
        function configurarFacturacion() {
            alert('Panel de Configuración Fiscal implementaría:\n\n' +
                  '• Actualización de datos fiscales\n' +
                  '• Configuración de certificados SAT\n' +
                  '• Configuración de PAC\n' +
                  '• Gestión de series y folios\n' +
                  '• Configuración de templates\n' +
                  '• Pruebas de conexión SAT\n\n' +
                  'Estado: Por implementar');
        }
        
        // Función para reporte de facturación
        function reporteFacturacion() {
            alert('Reporte de Facturación implementaría:\n\n' +
                  '• Reporte mensual de facturas emitidas\n' +
                  '• Análisis de IVA cobrado vs declarado\n' +
                  '• Estadísticas por cliente y producto\n' +
                  '• Exportación a Excel/PDF\n' +
                  '• Gráficos de facturación\n' +
                  '• Cumplimiento fiscal\n\n' +
                  'Estado: Por implementar');
        }
        
        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            // Animar cards
            const cards = document.querySelectorAll('.admin-card, .factura-card');
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
    </script>
</body>
</html>