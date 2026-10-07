<?php
/**
 * Plugin Name: Simple Form
 * Description: Formulario de pago simple, visual, con shortcode.
 * Version:     1.0.0
 * Author:      BrunoPe
 * License:     GPL v2 or later
 * Text Domain: simple-form
 * 
 * @package SimpleForm
 */

// Primera capa de seguridad: evitar ejecución directa.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Constantes globales del plugin.
define( 'SIMPLE_FORM_VERSION', get_file_data( __FILE__, array( 'Version' => 'Version' ) )['Version'] );define( 'SIMPLE_FORM_PATH', plugin_dir_path( __FILE__ ) );
define( 'SIMPLE_FORM_URL', plugin_dir_url( __FILE__ ) );
define( 'SIMPLE_FORM_BASENAME', plugin_basename( __FILE__ ) );

// Autoload PSR-4 estándar (mapea namespaces directamente a directorios).
spl_autoload_register(
    function ( $class ) {

        $prefix   = 'SimpleForm\\';
        $base_dir = SIMPLE_FORM_PATH . 'src/';

        $len = strlen( $prefix );

        // Verificar si la clase usa este namespace base.
        if ( strncmp( $prefix, $class, $len ) !== 0 ) {
            return;
        }

        // Obtener la ruta relativa de la clase (Ej: Core\Plugin).
        $relative_class = substr( $class, $len );

        // Convertir separadores de namespace en separadores de directorio (Ej: src/Core/Plugin.php).
        $file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

        if ( file_exists( $file ) ) {
            require $file;
        }
    }
);

register_activation_hook(
    __FILE__,
    array( '\SimpleForm\Activation\Activate', 'activate' )
);

register_deactivation_hook(
    __FILE__,
    array( '\SimpleForm\Activation\Deactivate', 'deactivate' )
);

// Arranque del plugin tras cargar WordPress.
add_action(
    'plugins_loaded',
    function () {
        \SimpleForm\Core\Plugin::get_instance();
    }
);