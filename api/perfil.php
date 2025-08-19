<?php
// api/perfil.php - API para gestión de perfil, métodos de pago y direcciones
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// Verificar que el usuario esté logueado
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'Usuario no autenticado']);
    exit;
}

$database = new Database();
$conn = $database->getConnection();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    switch ($action) {
        case 'get_payment_methods':
            // Obtener métodos de pago del usuario
            $stmt = $conn->prepare("
                SELECT id, tipo, nombre_tarjeta, ultimos_4_digitos, mes_expiracion, 
                       ano_expiracion, nombre_titular, banco_emisor, es_principal, created_at
                FROM metodos_pago 
                WHERE id_cliente = ? AND activo = 1 
                ORDER BY es_principal DESC, created_at DESC
            ");
            $stmt->execute([$_SESSION['usuario_id']]);
            $metodos = $stmt->fetchAll();
            
            $response['success'] = true;
            $response['data'] = $metodos;
            break;
            
        case 'add_payment_method':
            $tipo = $_POST['tipo_tarjeta'] ?? 'tarjeta_credito';
            $numero_tarjeta = preg_replace('/\s+/', '', $_POST['numero_tarjeta'] ?? '');
            $mes_exp = $_POST['mes_expiracion'] ?? '';
            $ano_exp = $_POST['ano_expiracion'] ?? '';
            $nombre_titular = trim($_POST['nombre_titular'] ?? '');
            $banco = trim($_POST['banco_emisor'] ?? '');
            $es_principal = isset($_POST['es_principal']) ? 1 : 0;
            
            // Validaciones
            if (empty($numero_tarjeta) || empty($mes_exp) || empty($ano_exp) || empty($nombre_titular)) {
                throw new Exception('Todos los campos de la tarjeta son requeridos');
            }
            
            if (strlen($numero_tarjeta) < 13 || strlen($numero_tarjeta) > 19) {
                throw new Exception('Número de tarjeta inválido');
            }
            
            // Validar que no sea una fecha pasada
            $fecha_exp = mktime(0, 0, 0, $mes_exp, 1, $ano_exp);
            if ($fecha_exp < time()) {
                throw new Exception('La tarjeta está vencida');
            }
            
            // Detectar tipo de tarjeta
            $nombre_tarjeta = '';
            if (preg_match('/^4/', $numero_tarjeta)) {
                $nombre_tarjeta = 'Visa';
            } elseif (preg_match('/^5[1-5]/', $numero_tarjeta)) {
                $nombre_tarjeta = 'Mastercard';
            } elseif (preg_match('/^3[47]/', $numero_tarjeta)) {
                $nombre_tarjeta = 'American Express';
            } else {
                $nombre_tarjeta = 'Tarjeta';
            }
            
            $ultimos_4 = substr($numero_tarjeta, -4);
            
            // Verificar que no esté duplicada
            $stmt = $conn->prepare("
                SELECT id FROM metodos_pago 
                WHERE id_cliente = ? AND ultimos_4_digitos = ? AND nombre_titular = ? AND activo = 1
            ");
            $stmt->execute([$_SESSION['usuario_id'], $ultimos_4, $nombre_titular]);
            if ($stmt->fetch()) {
                throw new Exception('Ya tienes una tarjeta registrada con estos datos');
            }
            
            // Si es principal, quitar principal de otros
            if ($es_principal) {
                $stmt = $conn->prepare("UPDATE metodos_pago SET es_principal = 0 WHERE id_cliente = ?");
                $stmt->execute([$_SESSION['usuario_id']]);
            }
            
            // Insertar nueva tarjeta (en producción, encriptar el número completo)
            $stmt = $conn->prepare("
                INSERT INTO metodos_pago 
                (id_cliente, tipo, nombre_tarjeta, ultimos_4_digitos, mes_expiracion, ano_expiracion, nombre_titular, banco_emisor, es_principal) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['usuario_id'], $tipo, $nombre_tarjeta, $ultimos_4, 
                $mes_exp, $ano_exp, $nombre_titular, $banco, $es_principal
            ]);
            
            $response['success'] = true;
            $response['message'] = 'Método de pago agregado correctamente';
            $response['data'] = ['id' => $conn->lastInsertId()];
            break;
            
        case 'delete_payment_method':
            $id_metodo = intval($_POST['id_metodo'] ?? 0);
            
            if (!$id_metodo) {
                throw new Exception('ID de método de pago requerido');
            }
            
            // Verificar que pertenece al usuario
            $stmt = $conn->prepare("
                SELECT id FROM metodos_pago 
                WHERE id = ? AND id_cliente = ? AND activo = 1
            ");
            $stmt->execute([$id_metodo, $_SESSION['usuario_id']]);
            if (!$stmt->fetch()) {
                throw new Exception('Método de pago no encontrado');
            }
            
            // Quitar principal de todos
            $stmt = $conn->prepare("UPDATE metodos_pago SET es_principal = 0 WHERE id_cliente = ?");
            $stmt->execute([$_SESSION['usuario_id']]);
            
            // Establecer como principal
            $stmt = $conn->prepare("UPDATE metodos_pago SET es_principal = 1 WHERE id = ? AND id_cliente = ?");
            $stmt->execute([$id_metodo, $_SESSION['usuario_id']]);
            
            $response['success'] = true;
            $response['message'] = 'Método de pago principal actualizado';
            break;
            
        case 'get_addresses':
            // Obtener direcciones del usuario
            $stmt = $conn->prepare("
                SELECT id, nombre_direccion, nombre_destinatario, telefono_contacto, 
                       calle_numero, colonia, ciudad, estado, codigo_postal, 
                       referencias, es_principal, created_at
                FROM direcciones_envio 
                WHERE id_cliente = ? AND activo = 1 
                ORDER BY es_principal DESC, created_at DESC
            ");
            $stmt->execute([$_SESSION['usuario_id']]);
            $direcciones = $stmt->fetchAll();
            
            $response['success'] = true;
            $response['data'] = $direcciones;
            break;
            
        case 'add_address':
            $nombre_direccion = trim($_POST['nombre_direccion'] ?? '');
            $nombre_destinatario = trim($_POST['nombre_destinatario'] ?? '');
            $telefono_contacto = trim($_POST['telefono_contacto'] ?? '');
            $calle_numero = trim($_POST['calle_numero'] ?? '');
            $colonia = trim($_POST['colonia'] ?? '');
            $ciudad = trim($_POST['ciudad'] ?? '');
            $estado = trim($_POST['estado'] ?? '');
            $codigo_postal = trim($_POST['codigo_postal'] ?? '');
            $referencias = trim($_POST['referencias'] ?? '');
            $es_principal = isset($_POST['es_principal']) ? 1 : 0;
            
            // Validaciones
            if (empty($nombre_direccion) || empty($nombre_destinatario) || empty($calle_numero) || empty($ciudad) || empty($estado)) {
                throw new Exception('Los campos marcados son requeridos');
            }
            
            // Validar código postal si se proporciona
            if ($codigo_postal && !preg_match('/^\d{5}$/', $codigo_postal)) {
                throw new Exception('Código postal debe tener 5 dígitos');
            }
            
            // Si es principal, quitar principal de otras
            if ($es_principal) {
                $stmt = $conn->prepare("UPDATE direcciones_envio SET es_principal = 0 WHERE id_cliente = ?");
                $stmt->execute([$_SESSION['usuario_id']]);
            }
            
            // Insertar nueva dirección
            $stmt = $conn->prepare("
                INSERT INTO direcciones_envio 
                (id_cliente, nombre_direccion, nombre_destinatario, telefono_contacto, calle_numero, 
                 colonia, ciudad, estado, codigo_postal, referencias, es_principal) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['usuario_id'], $nombre_direccion, $nombre_destinatario, $telefono_contacto,
                $calle_numero, $colonia, $ciudad, $estado, $codigo_postal, $referencias, $es_principal
            ]);
            
            $response['success'] = true;
            $response['message'] = 'Dirección agregada correctamente';
            $response['data'] = ['id' => $conn->lastInsertId()];
            break;
            
        case 'update_address':
            $id_direccion = intval($_POST['id_direccion'] ?? 0);
            $nombre_direccion = trim($_POST['nombre_direccion'] ?? '');
            $nombre_destinatario = trim($_POST['nombre_destinatario'] ?? '');
            $telefono_contacto = trim($_POST['telefono_contacto'] ?? '');
            $calle_numero = trim($_POST['calle_numero'] ?? '');
            $colonia = trim($_POST['colonia'] ?? '');
            $ciudad = trim($_POST['ciudad'] ?? '');
            $estado = trim($_POST['estado'] ?? '');
            $codigo_postal = trim($_POST['codigo_postal'] ?? '');
            $referencias = trim($_POST['referencias'] ?? '');
            $es_principal = isset($_POST['es_principal']) ? 1 : 0;
            
            if (!$id_direccion) {
                throw new Exception('ID de dirección requerido');
            }
            
            // Verificar que pertenece al usuario
            $stmt = $conn->prepare("
                SELECT id FROM direcciones_envio 
                WHERE id = ? AND id_cliente = ? AND activo = 1
            ");
            $stmt->execute([$id_direccion, $_SESSION['usuario_id']]);
            if (!$stmt->fetch()) {
                throw new Exception('Dirección no encontrada');
            }
            
            // Validaciones
            if (empty($nombre_direccion) || empty($nombre_destinatario) || empty($calle_numero) || empty($ciudad) || empty($estado)) {
                throw new Exception('Los campos marcados son requeridos');
            }
            
            // Si es principal, quitar principal de otras
            if ($es_principal) {
                $stmt = $conn->prepare("UPDATE direcciones_envio SET es_principal = 0 WHERE id_cliente = ? AND id != ?");
                $stmt->execute([$_SESSION['usuario_id'], $id_direccion]);
            }
            
            // Actualizar dirección
            $stmt = $conn->prepare("
                UPDATE direcciones_envio SET 
                nombre_direccion = ?, nombre_destinatario = ?, telefono_contacto = ?, 
                calle_numero = ?, colonia = ?, ciudad = ?, estado = ?, codigo_postal = ?, 
                referencias = ?, es_principal = ?, updated_at = NOW()
                WHERE id = ? AND id_cliente = ?
            ");
            $stmt->execute([
                $nombre_direccion, $nombre_destinatario, $telefono_contacto, $calle_numero,
                $colonia, $ciudad, $estado, $codigo_postal, $referencias, $es_principal,
                $id_direccion, $_SESSION['usuario_id']
            ]);
            
            $response['success'] = true;
            $response['message'] = 'Dirección actualizada correctamente';
            break;
            
        case 'delete_address':
            $id_direccion = intval($_POST['id_direccion'] ?? 0);
            
            if (!$id_direccion) {
                throw new Exception('ID de dirección requerido');
            }
            
            // Verificar que pertenece al usuario
            $stmt = $conn->prepare("
                SELECT id FROM direcciones_envio 
                WHERE id = ? AND id_cliente = ? AND activo = 1
            ");
            $stmt->execute([$id_direccion, $_SESSION['usuario_id']]);
            if (!$stmt->fetch()) {
                throw new Exception('Dirección no encontrada');
            }
            
            // Eliminar (soft delete)
            $stmt = $conn->prepare("UPDATE direcciones_envio SET activo = 0 WHERE id = ? AND id_cliente = ?");
            $stmt->execute([$id_direccion, $_SESSION['usuario_id']]);
            
            $response['success'] = true;
            $response['message'] = 'Dirección eliminada';
            break;
            
        case 'set_primary_address':
            $id_direccion = intval($_POST['id_direccion'] ?? 0);
            
            if (!$id_direccion) {
                throw new Exception('ID de dirección requerido');
            }
            
            // Verificar que pertenece al usuario
            $stmt = $conn->prepare("
                SELECT id FROM direcciones_envio 
                WHERE id = ? AND id_cliente = ? AND activo = 1
            ");
            $stmt->execute([$id_direccion, $_SESSION['usuario_id']]);
            if (!$stmt->fetch()) {
                throw new Exception('Dirección no encontrada');
            }
            
            // Quitar principal de todas
            $stmt = $conn->prepare("UPDATE direcciones_envio SET es_principal = 0 WHERE id_cliente = ?");
            $stmt->execute([$_SESSION['usuario_id']]);
            
            // Establecer como principal
            $stmt = $conn->prepare("UPDATE direcciones_envio SET es_principal = 1 WHERE id = ? AND id_cliente = ?");
            $stmt->execute([$id_direccion, $_SESSION['usuario_id']]);
            
            $response['success'] = true;
            $response['message'] = 'Dirección principal actualizada';
            break;
            
        case 'get_user_stats':
            // Obtener estadísticas del usuario
            $stats = [];
            
            // Items en carrito
            $stmt = $conn->prepare("SELECT COALESCE(SUM(cantidad), 0) as total FROM carrito_compras WHERE id_cliente = ?");
            $stmt->execute([$_SESSION['usuario_id']]);
            $result = $stmt->fetch();
            $stats['items_carrito'] = intval($result['total']);
            
            // Contar pedidos (cuando se implemente)
            $stats['pedidos_total'] = 0;
            $stats['pedidos_pendientes'] = 0;
            $stats['total_gastado'] = 0;
            
            // Contar métodos de pago
            $stmt = $conn->prepare("SELECT COUNT(*) as total FROM metodos_pago WHERE id_cliente = ? AND activo = 1");
            $stmt->execute([$_SESSION['usuario_id']]);
            $result = $stmt->fetch();
            $stats['metodos_pago'] = intval($result['total']);
            
            // Contar direcciones
            $stmt = $conn->prepare("SELECT COUNT(*) as total FROM direcciones_envio WHERE id_cliente = ? AND activo = 1");
            $stmt->execute([$_SESSION['usuario_id']]);
            $result = $stmt->fetch();
            $stats['direcciones'] = intval($result['total']);
            
            // Productos favoritos (cuando se implemente)
            $stats['productos_favoritos'] = 0;
            
            $response['success'] = true;
            $response['data'] = $stats;
            break;
            
        case 'update_profile':
            $nombre = trim($_POST['nombre'] ?? '');
            $telefono = trim($_POST['telefono'] ?? '');
            $direccion_general = trim($_POST['direccion'] ?? '');
            
            if (empty($nombre)) {
                throw new Exception('El nombre es requerido');
            }
            
            // Actualizar información básica
            $stmt = $conn->prepare("
                UPDATE clientes SET 
                nombre = ?, telefono = ?, direccion = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$nombre, $telefono, $direccion_general, $_SESSION['usuario_id']]);
            
            // Actualizar sesión
            $_SESSION['usuario_nombre'] = $nombre;
            $_SESSION['usuario_telefono'] = $telefono;
            
            $response['success'] = true;
            $response['message'] = 'Perfil actualizado correctamente';
            break;
            
        case 'change_password':
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                throw new Exception('Todos los campos son requeridos');
            }
            
            if (strlen($new_password) < 6) {
                throw new Exception('La nueva contraseña debe tener al menos 6 caracteres');
            }
            
            if ($new_password !== $confirm_password) {
                throw new Exception('Las contraseñas no coinciden');
            }
            
            // Verificar contraseña actual
            $stmt = $conn->prepare("SELECT password FROM clientes WHERE id = ?");
            $stmt->execute([$_SESSION['usuario_id']]);
            $usuario = $stmt->fetch();
            
            if (!$usuario || !password_verify($current_password, $usuario['password'])) {
                throw new Exception('La contraseña actual es incorrecta');
            }
            
            // Actualizar contraseña
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE clientes SET password = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_password_hash, $_SESSION['usuario_id']]);
            
            $response['success'] = true;
            $response['message'] = 'Contraseña actualizada correctamente';
            break;
            
        case 'validate_card_number':
            // Validador de número de tarjeta (algoritmo de Luhn)
            $numero = preg_replace('/\s+/', '', $_POST['numero'] ?? '');
            
            if (empty($numero)) {
                throw new Exception('Número de tarjeta requerido');
            }
            
            $valid = false;
            $tipo = 'Desconocido';
            
            if (preg_match('/^4\d{12}(\d{3})?$/', $numero)) {
                $tipo = 'Visa';
                $valid = true;
            } elseif (preg_match('/^5[1-5]\d{14}$/', $numero)) {
                $tipo = 'Mastercard';
                $valid = true;
            } elseif (preg_match('/^3[47]\d{13}$/', $numero)) {
                $tipo = 'American Express';
                $valid = true;
            }
            
            // Aplicar algoritmo de Luhn para validación adicional
            if ($valid) {
                $sum = 0;
                $alternate = false;
                for ($i = strlen($numero) - 1; $i >= 0; $i--) {
                    $digit = intval($numero[$i]);
                    if ($alternate) {
                        $digit *= 2;
                        if ($digit > 9) {
                            $digit = ($digit % 10) + 1;
                        }
                    }
                    $sum += $digit;
                    $alternate = !$alternate;
                }
                $valid = ($sum % 10 === 0);
            }
            
            $response['success'] = true;
            $response['data'] = [
                'valid' => $valid,
                'tipo' => $tipo,
                'ultimos_4' => substr($numero, -4)
            ];
            break;
            
        default:
            throw new Exception('Acción no válida: ' . $action);
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    error_log("Error en API perfil: " . $e->getMessage());
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
            $stmt->execute([$id_metodo, $_SESSION['usuario_id']]);
            if (!$stmt->fetch()) {
                throw new Exception('Método de pago no encontrado');
            }
            
            // Eliminar (soft delete)
            $stmt = $conn->prepare("UPDATE metodos_pago SET activo = 0 WHERE id = ? AND id_cliente = ?");
            $stmt->execute([$id_metodo, $_SESSION['usuario_id']]);
            
            $response['success'] = true;
            $response['message'] = 'Método de pago eliminado';
            break;
            
        case 'set_primary_payment':
            $id_metodo = intval($_POST['id_metodo'] ?? 0);
            
            if (!$id_metodo) {
                throw new Exception('ID de método de pago requerido');
            }
            
            // Verificar que pertenece al usuario
            $stmt = $conn->prepare("
                SELECT id FROM metodos_pago 
                WHERE id = ? AND id_cliente = ? AND activo = 1
            ");