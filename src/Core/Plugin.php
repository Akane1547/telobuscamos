<?php
/**
 * Orquestador del plugin: instancia las clases principales.
 *
 * @package SimpleForm\Core
 */

namespace SimpleForm\Core;
use SimpleForm\Admin\AdminPayments;
use SimpleForm\Admin\AdminServices;
use SimpleForm\Database\Database;
use SimpleForm\Rest\WebhookController;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin {


    /**
     * Instancia única (Patrón Singleton).
     *
     * @var Plugin|null
     */
    private static $instance = null;

    /**
     * Obtiene la instancia única de la clase.
     */
    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor privado para prevenir instanciación externa.
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Instancia las clases que registran hooks.
     */
    private function init_hooks(): void {
        $assets = new Assets( SIMPLE_FORM_VERSION );
        new Ajax();
        new Shortcode( $assets );

        // Solo instanciar la pantalla de administración si estamos en wp-admin
        if ( is_admin() ) {
            new AdminServices();
            new AdminPayments();
        }

        // Ruta que reciben las notificaciones de Mercado Pago. Va en rest_api_init
        // porque antes de ese hook registrar rutas avisa que se hizo mal.
        add_action(
            'rest_api_init',
            function () {
                ( new WebhookController() )->register();
            }
        );

        // Esquema propio: comprueba la versión guardada y migra si hace falta.
        Database::maybe_upgrade();
    }
}