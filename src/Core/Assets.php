<?php

namespace SimpleForm\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Assets {

    const HANDLE_STYLE  = 'simple-form-style';
    const HANDLE_CONFIG = 'simple-form-config';

    private string $version;

    public function __construct( string $version ) {
        $this->version = $version;
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
    }

    /**
     * Versión del asset para el cache-busting.
     *
     * Público y estático para que el admin use la misma regla que el front
     * en vez de duplicarla.
     *
     * @param string $relative_path Ruta relativa al directorio del plugin.
     * @param string $version       Versión a usar cuando no aplica filemtime().
     * @return string
     */
    public static function asset_version( string $relative_path, string $version ): string {
        $full_path = SIMPLE_FORM_PATH . $relative_path;

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && file_exists( $full_path ) ) {
            return (string) filemtime( $full_path );
        }

        return $version;
    }
    public function register_assets(): void {
        wp_register_style(
            self::HANDLE_STYLE,
            SIMPLE_FORM_URL . 'assets/css/form.css',
            array(),
            self::asset_version( 'assets/css/form.css', $this->version )
        );

        $modules = array(
            'config'    => array(),
            'validator' => array( self::HANDLE_CONFIG ),
            'ui'        => array( 'simple-form-validator' ),
            'receipt'   => array( 'simple-form-ui' ),
            'map'       => array( 'simple-form-receipt' ),
            'stepper'   => array( 'simple-form-map' ),
        );

        foreach ( $modules as $name => $deps ) {
            $relative_path = 'assets/js/' . $name . '.js';

            wp_register_script(
                'simple-form-' . $name,
                SIMPLE_FORM_URL . $relative_path,
                $deps,
                self::asset_version( $relative_path, $this->version ),
                true
            );
        }
    }

    public function enqueue(): void {
        static $done = false;

        if ( $done ) {
            return;
        }
        $done = true;

        wp_localize_script(
            self::HANDLE_CONFIG,
            'SimpleFormConfig',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'simple_form_nonce' ),
                'actions' => array(
                    'getProgress'     => 'simple_form_get_progress',
                    'saveStep1'       => 'simple_form_save_step1',
                    'saveStep2'       => 'simple_form_save_step2',
                    'saveStep3'       => 'simple_form_save_step3',
                    'calculatePrice'  => 'simple_form_calculate_price',
                    'getCoverageArea' => 'simple_form_get_coverage_area',
                ),
                // Punto de partida del pin (Santiago) y vista inicial antes de
                // que llegue el contorno. El encuadre definitivo lo fija el
                // polígono que devuelve get_coverage_area.
                'defaultCenter' => array(
                    'lat'  => -33.4489,
                    'lng'  => -70.6693,
                    'zoom' => 4,
                ),
                // Leaflet empaquetado en el plugin: el front carga estas URLs
                // de forma diferida y no depende de ningún CDN de terceros.
                'leaflet' => array(
                    'css' => SIMPLE_FORM_URL . 'assets/vendor/leaflet/leaflet.css',
                    'js'  => SIMPLE_FORM_URL . 'assets/vendor/leaflet/leaflet.js',
                ),
            )
        );

        wp_enqueue_style( self::HANDLE_STYLE );
        wp_enqueue_script( 'simple-form-stepper' ); // arrastra toda la cadena de dependencias.
    }
}