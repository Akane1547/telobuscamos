<?php
/**
 * Lógica de activación del plugin.
 *
 * @package SimpleForm\Activation
 */

namespace SimpleForm\Activation;

use SimpleForm\Database\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Activate
 *
 * Se ejecuta una sola vez al activar el plugin desde el panel de WP.
 */
final class Activate {

    /**
     * Punto de entrada llamado por register_activation_hook().
     */
    public static function activate(): void {

        self::check_requirements();
        self::create_tables();

        // Refresca las reglas de rewrite de WP.
        flush_rewrite_rules();
    }

    /**
     * Verifica requisitos mínimos (versión de PHP).
     */
    private static function check_requirements(): void {

        if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
            deactivate_plugins( SIMPLE_FORM_BASENAME );
            wp_die(
                esc_html__( 'Simple Form requiere PHP 7.4 o superior. Actualiza tu versión de PHP para activar este plugin.', 'simple-form' ),
                esc_html__( 'Error de activación', 'simple-form' ),
                array( 'back_link' => true )
            );
        }
    }

 

    /**
     * Crea el esquema propio vía dbDelta().
     *
     * Delega en Database para que la activación y la migración en caliente
     * usen exactamente la misma definición de tablas.
     */
    private static function create_tables(): void {
        Database::create_tables();
    }
}