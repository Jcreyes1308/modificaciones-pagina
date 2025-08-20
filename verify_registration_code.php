<?php
// verify_registration_code.php - Verificar código de 6 dígitos
session_start();

require_once 'config/database.php';
require_once 'config/verification.php';

$database = new Database();
$conn = $database->getConnection();

$email = $_GET['email'] ?? '';
$error = '';
$success = '';

if (empty($email)) {
    header('Location: registro.php');
    exit();
}

// Procesar verificación del código
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codigo = trim($_POST['codigo'] ?? '');
    
    if (empty($codigo)) {
        $error = 'Código requerido';
    } elseif (strlen($codigo) !== 6 || !ctype_digit($codigo)) {
        $error = 'El código debe ser de 6 dígitos numéricos';
    } else {
        try {
            // Buscar registro pendiente con este código
            $stmt = $conn->prepare("
                SELECT * FROM pending_registrations 
                WHERE email = ? AND verification_token = ? 
                AND expires_at > NOW()
            ");
            $stmt->execute([$email, $codigo]);
            $pending_user = $stmt->fetch();
            
            if (!$pending_user) {
                $error = 'Código inválido o expirado';
            } else {
                // Verificar que el email no exista ya en clientes
                $stmt = $conn->prepare("SELECT id FROM clientes WHERE email = ?");
                $stmt->execute([$pending_user['email']]);
                if ($stmt->fetch()) {
                    $error = 'Esta cuenta ya existe y está verificada';
                } else {
                    // Crear cuenta real en clientes
                    $stmt = $conn->prepare("
                        INSERT INTO clientes (nombre, email, password, telefono, direccion, email_verified, activo) 
                        VALUES (?, ?, ?, ?, ?, 1, 1)
                    ");
                    $stmt->execute([
                        $pending_user['nombre'], 
                        $pending_user['email'], 
                        $pending_user['password_hash'], 
                        $pending_user['telefono'], 
                        $pending_user['direccion']
                    ]);
                    
                    $nuevo_id = $conn->lastInsertId();
                    
                    // Eliminar de pending_registrations
                    $stmt = $conn->prepare("DELETE FROM pending_registrations WHERE id = ?");
                    $stmt->execute([$pending_user['id']]);
                    
                    // AUTO-LOGIN después de verificación exitosa
                    $_SESSION['usuario_id'] = $nuevo_id;
                    $_SESSION['usuario_nombre'] = $pending_user['nombre'];
                    $_SESSION['usuario_email'] = $pending_user['email'];
                    $_SESSION['usuario_telefono'] = $pending_user['telefono'];
                    
                    $success = 'Cuenta verificada y creada exitosamente. ¡Bienvenido!';
                    
                    // Redirigir después de 3 segundos
                    echo '<script>
                        setTimeout(function() {
                            window.location.href = "index.php?mensaje=cuenta_verificada";
                        }, 3000);
                    </script>';
                }
            }
        } catch (Exception $e) {
            $error = 'Error al verificar: ' . $e->getMessage();
            error_log("Error en verificación: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificar Código - Novedades Ashley</title>
    
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
        
        .verify-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            overflow: hidden;
            max-width: 500px;
            width: 100%;
            margin: 0 auto;
            position: relative;
        }
        
        .verify-header {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        
        .verify-icon {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px auto;
            font-size: 2.5rem;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        .verify-body {
            padding: 40px 30px;
        }
        
        .code-input {
            text-align: center;
            font-size: 2rem;
            font-weight: bold;
            letter-spacing: 1rem;
            border: 3px solid #e9ecef;
            border-radius: 15px;
            padding: 20px;
            margin: 20px 0;
            transition: all 0.3s ease;
            font-family: monospace;
        }
        
        .code-input:focus {
            border-color: #28a745;
            box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
            transform: scale(1.02);
        }
        
        .btn-verify {
            background: linear-gradient(45deg, #28a745, #20c997);
            border: none;
            border-radius: 15px;
            padding: 15px;
            font-weight: bold;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            width: 100%;
            color: white;
        }
        
        .btn-verify:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(40, 167, 69, 0.4);
            color: white;
        }
        
        .btn-resend {
            background: none;
            border: 2px solid #667eea;
            color: #667eea;
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .btn-resend:hover {
            background: #667eea;
            color: white;
        }
        
        .email-display {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin: 20px 0;
            text-align: center;
            border: 2px dashed #dee2e6;
        }
        
        .progress-dots {
            display: flex;
            justify-content: center;
            margin: 20px 0;
        }
        
        .progress-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #dee2e6;
            margin: 0 5px;
            transition: all 0.3s ease;
        }
        
        .progress-dot.active {
            background: #28a745;
            transform: scale(1.2);
        }
        
        .success-animation {
            animation: bounceIn 0.6s ease;
        }
        
        .shake-animation {
            animation: shake 0.6s ease;
        }
        
        @keyframes bounceIn {
            0% { transform: scale(0.3); opacity: 0; }
            50% { transform: scale(1.05); }
            70% { transform: scale(0.9); }
            100% { transform: scale(1); opacity: 1; }
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-10px); }
            20%, 40%, 60%, 80% { transform: translateX(10px); }
        }
        
        @media (max-width: 768px) {
            .verify-container {
                margin: 20px;
                border-radius: 15px;
            }
            
            .verify-header, .verify-body {
                padding: 30px 20px;
            }
            
            .code-input {
                font-size: 1.5rem;
                letter-spacing: 0.5rem;
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="verify-container">
        <!-- Header -->
        <div class="verify-header">
            <div class="verify-icon">
                <i class="fas fa-key"></i>
            </div>
            <h2 class="mb-3">Verificar tu Código</h2>
            <p class="mb-0">Ingresa el código de 6 dígitos</p>
        </div>
        
        <!-- Body -->
        <div class="verify-body">
            <div class="email-display">
                <i class="fas fa-envelope me-2 text-success"></i>
                <strong>Código enviado a:</strong><br>
                <span class="text-success"><?= maskEmail($email) ?></span>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success success-animation" role="alert">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
                    <br><small>Redirigiendo...</small>
                </div>
            <?php endif; ?>
            
            <form method="POST" id="verifyForm">
                <div class="mb-4">
                    <label for="codigo" class="form-label text-center w-100">
                        <strong>Código de 6 dígitos:</strong>
                    </label>
                    <input type="text" class="form-control code-input" id="codigo" 
                           name="codigo" maxlength="6" placeholder="000000" 
                           autocomplete="off" pattern="[0-9]{6}" inputmode="numeric">
                    <small class="text-muted d-block text-center mt-2">
                        El código expira en 24 horas
                    </small>
                </div>
                
                <div class="progress-dots">
                    <div class="progress-dot" id="dot-1"></div>
                    <div class="progress-dot" id="dot-2"></div>
                    <div class="progress-dot" id="dot-3"></div>
                    <div class="progress-dot" id="dot-4"></div>
                    <div class="progress-dot" id="dot-5"></div>
                    <div class="progress-dot" id="dot-6"></div>
                </div>
                
                <button type="submit" class="btn btn-verify" id="verifyBtn">
                    <i class="fas fa-check-circle"></i> Verificar Cuenta
                </button>
            </form>
            
            <!-- Botones de acción -->
            <div class="text-center mt-4">
                <p class="text-muted mb-3">¿No recibiste el código?</p>
                
                <div class="d-grid gap-2">
                    <a href="registro.php" class="btn btn-resend">
                        <i class="fas fa-redo"></i> Intentar de Nuevo
                    </a>
                </div>
                
                <div class="mt-3">
                    <small class="text-muted">
                        Revisa tu carpeta de spam o correo no deseado
                    </small>
                </div>
            </div>
            
            <div class="text-center mt-4 pt-3 border-top">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> Volver al inicio
                </a>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Configurar input del código
        document.addEventListener('DOMContentLoaded', function() {
            setupCodeInput();
            
            // Focus automático en el input
            document.getElementById('codigo').focus();
        });
        
        function setupCodeInput() {
            const codeInput = document.getElementById('codigo');
            
            codeInput.addEventListener('input', function(e) {
                // Solo permitir números
                let value = e.target.value.replace(/\D/g, '');
                e.target.value = value.substring(0, 6);
                
                // Actualizar puntos de progreso
                updateProgressDots(value.length);
                
                // Auto-verificar cuando tenga 6 dígitos
                if (value.length === 6) {
                    setTimeout(() => {
                        document.getElementById('verifyForm').submit();
                    }, 500);
                }
            });
            
            // Permitir pegar código
            codeInput.addEventListener('paste', function(e) {
                e.preventDefault();
                const paste = (e.clipboardData || window.clipboardData).getData('text');
                const numbers = paste.replace(/\D/g, '').substring(0, 6);
                codeInput.value = numbers;
                updateProgressDots(numbers.length);
                
                if (numbers.length === 6) {
                    setTimeout(() => {
                        document.getElementById('verifyForm').submit();
                    }, 500);
                }
            });
        }
        
        // Actualizar puntos de progreso
        function updateProgressDots(count) {
            for (let i = 1; i <= 6; i++) {
                const dot = document.getElementById(`dot-${i}`);
                if (i <= count) {
                    dot.classList.add('active');
                } else {
                    dot.classList.remove('active');
                }
            }
        }
        
        // Manejar envío del formulario
        document.getElementById('verifyForm').addEventListener('submit', function(e) {
            const codigo = document.getElementById('codigo').value.trim();
            const submitBtn = document.getElementById('verifyBtn');
            
            if (!codigo || codigo.length !== 6) {
                e.preventDefault();
                alert('Ingresa un código de 6 dígitos');
                shakeInput();
                return;
            }
            
            // Mostrar loading
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verificando...';
            submitBtn.disabled = true;
            
            // Si hay error, restaurar después de 5 segundos
            setTimeout(() => {
                if (submitBtn.disabled) {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            }, 5000);
        });
        
        // Animación de error
        function shakeInput() {
            const input = document.getElementById('codigo');
            input.classList.add('shake-animation');
            setTimeout(() => {
                input.classList.remove('shake-animation');
            }, 600);
        }
        
        // Animación de entrada
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.querySelector('.verify-container');
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