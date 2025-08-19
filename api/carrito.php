<?php
// api/carrito.php - API del carrito CORREGIDA para tu estructura real
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$database = new Database();
$conn = $database->getConnection();

// Obtener acción
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    switch ($action) {
        case 'agregar':
            $id_producto = intval($_POST['id_producto'] ?? 0);
            $cantidad = intval($_POST['cantidad'] ?? 1);
            
            if (!$id_producto) {
                throw new Exception('ID de producto requerido');
            }
            
            if ($cantidad <= 0) {
                throw new Exception('La cantidad debe ser mayor a 0');
            }
            
            // CORREGIDO: Usar tu estructura real - precio y cantidad_etiquetas
            $stmt = $conn->prepare("SELECT nombre, precio, cantidad_etiquetas FROM productos WHERE id = ? AND activo = 1");
            $stmt->execute([$id_producto]);
            $producto = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$producto) {
                throw new Exception('Producto no encontrado');
            }
            
            // Verificar stock disponible
            if ($cantidad > $producto['cantidad_etiquetas']) {
                throw new Exception('Stock insuficiente. Disponible: ' . $producto['cantidad_etiquetas']);
            }
            
            // Usar variable de sesión consistente
            $id_cliente = $_SESSION['usuario_id'] ?? null;
            
            if ($id_cliente) {
                // Usuario registrado - guardar en BD
                // Verificar si ya existe el producto en el carrito
                $stmt = $conn->prepare("SELECT cantidad FROM carrito_compras WHERE id_cliente = ? AND id_producto = ?");
                $stmt->execute([$id_cliente, $id_producto]);
                $item_existente = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($item_existente) {
                    // Ya existe - actualizar cantidad
                    $nueva_cantidad = $item_existente['cantidad'] + $cantidad;
                    
                    // Verificar stock para nueva cantidad
                    if ($nueva_cantidad > $producto['cantidad_etiquetas']) {
                        throw new Exception('Stock insuficiente. Disponible: ' . $producto['cantidad_etiquetas'] . ', en carrito: ' . $item_existente['cantidad']);
                    }
                    
                    $stmt = $conn->prepare("UPDATE carrito_compras SET cantidad = ? WHERE id_cliente = ? AND id_producto = ?");
                    $stmt->execute([$nueva_cantidad, $id_cliente, $id_producto]);
                } else {
                    // No existe - insertar nuevo
                    $stmt = $conn->prepare("INSERT INTO carrito_compras (id_cliente, id_producto, cantidad) VALUES (?, ?, ?)");
                    $stmt->execute([$id_cliente, $id_producto, $cantidad]);
                }
            } else {
                // Usuario visitante - usar sesión
                if (!isset($_SESSION['carrito'])) {
                    $_SESSION['carrito'] = [];
                }
                
                // Estructura consistente del carrito de sesión
                $key = 'prod_' . $id_producto;
                $cantidad_actual = $_SESSION['carrito'][$key]['cantidad'] ?? 0;
                $nueva_cantidad = $cantidad_actual + $cantidad;
                
                // Verificar stock
                if ($nueva_cantidad > $producto['cantidad_etiquetas']) {
                    throw new Exception('Stock insuficiente. Disponible: ' . $producto['cantidad_etiquetas']);
                }
                
                $_SESSION['carrito'][$key] = [
                    'tipo' => 'producto',
                    'id' => $id_producto,
                    'id_producto' => $id_producto,
                    'nombre' => $producto['nombre'],
                    'precio' => $producto['precio'], // CORREGIDO: usar tu estructura
                    'cantidad' => $nueva_cantidad
                ];
            }
            
            $response['success'] = true;
            $response['message'] = 'Producto agregado al carrito';
            break;
            
        case 'obtener':
            $items = [];
            $total = 0;
            $contador_items = 0;
            
            $id_cliente = $_SESSION['usuario_id'] ?? null;
            
            if ($id_cliente) {
                // Usuario registrado - obtener de BD
                // CORREGIDO: usar tu estructura - precio y cantidad_etiquetas
                $stmt = $conn->prepare("
                    SELECT 
                        c.id,
                        c.id_producto,
                        c.cantidad,
                        p.nombre,
                        p.precio,
                        p.cantidad_etiquetas
                    FROM carrito_compras c
                    INNER JOIN productos p ON c.id_producto = p.id
                    WHERE c.id_cliente = ? AND p.activo = 1
                    ORDER BY c.created_at DESC
                ");
                $stmt->execute([$id_cliente]);
                $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($resultados as $row) {
                    $precio = floatval($row['precio']);
                    $subtotal = $precio * $row['cantidad'];
                    
                    $items[] = [
                        'id_carrito' => $row['id'],
                        'id_producto' => $row['id_producto'],
                        'nombre' => $row['nombre'],
                        'precio' => $precio,
                        'cantidad' => intval($row['cantidad']),
                        'subtotal' => $subtotal,
                        'stock_disponible' => intval($row['cantidad_etiquetas']), // CORREGIDO
                        'tipo' => 'producto',
                        'tipo_storage' => 'bd'
                    ];
                    
                    $total += $subtotal;
                    $contador_items += intval($row['cantidad']);
                }
            } else {
                // Usuario visitante - obtener de sesión
                if (isset($_SESSION['carrito']) && !empty($_SESSION['carrito'])) {
                    foreach ($_SESSION['carrito'] as $key => $item) {
                        // Verificar que el producto sigue existiendo
                        $stmt = $conn->prepare("SELECT cantidad_etiquetas FROM productos WHERE id = ? AND activo = 1");
                        $stmt->execute([$item['id_producto']]);
                        $producto_actual = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($producto_actual) {
                            $precio = floatval($item['precio']);
                            $subtotal = $precio * $item['cantidad'];
                            
                            $items[] = [
                                'key' => $key,
                                'id_producto' => $item['id_producto'],
                                'nombre' => $item['nombre'],
                                'precio' => $precio,
                                'cantidad' => intval($item['cantidad']),
                                'subtotal' => $subtotal,
                                'stock_disponible' => intval($producto_actual['cantidad_etiquetas']), // CORREGIDO
                                'tipo' => 'producto',
                                'tipo_storage' => 'sesion'
                            ];
                            
                            $total += $subtotal;
                            $contador_items += intval($item['cantidad']);
                        } else {
                            // Producto ya no existe - eliminarlo de la sesión
                            unset($_SESSION['carrito'][$key]);
                        }
                    }
                }
            }
            
            $response['success'] = true;
            $response['data'] = [
                'items' => $items,
                'total' => round($total, 2),
                'contador' => $contador_items,
                'tiene_items' => count($items) > 0
            ];
            break;
            
        case 'actualizar':
            $cantidad = intval($_POST['cantidad'] ?? 0);
            
            if ($cantidad <= 0) {
                throw new Exception('La cantidad debe ser mayor a 0');
            }
            
            $id_cliente = $_SESSION['usuario_id'] ?? null;
            
            if ($id_cliente && isset($_POST['id_carrito'])) {
                // Usuario registrado - actualizar en BD
                $id_carrito = intval($_POST['id_carrito']);
                
                // CORREGIDO: usar tu estructura
                $stmt = $conn->prepare("
                    SELECT p.cantidad_etiquetas, p.nombre 
                    FROM carrito_compras c 
                    INNER JOIN productos p ON c.id_producto = p.id 
                    WHERE c.id = ? AND c.id_cliente = ?
                ");
                $stmt->execute([$id_carrito, $id_cliente]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$item) {
                    throw new Exception('Item no encontrado en el carrito');
                }
                
                if ($cantidad > $item['cantidad_etiquetas']) {
                    throw new Exception('Stock insuficiente. Disponible: ' . $item['cantidad_etiquetas']);
                }
                
                $stmt = $conn->prepare("UPDATE carrito_compras SET cantidad = ? WHERE id = ? AND id_cliente = ?");
                $stmt->execute([$cantidad, $id_carrito, $id_cliente]);
                
            } else if (isset($_POST['key'])) {
                // Usuario visitante - actualizar en sesión
                $key = $_POST['key'];
                
                if (!isset($_SESSION['carrito'][$key])) {
                    throw new Exception('Item no encontrado en el carrito');
                }
                
                $id_producto = $_SESSION['carrito'][$key]['id_producto'];
                
                // CORREGIDO: usar tu estructura
                $stmt = $conn->prepare("SELECT cantidad_etiquetas FROM productos WHERE id = ? AND activo = 1");
                $stmt->execute([$id_producto]);
                $producto = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$producto) {
                    throw new Exception('Producto no encontrado');
                }
                
                if ($cantidad > $producto['cantidad_etiquetas']) {
                    throw new Exception('Stock insuficiente. Disponible: ' . $producto['cantidad_etiquetas']);
                }
                
                $_SESSION['carrito'][$key]['cantidad'] = $cantidad;
                
            } else {
                throw new Exception('Datos insuficientes para actualizar');
            }
            
            $response['success'] = true;
            $response['message'] = 'Cantidad actualizada';
            break;
            
        case 'eliminar':
            $id_cliente = $_SESSION['usuario_id'] ?? null;
            
            if ($id_cliente && isset($_POST['id_carrito'])) {
                // Usuario registrado - eliminar de BD
                $id_carrito = intval($_POST['id_carrito']);
                $stmt = $conn->prepare("DELETE FROM carrito_compras WHERE id = ? AND id_cliente = ?");
                $stmt->execute([$id_carrito, $id_cliente]);
                
            } else if (isset($_POST['key'])) {
                // Usuario visitante - eliminar de sesión
                $key = $_POST['key'];
                if (isset($_SESSION['carrito'][$key])) {
                    unset($_SESSION['carrito'][$key]);
                } else {
                    throw new Exception('Item no encontrado');
                }
                
            } else {
                throw new Exception('Datos insuficientes para eliminar');
            }
            
            $response['success'] = true;
            $response['message'] = 'Producto eliminado del carrito';
            break;
            
        case 'contar':
            $contador = 0;
            $id_cliente = $_SESSION['usuario_id'] ?? null;
            
            if ($id_cliente) {
                // Usuario registrado
                $stmt = $conn->prepare("
                    SELECT COALESCE(SUM(c.cantidad), 0) as total 
                    FROM carrito_compras c 
                    INNER JOIN productos p ON c.id_producto = p.id 
                    WHERE c.id_cliente = ? AND p.activo = 1
                ");
                $stmt->execute([$id_cliente]);
                $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
                $contador = intval($resultado['total']);
            } else {
                // Usuario visitante
                if (isset($_SESSION['carrito']) && !empty($_SESSION['carrito'])) {
                    foreach ($_SESSION['carrito'] as $item) {
                        $contador += intval($item['cantidad']);
                    }
                }
            }
            
            $response['success'] = true;
            $response['data'] = $contador;
            break;
            
      case 'limpiar':
    $id_cliente = $_SESSION['usuario_id'] ?? null;
    
    if ($id_cliente) {
        // Usuario registrado - limpiar BD
        $stmt = $conn->prepare("DELETE FROM carrito_compras WHERE id_cliente = ?");
        $stmt->execute([$id_cliente]);
    } else {
        // Usuario visitante - limpiar sesión
        $_SESSION['carrito'] = [];
    }
    
    $response['success'] = true;
    $response['message'] = 'Carrito vaciado';
    break;

case 'recomprar':
    $id_pedido = intval($_POST['id_pedido'] ?? 0);
    
    if (!$id_pedido) {
        throw new Exception('ID de pedido requerido');
    }
    
    $id_cliente = $_SESSION['usuario_id'] ?? null;
    if (!$id_cliente) {
        throw new Exception('Debes iniciar sesión para recomprar');
    }
    
    // Verificar que el pedido pertenece al usuario
    $stmt = $conn->prepare("SELECT id FROM pedidos WHERE id = ? AND id_cliente = ?");
    $stmt->execute([$id_pedido, $id_cliente]);
    if (!$stmt->fetch()) {
        throw new Exception('Pedido no encontrado');
    }
    
    // Obtener productos del pedido
    $stmt = $conn->prepare("
        SELECT 
            pd.id_producto, 
            pd.cantidad,
            p.nombre,
            p.precio,
            p.cantidad_etiquetas
        FROM pedido_detalles pd
        INNER JOIN productos p ON pd.id_producto = p.id
        WHERE pd.id_pedido = ? AND p.activo = 1
    ");
    $stmt->execute([$id_pedido]);
    $productos_pedido = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($productos_pedido)) {
        throw new Exception('No hay productos disponibles para recomprar');
    }
    
    $agregados = 0;
    $no_disponibles = [];
    
    foreach ($productos_pedido as $producto) {
        try {
            // Verificar si ya está en el carrito
            $stmt = $conn->prepare("
                SELECT cantidad FROM carrito_compras 
                WHERE id_cliente = ? AND id_producto = ?
            ");
            $stmt->execute([$id_cliente, $producto['id_producto']]);
            $en_carrito = $stmt->fetch();
            
            $cantidad_deseada = $producto['cantidad'];
            if ($en_carrito) {
                $cantidad_deseada += $en_carrito['cantidad'];
            }
            
            // Verificar stock disponible
            if ($cantidad_deseada <= $producto['cantidad_etiquetas']) {
                if ($en_carrito) {
                    // Actualizar cantidad existente
                    $stmt = $conn->prepare("
                        UPDATE carrito_compras 
                        SET cantidad = ? 
                        WHERE id_cliente = ? AND id_producto = ?
                    ");
                    $stmt->execute([$cantidad_deseada, $id_cliente, $producto['id_producto']]);
                } else {
                    // Agregar nuevo item
                    $stmt = $conn->prepare("
                        INSERT INTO carrito_compras (id_cliente, id_producto, cantidad) 
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$id_cliente, $producto['id_producto'], $producto['cantidad']]);
                }
                $agregados++;
            } else {
                $no_disponibles[] = $producto['nombre'] . ' (stock: ' . $producto['cantidad_etiquetas'] . ')';
            }
            
        } catch (Exception $e) {
            $no_disponibles[] = $producto['nombre'] . ' (error)';
        }
    }
    
    $mensaje = "Se agregaron $agregados productos al carrito";
    if (!empty($no_disponibles)) {
        $mensaje .= ". No disponibles: " . implode(', ', $no_disponibles);
    }
    
    if ($agregados === 0) {
        throw new Exception('No se pudo agregar ningún producto al carrito');
    }
    
    $response['success'] = true;
    $response['message'] = $mensaje;
    $response['data'] = [
        'agregados' => $agregados,
        'no_disponibles' => $no_disponibles
    ];
    break;

default:
    throw new Exception('Acción no válida: ' . $action);
}
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    error_log("Error en carrito API: " . $e->getMessage());
}

// Enviar respuesta JSON
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
?>