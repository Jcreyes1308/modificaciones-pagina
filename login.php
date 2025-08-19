<?php
// login.php - Página de inicio de sesión
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

// Procesar formulario de login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Por favor completa todos los campos';
    } else {
        try {
            // Buscar usuario por email
            $stmt = $conn->prepare("SELECT id, nombre, email, password, activo FROM clientes WHERE email = ?");
            $stmt->execute([$email]);
            $usuario = $stmt->fetch();
            
            if ($usuario && password_verify($password, $usuario['password'])) {
                if ($usuario['activo']) {
                    // Login exitoso
                    $_SESSION['usuario_id'] = $usuario['id'];
                    $_SESSION['usuario_nombre'] = $usuario['nombre'];
                    $_SESSION['usuario_email'] = $usuario['email'];
                    
                    // Migrar carrito de sesión a base de datos si existe
                    if (isset($_SESSION['carrito']) && !empty($_SESSION['carrito'])) {
                        foreach ($_SESSION['carrito'] as $item) {
                            try {
                                if ($item['tipo'] === 'producto') {
                                    $stmt = $conn->prepare("
                                        INSERT INTO carrito_compras (id_cliente, id_producto, cantidad) 
                                        VALUES (?, ?, ?)
                                        ON DUPLICATE KEY UPDATE cantidad = cantidad + VALUES(cantidad)
                                    ");
                                    $stmt->execute([$usuario['id'], $item['id'], $item['cantidad']]);
                                } else if ($item['tipo'] === 'paquete') {
                                    $stmt = $conn->prepare("
                                        INSERT INTO carrito_compras (id_cliente, id_paquete, cantidad) 
                                        VALUES (?, ?, ?)
                                        ON DUPLICATE KEY UPDATE cantidad = cantidad + VALUES(cantidad)
                                    ");
                                    $stmt->execute([$usuario['id'], $item['id'], $item['cantidad']]);
                                }
                            } catch (Exception $e) {
                                // Error al migrar algún item, pero no interrumpir el login
                                error_log("Error migrando carrito: " . $e->getMessage());
                            }
                        }
                        // Limpiar carrito de sesión
                        unset($_SESSION['carrito']);
                    }
                    
                    // Redirigir
                    $redirect = $_GET['redirect'] ?? 'index.php';
                    header('Location: ' . $redirect);
                    exit();
                } else {
                    $error = 'Tu cuenta está desactivada. Contacta al administrador.';
                }
            } else {
                $error = 'Email o contraseña incorrectos';
            }
        } catch (Exception $e) {
            $error = 'Error al procesar login: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Tienda Multicategoría</title>
    
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
        .login-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            overflow: hidden;
            max-width: 900px;
            width: 100%;
            margin: 0 auto;
        }
        .login-form {
            padding: 40px 30px;
        }
        .login-image {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 500px;
            position: relative;
        }
        .login-image::before {
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
        .btn-login {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        .btn-login:hover {
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
        .form-floating {
            position: relative;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 20px 10px;
            }
            .login-form {
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
                <div class="login-container">
                    <div class="row g-0">
                        <!-- Panel izquierdo - Imagen/Branding -->
                        <div class="col-lg-6 d-none d-lg-block">
                            <div class="login-image">
                                <div class="text-center" style="z-index: 1;">
                                    <i class="fas fa-store fa-4x mb-4"></i>
                                    <h2 class="brand-title">Tienda Multicategoría</h2>
                                    <p class="lead">Tu tienda de confianza para todo lo que necesitas</p>
                                    <div class="mt-4">
                                        <div class="d-flex align-items-center justify-content-center mb-3">
                                            <i class="fas fa-check-circle me-2"></i>
                                            <span>Productos de calidad</span>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-center mb-3">
                                            <i class="fas fa-shipping-fast me-2"></i>
                                            <span>Envío rápido</span>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-center">
                                            <i class="fas fa-shield-alt me-2"></i>
                                            <span>Compra segura</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Panel derecho - Formulario -->
                        <div class="col-lg-6">
                            <div class="login-form">
                                <div class="text-center mb-4">
                                    <h3><i class="fas fa-sign-in-alt"></i> Iniciar Sesión</h3>
                                    <p class="text-muted">Accede a tu cuenta para continuar</p>
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
                                
                                <form method="POST" action="" id="loginForm">
                                    <div class="form-floating mb-3">
                                        <input type="email" class="form-control" id="email" name="email" 
                                               placeholder="correo@ejemplo.com" required 
                                               value="<?= htmlspecialchars($email ?? '') ?>">
                                        <label for="email">
                                            <i class="fas fa-envelope"></i> Correo Electrónico
                                        </label>
                                    </div>
                                    
                                    <div class="form-floating mb-4 position-relative">
                                        <input type="password" class="form-control" id="password" name="password" 
                                               placeholder="Contraseña" required>
                                        <label for="password">
                                            <i class="fas fa-lock"></i> Contraseña
                                        </label>
                                        <button type="button" class="password-toggle" onclick="togglePassword()">
                                            <i class="fas fa-eye" id="password-icon"></i>
                                        </button>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-center mb-4">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" id="remember" name="remember">
                                            <label class="form-check-label" for="remember">
                                                Recordarme
                                            </label>
                                        </div>
                                        <a href="forgot-password.php" class="text-decoration-none">
                                            ¿Olvidaste tu contraseña?
                                        </a>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-login btn-primary w-100 mb-3">
                                        <i class="fas fa-sign-in-alt"></i> Iniciar Sesión
                                    </button>
                                </form>
                                
                                <hr class="my-4">
                                
                                <div class="text-center">
                                    <p class="mb-3">¿No tienes cuenta?</p>
                                    <a href="registro.php" class="btn btn-outline-primary w-100">
                                        <i class="fas fa-user-plus"></i> Crear Cuenta Nueva
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
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const passwordIcon = document.getElementById('password-icon');
            
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
        
        // Validación del formulario
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            
            if (!email || !password) {
                e.preventDefault();
                alert('Por favor completa todos los campos');
                return;
            }
            
            // Validación básica de email
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                alert('Por favor ingresa un email válido');
                return;
            }
            
            // Mostrar loading en el botón
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Iniciando sesión...';
            submitBtn.disabled = true;
            
            // Si hay error, restaurar el botón (esto se ejecutará si el form se vuelve a cargar)
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 5000);
        });
        
        // Efectos visuales
        document.addEventListener('DOMContentLoaded', function() {
            // Animación de entrada
            const loginContainer = document.querySelector('.login-container');
            loginContainer.style.transform = 'translateY(30px)';
            loginContainer.style.opacity = '0';
            
            setTimeout(() => {
                loginContainer.style.transition = 'all 0.6s ease';
                loginContainer.style.transform = 'translateY(0)';
                loginContainer.style.opacity = '1';
            }, 100);
            
            // Focus automático en el email
            document.getElementById('email').focus();
        });
    </script>
</body>
</html>