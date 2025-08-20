<?php
// registro.php - CORREGIDO - Sistema con código de 6 dígitos ÚNICAMENTE
session_start();

// Si ya está logueado, redirigir al inicio
if (isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit();
}

require_once 'config/database.php';
require_once 'config/verification.php';

$database = new Database();
$conn = $database->getConnection();

$error = '';
$success = '';

// Procesar formulario de registro
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validaciones
    if (empty($nombre) || empty($email) || empty($password)) {
        $error = 'Nombre, email y contraseña son requeridos';
    } elseif (strlen($password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres';
    } elseif ($password !== $confirm_password) {
        $error = 'Las contraseñas no coinciden';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email no válido';
    } else {
        try {
            // VERIFICAR QUE NO EXISTA EN CLIENTES (usuarios ya verificados)
            $stmt = $conn->prepare("SELECT id FROM clientes WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'Este email ya está registrado y verificado';
            } else {
                // VERIFICAR SI YA ESTÁ EN PENDING (eliminar registros expirados primero)
                $stmt = $conn->prepare("DELETE FROM pending_registrations WHERE email = ? AND expires_at <= NOW()");
                $stmt->execute([$email]);
                
                $stmt = $conn->prepare("SELECT id, attempts FROM pending_registrations WHERE email = ?");
                $stmt->execute([$email]);
                $existing_pending = $stmt->fetch();
                
                if ($existing_pending && $existing_pending['attempts'] >= 3) {
                    $error = 'Demasiados intentos de registro. Espera 24 horas o contacta soporte.';
                } else {
                    // GENERAR CÓDIGO DE 6 DÍGITOS
                    $codigo_verificacion = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
                    $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    
                    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
                    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    
                    // GUARDAR EN PENDING_REGISTRATIONS con código
                    if ($existing_pending) {
                        // Actualizar registro existente
                        $stmt = $conn->prepare("
                            UPDATE pending_registrations 
                            SET nombre = ?, password_hash = ?, telefono = NULL, direccion = NULL, 
                                verification_token = ?, expires_at = ?, attempts = attempts + 1,
                                ip_address = ?, user_agent = ?
                            WHERE email = ?
                        ");
                        $stmt->execute([
                            $nombre, $password_hash, 
                            $codigo_verificacion, $expires_at, $ip_address, $user_agent, $email
                        ]);
                        $pending_id = $existing_pending['id'];
                    } else {
                        // Crear nuevo registro
                        $stmt = $conn->prepare("
                            INSERT INTO pending_registrations 
                            (nombre, email, password_hash, telefono, direccion, verification_token, expires_at, ip_address, user_agent) 
                            VALUES (?, ?, ?, NULL, NULL, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $nombre, $email, $password_hash, 
                            $codigo_verificacion, $expires_at, $ip_address, $user_agent
                        ]);
                        $pending_id = $conn->lastInsertId();
                    }
                    
                    // ENVIAR CÓDIGO DE 6 DÍGITOS POR EMAIL
                    $verification_service = new VerificationService($conn);
                    $email_sent = $verification_service->sendRegistrationVerificationCode($email, $nombre, $codigo_verificacion);
                    
                    if ($email_sent) {
                        $success = 'Registro iniciado. Te hemos enviado un código de 6 dígitos a tu email. Tienes 24 horas para verificar tu cuenta.';
                        
                        // Redirigir a página de verificación con email
                        echo '<script>
                            setTimeout(function() {
                                window.location.href = "verify_registration_code.php?email=' . urlencode($email) . '";
                            }, 3000);
                        </script>';
                    } else {
                        $error = 'Error enviando código de verificación. Intenta nuevamente.';
                    }
                }
            }
        } catch (Exception $e) {
            $error = 'Error al procesar el registro: ' . $e->getMessage();
            error_log("Error en registro: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrarse - Novedades Ashley</title>
    
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
        .register-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            overflow: hidden;
            max-width: 1000px;
            width: 100%;
            margin: 0 auto;
        }
        .register-form {
            padding: 40px 30px;
        }
        .register-image {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 600px;
            position: relative;
        }
        .register-image::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="dots" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="10" cy="10" r="1.5" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23dots)"/></svg>');
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
        .btn-register {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
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
        .brand-title {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 20px 10px;
            }
            .register-form {
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
        }
    </style>
</head>
<body>
    <a href="index.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Volver a la tienda
    </a>
    
    <div class="container-fluid">
        <div class="row justify-content-center">
            <div class="col-12 col-xl-10">
                <div class="register-container">
                    <div class="row g-0">
                        <!-- Panel izquierdo - Imagen/Branding -->
                        <div class="col-lg-5 d-none d-lg-block">
                            <div class="register-image">
                                <div class="text-center" style="z-index: 1;">
                                    <i class="fas fa-envelope-circle-check fa-4x mb-4"></i>
                                    <h2 class="brand-title">Código de 6 Dígitos</h2>
                                    <p class="lead mb-4">Te enviamos un código a tu email para verificar</p>
                                    
                                    <div class="text-start">
                                        <div class="d-flex align-items-center mb-3">
                                            <i class="fas fa-shield-check fa-2x me-3"></i>
                                            <div>
                                                <strong>Mayor Seguridad</strong><br>
                                                <small>Solo cuentas verificadas</small>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center mb-3">
                                            <i class="fas fa-clock fa-2x me-3"></i>
                                            <div>
                                                <strong>24 Horas</strong><br>
                                                <small>Tiempo para verificar tu email</small>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center mb-3">
                                            <i class="fas fa-key fa-2x me-3"></i>
                                            <div>
                                                <strong>Código de 6 Dígitos</strong><br>
                                                <small>Fácil de ingresar</small>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-user-check fa-2x me-3"></i>
                                            <div>
                                                <strong>Activación Automática</strong><br>
                                                <small>Ingresa el código y listo</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Panel derecho - Formulario -->
                        <div class="col-lg-7">
                            <div class="register-form">
                                <div class="text-center mb-4">
                                    <h3><i class="fas fa-user-plus"></i> Crear Cuenta Nueva</h3>
                                    <p class="text-muted">Te enviaremos un código de 6 dígitos</p>
                                </div>
                                
                                <?php if ($error): ?>
                                    <div class="alert alert-danger" role="alert">
                                        <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($success): ?>
                                    <div class="alert alert-success" role="alert">
                                        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                                        <br><small>Redirigiendo...</small>
                                    </div>
                                <?php endif; ?>
                                
                                <form method="POST" action="" id="registerForm">
                                    <div class="row">
                                        <div class="col-12 mb-3">
                                            <div class="form-floating">
                                                <input type="text" class="form-control" id="nombre" name="nombre" 
                                                       placeholder="Tu nombre completo" required maxlength="100"
                                                       value="<?= htmlspecialchars($nombre ?? '') ?>">
                                                <label for="nombre">
                                                    <i class="fas fa-user"></i> Nombre Completo *
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <div class="col-12 mb-3">
                                            <div class="form-floating">
                                                <input type="email" class="form-control" id="email" name="email" 
                                                       placeholder="correo@ejemplo.com" required maxlength="150"
                                                       value="<?= htmlspecialchars($email ?? '') ?>">
                                                <label for="email">
                                                    <i class="fas fa-envelope"></i> Correo Electrónico *
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <div class="form-floating position-relative">
                                                <input type="password" class="form-control" id="password" name="password" 
                                                       placeholder="Contraseña" required minlength="6">
                                                <label for="password">
                                                    <i class="fas fa-lock"></i> Contraseña *
                                                </label>
                                                <button type="button" class="password-toggle" onclick="togglePassword('password')">
                                                    <i class="fas fa-eye" id="password-icon"></i>
                                                </button>
                                            </div>
                                            <div class="password-strength" id="password-strength"></div>
                                        </div>
                                        
                                        <div class="col-md-6 mb-4">
                                            <div class="form-floating position-relative">
                                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                                       placeholder="Confirmar contraseña" required minlength="6">
                                                <label for="confirm_password">
                                                    <i class="fas fa-lock"></i> Confirmar Contraseña *
                                                </label>
                                                <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                                                    <i class="fas fa-eye" id="confirm_password-icon"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Nota informativa actualizada -->
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i>
                                        <strong>Proceso de verificación:</strong>
                                        <ul class="mb-0 mt-2">
                                            <li>Te enviaremos un código de 6 dígitos</li>
                                            <li>Tienes 24 horas para verificar</li>
                                            <li>Sin verificación no puedes iniciar sesión</li>
                                            <li>Solo entonces se crea tu cuenta</li>
                                        </ul>
                                    </div>
                                    
                                    <div class="form-check mb-4">
                                        <input type="checkbox" class="form-check-input" id="terminos" required>
                                        <label class="form-check-label" for="terminos">
                                            Acepto los <a href="terminos.php" target="_blank">términos y condiciones</a> 
                                            y la <a href="privacidad.php" target="_blank">política de privacidad</a> *
                                        </label>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-register btn-primary w-100 mb-3">
                                        <i class="fas fa-key"></i> Enviar Código
                                    </button>
                                    
                                    <small class="text-muted">* Campos requeridos</small>
                                </form>
                                
                                <hr class="my-4">
                                
                                <div class="text-center">
                                    <p class="mb-0">¿Ya tienes cuenta?</p>
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
        
        // Validación de fortaleza de contraseña
        document.getElementById('password').addEventListener('input', function() {
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
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
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
        
        // Validación del formulario
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const nombre = document.getElementById('nombre').value.trim();
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const terminos = document.getElementById('terminos').checked;
            
            // Validaciones
            if (!nombre || !email || !password) {
                e.preventDefault();
                alert('Por favor completa todos los campos requeridos');
                return;
            }
            
            if (password.length < 6) {
                e.preventDefault();
                alert('La contraseña debe tener al menos 6 caracteres');
                return;
            }
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Las contraseñas no coinciden');
                return;
            }
            
            if (!terminos) {
                e.preventDefault();
                alert('Debes aceptar los términos y condiciones');
                return;
            }
            
            // Validación de email
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                alert('Por favor ingresa un email válido');
                return;
            }
            
            // Mostrar loading en el botón
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando código...';
            submitBtn.disabled = true;
            
            // Si hay error, restaurar el botón después de 10 segundos
            setTimeout(() => {
                if (submitBtn.disabled) {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            }, 10000);
        });
        
        // Animación de entrada
        document.addEventListener('DOMContentLoaded', function() {
            const registerContainer = document.querySelector('.register-container');
            registerContainer.style.transform = 'translateY(30px)';
            registerContainer.style.opacity = '0';
            
            setTimeout(() => {
                registerContainer.style.transition = 'all 0.6s ease';
                registerContainer.style.transform = 'translateY(0)';
                registerContainer.style.opacity = '1';
            }, 100);
            
            // Focus en el nombre
            document.getElementById('nombre').focus();
        });
    </script>
</body>
</html>