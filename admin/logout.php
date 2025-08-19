<?php
// admin/logout.php - Cerrar sesión del administrador
session_start();

// Limpiar todas las variables de sesión de admin
unset($_SESSION['admin_id']);
unset($_SESSION['admin_nombre']);
unset($_SESSION['admin_rol']);
unset($_SESSION['admin_email']);

// Destruir la sesión de admin (pero mantener sesión de cliente si existe)
if (!isset($_SESSION['usuario_id'])) {
    session_destroy();
}

// Redirigir al login de admin
header('Location: login.php?mensaje=logout');
exit();
?>