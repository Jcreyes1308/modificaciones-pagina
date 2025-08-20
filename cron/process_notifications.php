<?php
/**
 * Procesador de Notificaciones para Windows
 * Archivo: cron/process_notifications.php
 */

// Configuración
$project_dir = __DIR__ . '/..'; // Directorio del proyecto (un nivel arriba)
$log_file = $project_dir . '/logs/cron_notifications.log';
$lock_file = sys_get_temp_dir() . '/notification_processor.lock';

// Crear directorio de logs si no existe
$logs_dir = $project_dir . '/logs';
if (!is_dir($logs_dir)) {
    mkdir($logs_dir, 0755, true);
}

// Función de logging
function log_message($message, $log_file) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// Verificar que no haya otro proceso ejecutándose
if (file_exists($lock_file)) {
    $pid = (int)file_get_contents($lock_file);
    
    // En Windows, verificar si el proceso existe
    $running = false;
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $cmd = "tasklist /FI \"PID eq $pid\" 2>NUL | find \"$pid\" >NUL";
        $running = (shell_exec($cmd) !== null);
    } else {
        $running = (posix_kill($pid, 0));
    }
    
    if ($running) {
        log_message("INFO: Procesador ya está ejecutándose (PID: $pid). Saliendo.", $log_file);
        exit(0);
    } else {
        log_message("WARNING: Lock file existe pero proceso no está corriendo. Removiendo lock.", $log_file);
        unlink($lock_file);
    }
}

// Crear lock file
file_put_contents($lock_file, getmypid());

// Función de limpieza
function cleanup() {
    global $lock_file, $log_file;
    if (file_exists($lock_file)) {
        unlink($lock_file);
    }
    log_message("INFO: Proceso finalizado", $log_file);
}

// Registrar función de limpieza
register_shutdown_function('cleanup');

log_message("INFO: Iniciando procesador de notificaciones", $log_file);

// Cambiar al directorio del proyecto
chdir($project_dir);

// Ejecutar el procesador de notificaciones
ob_start();
$start_time = microtime(true);

try {
    // Incluir el procesador principal
    include 'api/notification_processor.php';
    
    $output = ob_get_clean();
    $execution_time = round((microtime(true) - $start_time) * 1000, 2);
    
    log_message("SUCCESS: Procesador ejecutado exitosamente en {$execution_time}ms", $log_file);
    
    if (!empty($output)) {
        log_message("OUTPUT: " . trim($output), $log_file);
    }
    
} catch (Exception $e) {
    ob_end_clean();
    log_message("ERROR: " . $e->getMessage(), $log_file);
    log_message("ERROR: Stack trace: " . $e->getTraceAsString(), $log_file);
    exit(1);
}

log_message("INFO: Procesamiento completado", $log_file);
?>