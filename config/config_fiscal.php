<?php
// config/config_fiscal.php - Configuración fiscal de la empresa
// Este archivo contiene los datos fiscales de Novedades Ashley
// IMPORTANTE: Actualizar con datos reales antes de producción

/**
 * DATOS FISCALES DE LA EMPRESA (TEMPORALES)
 * Estos datos son temporales para desarrollo y pruebas
 * Reemplazar con información fiscal real antes de usar en producción
 */
$empresa_fiscal = [
    // Información básica de la empresa
    'razon_social' => 'NOVEDADES ASHLEY S.A. DE C.V.',
    'nombre_comercial' => 'Novedades Ashley',
    'rfc' => 'NAS123456789', // ⚠️ RFC TEMPORAL - REEMPLAZAR CON REAL
    
    // Dirección fiscal
    'direccion_fiscal' => [
        'calle' => 'Calle Principal #123',
        'colonia' => 'Col. Centro',
        'ciudad' => 'Ciudad de México',
        'estado' => 'CDMX',
        'codigo_postal' => '01000',
        'pais' => 'México'
    ],
    
    // Información de contacto
    'contacto' => [
        'telefono' => '558-422-6977',
        'email' => 'noe.cruzb91@gmail.com',
        'sitio_web' => 'www.novedadesashley.com'
    ],
    
    // Información fiscal específica
    'regimen_fiscal' => 'Régimen General de Ley Personas Morales',
    'actividad_economica' => 'Comercio al por menor de artículos diversos',
    'lugar_expedicion' => '01000', // Código postal donde se expiden las facturas
    
    // Configuración de facturación
    'serie_factura' => 'A', // Serie de facturas
    'folio_inicial' => 1, // Folio inicial
    'moneda_default' => 'MXN',
    'tipo_cambio_default' => '1.000000',
    
    // Formas y métodos de pago según SAT
    'formas_pago' => [
        '01' => 'Efectivo',
        '02' => 'Cheque nominativo',
        '03' => 'Transferencia electrónica de fondos',
        '04' => 'Tarjeta de crédito',
        '05' => 'Monedero electrónico',
        '06' => 'Dinero electrónico',
        '28' => 'Tarjeta de débito',
        '29' => 'Tarjeta de servicios',
        '99' => 'Por definir'
    ],
    
    'metodos_pago' => [
        'PUE' => 'Pago en una sola exhibición',
        'PPD' => 'Pago en parcialidades o diferido'
    ],
    
    // Tipos de comprobante
    'tipos_comprobante' => [
        'I' => 'Ingreso',
        'E' => 'Egreso',
        'T' => 'Traslado',
        'N' => 'Nómina',
        'P' => 'Pago'
    ],
    
    // Impuestos
    'impuestos' => [
        'iva' => [
            'tasa' => 0.16,
            'descripcion' => 'IVA 16%',
            'tipo' => 'Tasa'
        ]
    ]
];

/**
 * CONFIGURACIÓN DE CLIENTE FISCAL POR DEFECTO
 * Para clientes que no tienen RFC registrado
 */
$cliente_fiscal_default = [
    'rfc' => 'XAXX010101000', // RFC genérico para público en general
    'uso_cfdi' => 'G03', // Gastos en general
    'regimen_fiscal' => 'Sin obligaciones fiscales'
];

/**
 * CATÁLOGO DE USOS DE CFDI (Los más comunes)
 */
$usos_cfdi = [
    'G01' => 'Adquisición de mercancías',
    'G02' => 'Devoluciones, descuentos o bonificaciones',
    'G03' => 'Gastos en general',
    'I01' => 'Construcciones',
    'I02' => 'Mobiliario y equipo de oficina por inversiones',
    'I03' => 'Equipo de transporte',
    'I04' => 'Equipo de computo y accesorios',
    'I05' => 'Dados, troqueles, moldes, matrices y herramental',
    'I06' => 'Comunicaciones telefónicas',
    'I07' => 'Comunicaciones satelitales',
    'I08' => 'Otra maquinaria y equipo',
    'D01' => 'Honorarios médicos, dentales y gastos hospitalarios',
    'D02' => 'Gastos médicos por incapacidad o discapacidad',
    'D03' => 'Gastos funerales',
    'D04' => 'Donativos',
    'D05' => 'Intereses reales efectivamente pagados por créditos hipotecarios',
    'D06' => 'Aportaciones voluntarias al SAR',
    'D07' => 'Primas por seguros de gastos médicos',
    'D08' => 'Gastos de transportación escolar obligatoria',
    'D09' => 'Depósitos en cuentas para el ahorro',
    'D10' => 'Pagos por servicios educativos',
    'P01' => 'Por definir'
];

/**
 * CONFIGURACIÓN DE CERTIFICADOS SAT (TEMPORAL)
 * En producción aquí irían los datos reales del certificado
 */
$certificado_sat = [
    'cer_path' => null, // Ruta al archivo .cer (temporal)
    'key_path' => null, // Ruta al archivo .key (temporal)
    'password' => null, // Contraseña del certificado (temporal)
    'uuid_generado' => true, // Si generar UUID simulado (true = temporal)
    'timbrado_activo' => false // Si el timbrado está activo (false = desarrollo)
];

/**
 * CONFIGURACIÓN DE PLANTILLAS
 */
$plantillas_factura = [
    'logo_empresa' => 'assets/images/logo_novedades_ashley.png', // Ruta al logo
    'mostrar_qr' => true, // Mostrar código QR
    'mostrar_sello_sat' => false, // Mostrar sello SAT (false en desarrollo)
    'pie_pagina' => '"Descubre lo nuevo, siente la diferencia"',
    'notas_adicionales' => [
        'Esta factura cumple con los requisitos del artículo 29-A del Código Fiscal de la Federación.',
        'Los datos fiscales pueden ser verificados en el portal del SAT.',
        'Para aclaraciones sobre esta factura, contactar al email: noe.cruzb91@gmail.com'
    ]
];

/**
 * FUNCIONES AUXILIARES PARA FACTURACIÓN
 */

/**
 * Obtener configuración fiscal de la empresa
 */
function obtenerEmpresaFiscal() {
    global $empresa_fiscal;
    return $empresa_fiscal;
}

/**
 * Obtener datos fiscales de cliente por defecto
 */
function obtenerClienteFiscalDefault() {
    global $cliente_fiscal_default;
    return $cliente_fiscal_default;
}

/**
 * Obtener uso de CFDI por código
 */
function obtenerUsoCFDI($codigo) {
    global $usos_cfdi;
    return $usos_cfdi[$codigo] ?? 'Uso no encontrado';
}

/**
 * Generar folio único para factura
 */
function generarFolioFactura($pedido_id) {
    global $empresa_fiscal;
    return $empresa_fiscal['serie_factura'] . '-' . str_pad($pedido_id, 6, '0', STR_PAD_LEFT);
}

/**
 * Generar UUID temporal (en producción usar PAC real)
 */
function generarUUIDTemporal() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

/**
 * Validar RFC básico
 */
function validarRFC($rfc) {
    // Validación básica de RFC
    $pattern = '/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/';
    return preg_match($pattern, strtoupper($rfc));
}

/**
 * Formatear dirección completa
 */
function formatearDireccionFiscal($direccion_array) {
    return implode(', ', array_filter([
        $direccion_array['calle'] ?? '',
        $direccion_array['colonia'] ?? '',
        $direccion_array['ciudad'] ?? '',
        $direccion_array['estado'] ?? '',
        'CP: ' . ($direccion_array['codigo_postal'] ?? ''),
        $direccion_array['pais'] ?? ''
    ]));
}

/**
 * NOTAS IMPORTANTES PARA PRODUCCIÓN:
 * 
 * 1. ACTUALIZAR RFC: Cambiar 'NAS123456789' por el RFC real de la empresa
 * 2. DIRECCIÓN FISCAL: Actualizar con la dirección fiscal real registrada en SAT
 * 3. CERTIFICADOS SAT: Configurar rutas reales de certificados .cer y .key
 * 4. PAC (Proveedor Autorizado de Certificación): Integrar con un PAC real para timbrado
 * 5. LOGO: Agregar logo real de la empresa en assets/images/
 * 6. RÉGIMEN FISCAL: Verificar que el régimen fiscal sea correcto
 * 7. UUID: En producción usar UUID real del PAC, no el generado localmente
 * 8. VALIDACIONES: Implementar validaciones adicionales según requerimientos del SAT
 * 9. BASE DE DATOS: Crear tabla para almacenar folios y UUIDs de facturas generadas
 * 10. RESPALDOS: Implementar sistema de respaldo de facturas según normativa
 */

// Hacer disponible la configuración globalmente
$GLOBALS['empresa_fiscal'] = $empresa_fiscal;
$GLOBALS['cliente_fiscal_default'] = $cliente_fiscal_default;
$GLOBALS['usos_cfdi'] = $usos_cfdi;
$GLOBALS['certificado_sat'] = $certificado_sat;
$GLOBALS['plantillas_factura'] = $plantillas_factura;

?>