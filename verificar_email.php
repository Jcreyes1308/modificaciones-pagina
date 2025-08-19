<?php
// verificar_email.php - Página para verificar email después del registro
session_start();

// Verificar que el usuario esté logueado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

// Verificar si el usuario ya tiene el email verificado
$stmt = $conn->prepare("SELECT email_verified, email FROM clientes WHERE id = ?");
$stmt->execute([$_SESSION['usuario_id']]);
$usuario = $stmt->fetch();

if ($usuario['email_verified']) {
    // Ya está verificado, redirigir
    header('Location: index.php?mensaje=email_ya_verificado');
    exit();
}

$first_time = isset($_GET['first_time']); // Viene del registro
$masked_email = maskEmail($usuario['email']);

function maskEmail($email) {
    if (empty($email)) return '';
    $at_pos = strpos($email, '@');
    if ($at_pos === false) return '***';
    $local = substr($email, 0, $at_pos);
    $domain = substr($email, $at_pos);
    if (strlen($local) <= 2) return '*' . $domain;
    return substr($local, 0, 2) . str_repeat('*', strlen($local) - 2) . $domain;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificar Email - Novedades Ashley</title>
    
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
            background: linear-gradient(45deg, #667eea, #764ba2);
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
            font-size: 1.5rem;
            font-weight: bold;
            letter-spacing: 0.5rem;
            border: 3px solid #e9ecef;
            border-radius: 15px;
            padding: 20px;
            margin: 20px 0;
            transition: all 0.3s ease;
        }
        
        .code-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
            transform: scale(1.02);
        }
        
        .btn-verify {
            background: linear-gradient(45deg, #667eea, #764ba2);
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
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
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
            background: #667eea;
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
                font-size: 1.2rem;
                letter-spacing: 0.3rem;
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
                <i class="fas fa-envelope"></i>
            </div>
            <h2 class="mb-3">Verificar tu Email</h2>
            <?php if ($first_time): ?>
                <p class="mb-0">¡Bienvenido <?= htmlspecialchars($_SESSION['usuario_nombre']) ?>!</p>
                <small>Solo falta un paso más...</small>
            <?php else: ?>
                <p class="mb-0">Confirma tu dirección de correo</p>
            <?php endif; ?>
        </div>
        
        <!-- Body -->
        <div class="verify-body">
            <?php if ($first_time): ?>
                <div class="alert alert-success" role="alert">
                    <i class="fas fa-check-circle"></i>
                    <strong>¡Cuenta creada exitosamente!</strong><br>
                    Te hemos enviado un código de verificación.
                </div>
            <?php endif; ?>
            
            <div class="email-display">
                <i class="fas fa-envelope me-2 text-primary"></i>
                <strong>Código enviado a:</strong><br>
                <span class="text-primary"><?= $masked_email ?></span>
            </div>
            
            <form id="verifyForm">
                <div class="mb-4">
                    <label for="verification-code" class="form-label text-center w-100">
                        <strong>Ingresa el código de 6 dígitos:</strong>
                    </label>
                    <input type="text" class="form-control code-input" id="verification-code" 
                           name="code" maxlength="6" placeholder="000000" autocomplete="off">
                    <small class="text-muted d-block text-center mt-2">
                        El código expira en <span id="countdown">15:00</span> minutos
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
                
                <button type="submit" class="btn btn-verify">
                    <i class="fas fa-check-circle"></i> Verificar Email
                </button>
            </form>
            
            <div id="message-container" class="mt-3"></div>
            
            <!-- Botones de acción -->
            <div class="text-center mt-4">
                <p class="text-muted mb-3">¿No recibiste el código?</p>
                
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-resend" id="resend-btn" onclick="resendCode()">
                        <i class="fas fa-redo"></i> Reenviar Código
                    </button>
                </div>
                
                <div class="mt-3">
                    <small class="text-muted">
                        Revisa tu carpeta de spam o correo no deseado
                    </small>
                </div>
            </div>
            
            <!-- Sección para continuar sin verificar -->
            <?php if (!$first_time): ?>
                <div class="text-center mt-4 pt-3 border-top">
                    <a href="index.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-arrow-left"></i> Continuar sin verificar
                    </a>
                </div>
            <?php else: ?>
                <div class="text-center mt-4 pt-3 border-top">
                    <p class="text-muted small">
                        <i class="fas fa-info-circle"></i>
                        La verificación te permite recibir notificaciones importantes
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        let countdownInterval;
        let resendCooldown = 0;
        
        // Inicializar página
        document.addEventListener('DOMContentLoaded', function() {
            startCountdown(15 * 60); // 15 minutos
            setupCodeInput();
            
            // Focus automático en el input
            document.getElementById('verification-code').focus();
        });
        
        // Configurar input del código
        function setupCodeInput() {
            const codeInput = document.getElementById('verification-code');
            
            codeInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                e.target.value = value.substring(0, 6);
                
                // Actualizar puntos de progreso
                updateProgressDots(value.length);
                
                // Auto-verificar cuando tenga 6 dígitos
                if (value.length === 6) {
                    setTimeout(() => verifyCode(), 500);
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
        
        // Manejar formulario
        document.getElementById('verifyForm').addEventListener('submit', function(e) {
            e.preventDefault();
            verifyCode();
        });
        
        // Verificar código
        async function verifyCode() {
            const code = document.getElementById('verification-code').value.trim();
            const submitBtn = document.querySelector('.btn-verify');
            const originalText = submitBtn.innerHTML;
            
            if (!code || code.length !== 6) {
                showMessage('Ingresa un código de 6 dígitos', 'error');
                shakeInput();
                return;
            }
            
            try {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verificando...';
                submitBtn.disabled = true;
                
                const formData = new FormData();
                formData.append('action', 'verify_email');
                formData.append('code', code);
                
                const response = await fetch('api/verification.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Éxito - Mostrar animación y redirigir
                    showSuccessAnimation();
                    showMessage('¡Email verificado correctamente! 🎉', 'success');
                    
                    setTimeout(() => {
                        window.location.href = 'index.php?mensaje=email_verificado';
                    }, 2500);
                    
                } else {
                    showMessage(data.message, 'error');
                    shakeInput();
                    document.getElementById('verification-code').value = '';
                    updateProgressDots(0);
                }
                
            } catch (error) {
                console.error('Error:', error);
                showMessage('Error de conexión. Intenta nuevamente.', 'error');
                shakeInput();
            } finally {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        }
        
        // Reenviar código
        async function resendCode() {
            if (resendCooldown > 0) {
                showMessage(`Espera ${resendCooldown} segundos antes de reenviar`, 'warning');
                return;
            }
            
            const resendBtn = document.getElementById('resend-btn');
            const originalText = resendBtn.innerHTML;
            
            try {
                resendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Reenviando...';
                resendBtn.disabled = true;
                
                const formData = new FormData();
                formData.append('action', 'resend_code');
                formData.append('type', 'email_verification');
                
                const response = await fetch('api/verification.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showMessage('Código reenviado correctamente 📧', 'success');
                    startResendCooldown(120); // 2 minutos
                    startCountdown(15 * 60); // Reiniciar countdown
                    
                    // Limpiar código anterior
                    document.getElementById('verification-code').value = '';
                    updateProgressDots(0);
                    
                } else {
                    showMessage(data.message, 'error');
                }
                
            } catch (error) {
                console.error('Error:', error);
                showMessage('Error al reenviar. Intenta más tarde.', 'error');
            } finally {
                resendBtn.innerHTML = originalText;
                resendBtn.disabled = false;
            }
        }
        
        // Countdown del código
        function startCountdown(seconds) {
            clearInterval(countdownInterval);
            
            countdownInterval = setInterval(() => {
                const minutes = Math.floor(seconds / 60);
                const remainingSeconds = seconds % 60;
                
                const display = `${minutes.toString().padStart(2, '0')}:${remainingSeconds.toString().padStart(2, '0')}`;
                document.getElementById('countdown').textContent = display;
                
                if (seconds <= 0) {
                    clearInterval(countdownInterval);
                    showMessage('El código ha expirado. Solicita uno nuevo.', 'warning');
                    document.getElementById('countdown').textContent = '00:00';
                }
                
                seconds--;
            }, 1000);
        }
        
        // Cooldown para reenvío
        function startResendCooldown(seconds) {
            resendCooldown = seconds;
            const resendBtn = document.getElementById('resend-btn');
            
            const updateCooldown = () => {
                if (resendCooldown > 0) {
                    resendBtn.innerHTML = `<i class="fas fa-clock"></i> Espera ${resendCooldown}s`;
                    resendBtn.disabled = true;
                    resendCooldown--;
                    setTimeout(updateCooldown, 1000);
                } else {
                    resendBtn.innerHTML = '<i class="fas fa-redo"></i> Reenviar Código';
                    resendBtn.disabled = false;
                }
            };
            
            updateCooldown();
        }
        
        // Mostrar mensajes
        function showMessage(message, type) {
            const container = document.getElementById('message-container');
            const alertClass = type === 'success' ? 'alert-success' : 
                               type === 'error' ? 'alert-danger' : 
                               type === 'warning' ? 'alert-warning' : 'alert-info';
            
            const icon = type === 'success' ? 'fa-check-circle' : 
                         type === 'error' ? 'fa-exclamation-triangle' : 
                         type === 'warning' ? 'fa-exclamation-circle' : 'fa-info-circle';
            
            container.innerHTML = `
                <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                    <i class="fas ${icon}"></i> ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
        }
        
        // Animación de éxito
        function showSuccessAnimation() {
            const container = document.querySelector('.verify-container');
            container.classList.add('success-animation');
            
            // Cambiar icono a check
            const icon = document.querySelector('.verify-icon i');
            icon.className = 'fas fa-check-circle';
            
            // Cambiar color del header
            const header = document.querySelector('.verify-header');
            header.style.background = 'linear-gradient(45deg, #28a745, #20c997)';
        }
        
        // Animación de error
        function shakeInput() {
            const input = document.getElementById('verification-code');
            input.classList.add('shake-animation');
            setTimeout(() => {
                input.classList.remove('shake-animation');
            }, 600);
        }
    </script>
</body>
</html>