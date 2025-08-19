<?php
// admin/login.php - Login de administradores CORRECTO
session_start();

// Si ya está logueado como admin, redirigir al dashboard
if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit();
}

require_once '../config/database.php';
$database = new Database();
$conn = $database->getConnection();

$error = '';
$success = '';

// Procesar formulario de login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Email y contraseña son requeridos';
    } else {
        try {
            // Buscar administrador por email
            $stmt = $conn->prepare("SELECT id, nombre, email, password, rol, activo FROM administradores WHERE email = ?");
            $stmt->execute([$email]);
            $admin = $stmt->fetch();
            
            if ($admin && password_verify($password, $admin['password'])) {
                if ($admin['activo']) {
                    // Login exitoso
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_nombre'] = $admin['nombre'];
                    $_SESSION['admin_email'] = $admin['email'];
                    $_SESSION['admin_rol'] = $admin['rol'];
                    
                    $success = 'Acceso concedido. Redirigiendo...';
                    header('refresh:1;url=dashboard.php');
                } else {
                    $error = 'Cuenta de administrador desactivada';
                }
            } else {
                $error = 'Credenciales incorrectas';
            }
        } catch (Exception $e) {
            $error = 'Error al procesar login: ' . $e->getMessage();
        }
    }
}

// Obtener mensaje de la URL si existe
$mensaje = $_GET['mensaje'] ?? '';
if ($mensaje === 'logout') {
    $success = 'Sesión cerrada correctamente';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Novedades Ashley</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        body {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .admin-login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3);
            overflow: hidden;
            max-width: 450px;
            width: 100%;
        }
        
        .admin-header {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 40px 30px 30px 30px;
            text-align: center;
        }
        
        .admin-body {
            padding: 40px 30px;
        }
        
        .crown-icon {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px auto;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .form-control {
            border-radius: 10px;
            padding: 15px;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .btn-admin-login {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
            border-radius: 10px;
            padding: 15px;
            font-weight: bold;
            transition: all 0.3s ease;
            color: white;
        }
        
        .btn-admin-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
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
        
        .back-to-store {
            position: fixed;
            top: 20px;
            left: 20px;
            color: white;
            text-decoration: none;
            background: rgba(255,255,255,0.1);
            padding: 10px 15px;
            border-radius: 20px;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }
        
        .back-to-store:hover {
            background: rgba(255,255,255,0.2);
            color: white;
        }
        
        .credentials-info {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <a href="../index.php" class="back-to-store">
        <i class="fas fa-arrow-left"></i> Volver a la tienda
    </a>
    
    <div class="admin-login-card">
        <div class="admin-header">
            <div class="crown-icon">
                <i class="fas fa-crown fa-2x"></i>
            </div>
            <h3 class="mb-0">Panel de Administración</h3>
            <p class="mb-0 opacity-75">Novedades Ashley</p>
        </div>
        
        <div class="admin-body">
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
            
            <form method="POST" action="" id="adminLoginForm">
                <div class="mb-3">
                    <label for="email" class="form-label">
                        <i class="fas fa-envelope"></i> Email de Administrador
                    </label>
                    <input type="email" class="form-control" id="email" name="email" 
                           placeholder="admin@novedadesashley.com" required 
                           value="<?= htmlspecialchars($email ?? '') ?>">
                </div>
                
                <div class="mb-4">
                    <label for="password" class="form-label">
                        <i class="fas fa-lock"></i> Contraseña
                    </label>
                    <div class="position-relative">
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder="Contraseña de administrador" required>
                        <button type="button" class="password-toggle" onclick="togglePassword()">
                            <i class="fas fa-eye" id="password-icon"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-admin-login w-100 mb-3">
                    <i class="fas fa-sign-in-alt"></i> Acceder al Panel
                </button>
            </form>
            
            <!-- Información de credenciales por defecto -->
            <div class="credentials-info">
                <h6><i class="fas fa-info-circle"></i> Credenciales por defecto:</h6>
                <p class="mb-1"><strong>Email:</strong> admin@novedadesashley.com</p>
                <p class="mb-1"><strong>Contraseña:</strong> password</p>
                <small class="text-muted">Cambia estas credenciales después del primer acceso</small>
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
        document.getElementById('adminLoginForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            
            if (!email || !password) {
                e.preventDefault();
                alert('Por favor completa todos los campos');
                return;
            }
            
            // Mostrar loading en el botón
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verificando...';
            submitBtn.disabled = true;
            
            // Restaurar si hay error
            setTimeout(() => {
                if (submitBtn.disabled) {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            }, 5000);
        });
        
        // Animación de entrada
        document.addEventListener('DOMContentLoaded', function() {
            const card = document.querySelector('.admin-login-card');
            card.style.transform = 'translateY(30px)';
            card.style.opacity = '0';
            
            setTimeout(() => {
                card.style.transition = 'all 0.6s ease';
                card.style.transform = 'translateY(0)';
                card.style.opacity = '1';
            }, 100);
            
            // Focus en el email
            document.getElementById('email').focus();
        });
    </script>
</body>
</html>