<?php
// api/auth.php - CORREGIDA
// API para manejar autenticación (login, logout, registro)

session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$database = new Database();
$conn = $database->getConnection();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    switch ($action) {
        case 'login':
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $remember = isset($_POST['remember']);
            
            if (empty($email) || empty($password)) {
                throw new Exception('Email y contraseña son requeridos');
            }
            
            // Buscar usuario
            $stmt = $conn->prepare("SELECT id, nombre, email, password, telefono, activo FROM clientes WHERE email = ?");
            $stmt->execute([$email]);
            $usuario = $stmt->fetch();
            
            if (!$usuario) {
                throw new Exception('Usuario no encontrado');
            }
            
            if (!password_verify($password, $usuario['password'])) {
                throw new Exception('Contraseña incorrecta');
            }
            
            if (!$usuario['activo']) {
                throw new Exception('Cuenta desactivada. Contacta al administrador');
            }
            
            // Login exitoso - CORREGIDO: usar nombres de variables consistentes
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_nombre'] = $usuario['nombre'];
            $_SESSION['usuario_email'] = $usuario['email'];
            $_SESSION['usuario_telefono'] = $usuario['telefono'];
            
            // Migrar carrito de sesión a BD si existe
            if (isset($_SESSION['carrito']) && !empty($_SESSION['carrito'])) {
                foreach ($_SESSION['carrito'] as $item) {
                    try {
                        // CORREGIDO: Solo manejar productos por ahora
                        if ($item['tipo'] === 'producto' || isset($item['id_producto'])) {
                            $id_producto = $item['id_producto'] ?? $item['id'];
                            $cantidad = $item['cantidad'];
                            
                            // Verificar si ya existe en carrito BD
                            $stmt_check = $conn->prepare("SELECT cantidad FROM carrito_compras WHERE id_cliente = ? AND id_producto = ?");
                            $stmt_check->execute([$usuario['id'], $id_producto]);
                            $existe = $stmt_check->fetch();
                            
                            if ($existe) {
                                // Actualizar cantidad
                                $stmt = $conn->prepare("UPDATE carrito_compras SET cantidad = cantidad + ? WHERE id_cliente = ? AND id_producto = ?");
                                $stmt->execute([$cantidad, $usuario['id'], $id_producto]);
                            } else {
                                // Insertar nuevo
                                $stmt = $conn->prepare("INSERT INTO carrito_compras (id_cliente, id_producto, cantidad) VALUES (?, ?, ?)");
                                $stmt->execute([$usuario['id'], $id_producto, $cantidad]);
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Error migrando item del carrito: " . $e->getMessage());
                    }
                }
                // Limpiar carrito de sesión
                unset($_SESSION['carrito']);
            }
            
            // Si seleccionó "recordarme", crear cookie (opcional)
            if ($remember) {
                $token = bin2hex(random_bytes(32));
                setcookie('remember_token', $token, time() + (86400 * 30), '/'); // 30 días
                
                // Aquí podrías guardar el token en BD si implementas tabla remember_tokens
            }
            
            $response['success'] = true;
            $response['message'] = 'Login exitoso';
            $response['data'] = [
                'usuario' => [
                    'id' => $usuario['id'],
                    'nombre' => $usuario['nombre'],
                    'email' => $usuario['email']
                ]
            ];
            break;
            
        case 'logout':
            // Limpiar sesión
            session_unset();
            session_destroy();
            
            // Limpiar cookie de recordar
            if (isset($_COOKIE['remember_token'])) {
                setcookie('remember_token', '', time() - 3600, '/');
            }
            
            $response['success'] = true;
            $response['message'] = 'Sesión cerrada correctamente';
            break;
            
        case 'register':
            $nombre = trim($_POST['nombre'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            $telefono = trim($_POST['telefono'] ?? '');
            $direccion = trim($_POST['direccion'] ?? '');
            
            // Validaciones
            if (empty($nombre) || empty($email) || empty($password)) {
                throw new Exception('Nombre, email y contraseña son requeridos');
            }
            
            if (strlen($password) < 6) {
                throw new Exception('La contraseña debe tener al menos 6 caracteres');
            }
            
            if ($password !== $confirm_password) {
                throw new Exception('Las contraseñas no coinciden');
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Email no válido');
            }
            
            // Verificar que el email no existe
            $stmt = $conn->prepare("SELECT id FROM clientes WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                throw new Exception('Este email ya está registrado');
            }
            
            // Crear usuario
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $conn->prepare("
                INSERT INTO clientes (nombre, email, password, telefono, direccion, activo) 
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            
            $stmt->execute([$nombre, $email, $password_hash, $telefono, $direccion]);
            $nuevo_id = $conn->lastInsertId();
            
            // Auto-login después del registro - CORREGIDO: variables consistentes
            $_SESSION['usuario_id'] = $nuevo_id;
            $_SESSION['usuario_nombre'] = $nombre;
            $_SESSION['usuario_email'] = $email;
            $_SESSION['usuario_telefono'] = $telefono;
            
            // Migrar carrito si existe
            if (isset($_SESSION['carrito']) && !empty($_SESSION['carrito'])) {
                foreach ($_SESSION['carrito'] as $item) {
                    try {
                        // CORREGIDO: Solo productos por ahora
                        if ($item['tipo'] === 'producto' || isset($item['id_producto'])) {
                            $id_producto = $item['id_producto'] ?? $item['id'];
                            $cantidad = $item['cantidad'];
                            
                            $stmt = $conn->prepare("INSERT INTO carrito_compras (id_cliente, id_producto, cantidad) VALUES (?, ?, ?)");
                            $stmt->execute([$nuevo_id, $id_producto, $cantidad]);
                        }
                    } catch (Exception $e) {
                        error_log("Error migrando carrito en registro: " . $e->getMessage());
                    }
                }
                unset($_SESSION['carrito']);
            }
            
            $response['success'] = true;
            $response['message'] = 'Cuenta creada exitosamente';
            $response['data'] = [
                'usuario' => [
                    'id' => $nuevo_id,
                    'nombre' => $nombre,
                    'email' => $email
                ]
            ];
            break;
            
        case 'check_session':
            // Verificar si hay sesión activa
            if (isset($_SESSION['usuario_id'])) {
                $response['success'] = true;
                $response['data'] = [
                    'logueado' => true,
                    'usuario' => [
                        'id' => $_SESSION['usuario_id'],
                        'nombre' => $_SESSION['usuario_nombre'],
                        'email' => $_SESSION['usuario_email']
                    ]
                ];
            } else {
                $response['success'] = true;
                $response['data'] = ['logueado' => false];
            }
            break;
            
        case 'update_profile':
            // Actualizar perfil
            if (!isset($_SESSION['usuario_id'])) {
                throw new Exception('Debes estar logueado');
            }
            
            $nombre = trim($_POST['nombre'] ?? '');
            $telefono = trim($_POST['telefono'] ?? '');
            $direccion = trim($_POST['direccion'] ?? '');
            
            if (empty($nombre)) {
                throw new Exception('El nombre es requerido');
            }
            
            $stmt = $conn->prepare("UPDATE clientes SET nombre = ?, telefono = ?, direccion = ? WHERE id = ?");
            $stmt->execute([$nombre, $telefono, $direccion, $_SESSION['usuario_id']]);
            
            // Actualizar datos en sesión
            $_SESSION['usuario_nombre'] = $nombre;
            $_SESSION['usuario_telefono'] = $telefono;
            
            $response['success'] = true;
            $response['message'] = 'Perfil actualizado correctamente';
            break;
            
        default:
            throw new Exception('Acción no válida');
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log("Error en auth API: " . $e->getMessage());
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>