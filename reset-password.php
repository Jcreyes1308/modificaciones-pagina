<?php
// reset-password.php - CORREGIDO con mejor debugging
session_start();

// Si ya está logueado, redirigir al inicio
if (isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit();
}

// Verificar que venga del proceso de recuperación
if (!isset($_SESSION['reset_user_id']) || !isset($_SESSION['reset_method'])) {
    header('Location: forgot-password.php');
    exit();
}

require_once 'config/database.php';
$database = new Database();
$conn = $database->getConnection();

$error = '';
$success = '';
$debug_info = ''; // Para debugging

// Procesar formulario de reset
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validaciones
    if (empty($code) || empty($new_password) || empty($confirm_password)) {
        $error = 'Todos los campos son requeridos';
    } elseif (strlen($code) !== 6 || !ctype_digit($code)) {
        $error = 'El código debe ser de 6 dígitos';
    } elseif (strlen($new_password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Las contraseñas no coinciden';
    } else {
        // Procesar reset usando la API
        $api_data = [
            'action' => 'reset_password',
            'user_id' => $_SESSION['reset_user_id'],
            'code' => $code,
            'new_password' => $new_password
        ];
        
        // CORREGIDO: URL absoluta y mejor manejo de errores
        $api_url = 'http://localhost/tienda-multicategoria/api/password-reset.php';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($api_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        // Debugging mejorado
        $debug_info = [
            'api_url' => $api_url,
            'http_code' => $http_code,
            'curl_error' => $curl_error,
            'response_length' => strlen($response),
            'response_preview' => substr($response, 0, 200),
            'session_data' => [
                'user_id' => $_SESSION['reset_user_id'],
                'method' => $_SESSION['reset_method'],
                'contact' => $_SESSION['reset_contact'] ?? 'No disponible'
            ]
        ];
        
        if ($curl_error) {
            $error = 'Error de conexión: ' . $curl_error;
        } elseif ($http_code !== 200) {
            $error = "Error del servidor (HTTP: {$http_code}). Verifica que el servidor esté funcionando.";
        } elseif (empty($response)) {
            $error = 'Respuesta vacía del servidor';
        } else {
            $result = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $error = 'Error al procesar respuesta del servidor: ' . json_last_error_msg();
            } elseif ($result && isset($result['success'])) {
                if ($result['success']) {
                    $success = $result['message'];
                    
                    // Limpiar datos de sesión
                    unset($_SESSION['reset_user_id']);
                    unset($_SESSION['reset_method']);
                    unset($_SESSION['reset_contact']);
                    unset($_SESSION['reset_masked']);
                    
                    // Redirigir al login después de 3 segundos
                    echo '<script>
                        setTimeout(function() {
                            window.location.href = "login.php?reset=success";
                        }, 3000);
                    </script>';
                } else {
                    $error = $result['message'] ?? 'Error desconocido del servidor';
                }
            } else {
                $error = 'Respuesta inválida del servidor';
            }
        }
    }
}

// Información del método usado
$method_info = [
    'email' => ['icon' => 'fas fa-envelope', 'name' => 'Email'],
    'sms' => ['icon' => 'fas fa-sms', 'name' => 'SMS'],
    'whatsapp' => ['icon' => 'fab fa-whatsapp', 'name' => 'WhatsApp']
];

$current_method = $_SESSION['reset_method'] ?? 'email';
$masked_contact = $_SESSION['reset_masked'] ?? '';

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer Contraseña - Novedades Ashley</title>
    
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
        .reset-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            overflow: hidden;
            max-width: 900px;
            width: 100%;
            margin: 0 auto;
        }
        .reset-form {
            padding: 40px 30px;
        }
        .reset-image {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 600px;
            position: relative;
        }
        .reset-image::before {
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
        .btn-reset {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        .btn-reset:hover {
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
        .code-input {
            text-align: center;
            font-size: 1.8rem;
            letter-spacing: 0.5rem;
            font-weight: bold;
            font-family: 'Courier New', monospace;
        }
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: none;
            color: #6c757d;
            cursor: pointer;
        }
        .password-strength {
            margin-top: 5px;
            font-size: 0.8rem;
        }
        .strength-weak { color: #dc3545; }
        .strength-medium { color: #ffc107; }
        .strength-strong { color: #28a745; }
        .method-badge {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 25px;
            padding: 8px 16px;
            display: inline-flex;
            align-items: center;
            margin-bottom: 20px;
        }
        .debug-info {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            font-size: 0.85rem;
            font-family: monospace;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 20px 10px;
            }
            .reset-form {
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
            .code-input {
                font-size: 1.5rem;
                letter-spacing: 0.3rem;
            }
        }
    </style>
</head>
<body>
    <a href="forgot-password.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Cambiar método
    </a>
    
    <div class="container-fluid">
        <div class="row justify-content-center">
            <div class="col-12 col-xl-10">
                <div class="reset-container">
                    <div class="row g-0">
                        <!-- Panel izquierdo - Imagen/Branding -->
                        <div class="col-lg-6 d-none d-lg-block">
                            <div class="reset-image">
                                <div class="text-center" style="z-index: 1;">
                                    <?php if ($success): ?>
                                        <i class="fas fa-check-circle fa-4x mb-4" style="color: #28a745;"></i>
                                        <h2 class="brand-title">¡Contraseña Actualizada!</h2>
                                        <p class="lead">Tu contraseña ha sido restablecida exitosamente</p>
                                        
                                        <div class="mt-4">
                                            <div class="alert alert-success" style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3);">
                                                <strong>Redirigiendo al login...</strong><br>
                                                <small>En 3 segundos serás redirigido automáticamente</small>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <i class="fas fa-key fa-4x mb-4"></i>
                                        <h2 class="brand-title">Nueva Contraseña</h2>
                                        <p class="lead">Ingresa el código y tu nueva contraseña</p>
                                        
                                        <div class="method-badge">
                                            <i class="<?= $method_info[$current_method]['icon'] ?> me-2"></i>
                                            Código enviado por <?= $method_info[$current_method]['name'] ?>
                                        </div>
                                        
                                        <div class="mt-4">
                                            <div class="d-flex align-items-center justify-content-center mb-3">
                                                <i class="fas fa-clock fa-2x me-3"></i>
                                                <div class="text-start">
                                                    <strong>Código válido por 15 min</strong><br>
                                                    <small>Tiempo restante: <span id="countdown">--:--</span></small>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center justify-content-center mb-3">
                                                <i class="fas fa-shield-alt fa-2x me-3"></i>
                                                <div class="text-start">
                                                    <strong>Seguridad máxima</strong><br>
                                                    <small>Código de un solo uso</small>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center justify-content-center">
                                                <i class="fas fa-user-lock fa-2x me-3"></i>
                                                <div class="text-start">
                                                    <strong>Nueva contraseña</strong><br>
                                                    <small>Mínimo 6 caracteres</small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Panel derecho - Formulario -->
                        <div class="col-lg-6">
                            <div class="reset-form">
                                <?php if ($success): ?>
                                    <!-- Mensaje de éxito -->
                                    <div class="text-center">
                                        <div class="alert alert-success" role="alert">
                                            <i class="fas fa-check-circle fa-3x mb-3"></i>
                                            <h4>¡Contraseña Restablecida!</h4>
                                            <p><?= htmlspecialchars($success) ?></p>
                                        </div>
                                        
                                        <div class="mt-4">
                                            <a href="login.php" class="btn btn-reset btn-primary btn-lg">
                                                <i class="fas fa-sign-in-alt"></i> Ir al Login
                                            </a>
                                        </div>
                                        
                                        <div class="mt-3">
                                            <small class="text-muted">
                                                Redirigiendo automáticamente en <span id="redirect-countdown">3</span> segundos...
                                            </small>
                                        </div>
                                    </div>
                                    
                                <?php else: ?>
                                    <!-- Formulario de reset -->
                                    <div class="text-center mb-4">
                                        <h3><i class="fas fa-key"></i> Nueva Contraseña</h3>
                                        <p class="text-muted">Ingresa el código y tu nueva contraseña</p>
                                    </div>
                                    
                                    <!-- Info del método usado -->
                                    <div class="alert alert-info" role="alert">
                                        <i class="<?= $method_info[$current_method]['icon'] ?>"></i>
                                        <strong>Código enviado a:</strong> <?= htmlspecialchars($masked_contact) ?>
                                        <br><small>Revisa tu <?= strtolower($method_info[$current_method]['name']) ?> e ingresa el código de 6 dígitos</small>
                                    </div>
                                    
                                    <?php if ($error): ?>
                                        <div class="alert alert-danger" role="alert">
                                            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- ⚠️ DEBUG INFO - QUITAR EN PRODUCCIÓN -->
                                    <?php if (!empty($debug_info) && $error): ?>
                                        <div class="debug-info">
                                            <strong>🔧 Debug Info (quitar en producción):</strong><br>
                                            <strong>API URL:</strong> <?= htmlspecialchars($debug_info['api_url']) ?><br>
                                            <strong>HTTP Code:</strong> <?= $debug_info['http_code'] ?><br>
                                            <strong>cURL Error:</strong> <?= $debug_info['curl_error'] ?: 'Ninguno' ?><br>
                                            <strong>Response Length:</strong> <?= $debug_info['response_length'] ?> bytes<br>
                                            <strong>Response Preview:</strong> <?= htmlspecialchars($debug_info['response_preview']) ?><br>
                                            <strong>Session User ID:</strong> <?= $debug_info['session_data']['user_id'] ?><br>
                                            <strong>Session Method:</strong> <?= $debug_info['session_data']['method'] ?><br>
                                            <details style="margin-top: 10px;">
                                                <summary>Ver datos completos</summary>
                                                <pre><?= print_r($debug_info, true) ?></pre>
                                            </details>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <form method="POST" action="" id="resetForm">
                                        <!-- Código de verificación -->
                                        <div class="form-floating mb-4">
                                            <input type="text" class="form-control code-input" id="code" name="code" 
                                                   placeholder="123456" required maxlength="6" pattern="[0-9]{6}"
                                                   autocomplete="off">
                                            <label for="code">
                                                <i class="fas fa-shield-check"></i> Código de 6 dígitos
                                            </label>
                                        </div>
                                        
                                        <!-- Nueva contraseña -->
                                        <div class="form-floating mb-3 position-relative">
                                            <input type="password" class="form-control" id="new_password" name="new_password" 
                                                   placeholder="Nueva contraseña" required minlength="6">
                                            <label for="new_password">
                                                <i class="fas fa-lock"></i> Nueva Contraseña
                                            </label>
                                            <button type="button" class="password-toggle" onclick="togglePassword('new_password')">
                                                <i class="fas fa-eye" id="new_password-icon"></i>
                                            </button>
                                        </div>
                                        <div class="password-strength" id="password-strength"></div>
                                        
                                        <!-- Confirmar contraseña -->
                                        <div class="form-floating mb-4 position-relative">
                                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                                   placeholder="Confirmar contraseña" required minlength="6">
                                            <label for="confirm_password">
                                                <i class="fas fa-lock"></i> Confirmar Nueva Contraseña
                                            </label>
                                            <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                                                <i class="fas fa-eye" id="confirm_password-icon"></i>
                                            </button>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-reset btn-primary w-100 mb-3">
                                            <i class="fas fa-key"></i> Restablecer Contraseña
                                        </button>
                                    </form>
                                    
                                    <div class="text-center">
                                        <button type="button" class="btn btn-outline-secondary me-2" id="resend_btn">
                                            <i class="fas fa-redo"></i> Reenviar código
                                        </button>
                                        <a href="forgot-password.php" class="btn btn-outline-primary">
                                            <i class="fas fa-arrow-left"></i> Cambiar método
                                        </a>
                                    </div>
                                    
                                    <!-- ⚠️ BOTÓN DE PRUEBA DIRECTA - QUITAR EN PRODUCCIÓN -->
                                    <div class="mt-3 text-center">
                                        <button type="button" class="btn btn-warning btn-sm" onclick="testApiDirectly()">
                                            🔧 Probar API directamente
                                        </button>
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
        // Función para mostrar/ocultar contraseña
        function togglePassword(fieldId) {
            const passwordInput = document.getElementById(fieldId);
            const passwordIcon = document.getElementById(fieldId + '-icon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                passwordIcon.classList.remove('fa-eye');
                passwordIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                passwordIcon.classList.remove('fa-eye-slash');
                passwordIcon.classList.add('fa-eye');
            }
        }
        
        // ⚠️ FUNCIÓN DE PRUEBA - QUITAR EN PRODUCCIÓN
        function testApiDirectly() {
            fetch('api/password-reset.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=reset_password&user_id=<?= $_SESSION['reset_user_id'] ?>&code=123456&new_password=test123'
            })
            .then(response => response.text())
            .then(data => {
                console.log('API Response:', data);
                alert('Respuesta de API (ver consola): ' + data.substring(0, 100) + '...');
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al conectar con API: ' + error);
            });
        }
        
        // Validación de fortaleza de contraseña
        document.getElementById('new_password')?.addEventListener('input', function() {
            const password = this.value;
            const strengthDiv = document.getElementById('password-strength');
            
            if (password.length === 0) {
                strengthDiv.innerHTML = '';
                return;
            }
            
            let strength = 0;
            let feedback = [];
            
            // Criterios de fortaleza
            if (password.length >= 8) strength++; else feedback.push('al menos 8 caracteres');
            if (/[a-z]/.test(password)) strength++; else feedback.push('letras minúsculas');
            if (/[A-Z]/.test(password)) strength++; else feedback.push('letras mayúsculas');
            if (/\d/.test(password)) strength++; else feedback.push('números');
            if (/[^a-zA-Z\d]/.test(password)) strength++; else feedback.push('símbolos');
            
            let strengthText = '';
            let strengthClass = '';
            
            if (strength <= 2) {
                strengthText = 'Débil';
                strengthClass = 'strength-weak';
            } else if (strength <= 3) {
                strengthText = 'Media';
                strengthClass = 'strength-medium';
            } else {
                strengthText = 'Fuerte';
                strengthClass = 'strength-strong';
            }
            
            strengthDiv.innerHTML = `
                <span class="${strengthClass}">
                    <i class="fas fa-shield-alt"></i> Contraseña: ${strengthText}
                </span>
            `;
            
            if (feedback.length > 0 && strength < 4) {
                strengthDiv.innerHTML += `<br><small>Falta: ${feedback.join(', ')}</small>`;
            }
        });
        
        // Validación de contraseñas coincidentes
        document.getElementById('confirm_password')?.addEventListener('input', function() {
            const password = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (confirmPassword.length > 0) {
                if (password === confirmPassword) {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                } else {
                    this.classList.remove('is-valid');
                    this.classList.add('is-invalid');
                }
            } else {
                this.classList.remove('is-valid', 'is-invalid');
            }
        });
        
        // Validación de código (solo números)
        document.getElementById('code')?.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
            
            if (this.value.length === 6) {
                this.classList.add('is-valid');
            } else {
                this.classList.remove('is-valid');
            }
        });
        
        // Validación del formulario
        document.getElementById('resetForm')?.addEventListener('submit', function(e) {
            const code = document.getElementById('code').value;
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (code.length !== 6) {
                e.preventDefault();
                alert('El código debe tener 6 dígitos');
                return;
            }
            
            if (newPassword.length < 6) {
                e.preventDefault();
                alert('La contraseña debe tener al menos 6 caracteres');
                return;
            }
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('Las contraseñas no coinciden');
                return;
            }
            
            // Mostrar loading
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restableciendo...';
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
            const btn = this;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Reenviando...';
            btn.disabled = true;
            
            // Aquí harías la llamada AJAX real para reenviar
            setTimeout(() => {
                alert('Código reenviado correctamente');
                btn.innerHTML = '<i class="fas fa-redo"></i> Reenviar código';
                btn.disabled = false;
            }, 2000);
        });
        
        // Countdown para redirección automática
        <?php if ($success): ?>
        let redirectCountdown = 3;
        const redirectInterval = setInterval(() => {
            document.getElementById('redirect-countdown').textContent = redirectCountdown;
            redirectCountdown--;
            
            if (redirectCountdown < 0) {
                clearInterval(redirectInterval);
                window.location.href = 'login.php?reset=success';
            }
        }, 1000);
        <?php endif; ?>
        
        // Animación de entrada
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.querySelector('.reset-container');
            container.style.transform = 'translateY(30px)';
            container.style.opacity = '0';
            
            setTimeout(() => {
                container.style.transition = 'all 0.6s ease';
                container.style.transform = 'translateY(0)';
                container.style.opacity = '1';
            }, 100);
            
            // Focus en el código
            const codeInput = document.getElementById('code');
            if (codeInput) {
                codeInput.focus();
            }
        });
    </script>
</body>
</html>