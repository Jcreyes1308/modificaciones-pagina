<?php
// config/database.php
// Configuración de conexión a la base de datos

// Datos de conexión - CORREGIDOS para tu base de datos existente
define('DB_HOST', 'localhost');
define('DB_NAME', 'tienda_multicategoria');  // ← Tu base de datos existente
define('DB_USER', 'root');
define('DB_PASS', ''); // En XAMPP por defecto no hay contraseña
define('DB_CHARSET', 'utf8mb4');

class Database {
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    private $charset = DB_CHARSET;
    public $conn;
    
    public function getConnection() {
        $this->conn = null;
        
        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
            $this->conn = new PDO($dsn, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $exception) {
            echo "Error de conexión: " . $exception->getMessage();
        }
        
        return $this->conn;
    }
    
    // Método para probar la conexión
    public function testConnection() {
        $connection = $this->getConnection();
        if ($connection) {
            echo "✅ Conexión exitosa a la base de datos!";
            return true;
        } else {
            echo "❌ Error al conectar con la base de datos";
            return false;
        }
    }
}
?>