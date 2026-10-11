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
            // geocode va después de map: publica sf:place-selected y map.js es
            // quien lo escucha para mover el pin. stepper cierra la cadena y
            // arrastra todo el resto.
            'geocode'   => array( 'simple-form-map' ),
            'stepper'   => array( 'simple-form-geocode' ),
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

        // Origen del geocodificador (esquema + host), para el preconnect del
        // front: sale de la misma constante que la URL, no se escribe dos veces.
        $geocoder_origin = wp_parse_url( Geocoder::ENDPOINT, PHP_URL_SCHEME ) . '://' . wp_parse_url( Geocoder::ENDPOINT, PHP_URL_HOST );

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
                    'geocode'         => 'simple_form_geocode',
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
                // Buscador de direcciones del Paso 2. El front consulta al
                // proveedor DIRECTO (permite CORS y el navegador reusa la
                // conexión y su caché: medido, 0,6 s contra 1,2 s pasando por
                // PHP). La URL se expone desde acá para poder cambiar de
                // proveedor sin tocar el JS; el endpoint propio queda como plan
                // B y como el lugar por donde entraría una clave si algún día se
                // pasa a un proveedor que la necesite.
                'geocoder' => array(
                    'url'    => Geocoder::ENDPOINT,
                    'origin' => $geocoder_origin,
                    'limit'  => Geocoder::LIMIT,
                    'bbox'   => Geocoder::CHILE_BBOX,
                ),
                // Textos que el front necesita mostrar sin pasar por el servidor.
                // Lo que ya viaja en cada respuesta (etiquetas de estado,
                // mensajes de error) no se repite acá.
                'i18n' => array(
                    'checking'     => __( 'Verificando el pago con Mercado Pago…', 'simple-form' ),
                    'stillPending' => __( 'Todavía no nos llegó la confirmación del pago. Puedes recargar la página en un momento.', 'simple-form' ),
                    'searching'    => __( 'Buscando direcciones…', 'simple-form' ),
                    'searchMore'   => __( 'Escribe al menos 3 letras.', 'simple-form' ),
                    'searchNoResults' => __( 'Sin resultados. Prueba con calle y comuna, o toca el mapa.', 'simple-form' ),
                    'searchError'  => __( 'No pudimos buscar direcciones. Puedes tocar el mapa.', 'simple-form' ),
                    // Mensajes del validador del cliente (validator.js). El
                    // servidor vuelve a validar lo mismo con sus propias reglas.
                    'errName'      => __( 'Ingresa tu nombre completo.', 'simple-form' ),
                    'errEmail'     => __( 'Correo electrónico inválido.', 'simple-form' ),
                    'errPhone'     => __( 'Ingresa un teléfono chileno válido (9 dígitos, empieza con 9).', 'simple-form' ),
                    'errService'   => __( 'Selecciona un servicio.', 'simple-form' ),
                    'errLocation'  => __( 'Selecciona una ubicación en el mapa.', 'simple-form' ),
                    'errRadius'    => __( 'El radio debe estar entre 1 y 20 km.', 'simple-form' ),
                    'errPayment'   => __( 'Selecciona un medio de pago.', 'simple-form' ),
                    'errGeneric'   => __( 'No se pudo guardar. Revisa los datos e inténtalo de nuevo.', 'simple-form' ),
                ),
            )
        );

        wp_enqueue_style( self::HANDLE_STYLE );
        wp_enqueue_script( 'simple-form-stepper' ); // arrastra toda la cadena de dependencias.
    }
}