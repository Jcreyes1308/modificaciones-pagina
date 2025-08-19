<?php
// test_emails.php - Script para probar el sistema de notificaciones por email
// ⚠️ SOLO PARA DESARROLLO - NO SUBIR A PRODUCCIÓN

// Configuración de seguridad básica
$allowed_ips = ['127.0.0.1', '::1', 'localhost']; // Solo localhost
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowed_ips) && 
    !in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'])) {
    die('❌ Acceso denegado - Solo disponible en desarrollo local');
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/verification.php';

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🧪 Test Sistema de Emails - Novedades Ashley</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 0; 
            padding: 20px; 
            background: #f4f4f4; 
        }
        .container { 
            max-width: 800px; 
            margin: 0 auto; 
            background: white; 
            padding: 30px; 
            border-radius: 15px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.1); 
        }
        .header { 
            text-align: center; 
            margin-bottom: 30px; 
            padding-bottom: 20px; 
            border-bottom: 3px solid #667eea; 
        }
        .test-section { 
            background: #f8f9fa; 
            padding: 20px; 
            margin: 20px 0; 
            border-radius: 10px; 
            border-left: 4px solid #667eea; 
        }
        .btn { 
            background: #667eea; 
            color: white; 
            padding: 12px 24px; 
            border: none; 
            border-radius: 8px; 
            cursor: pointer; 
            font-size: 16px; 
            margin: 5px; 
            transition: all 0.3s; 
        }
        .btn:hover { 
            background: #5a6fd8; 
            transform: translateY(-2px); 
        }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-warning:hover { background: #e0a800; }
        .btn-danger { background: #dc3545; }
        .btn-danger:hover { background: #c82333; }
        .result { 
            margin: 15px 0; 
            padding: 15px; 
            border-radius: 8px; 
            font-family: monospace; 
        }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; }
        .form-group { margin: 15px 0; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group select, .form-group textarea { 
            width: 100%; 
            padding: 10px; 
            border: 1px solid #ddd; 
            border-radius: 5px; 
            font-size: 14px; 
        }
        .order-info { 
            background: #e8f5e8; 
            padding: 15px; 
            border-radius: 8px; 
            margin: 15px 0; 
            border: 1px solid #28a745; 
        }
        .warning-box { 
            background: #fff3cd; 
            border: 1px solid #ffeaa7; 
            color: #856404; 
            padding: 15px; 
            border-radius: 8px; 
            margin: 20px 0; 
        }
        .instructions { 
            background: #d1ecf1; 
            border: 1px solid #bee5eb; 
            color: #0c5460; 
            padding: 20px; 
            border-radius: 8px; 
            margin: 20px 0; 
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🧪 Test Sistema de Emails</h1>
            <p><strong>Novedades Ashley - Panel de Pruebas</strong></p>
            <p style="color: #666;">Herramienta para probar emails automáticos</p>
        </div>

        <div class="warning-box">
            <strong>⚠️ IMPORTANTE:</strong><br>
            • Este panel solo funciona en desarrollo local<br>
            • Los emails se enviarán realmente a las direcciones de los clientes<br>
            • Úsalo solo con pedidos de prueba<br>
            • Elimina este archivo antes de subir a producción
        </div>

        <?php
        $database = new Database();
        $conn = $database->getConnection();
        $verification_service = new VerificationService($conn);

        // Obtener pedidos disponibles para testing
        $stmt = $conn->prepare("
            SELECT p.id, p.numero_pedido, p.estado, p.total, p.created_at,
                   c.nombre, c.email
            FROM pedidos p
            INNER JOIN clientes c ON p.id_cliente = c.id
            WHERE p.activo = 1
            ORDER BY p.created_at DESC
            LIMIT 10
        ");
        $stmt->execute();
        $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <div class="instructions">
            <h3>📋 Instrucciones:</h3>
            <ol>
                <li><strong>Verifica la configuración:</strong> Asegúrate de que el Gmail SMTP esté configurado correctamente</li>
                <li><strong>Selecciona un pedido:</strong> Usa un pedido de prueba con tu propio email</li>
                <li><strong>Prueba cada tipo de email:</strong> Confirmación, factura y cambio de estado</li>
                <li><strong>Revisa tu bandeja:</strong> Los emails pueden llegar a la carpeta de spam</li>
                <li><strong>Verifica logs:</strong> Revisa la carpeta /logs/ para debugging</li>
            </ol>
        </div>

        <!-- SECCIÓN 1: INFORMACIÓN DEL SISTEMA -->
        <div class="test-section">
            <h3>📊 Estado del Sistema</h3>
            
            <?php
            // Verificar configuración
            $smtp_configured = !empty($verification_service);
            $total_pedidos = count($pedidos);
            $pedidos_confirmados = count(array_filter($pedidos, function($p) { return $p['estado'] === 'confirmado'; }));
            ?>
            
            <div class="result info">
                <strong>📧 SMTP Configurado:</strong> <?= $smtp_configured ? '✅ Sí' : '❌ No' ?><br>
                <strong>📦 Total de pedidos:</strong> <?= $total_pedidos ?><br>
                <strong>✅ Pedidos confirmados:</strong> <?= $pedidos_confirmados ?><br>
                <strong>📁 Directorio de logs:</strong> <?= is_dir(__DIR__ . '/logs') ? '✅ Existe' : '❌ No existe' ?><br>
                <strong>📝 PHPMailer:</strong> <?= class_exists('PHPMailer\PHPMailer\PHPMailer') ? '✅ Disponible' : '❌ No disponible' ?>
            </div>
        </div>

        <!-- SECCIÓN 2: LISTA DE PEDIDOS -->
        <div class="test-section">
            <h3>📦 Pedidos Disponibles para Testing</h3>
            
            <?php if (empty($pedidos)): ?>
                <div class="result error">
                    ❌ No hay pedidos en la base de datos para testear.<br>
                    Crea un pedido de prueba primero.
                </div>
            <?php else: ?>
                <?php foreach ($pedidos as $pedido): ?>
                    <div class="order-info">
                        <strong>Pedido:</strong> <?= htmlspecialchars($pedido['numero_pedido']) ?> | 
                        <strong>Cliente:</strong> <?= htmlspecialchars($pedido['nombre']) ?> | 
                        <strong>Email:</strong> <?= htmlspecialchars($pedido['email']) ?><br>
                        <strong>Estado:</strong> <?= ucfirst($pedido['estado']) ?> | 
                        <strong>Total:</strong> $<?= number_format($pedido['total'], 2) ?> | 
                        <strong>Fecha:</strong> <?= date('d/m/Y H:i', strtotime($pedido['created_at'])) ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- SECCIÓN 3: TESTS AUTOMÁTICOS -->
        <div class="test-section">
            <h3>🤖 Tests Automáticos</h3>
            <p>Ejecuta todos los tipos de email con el último pedido confirmado:</p>
            
            <button class="btn btn-success" onclick="runAutoTests()">
                🚀 Ejecutar Todos los Tests
            </button>
            
            <div id="auto-test-results"></div>
        </div>

        <!-- SECCIÓN 4: TESTS MANUALES -->
        <div class="test-section">
            <h3>✋ Tests Manuales</h3>
            
            <form id="manual-test-form">
                <div class="form-group">
                    <label>📦 Seleccionar Pedido:</label>
                    <select name="order_id" required>
                        <option value="">-- Selecciona un pedido --</option>
                        <?php foreach ($pedidos as $pedido): ?>
                            <option value="<?= $pedido['id'] ?>">
                                <?= htmlspecialchars($pedido['numero_pedido']) ?> - 
                                <?= htmlspecialchars($pedido['nombre']) ?> - 
                                $<?= number_format($pedido['total'], 2) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>📧 Tipo de Email:</label>
                    <select name="email_type" required>
                        <option value="">-- Selecciona tipo --</option>
                        <option value="payment_confirmation">✅ Confirmación de Pago</option>
                        <option value="invoice">📄 Factura PDF</option>
                        <option value="status_update">📦 Cambio de Estado</option>
                    </select>
                </div>

                <div id="status-fields" style="display: none;">
                    <div class="form-group">
                        <label>Estado Anterior:</label>
                        <select name="old_status">
                            <option value="pendiente">Pendiente</option>
                            <option value="confirmado">Confirmado</option>
                            <option value="procesando">Procesando</option>
                            <option value="enviado">Enviado</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Estado Nuevo:</label>
                        <select name="new_status">
                            <option value="confirmado">Confirmado</option>
                            <option value="procesando">Procesando</option>
                            <option value="enviado">Enviado</option>
                            <option value="entregado">Entregado</option>
                            <option value="cancelado">Cancelado</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Comentarios (opcional):</label>
                        <textarea name="comments" placeholder="Comentarios adicionales sobre el cambio de estado..."></textarea>
                    </div>
                </div>

                <button type="submit" class="btn">📤 Enviar Email de Prueba</button>
            </form>

            <div id="manual-test-results"></div>
        </div>

        <!-- SECCIÓN 5: LOGS EN VIVO -->
        <div class="test-section">
            <h3>📋 Logs del Sistema</h3>
            <button class="btn btn-warning" onclick="loadLogs()">🔄 Cargar Logs Recientes</button>
            <div id="logs-container"></div>
        </div>
    </div>

    <script>
        // Mostrar/ocultar campos según el tipo de email
        document.querySelector('select[name="email_type"]').addEventListener('change', function() {
            const statusFields = document.getElementById('status-fields');
            if (this.value === 'status_update') {
                statusFields.style.display = 'block';
            } else {
                statusFields.style.display = 'none';
            }
        });

        // Test automático
        async function runAutoTests() {
            const resultsDiv = document.getElementById('auto-test-results');
            resultsDiv.innerHTML = '<div class="result info">🔄 Ejecutando tests automáticos...</div>';

            try {
                const response = await fetch('test_emails.php?run_auto_tests=1');
                const data = await response.json();
                
                let html = '<div class="result ' + (data.success ? 'success' : 'error') + '">';
                html += '<strong>Resultados:</strong><br>';
                
                if (data.success) {
                    html += `✅ Email de confirmación: ${data.results.confirmation_email ? 'Enviado' : 'Falló'}<br>`;
                    html += `📄 Email de factura: ${data.results.invoice_email ? 'Enviado' : 'Falló'}<br>`;
                    html += `📦 Email de estado: ${data.results.status_update_email ? 'Enviado' : 'Falló'}<br>`;
                    html += `🎯 Pedido usado: ${data.order_id}`;
                } else {
                    html += `❌ Error: ${data.message}`;
                }
                
                html += '</div>';
                resultsDiv.innerHTML = html;
                
            } catch (error) {
                resultsDiv.innerHTML = '<div class="result error">❌ Error: ' + error.message + '</div>';
            }
        }

        // Test manual
        document.getElementById('manual-test-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const resultsDiv = document.getElementById('manual-test-results');
            resultsDiv.innerHTML = '<div class="result info">🔄 Enviando email...</div>';

            const formData = new FormData(this);
            formData.append('run_manual_test', '1');

            try {
                const response = await fetch('test_emails.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                const resultClass = data.success ? 'success' : 'error';
                const icon = data.success ? '✅' : '❌';
                
                resultsDiv.innerHTML = `<div class="result ${resultClass}">${icon} ${data.message}</div>`;
                
            } catch (error) {
                resultsDiv.innerHTML = '<div class="result error">❌ Error: ' + error.message + '</div>';
            }
        });

        // Cargar logs
        async function loadLogs() {
            const logsDiv = document.getElementById('logs-container');
            logsDiv.innerHTML = '<div class="result info">🔄 Cargando logs...</div>';

            try {
                const response = await fetch('test_emails.php?load_logs=1');
                const data = await response.json();
                
                if (data.success) {
                    let html = '<div class="result success">';
                    html += '<strong>📋 Logs Recientes:</strong><br><br>';
                    
                    if (data.logs.length === 0) {
                        html += 'No hay logs disponibles.';
                    } else {
                        data.logs.forEach(log => {
                            html += `<strong>${log.timestamp}:</strong> ${log.message}<br>`;
                        });
                    }
                    
                    html += '</div>';
                    logsDiv.innerHTML = html;
                } else {
                    logsDiv.innerHTML = '<div class="result error">❌ Error cargando logs: ' + data.message + '</div>';
                }
                
            } catch (error) {
                logsDiv.innerHTML = '<div class="result error">❌ Error: ' + error.message + '</div>';
            }
        }
    </script>
</body>
</html>

<?php
// ===================================
// PROCESAMIENTO DE REQUESTS AJAX
// ===================================

// Test automático
if (isset($_GET['run_auto_tests'])) {
    header('Content-Type: application/json');
    
    try {
        // Buscar último pedido confirmado
        $stmt = $conn->prepare("
            SELECT id FROM pedidos 
            WHERE estado = 'confirmado' 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->fetch();
        
        if (!$result) {
            echo json_encode([
                'success' => false,
                'message' => 'No hay pedidos confirmados para testear'
            ]);
            exit;
        }
        
        $order_id = $result['id'];
        
        // Ejecutar tests
        $confirmation_test = $verification_service->sendPaymentConfirmationEmail($order_id);
        sleep(1); // Pausa entre emails
        
        $invoice_test = $verification_service->sendInvoiceEmail($order_id);
        sleep(1);
        
        $status_test = $verification_service->sendOrderStatusUpdate(
            $order_id, 
            'confirmado', 
            'procesando', 
            'Test automático del sistema de emails'
        );
        
        echo json_encode([
            'success' => true,
            'results' => [
                'confirmation_email' => $confirmation_test,
                'invoice_email' => $invoice_test,
                'status_update_email' => $status_test
            ],
            'order_id' => $order_id,
            'message' => 'Tests automáticos completados'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error en tests automáticos: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Test manual
if (isset($_POST['run_manual_test'])) {
    header('Content-Type: application/json');
    
    try {
        $order_id = (int)$_POST['order_id'];
        $email_type = $_POST['email_type'];
        
        if (!$order_id || !$email_type) {
            echo json_encode([
                'success' => false,
                'message' => 'Datos incompletos'
            ]);
            exit;
        }
        
        $result = false;
        $message = '';
        
        switch ($email_type) {
            case 'payment_confirmation':
                $result = $verification_service->sendPaymentConfirmationEmail($order_id);
                $message = $result ? 
                    'Email de confirmación de pago enviado correctamente' : 
                    'Error enviando email de confirmación';
                break;
                
            case 'invoice':
                $result = $verification_service->sendInvoiceEmail($order_id);
                $message = $result ? 
                    'Factura enviada por email correctamente' : 
                    'Error enviando factura por email';
                break;
                
            case 'status_update':
                $old_status = $_POST['old_status'] ?? 'confirmado';
                $new_status = $_POST['new_status'] ?? 'procesando';
                $comments = $_POST['comments'] ?? 'Test manual desde panel de pruebas';
                
                $result = $verification_service->sendOrderStatusUpdate(
                    $order_id, 
                    $old_status, 
                    $new_status, 
                    $comments
                );
                $message = $result ? 
                    "Email de cambio de estado enviado: {$old_status} → {$new_status}" : 
                    'Error enviando email de cambio de estado';
                break;
                
            default:
                echo json_encode([
                    'success' => false,
                    'message' => 'Tipo de email no válido'
                ]);
                exit;
        }
        
        echo json_encode([
            'success' => $result,
            'message' => $message
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error en test manual: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Cargar logs
if (isset($_GET['load_logs'])) {
    header('Content-Type: application/json');
    
    try {
        $logs = [];
        $log_file = __DIR__ . '/logs/stripe_activity.log';
        
        if (file_exists($log_file)) {
            $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $recent_lines = array_slice($lines, -20); // Últimas 20 líneas
            
            foreach ($recent_lines as $line) {
                if (preg_match('/^\[(.*?)\]/', $line, $matches)) {
                    $logs[] = [
                        'timestamp' => $matches[1],
                        'message' => $line
                    ];
                } else {
                    $logs[] = [
                        'timestamp' => 'Unknown',
                        'message' => $line
                    ];
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'logs' => array_reverse($logs) // Más recientes primero
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error cargando logs: ' . $e->getMessage()
        ]);
    }
    exit;
}
?>