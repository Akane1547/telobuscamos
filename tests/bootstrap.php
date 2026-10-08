<?php
/**
 * Arranque de la suite unitaria: las clases que no dependen de WordPress.
 *
 * Aquí NO se carga WordPress. Se define ABSPATH (las clases del plugin salen si
 * no está) y se doblan las pocas funciones de WordPress que usan, con estado en
 * memoria que cada prueba reinicia desde FakeWordPress.
 *
 * Las rutas se derivan de este archivo, no se escriben a mano: así el arnés
 * funciona en cualquier máquina que tenga el plugin.
 *
 * @package SimpleForm\Tests
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'SIMPLE_FORM_PATH', dirname( __DIR__ ) . '/' );

// Una constante como las de wp-config.php, con valor de mentira, para poder
// probar que manda sobre lo que se guarda cifrado en la base. Los campos cuya
// ruta almacenada se prueba (webhook_secret) no tienen constante a propósito.
define( 'SIMPLE_FORM_MERCADO_PAGO_ACCESS_TOKEN', 'CONST-DE-PRUEBA-EN-EL-BOOTSTRAP' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/support/FakeWordPress.php';

// --- dobles de las funciones de WordPress que usan las clases unitarias ------

function get_option( $key, $default = false ) {
	return \SimpleForm\Tests\FakeWordPress::get_option( (string) $key, $default );
}

function update_option( $key, $value ) {
	return \SimpleForm\Tests\FakeWordPress::update_option( (string) $key, $value );
}

function delete_option( $key ) {
	return \SimpleForm\Tests\FakeWordPress::delete_option( (string) $key );
}

function sanitize_key( $key ) {
	return \SimpleForm\Tests\FakeWordPress::sanitize_key( (string) $key );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function home_url() {
	return \SimpleForm\Tests\FakeWordPress::home_url();
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function wp_salt( $scheme = 'auth' ) {
	return \SimpleForm\Tests\FakeWordPress::salt();
}
