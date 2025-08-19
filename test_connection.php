<?php
// test_connection.php
// Archivo para probar que la conexión a la base de datos funciona correctamente

require_once 'config/database.php';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prueba de Conexión - Tienda Multicategoría</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .success {
            color: #28a745;
            background: #d4edda;
            padding: 15px;
            border-radius: 5px;
            border: 1px solid #c3e6cb;
        }
        .error {
            color: #dc3545;
            background: #f8d7da;
            padding: 15px;
            border-radius: 5px;
            border: 1px solid #f5c6cb;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Prueba de Conexión - Tienda Multicategoría</h1>
        
        <h2>1. Prueba de Conexión a Base de Datos</h2>
        <div class="test-result">
            <?php
            $database = new Database();
            $conn = $database->getConnection();
            
            if ($conn) {
                echo '<div class="success">✅ ¡Conexión exitosa a la base de datos!</div>';
                
                // Mostrar información de la base de datos
                try {
                    $stmt = $conn->query("SELECT DATABASE() as db_name");
                    $result = $stmt->fetch();
                    echo '<div class="info">📊 Base de datos activa: <strong>' . $result['db_name'] . '</strong></div>';
                    
                    // Verificar que las tablas existen
                    echo '<h2>2. Verificación de Tablas</h2>';
                    $stmt = $conn->query("SHOW TABLES");
                    $tables = $stmt->fetchAll();
                    
                    if (count($tables) > 0) {
                        echo '<div class="success">✅ Se encontraron ' . count($tables) . ' tablas</div>';
                        echo '<table>';
                        echo '<tr><th>Tablas en la Base de Datos</th><th>Estado</th></tr>';
                        foreach ($tables as $table) {
                            $table_name = array_values($table)[0];
                            echo '<tr><td>' . $table_name . '</td><td>✅ OK</td></tr>';
                        }
                        echo '</table>';
                    } else {
                        echo '<div class="error">❌ No se encontraron tablas. Ejecuta el script SQL primero.</div>';
                    }
                    
                    // Verificar datos de ejemplo
                    echo '<h2>3. Verificación de Datos</h2>';
                    $stmt = $conn->query("SELECT COUNT(*) as total FROM categorias");
                    $result = $stmt->fetch();
                    echo '<p>📂 Categorías: <strong>' . $result['total'] . '</strong></p>';
                    
                    $stmt = $conn->query("SELECT COUNT(*) as total FROM administradores");
                    $result = $stmt->fetch();
                    echo '<p>👨‍💼 Administradores: <strong>' . $result['total'] . '</strong></p>';
                    
                    // Mostrar categorías
                    if ($result['total'] > 0) {
                        echo '<h3>Categorías disponibles:</h3>';
                        $stmt = $conn->query("SELECT nombre, descripcion FROM categorias WHERE activo = 1");
                        $categorias = $stmt->fetchAll();
                        echo '<ul>';
                        foreach ($categorias as $categoria) {
                            echo '<li><strong>' . $categoria['nombre'] . '</strong> - ' . $categoria['descripcion'] . '</li>';
                        }
                        echo '</ul>';
                    }
                    
                } catch(PDOException $e) {
                    echo '<div class="error">❌ Error al verificar la base de datos: ' . $e->getMessage() . '</div>';
                }
                
            } else {
                echo '<div class="error">❌ No se pudo conectar a la base de datos</div>';
                echo '<div class="info">
                    <strong>Verifica que:</strong><br>
                    • XAMPP esté ejecutándose<br>
                    • MySQL esté iniciado en XAMPP<br>
                    • La base de datos "tienda_multicategoria" exista<br>
                    • El script SQL se haya ejecutado correctamente
                </div>';
            }
            ?>
        </div>
        
        <div class="info">
            <h3>📋 Próximos pasos:</h3>
            <p>Si la conexión es exitosa, ya puedes continuar con:</p>
            <ul>
                <li>Crear la página principal (index.php)</li>
                <li>Crear el sistema de productos</li>
                <li>Crear el carrito de compras</li>
                <li>Crear el panel de administración</li>
            </ul>
        </div>
    </div>
</body>
</html>