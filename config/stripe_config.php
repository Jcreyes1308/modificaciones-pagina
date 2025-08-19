<?php
// config/stripe_config.php
// Configuración de Stripe para pagos reales

// ===================================
// CONFIGURACIÓN STRIPE
// ===================================

// Ambiente actual (cambiar a 'production' cuando esté listo)
define('STRIPE_ENVIRONMENT', 'sandbox'); // 'sandbox' o 'production'

// ===================================
// CREDENCIALES SANDBOX (Para pruebas)
// ===================================
$stripe_sandbox_config = [
    'public_key' => 'pk_test_51Rxhll18BPFUUmSaGehfFahGfiLMRLZHi99EEBaP8lUEKDI5kQf7EMA7bqtDOuRC0y7x5oPV3JH8XJExhssgi4mt00HsivxTCu', // ← AQUÍ va la clave pública de prueba del cliente
    'secret_key' => 'sk_test_51Rxhll18BPFUUmSaOaValJGZ35JSK3LrEU61lL3n8z7Fww3T2KUXBYzf8tSMFaHJXHd1qRmsGL84YeVKGcm6KuuD00U1wGMymO', // ← AQUÍ va la clave secreta de prueba del cliente
    'webhook_secret' => 'whsec_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX' // ← AQUÍ va el webhook secret de prueba
];

// ===================================
// CREDENCIALES PRODUCCIÓN (Para ventas reales)
// ===================================
$stripe_production_config = [
    'public_key' => 'pk_live_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX', // ← AQUÍ va la clave pública REAL del cliente
    'secret_key' => 'sk_live_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX', // ← AQUÍ va la clave secreta REAL del cliente
    'webhook_secret' => 'whsec_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX' // ← AQUÍ va el webhook secret REAL
];

// ===================================
// CONFIGURACIÓN ACTIVA
// ===================================
if (STRIPE_ENVIRONMENT === 'production') {
    $stripe_config = $stripe_production_config;
    define('STRIPE_MODE', 'PRODUCCIÓN');
} else {
    $stripe_config = $stripe_sandbox_config;
    define('STRIPE_MODE', 'PRUEBAS');
}

// Configuración general
$stripe_general_config = [
    'currency' => 'mxn',
    'country' => 'MX',
    'payment_methods' => ['card'], // Se puede expandir: 'card', 'oxxo', 'spei'
    'capture_method' => 'automatic', // 'automatic' o 'manual'
    'confirmation_method' => 'automatic',
    'setup_future_usage' => 'off_session', // Para guardar métodos de pago
];

// Combinar configuraciones
$stripe_config = array_merge($stripe_config, $stripe_general_config);

// ===================================
// CONFIGURACIÓN DE EMPRESA
// ===================================
$stripe_business_config = [
    'business_name' => 'Novedades Ashley',
    'business_url' => 'https://novedadesashley.com', // ← CAMBIAR por la URL real
    'support_email' => 'noe.cruzb91@gmail.com',
    'support_phone' => '558-422-6977',
    'statement_descriptor' => 'NOVEDADES ASHLEY', // Aparece en el estado de cuenta (máx 22 caracteres)
    'statement_descriptor_suffix' => 'COMPRA', // Sufijo dinámico por transacción
];

$stripe_config = array_merge($stripe_config, $stripe_business_config);

// ===================================
// CONFIGURACIÓN DE WEBHOOKS
// ===================================
$stripe_webhook_events = [
    'payment_intent.succeeded',
    'payment_intent.payment_failed', 
    'payment_intent.canceled',
    'charge.dispute.created',
    'invoice.payment_succeeded',
    'customer.subscription.created',
    'customer.subscription.updated',
    'customer.subscription.deleted'
];

$stripe_config['webhook_events'] = $stripe_webhook_events;

// ===================================
// RUTAS Y URLs
// ===================================
$stripe_urls = [
    'success_url' => 'https://localhost/tienda-multicategoria/checkout_confirmacion.php?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => 'https://localhost/tienda-multicategoria/checkout.php?canceled=true',
    'webhook_url' => 'https://localhost/tienda-multicategoria/api/stripe_webhook.php'
];

$stripe_config = array_merge($stripe_config, $stripe_urls);

// ===================================
// FUNCIÓN PARA OBTENER CONFIGURACIÓN
// ===================================
function getStripeConfig() {
    global $stripe_config;
    return $stripe_config;
}

// ===================================
// FUNCIÓN PARA VALIDAR CONFIGURACIÓN
// ===================================
function validateStripeConfig() {
    global $stripe_config;
    
    $required_keys = ['public_key', 'secret_key', 'currency'];
    $missing_keys = [];
    
    foreach ($required_keys as $key) {
        if (empty($stripe_config[$key]) || strpos($stripe_config[$key], 'XXXXXXX') !== false) {
            $missing_keys[] = $key;
        }
    }
    
    if (!empty($missing_keys)) {
        throw new Exception('Configuración de Stripe incompleta. Faltan: ' . implode(', ', $missing_keys));
    }
    
    return true;
}

// ===================================
// FUNCIÓN PARA INICIALIZAR STRIPE
// ===================================
function initializeStripe() {
    // Verificar si Composer está instalado
    $composer_autoload = __DIR__ . '/../vendor/autoload.php';
    
    if (!file_exists($composer_autoload)) {
        throw new Exception('Stripe SDK no encontrado. Ejecuta: composer require stripe/stripe-php');
    }
    
    require_once $composer_autoload;
    
    // Validar configuración
    validateStripeConfig();
    
    // Configurar Stripe
    $config = getStripeConfig();
    \Stripe\Stripe::setApiKey($config['secret_key']);
    \Stripe\Stripe::setApiVersion('2023-10-16'); // Versión estable de la API
    
    return true;
}

// ===================================
// TARJETAS DE PRUEBA STRIPE
// ===================================
$stripe_test_cards = [
    'visa_success' => '4242424242424242',
    'visa_declined' => '4000000000000002',
    'mastercard_success' => '5555555555554444',
    'amex_success' => '378282246310005',
    'mexico_visa' => '4000000000000069', // Específica para México
    'insufficient_funds' => '4000000000009995',
    'expired_card' => '4000000000000069', // Usar con fecha pasada
    'incorrect_cvc' => '4000000000000127',
    'processing_error' => '4000000000000119',
    'disputed_charge' => '4000000000000259'
];

$stripe_config['test_cards'] = $stripe_test_cards;

// ===================================
// LOGGING Y DEBUG
// ===================================
function logStripeActivity($type, $message, $data = null) {
    $log_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'environment' => STRIPE_MODE,
        'type' => $type, // 'success', 'error', 'info', 'warning'
        'message' => $message,
        'data' => $data
    ];
    
    $log_file = __DIR__ . '/../logs/stripe_' . date('Y-m-d') . '.log';
    
    // Crear directorio de logs si no existe
    $log_dir = dirname($log_file);
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    // Escribir log
    file_put_contents($log_file, json_encode($log_entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    
    // En desarrollo, también mostrar en error_log
    if (STRIPE_ENVIRONMENT === 'sandbox') {
        error_log("Stripe [{$type}]: {$message}");
    }
}

// ===================================
// INFORMACIÓN PARA EL DESARROLLADOR
// ===================================
if (STRIPE_ENVIRONMENT === 'sandbox') {
    // Solo mostrar en desarrollo
    $setup_instructions = "
    ========================================
    CONFIGURACIÓN STRIPE - MODO DESARROLLO
    ========================================
    
    1. CREAR CUENTA STRIPE:
       → Ir a: https://dashboard.stripe.com/register
       → Crear cuenta gratuita
    
    2. OBTENER CLAVES DE PRUEBA:
       → Dashboard > Developers > API keys
       → Copiar 'Publishable key' y 'Secret key' de TEST DATA
    
    3. CONFIGURAR WEBHOOKS (Opcional para empezar):
       → Dashboard > Developers > Webhooks
       → Crear endpoint: " . $stripe_config['webhook_url'] . "
       → Seleccionar eventos: payment_intent.succeeded, payment_intent.payment_failed
    
    4. REEMPLAZAR CREDENCIALES:
       → En este archivo, líneas 13-16
       → Reemplazar las X por tus claves reales
    
    5. TARJETAS DE PRUEBA:
       → Visa: 4242424242424242
       → Mastercard: 5555555555554444 
       → Amex: 378282246310005
       → CVV: cualquier 3 dígitos
       → Fecha: cualquier fecha futura
    
    6. CAMBIAR A PRODUCCIÓN:
       → Línea 8: STRIPE_ENVIRONMENT = 'production'
       → Configurar claves LIVE en líneas 21-25
    
    ========================================
    ";
    
    // Solo loggear en desarrollo
    logStripeActivity('info', 'Stripe configurado en modo desarrollo', [
        'public_key_configured' => !empty($stripe_config['public_key']) && strpos($stripe_config['public_key'], 'XXXXX') === false,
        'secret_key_configured' => !empty($stripe_config['secret_key']) && strpos($stripe_config['secret_key'], 'XXXXX') === false
    ]);
}

// ===================================
// EXPORTAR CONFIGURACIÓN
// ===================================
return $stripe_config;
?>