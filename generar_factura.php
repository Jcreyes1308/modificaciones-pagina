<?php
// generar_factura.php - Generador de facturas PDF para México
session_start();
require_once 'config/database.php';

// Verificar que el usuario esté logueado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

$order_id = intval($_GET['order_id'] ?? 0);
$download = $_GET['download'] ?? 'view'; // 'view' o 'download'

if (!$order_id) {
    die('ID de pedido no válido');
}

$database = new Database();
$conn = $database->getConnection();

// Obtener datos del pedido con validación de cliente
try {
    $stmt = $conn->prepare("
        SELECT 
            p.*,
            c.nombre as cliente_nombre,
            c.email as cliente_email,
            c.telefono as cliente_telefono,
            de.nombre_destinatario,
            de.telefono_contacto,
            de.calle_numero,
            de.colonia,
            de.ciudad,
            de.estado as estado_direccion,
            de.codigo_postal,
            de.referencias
        FROM pedidos p
        INNER JOIN clientes c ON p.id_cliente = c.id
        LEFT JOIN direcciones_envio de ON p.id_direccion_envio = de.id
        WHERE p.id = ? AND p.id_cliente = ? AND p.activo = 1
    ");
    $stmt->execute([$order_id, $_SESSION['usuario_id']]);
    $pedido = $stmt->fetch();
    
    if (!$pedido) {
        die('Pedido no encontrado o no tienes permisos para acceder a él');
    }
    
    // Obtener items del pedido
    $stmt = $conn->prepare("
        SELECT pd.*, p.clave_producto
        FROM pedido_detalles pd
        LEFT JOIN productos p ON pd.id_producto = p.id
        WHERE pd.id_pedido = ?
        ORDER BY pd.id
    ");
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll();
    
} catch (Exception $e) {
    die('Error al obtener datos del pedido: ' . $e->getMessage());
}

// Datos fiscales de la empresa (TEMPORALES - reemplazar con datos reales)
$empresa = [
    'razon_social' => 'NOVEDADES ASHLEY S.A. DE C.V.',
    'rfc' => 'NAS123456789', // RFC temporal
    'direccion' => 'Calle Principal #123, Col. Centro',
    'ciudad' => 'Ciudad de México, CDMX',
    'codigo_postal' => '01000',
    'telefono' => '558-422-6977',
    'email' => 'noe.cruzb91@gmail.com',
    'regimen_fiscal' => 'Régimen General de Ley Personas Morales',
    'lugar_expedicion' => '01000' // Código postal de expedición
];

// Datos del cliente (TEMPORALES - después agregar RFC real del cliente)
$cliente_fiscal = [
    'rfc' => 'XAXX010101000', // RFC genérico temporal
    'razon_social' => strtoupper($pedido['cliente_nombre']),
    'direccion' => $pedido['calle_numero'] ?? 'Dirección no especificada',
    'ciudad' => ($pedido['ciudad'] ?? 'Ciudad') . ', ' . ($pedido['estado_direccion'] ?? 'Estado'),
    'codigo_postal' => $pedido['codigo_postal'] ?? '00000',
    'uso_cfdi' => 'G03 - Gastos en general'
];

// Datos de la factura
$factura_data = [
    'folio' => str_pad($pedido['id'], 6, '0', STR_PAD_LEFT),
    'serie' => 'A',
    'fecha_emision' => date('Y-m-d\TH:i:s', strtotime($pedido['created_at'])),
    'fecha_vencimiento' => date('Y-m-d', strtotime($pedido['created_at'] . ' +30 days')),
    'moneda' => 'MXN',
    'tipo_cambio' => '1.000000',
    'forma_pago' => '03 - Transferencia electrónica de fondos',
    'metodo_pago' => 'PUE - Pago en una sola exhibición',
    'condiciones_pago' => 'Contado'
];

// Función para generar HTML de la factura
function generarHTMLFactura($empresa, $cliente_fiscal, $factura_data, $pedido, $items) {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Factura <?= $factura_data['serie'] ?>-<?= $factura_data['folio'] ?></title>
        <style>
            @page {
                margin: 20mm;
                size: A4;
            }
            
            body {
                font-family: Arial, sans-serif;
                font-size: 10pt;
                line-height: 1.2;
                color: #333;
                margin: 0;
                padding: 0;
            }
            
            .header {
                border-bottom: 2px solid #667eea;
                padding-bottom: 10px;
                margin-bottom: 15px;
            }
            
            .empresa-info {
                float: left;
                width: 60%;
            }
            
            .factura-info {
                float: right;
                width: 35%;
                text-align: right;
            }
            
            .logo {
                font-size: 24pt;
                font-weight: bold;
                color: #667eea;
                margin-bottom: 5px;
            }
            
            .factura-box {
                border: 2px solid #667eea;
                padding: 10px;
                background: #f8f9fa;
            }
            
            .factura-title {
                font-size: 18pt;
                font-weight: bold;
                color: #667eea;
                margin-bottom: 10px;
            }
            
            .cliente-section, .items-section {
                clear: both;
                margin: 20px 0;
            }
            
            .section-title {
                background: #667eea;
                color: white;
                padding: 8px 10px;
                font-weight: bold;
                margin-bottom: 10px;
            }
            
            .datos-fiscales {
                background: #f8f9fa;
                padding: 10px;
                border: 1px solid #ddd;
            }
            
            .row {
                display: table;
                width: 100%;
                margin-bottom: 5px;
            }
            
            .col-left {
                display: table-cell;
                width: 50%;
                vertical-align: top;
                padding-right: 10px;
            }
            
            .col-right {
                display: table-cell;
                width: 50%;
                vertical-align: top;
                padding-left: 10px;
            }
            
            .items-table {
                width: 100%;
                border-collapse: collapse;
                margin: 10px 0;
            }
            
            .items-table th {
                background: #667eea;
                color: white;
                padding: 8px 5px;
                text-align: center;
                font-weight: bold;
                border: 1px solid #667eea;
            }
            
            .items-table td {
                padding: 6px 5px;
                border: 1px solid #ddd;
                text-align: center;
            }
            
            .items-table td.text-left {
                text-align: left;
            }
            
            .items-table td.text-right {
                text-align: right;
            }
            
            .totales {
                float: right;
                width: 300px;
                margin-top: 20px;
            }
            
            .totales-table {
                width: 100%;
                border-collapse: collapse;
            }
            
            .totales-table td {
                padding: 5px 10px;
                border: 1px solid #ddd;
            }
            
            .totales-table .label {
                background: #f8f9fa;
                font-weight: bold;
                text-align: right;
                width: 60%;
            }
            
            .totales-table .amount {
                text-align: right;
                width: 40%;
            }
            
            .total-final {
                background: #667eea !important;
                color: white !important;
                font-weight: bold;
                font-size: 12pt;
            }
            
            .footer {
                clear: both;
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #ddd;
                font-size: 9pt;
                color: #666;
            }
            
            .qr-section {
                text-align: center;
                margin: 20px 0;
            }
            
            .fiscal-info {
                background: #fff9c4;
                border: 1px solid #f0ad4e;
                padding: 10px;
                margin: 15px 0;
                border-radius: 4px;
            }
            
            .clear {
                clear: both;
            }
            
            @media print {
                .no-print {
                    display: none;
                }
            }
        </style>
    </head>
    <body>
        <!-- Header -->
        <div class="header">
            <div class="empresa-info">
                <div class="logo">🛍️ NOVEDADES ASHLEY</div>
                <div><strong><?= htmlspecialchars($empresa['razon_social']) ?></strong></div>
                <div>RFC: <?= htmlspecialchars($empresa['rfc']) ?></div>
                <div><?= htmlspecialchars($empresa['direccion']) ?></div>
                <div><?= htmlspecialchars($empresa['ciudad']) ?></div>
                <div>CP: <?= htmlspecialchars($empresa['codigo_postal']) ?></div>
                <div>Tel: <?= htmlspecialchars($empresa['telefono']) ?></div>
                <div>Email: <?= htmlspecialchars($empresa['email']) ?></div>
            </div>
            
            <div class="factura-info">
                <div class="factura-box">
                    <div class="factura-title">FACTURA</div>
                    <div><strong>Serie: <?= $factura_data['serie'] ?></strong></div>
                    <div><strong>Folio: <?= $factura_data['folio'] ?></strong></div>
                    <div>Fecha: <?= date('d/m/Y H:i', strtotime($factura_data['fecha_emision'])) ?></div>
                    <div>Lugar de Expedición: <?= $empresa['lugar_expedicion'] ?></div>
                </div>
            </div>
            
            <div class="clear"></div>
        </div>
        
        <!-- Información fiscal -->
        <div class="fiscal-info">
            <strong>Régimen Fiscal:</strong> <?= htmlspecialchars($empresa['regimen_fiscal']) ?><br>
            <strong>Forma de Pago:</strong> <?= $factura_data['forma_pago'] ?><br>
            <strong>Método de Pago:</strong> <?= $factura_data['metodo_pago'] ?>
        </div>
        
        <!-- Datos del cliente -->
        <div class="cliente-section">
            <div class="section-title">DATOS DEL RECEPTOR</div>
            <div class="datos-fiscales">
                <div class="row">
                    <div class="col-left">
                        <strong>Razón Social:</strong><br>
                        <?= htmlspecialchars($cliente_fiscal['razon_social']) ?>
                    </div>
                    <div class="col-right">
                        <strong>RFC:</strong> <?= htmlspecialchars($cliente_fiscal['rfc']) ?><br>
                        <strong>Uso de CFDI:</strong> <?= htmlspecialchars($cliente_fiscal['uso_cfdi']) ?>
                    </div>
                </div>
                <div class="row">
                    <div class="col-left">
                        <strong>Dirección:</strong><br>
                        <?= htmlspecialchars($cliente_fiscal['direccion']) ?><br>
                        <?= htmlspecialchars($cliente_fiscal['ciudad']) ?><br>
                        CP: <?= htmlspecialchars($cliente_fiscal['codigo_postal']) ?>
                    </div>
                    <div class="col-right">
                        <strong>Email:</strong> <?= htmlspecialchars($pedido['cliente_email']) ?><br>
                        <?php if ($pedido['cliente_telefono']): ?>
                            <strong>Teléfono:</strong> <?= htmlspecialchars($pedido['cliente_telefono']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Detalle de productos -->
        <div class="items-section">
            <div class="section-title">CONCEPTOS</div>
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 8%;">Cant.</th>
                        <th style="width: 12%;">Clave</th>
                        <th style="width: 40%;">Descripción</th>
                        <th style="width: 15%;">Precio Unit.</th>
                        <th style="width: 10%;">IVA</th>
                        <th style="width: 15%;">Importe</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php 
                        $iva_item = $item['subtotal'] * 0.16;
                        $total_item = $item['subtotal'] + $iva_item;
                        ?>
                        <tr>
                            <td><?= $item['cantidad'] ?></td>
                            <td><?= htmlspecialchars($item['clave_producto'] ?? 'N/A') ?></td>
                            <td class="text-left">
                                <strong><?= htmlspecialchars($item['nombre_producto']) ?></strong>
                                <?php if ($item['descripcion_producto']): ?>
                                    <br><small><?= htmlspecialchars($item['descripcion_producto']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">$<?= number_format($item['precio_unitario'], 2) ?></td>
                            <td class="text-right">$<?= number_format($iva_item, 2) ?></td>
                            <td class="text-right">$<?= number_format($item['subtotal'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Totales -->
        <div class="totales">
            <table class="totales-table">
                <tr>
                    <td class="label">Subtotal:</td>
                    <td class="amount">$<?= number_format($pedido['subtotal'], 2) ?></td>
                </tr>
                <?php if ($pedido['descuentos'] > 0): ?>
                    <tr>
                        <td class="label">Descuentos:</td>
                        <td class="amount">-$<?= number_format($pedido['descuentos'], 2) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($pedido['costo_envio'] > 0): ?>
                    <tr>
                        <td class="label">Envío:</td>
                        <td class="amount">$<?= number_format($pedido['costo_envio'], 2) ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td class="label">IVA (16%):</td>
                    <td class="amount">$<?= number_format($pedido['impuestos'], 2) ?></td>
                </tr>
                <tr class="total-final">
                    <td class="label">TOTAL:</td>
                    <td class="amount">$<?= number_format($pedido['total'], 2) ?></td>
                </tr>
            </table>
        </div>
        
        <div class="clear"></div>
        
        <!-- Código QR simulado -->
        <div class="qr-section">
            <div style="border: 2px solid #333; width: 100px; height: 100px; margin: 0 auto; display: flex; align-items: center; justify-content: center; background: #f8f9fa;">
                <div style="font-size: 8pt; text-align: center;">
                    QR CODE<br>
                    SAT<br>
                    CFDI
                </div>
            </div>
            <div style="margin-top: 10px; font-size: 9pt;">
                <strong>UUID:</strong> <?= strtoupper(uniqid() . '-' . uniqid()) ?><br>
                <strong>Sello Digital SAT:</strong> (Pendiente de implementación)
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <div style="text-align: center;">
                <p><strong>NOVEDADES ASHLEY - "Descubre lo nuevo, siente la diferencia"</strong></p>
                <p>Esta es una factura temporal para demostración. Los datos fiscales y el UUID son simulados.</p>
                <p>Para consultas: <?= $empresa['email'] ?> | Tel: <?= $empresa['telefono'] ?></p>
            </div>
        </div>
        
        <!-- Botones de acción (solo para vista web) -->
        <div class="no-print" style="position: fixed; top: 20px; right: 20px; background: white; padding: 15px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
            <button onclick="window.print()" style="background: #667eea; color: white; border: none; padding: 10px 20px; border-radius: 5px; margin-right: 10px; cursor: pointer;">
                🖨️ Imprimir
            </button>
            <button onclick="descargarPDF()" style="background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 5px; margin-right: 10px; cursor: pointer;">
                📄 Descargar PDF
            </button>
            <button onclick="cerrarFactura()" style="background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">
                ❌ Cerrar
            </button>
        </div>
        
        <script>
            function descargarPDF() {
                // Simular descarga de PDF
                alert('Función de descarga PDF en desarrollo.\n\nEn producción aquí se generaría el PDF real con una librería como mPDF o TCPDF.');
            }
            
            function cerrarFactura() {
                if (window.opener) {
                    window.close();
                } else {
                    window.history.back();
                }
            }
            
            // Auto-focus para imprimir si se requiere download
            <?php if ($download === 'print'): ?>
                window.onload = function() {
                    setTimeout(function() {
                        window.print();
                    }, 500);
                };
            <?php endif; ?>
        </script>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

// Generar y mostrar la factura
$html_factura = generarHTMLFactura($empresa, $cliente_fiscal, $factura_data, $pedido, $items);

// Headers para mostrar como HTML
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');

echo $html_factura;
?>