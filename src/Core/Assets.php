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

    private function asset_version( string $relative_path ): string {
        $full_path = SIMPLE_FORM_PATH . $relative_path;

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && file_exists( $full_path ) ) {
            return (string) filemtime( $full_path );
        }

        return $this->version;
    }
    public function register_assets(): void {
        wp_register_style(
            self::HANDLE_STYLE,
            SIMPLE_FORM_URL . 'assets/css/form.css',
            array(),
            $this->asset_version( 'assets/css/form.css' )
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
                $this->asset_version( $relative_path ),
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
                'defaultCenter' => array(
                    'lat'  => -33.4489,
                    'lng'  => -70.6693,
                    'zoom' => 12,
                ),
            )
        );

        wp_enqueue_style( self::HANDLE_STYLE );
        wp_enqueue_script( 'simple-form-stepper' ); // arrastra toda la cadena de dependencias.
    }
}