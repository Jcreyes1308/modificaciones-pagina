<?php
// agregar_productos_ejemplo.php
// Script para agregar productos de ejemplo a la base de datos

require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

try {
    // Productos de ejemplo para cada categoría
    $productos_ejemplo = [
        // Ropa de Paca
        [
            'nombre' => 'Playera Nike Original',
            'descripcion' => 'Playera deportiva Nike en excelente estado, talla M, color azul',
            'id_categoria' => 1,
            'precio_menudeo' => 150.00,
            'precio_mayoreo' => 120.00,
            'stock' => 25,
            'peso' => 0.2
        ],
        [
            'nombre' => 'Jeans Levis 501',
            'descripcion' => 'Jeans clásicos Levis 501, talla 32x34, color azul índigo',
            'id_categoria' => 1,
            'precio_menudeo' => 280.00,
            'precio_mayoreo' => 220.00,
            'stock' => 15,
            'peso' => 0.8
        ],
        [
            'nombre' => 'Sudadera Adidas',
            'descripcion' => 'Sudadera con capucha Adidas, talla L, color gris',
            'id_categoria' => 1,
            'precio_menudeo' => 200.00,
            'precio_mayoreo' => 160.00,
            'stock' => 18,
            'peso' => 0.6
        ],
        
        // Papelería
        [
            'nombre' => 'Cuaderno Universitario 100 hojas',
            'descripcion' => 'Cuaderno de rayas, 100 hojas, pasta dura, marca Scribe',
            'id_categoria' => 2,
            'precio_menudeo' => 25.00,
            'precio_mayoreo' => 18.00,
            'stock' => 100,
            'peso' => 0.3
        ],
        [
            'nombre' => 'Set de Plumas BIC',
            'descripcion' => 'Paquete de 10 plumas BIC azules, punta media',
            'id_categoria' => 2,
            'precio_menudeo' => 45.00,
            'precio_mayoreo' => 35.00,
            'stock' => 50,
            'peso' => 0.1
        ],
        [
            'nombre' => 'Calculadora Científica Casio',
            'descripcion' => 'Calculadora científica Casio FX-82MS, ideal para estudiantes',
            'id_categoria' => 2,
            'precio_menudeo' => 320.00,
            'precio_mayoreo' => 280.00,
            'stock' => 20,
            'peso' => 0.25
        ],
        
        // Artículos para Fiestas
        [
            'nombre' => 'Globos Metálicos Números',
            'descripcion' => 'Set de globos metálicos con números del 0-9, color dorado',
            'id_categoria' => 3,
            'precio_menudeo' => 85.00,
            'precio_mayoreo' => 65.00,
            'stock' => 30,
            'peso' => 0.15
        ],
        [
            'nombre' => 'Piñata Tradicional',
            'descripcion' => 'Piñata artesanal tradicional mexicana, varios colores',
            'id_categoria' => 3,
            'precio_menudeo' => 180.00,
            'precio_mayoreo' => 150.00,
            'stock' => 12,
            'peso' => 1.2
        ],
        [
            'nombre' => 'Mantel Desechable Colores',
            'descripcion' => 'Mantel plástico desechable, 1.40x2.00m, varios colores',
            'id_categoria' => 3,
            'precio_menudeo' => 15.00,
            'precio_mayoreo' => 10.00,
            'stock' => 80,
            'peso' => 0.1
        ],
        
        // Tenis
        [
            'nombre' => 'Tenis Nike Air Force 1',
            'descripcion' => 'Tenis Nike Air Force 1 blancos, talla 8.5 US, seminuevos',
            'id_categoria' => 4,
            'precio_menudeo' => 850.00,
            'precio_mayoreo' => 750.00,
            'stock' => 8,
            'peso' => 1.1
        ],
        [
            'nombre' => 'Tenis Converse All Star',
            'descripcion' => 'Tenis Converse All Star negros, talla 7 US, buen estado',
            'id_categoria' => 4,
            'precio_menudeo' => 420.00,
            'precio_mayoreo' => 380.00,
            'stock' => 10,
            'peso' => 0.9
        ],
        [
            'nombre' => 'Tenis Deportivos Genéricos',
            'descripcion' => 'Tenis deportivos para correr, marca genérica, varios colores',
            'id_categoria' => 4,
            'precio_menudeo' => 180.00,
            'precio_mayoreo' => 140.00,
            'stock' => 25,
            'peso' => 0.8
        ]
    ];
    
    // Insertar productos
    $stmt_producto = $conn->prepare("
        INSERT INTO productos (nombre, descripcion, id_categoria, precio_menudeo, precio_mayoreo, stock, peso, dimensiones, activo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");
    
    $productos_insertados = 0;
    
    foreach ($productos_ejemplo as $producto) {
        $dimensiones = "Estándar"; // Dimensiones genéricas
        
        $stmt_producto->execute([
            $producto['nombre'],
            $producto['descripcion'],
            $producto['id_categoria'],
            $producto['precio_menudeo'],
            $producto['precio_mayoreo'],
            $producto['stock'],
            $producto['peso'],
            $dimensiones
        ]);
        
        $productos_insertados++;
    }
    
    // Crear algunos paquetes promocionales
    $paquetes_ejemplo = [
        [
            'nombre' => 'Pack Estudiante Completo',
            'descripcion' => 'Cuaderno + Set de plumas + Calculadora - Todo lo que necesitas para estudiar',
            'precio_paquete' => 350.00,
            'precio_original' => 390.00,
            'descuento_porcentaje' => 10.26,
            'stock_paquete' => 15
        ],
        [
            'nombre' => 'Pack Fiesta Infantil',
            'descripcion' => 'Globos + Mantel + Decoraciones - Para la fiesta perfecta',
            'precio_paquete' => 250.00,
            'precio_original' => 280.00,
            'descuento_porcentaje' => 10.71,
            'stock_paquete' => 10
        ]
    ];
    
    $stmt_paquete = $conn->prepare("
        INSERT INTO paquetes_promocionales (nombre, descripcion, precio_paquete, precio_original, descuento_porcentaje, stock_paquete, activo)
        VALUES (?, ?, ?, ?, ?, ?, 1)
    ");
    
    $paquetes_insertados = 0;
    
    foreach ($paquetes_ejemplo as $paquete) {
        $stmt_paquete->execute([
            $paquete['nombre'],
            $paquete['descripcion'],
            $paquete['precio_paquete'],
            $paquete['precio_original'],
            $paquete['descuento_porcentaje'],
            $paquete['stock_paquete']
        ]);
        
        $paquetes_insertados++;
    }
    
    // Asociar productos a paquetes
    $id_paquete_estudiante = $conn->lastInsertId() - 1; // Pack Estudiante
    $id_paquete_fiesta = $conn->lastInsertId(); // Pack Fiesta
    
    // Productos para Pack Estudiante (cuaderno, plumas, calculadora)
    $stmt_paquete_producto = $conn->prepare("
        INSERT INTO paquete_productos (id_paquete, id_producto, cantidad)
        VALUES (?, ?, ?)
    ");
    
    // Pack Estudiante: Cuaderno (id ~4), Plumas (id ~5), Calculadora (id ~6)
    $stmt_paquete_producto->execute([$id_paquete_estudiante, 4, 1]);
    $stmt_paquete_producto->execute([$id_paquete_estudiante, 5, 1]);
    $stmt_paquete_producto->execute([$id_paquete_estudiante, 6, 1]);
    
    // Pack Fiesta: Globos (id ~7), Mantel (id ~9)
    $stmt_paquete_producto->execute([$id_paquete_fiesta, 7, 1]);
    $stmt_paquete_producto->execute([$id_paquete_fiesta, 9, 2]);
    
    echo "✅ ¡Productos de ejemplo agregados exitosamente!<br>";
    echo "📦 Productos insertados: $productos_insertados<br>";
    echo "🎁 Paquetes insertados: $paquetes_insertados<br>";
    echo "<br><a href='index.php'>← Volver al inicio</a>";
    
} catch (Exception $e) {
    echo "❌ Error al insertar productos: " . $e->getMessage();
}
?>