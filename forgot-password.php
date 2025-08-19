<?php
// forgot-password.php - Página de recuperación de contraseña
session_start();

// Si ya está logueado, redirigir al inicio
if (isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit();
}

require_once 'config/database.php';
$database = new Database();
$conn = $database->getConnection();

$error = '';
$success = '';
$step = 1; // 1 = seleccionar método, 2 = código enviado

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'send_code') {
        $method = $_POST['method'] ?? '';
        $contact = trim($_POST['contact'] ?? '');
        
        if (empty($contact)) {
            $error = 'Por favor ingresa tu email o teléfono';
        } else {
            // Buscar usuario por email o teléfono
            $user = null;
            
            if ($method === 'email') {
                if (!filter_var($contact, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Email no válido';
                } else {
                    $stmt = $conn->prepare("SELECT id, nombre, email, telefono, activo FROM clientes WHERE email = ?");
                    $stmt->execute([$contact]);
                    $user = $stmt->fetch();
                }
            } else {
                // Limpiar teléfono
                $clean_phone = preg_replace('/[^0-9]/', '', $contact);
                if (strlen($clean_phone) < 10) {
                    $error = 'Número de teléfono inválido';
                } else {
                    $stmt = $conn->prepare("SELECT id, nombre, email, telefono, activo FROM clientes WHERE telefono = ? OR telefono = ?");
                    $stmt->execute([$contact, $clean_phone]);
                    $user = $stmt->fetch();
                }
            }
            
            if (!$user) {
                $error = 'No encontramos una cuenta con esos datos';
            } elseif (!$user['activo']) {
                $error = 'Tu cuenta está desactivada. Contacta al administrador';
            } else {
                // Enviar código usando la API
                $api_data = [
                    'action' => 'send_reset_code',
                    'method' => $method,
                    'user_id' => $user['id'],
                    'contact' => $contact
                ];
                
                // Llamada a API CORREGIDA
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/tienda-multicategoria/api/password-reset.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($api_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

// DEBUG temporal - puedes quitar estas líneas después
echo "<!-- DEBUG: HTTP Code: " . $http_code . " -->";
echo "<!-- DEBUG: Response: " . htmlspecialchars($response) . " -->";

curl_close($ch);
                
                if ($http_code === 200 && $response) {
    $result = json_decode($response, true);
    
    if ($result && $result['success']) {
        $success = $result['message'];
        
        // DEBUG: Mostrar código por ahora - QUITAR EN PRODUCCIÓN
        if (isset($result['data']['debug_code'])) {
            $success .= " (Código de prueba: " . $result['data']['debug_code'] . ")";
        }
        
        $step = 2;
        
        // Guardar datos en sesión para el siguiente paso
        $_SESSION['reset_user_id'] = $user['id'];
        $_SESSION['reset_method'] = $method;
        $_SESSION['reset_contact'] = $contact;
        $_SESSION['reset_masked'] = $result['data']['masked_contact'] ?? $contact;
    } else {
        $error = $result['message'] ?? 'Error al enviar código';
    }
} else {
    $error = 'Error del servidor (HTTP: ' . $http_code . ') - Respuesta: ' . substr($response, 0, 100);
}
            }
        }
    }
}

// Si viene de la URL con step=2, mostrar formulario de código
if (isset($_GET['step']) && $_GET['step'] == '2' && isset($_SESSION['reset_user_id'])) {
    $step = 2;
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Contraseña - Novedades Ashley</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 15px;
        }
        .recovery-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            overflow: hidden;
            max-width: 900px;
            width: 100%;
            margin: 0 auto;
        }
        .recovery-form {
            padding: 40px 30px;
        }
        .recovery-image {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 500px;
            position: relative;
        }
        .recovery-image::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="white" opacity="0.1"/><circle cx="75" cy="75" r="1" fill="white" opacity="0.1"/><circle cx="50" cy="10" r="1" fill="white" opacity="0.1"/><circle cx="10" cy="90" r="1" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }
        .form-control {
            border-radius: 10px;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-recovery {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        .btn-recovery:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .brand-title {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 20px;
        }
        .alert {
            border-radius: 10px;
            border: none;
        }
        .back-link {
            position: fixed;
            top: 20px;
            left: 20px;
            color: white;
            text-decoration: none;
            background: rgba(255,255,255,0.2);
            padding: 8px 15px;
            border-radius: 25px;
            transition: all 0.3s ease;
            z-index: 1000;
            backdrop-filter: blur(10px);
        }
        .back-link:hover {
            background: rgba(255,255,255,0.3);
            color: white;
        }
        
        /* Estilos para pestañas de métodos */
        .method-tabs {
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 30px;
        }
        .method-tab {
            border: none;
            background: none;
            padding: 15px 20px;
            margin-right: 10px;
            border-radius: 10px 10px 0 0;
            transition: all 0.3s ease;
            position: relative;
        }
        .method-tab.active {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
        }
        .method-tab:not(.active) {
            color: #6c757d;
        }
        .method-tab:not(.active):hover {
            background: #f8f9fa;
            color: #495057;
        }
        .method-content {
            display: none;
        }
        .method-content.active {
            display: block;
        }
        .status-badge {
            font-size: 0.75rem;
            padding: 2px 8px;
            border-radius: 12px;
            margin-left: 8px;
        }
        .status-available {
            background: #d4edda;
            color: #155724;
        }
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        .code-input {
            text-align: center;
            font-size: 1.5rem;
            letter-spacing: 0.5rem;
            font-weight: bold;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 20px 10px;
            }
            .recovery-form {
                padding: 30px 20px;
            }
            .brand-title {
                font-size: 1.5rem;
            }
            .back-link {
                position: relative;
                display: inline-block;
                margin-bottom: 20px;
                top: 0;
                left: 0;
            }
            .method-tab {
                padding: 10px 15px;
                margin-right: 5px;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <a href="login.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Volver al login
    </a>
    
    <div class="container-fluid">
        <div class="row justify-content-center">
            <div class="col-12 col-xl-10">
                <div class="recovery-container">
                    <div class="row g-0">
                        <!-- Panel izquierdo - Imagen/Branding -->
                        <div class="col-lg-6 d-none d-lg-block">
                            <div class="recovery-image">
                                <div class="text-center" style="z-index: 1;">
                                    <?php if ($step === 1): ?>
                                        <i class="fas fa-key fa-4x mb-4"></i>
                                        <h2 class="brand-title">Recuperar Contraseña</h2>
                                        <p class="lead">Elige cómo quieres recibir tu código de recuperación</p>
                                        
                                        <div class="mt-4">
                                            <div class="d-flex align-items-center justify-content-center mb-3">
                                                <i class="fas fa-envelope fa-2x me-3"></i>
                                                <div class="text-start">
                                                    <strong>Por Email</strong><br>
                                                    <small>Disponible inmediatamente</small>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center justify-content-center mb-3">
                                                <i class="fas fa-sms fa-2x me-3"></i>
                                                <div class="text-start">
                                                    <strong>Por SMS</strong><br>
                                                    <small>Próximamente disponible</small>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center justify-content-center">
                                                <i class="fab fa-whatsapp fa-2x me-3"></i>
                                                <div class="text-start">
                                                    <strong>Por WhatsApp</strong><br>
                                                    <small>Próximamente disponible</small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <i class="fas fa-shield-check fa-4x mb-4"></i>
                                        <h2 class="brand-title">Código Enviado</h2>
                                        <p class="lead">Revisa tu <?= $_SESSION['reset_method'] === 'email' ? 'email' : 'teléfono' ?> e ingresa el código</p>
                                        
                                        <div class="mt-4">
                                            <div class="alert alert-info" style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3);">
                                                <strong>Código enviado a:</strong><br>
                                                <?= htmlspecialchars($_SESSION['reset_masked'] ?? '') ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Panel derecho - Formulario -->
                        <div class="col-lg-6">
                            <div class="recovery-form">
                                <?php if ($step === 1): ?>
                                    <!-- STEP 1: Seleccionar método y enviar código -->
                                    <div class="text-center mb-4">
                                        <h3><i class="fas fa-key"></i> Recuperar Contraseña</h3>
                                        <p class="text-muted">Selecciona cómo quieres recibir tu código</p>
                                    </div>
                                    
                                    <?php if ($error): ?>
                                        <div class="alert alert-danger" role="alert">
                                            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($success): ?>
                                        <div class="alert alert-success" role="alert">
                                            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Pestañas de métodos -->
                                    <div class="method-tabs">
                                        <button type="button" class="method-tab active" data-method="email">
                                            <i class="fas fa-envelope"></i> Email
                                            <span class="status-badge status-available">Disponible</span>
                                        </button>
                                        <button type="button" class="method-tab" data-method="sms">
                                            <i class="fas fa-sms"></i> SMS
                                            <span class="status-badge status-pending">Próximamente</span>
                                        </button>
                                        <button type="button" class="method-tab" data-method="whatsapp">
                                            <i class="fab fa-whatsapp"></i> WhatsApp
                                            <span class="status-badge status-pending">Próximamente</span>
                                        </button>
                                    </div>
                                    
                                    <form method="POST" action="" id="recoveryForm">
                                        <input type="hidden" name="action" value="send_code">
                                        <input type="hidden" name="method" id="selected_method" value="email">
                                        
                                        <!-- Contenido Email -->
                                        <div class="method-content active" id="email_content">
                                            <div class="form-floating mb-3">
                                                <input type="email" class="form-control" name="contact" id="email_input" 
                                                       placeholder="correo@ejemplo.com" required>
                                                <label for="email_input">
                                                    <i class="fas fa-envelope"></i> Tu Email Registrado
                                                </label>
                                            </div>
                                            <div class="alert alert-info">
                                                <i class="fas fa-info-circle"></i>
                                                Te enviaremos un código de 6 dígitos a tu email. Revisa también tu carpeta de spam.
                                            </div>
                                        </div>
                                        
                                        <!-- Contenido SMS -->
                                        <div class="method-content" id="sms_content">
                                            <div class="form-floating mb-3">
                                                <input type="tel" class="form-control" name="contact" id="phone_input" 
                                                       placeholder="555-123-4567" disabled>
                                                <label for="phone_input">
                                                    <i class="fas fa-phone"></i> Tu Teléfono Registrado
                                                </label>
                                            </div>
                                            <div class="alert alert-warning">
                                                <i class="fas fa-clock"></i>
                                                <strong>Próximamente disponible</strong><br>
                                                Estamos configurando el servicio de SMS. Por ahora usa el email.
                                            </div>
                                        </div>
                                        
                                        <!-- Contenido WhatsApp -->
                                        <div class="method-content" id="whatsapp_content">
                                            <div class="form-floating mb-3">
                                                <input type="tel" class="form-control" name="contact" id="whatsapp_input" 
                                                       placeholder="555-123-4567" disabled>
                                                <label for="whatsapp_input">
                                                    <i class="fab fa-whatsapp"></i> Tu WhatsApp Registrado
                                                </label>
                                            </div>
                                            <div class="alert alert-warning">
                                                <i class="fas fa-clock"></i>
                                                <strong>Próximamente disponible</strong><br>
                                                Estamos configurando WhatsApp Business. Por ahora usa el email.
                                            </div>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-recovery btn-primary w-100 mb-3" id="send_btn">
                                            <i class="fas fa-paper-plane"></i> Enviar Código
                                        </button>
                                    </form>
                                    
                                <?php else: ?>
                                    <!-- STEP 2: Mensaje de código enviado -->
                                    <div class="text-center mb-4">
                                        <h3><i class="fas fa-shield-check"></i> Código Enviado</h3>
                                        <p class="text-muted">Ingresa el código que recibiste</p>
                                    </div>
                                    
                                    <div class="alert alert-success" role="alert">
                                        <i class="fas fa-check-circle"></i> 
                                        Código enviado a: <strong><?= htmlspecialchars($_SESSION['reset_masked'] ?? '') ?></strong>
                                    </div>
                                    
                                    <div class="text-center mb-4">
                                        <p>Revisa tu <?= $_SESSION['reset_method'] === 'email' ? 'email' : 'teléfono' ?> e ingresa el código de 6 dígitos.</p>
                                        <p class="text-muted small">El código expira en 15 minutos.</p>
                                        
                                        <div class="mt-3">
                                            <a href="reset-password.php" class="btn btn-recovery btn-primary">
                                                <i class="fas fa-key"></i> Continuar con el código
                                            </a>
                                        </div>
                                        
                                        <hr class="my-4">
                                        
                                        <div class="d-flex justify-content-between">
                                            <a href="forgot-password.php" class="btn btn-outline-secondary">
                                                <i class="fas fa-arrow-left"></i> Cambiar método
                                            </a>
                                            <button type="button" class="btn btn-outline-primary" id="resend_btn">
                                                <i class="fas fa-redo"></i> Reenviar código
                                            </button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <hr class="my-4">
                                
                                <div class="text-center">
                                    <p class="mb-0">¿Recordaste tu contraseña?</p>
                                    <a href="login.php" class="btn btn-outline-primary mt-2">
                                        <i class="fas fa-sign-in-alt"></i> Iniciar Sesión
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Manejo de pestañas de métodos
        document.querySelectorAll('.method-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const method = this.dataset.method;
                
                // Si es SMS o WhatsApp, mostrar alerta
                if (method === 'sms' || method === 'whatsapp') {
                    alert('Este método estará disponible próximamente. Por ahora usa el email.');
                    return;
                }
                
                // Cambiar pestaña activa
                document.querySelectorAll('.method-tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                // Cambiar contenido activo
                document.querySelectorAll('.method-content').forEach(c => c.classList.remove('active'));
                document.getElementById(method + '_content').classList.add('active');
                
                // Actualizar método seleccionado
                document.getElementById('selected_method').value = method;
                
                // Actualizar texto del botón
                const sendBtn = document.getElementById('send_btn');
                if (method === 'email') {
                    sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar por Email';
                } else if (method === 'sms') {
                    sendBtn.innerHTML = '<i class="fas fa-sms"></i> Enviar por SMS';
                } else if (method === 'whatsapp') {
                    sendBtn.innerHTML = '<i class="fab fa-whatsapp"></i> Enviar por WhatsApp';
                }
            });
        });
        
        // Validación del formulario
        document.getElementById('recoveryForm')?.addEventListener('submit', function(e) {
            const method = document.getElementById('selected_method').value;
            const contact = document.querySelector(`#${method}_content input[name="contact"]`).value.trim();
            
            if (!contact) {
                e.preventDefault();
                alert('Por favor ingresa tu ' + (method === 'email' ? 'email' : 'teléfono'));
                return;
            }
            
            if (method === 'email') {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(contact)) {
                    e.preventDefault();
                    alert('Por favor ingresa un email válido');
                    return;
                }
            } else {
                const phoneRegex = /^[\d\s\-\(\)\+]{10,}$/;
                if (!phoneRegex.test(contact)) {
                    e.preventDefault();
                    alert('Por favor ingresa un número de teléfono válido');
                    return;
                }
            }
            
            // Mostrar loading
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';
            submitBtn.disabled = true;
            
            // Restaurar botón si hay error
            setTimeout(() => {
                if (submitBtn.disabled) {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            }, 10000);
        });
        
        // Botón de reenviar código
        document.getElementById('resend_btn')?.addEventListener('click', function() {
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Reenviando...';
            this.disabled = true;
            
            // Simular reenvío (aquí harías la llamada AJAX real)
            setTimeout(() => {
                alert('Código reenviado correctamente');
                this.innerHTML = '<i class="fas fa-redo"></i> Reenviar código';
                this.disabled = false;
            }, 2000);
        });
        
        // Animación de entrada
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.querySelector('.recovery-container');
            container.style.transform = 'translateY(30px)';
            container.style.opacity = '0';
            
            setTimeout(() => {
                container.style.transition = 'all 0.6s ease';
                container.style.transform = 'translateY(0)';
                container.style.opacity = '1';
            }, 100);
        });
    </script>
</body>
</html>