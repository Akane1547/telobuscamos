<?php
/**
 * Radio de búsqueda: el rango que fija el administrador manda en el slider, en
 * el validador del navegador y en la validación del servidor.
 *
 * Restaura la opción del radio y la lista de servicios al terminar.
 */

define( 'WP_USE_THEMES', false );
define( 'DOING_AJAX', true );

$sf_site_root = (string) getenv( 'SF_SITE_PATH' );
require ( '' !== $sf_site_root ? rtrim( $sf_site_root, '/' ) : dirname( __DIR__, 5 ) ) . '/wp-load.php';

// El límite por minuto existe para el sitio real; corriendo 13 suites seguidas
// desde la misma IP se llega a 429 y las pruebas fallan por un motivo que no
// tiene que ver con lo que prueban. El hook es el que el propio RateLimit
// documenta para desactivarlo en pruebas (test-rate-limit.php no lo usa).
add_filter( 'simple_form_rate_limit', '__return_zero' );


use SimpleForm\Admin\AdminRadius;
use SimpleForm\Core\Assets;
use SimpleForm\Core\Radius;
use SimpleForm\Core\Shortcode;

class SF_Die extends Exception {}

function sf_die_handler( $message = '', $title = '', $args = array() ) {
	throw new SF_Die( is_string( $message ) ? $message : '' );
}

add_filter( 'wp_die_handler', fn() => 'sf_die_handler' );
add_filter( 'wp_die_ajax_handler', fn() => 'sf_die_handler' );

$GLOBALS['sf_status']   = null;
$GLOBALS['sf_redirect'] = '';

add_filter(
	'status_header',
	function ( $status_header, $code ) {
		$GLOBALS['sf_status'] = $code;

		return $status_header;
	},
	10,
	2
);

// wp_safe_redirect() termina en exit; se corta para poder ver el destino.
add_filter(
	'wp_redirect',
	function ( $location ) {
		$GLOBALS['sf_redirect'] = (string) $location;

		throw new SF_Die( 'redirect' );
	},
	1
);

ob_start();

$failures = 0;

function check( $label, $actual, $expected ) {
	global $failures;

	$ok = ( $actual === $expected );
	if ( ! $ok ) {
		$failures++;
	}

	printf( "%s %-58s esperado=%-22s obtenido=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export( $expected, true ), var_export( $actual, true ) );
}

function call( $ajax, string $method, array $post, bool $con_nonce = true ) {
	$GLOBALS['sf_status'] = null;

	$_POST    = $con_nonce ? array_merge( array( 'nonce' => wp_create_nonce( 'simple_form_nonce' ) ), $post ) : $post;
	$_REQUEST = $_POST;

	ob_start();

	try {
		$ajax->{$method}();
	} catch ( SF_Die $e ) {
		return array( $GLOBALS['sf_status'], json_decode( ob_get_clean(), true ) );
	}

	return array( 'no-die', json_decode( ob_get_clean(), true ) );
}

$Ajax  = 'SimpleForm\Core\Ajax';
$ajax  = new $Ajax();

$radio_before    = get_option( Radius::OPTION, null );
$services_before = get_option( 'simple_form_services', null );

global $wpdb;
$tabla_pedidos = \SimpleForm\Database\Database::table_name( \SimpleForm\Database\Database::TABLE_ORDERS );
$max_pedido    = (int) $wpdb->get_var( "SELECT MAX(id) FROM `{$tabla_pedidos}`" );

try {
	// ------------------------------------------------------------- defaults

	echo "\n=== siembra y normalización ===\n";

	delete_option( Radius::OPTION );
	Radius::install();

	check( 'install siembra 100 / 1000 / 100', get_option( Radius::OPTION ), Radius::DEFAULTS );

	update_option( Radius::OPTION, array( 'min_m' => 300, 'max_m' => 900, 'step_m' => 100 ) );
	Radius::install();
	check( 'no pisa una configuración propia', Radius::min_m(), 300 );

	$raro = Radius::normalize( array( 'min_m' => 15, 'max_m' => 1000, 'step_m' => 33 ) );
	check( 'cuantiza a múltiplos de 10', array( $raro['min_m'], $raro['step_m'] ), array( 20, 30 ) );

	$invertido = Radius::normalize( array( 'min_m' => 2000, 'max_m' => 1000 ) );
	check( 'un mínimo mayor que el máximo se corrige', $invertido['min_m'] < $invertido['max_m'], true );
	check( 'sin basura (texto en vez de número) usa el default', Radius::normalize( array( 'min_m' => 'abc' ) )['min_m'], Radius::DEFAULTS['min_m'] );

	check( 'format: metros', Radius::format( 0.1 ), '100 m' );
	check( 'format: km entero', Radius::format( 1 ), '1 km' );
	check( 'format: medio km', Radius::format( 1.5 ), '1,5 km' );

	// --------------------------------------------------------- lo que se pinta

	echo "\n=== el slider del formulario usa el rango guardado ===\n";

	update_option( Radius::OPTION, array( 'min_m' => 200, 'max_m' => 5000, 'step_m' => 500 ) );

	$html = ( new Shortcode( new Assets( 'test' ) ) )->render( array() );

	check( 'min del slider', preg_match( '/id="pn-map-radius"[^>]*min="200"/s', $html ), 1 );
	check( 'max del slider', preg_match( '/id="pn-map-radius"[^>]*max="5000"/s', $html ), 1 );
	check( 'step del slider', preg_match( '/id="pn-map-radius"[^>]*step="500"/s', $html ), 1 );
	check( 'value inicial en el mínimo', preg_match( '/id="pn-map-radius"[^>]*value="200"/s', $html ), 1 );
	check( 'el campo en km arranca en 0.2', preg_match( '/id="pn-radius"[^>]*value="0.2"/s', $html ), 1 );
	check( 'la etiqueta muestra 200 m', str_contains( $html, '>200 m<' ), true );

	// ------------------------------------------------------------ el servidor

	echo "\n=== el servidor valida contra el mismo rango ===\n";

	update_option(
		'simple_form_services',
		array(
			array(
				'id'           => 'sf-radius-plan',
				'label'        => 'Plan de radio',
				'base_price'   => 1000,
				'price_per_km' => 200,
			),
		)
	);

	$santiago = array( 'lat' => '-33.4489', 'lng' => '-70.6693' );

	list( $status, $json ) = call( $ajax, 'calculate_price', array( 'service_id' => 'sf-radius-plan' ) + $santiago + array( 'radius_km' => 0.6 ) );
	check( 'radio dentro del rango: calcula', $status, null );
	check( 'precio = base + por km', $json['data']['price'], 1120 );

	list( $status, $json ) = call( $ajax, 'calculate_price', array( 'service_id' => 'sf-radius-plan' ) + $santiago + array( 'radius_km' => 0.15 ) );
	check( 'por debajo del mínimo: 422', $status, 422 );

	list( $status, $json ) = call( $ajax, 'calculate_price', array( 'service_id' => 'sf-radius-plan' ) + $santiago + array( 'radius_km' => 5.5 ) );
	check( 'por encima del máximo: 422', $status, 422 );

	// El mismo payload, con el rango movido: lo que antes valía, ya no.
	update_option( Radius::OPTION, array( 'min_m' => 100, 'max_m' => 1000, 'step_m' => 100 ) );

	list( $status, $json ) = call( $ajax, 'calculate_price', array( 'service_id' => 'sf-radius-plan' ) + $santiago + array( 'radius_km' => 0.6 ) );
	check( 'ajustar el rango cambia la validación', $json['data']['price'], 1120 );

	list( $status, $json ) = call( $ajax, 'calculate_price', array( 'service_id' => 'sf-radius-plan' ) + $santiago + array( 'radius_km' => 5.5 ) );
	check( 'y 5,5 km sigue fuera de rango', $status, 422 );

	// ------------------------------------- el paso 2 se completa con el rango

	echo "\n=== con 10 m de mínimo el Paso 2 también se completa ===\n";

	// Regresión: is_step_complete() exigía radius_km >= 0.1 (100 m) escrito a
	// mano; con un rango de 10 a 100 m ningún pedido avanzaba del Paso 2.
	update_option( Radius::OPTION, array( 'min_m' => 10, 'max_m' => 100, 'step_m' => 10 ) );

	list( $status, $json ) = call(
		$ajax,
		'save_step1',
		array(
			'name'            => 'Radio Corto',
			'email'           => 'radio.corto@example.test',
			'phone'           => '912345690',
			'object_type'     => 'llaves',
			'object_size'     => 'pequeno',
			'object_material' => 'metal',
			'description'     => 'Llavero con cinta roja',
		)
	);

	$session_corto = (string) ( $json['data']['session_id'] ?? '' );

	check( 'paso 1 guardado', $status, null );

	list( $status, $json ) = call(
		$ajax,
		'save_step2',
		array(
			'session_id' => $session_corto,
			'service_id' => 'sf-radius-plan',
			'lat'        => '-33.4489',
			'lng'        => '-70.6693',
			'radius_km'  => 0.01,
		)
	);

	check( 'el radio mínimo del rango se acepta', $json['success'] ?? null, true );
	check( 'y el Paso 2 queda completo', isset( $json['data']['progress']['step2'] ), true );
	check( 'con el radio guardado', $json['data']['progress']['step2']['radius_km'] ?? null, 0.01 );

	$draft_corto = \SimpleForm\Core\Session::find_draft_id( $session_corto );
	$order_corto = '' !== (string) $draft_corto ? \SimpleForm\Database\OrderRepository::find_by_draft_id( $draft_corto ) : null;

	check( 'is_step_complete() lo da por completo', \SimpleForm\Database\OrderRepository::is_step_complete( (array) $order_corto, 2 ), true );

	// Un radio por debajo del mínimo sigue rechazándose.
	list( $status, $json ) = call(
		$ajax,
		'save_step2',
		array(
			'session_id' => $session_corto,
			'service_id' => 'sf-radius-plan',
			'lat'        => '-33.4489',
			'lng'        => '-70.6693',
			'radius_km'  => 0.005,
		)
	);

	check( 'por debajo del mínimo: 422', $status, 422 );

	// Se deja el rango que espera la sección siguiente.
	update_option( Radius::OPTION, array( 'min_m' => 100, 'max_m' => 1000, 'step_m' => 100 ) );

	// -------------------------------------------------------------- wp-admin

	echo "\n=== la pantalla del admin ===\n";

	$admin = new AdminRadius();

	wp_set_current_user( 0 );

	try {
		$admin->handle_save();
		check( 'sin permiso no guarda', 'siguió', 'cortó' );
	} catch ( SF_Die $e ) {
		check( 'sin permiso no guarda', 'cortó', 'cortó' );
	}

	wp_set_current_user( 1 );

	try {
		$_POST    = array( 'radius' => array( 'min_m' => 500, 'max_m' => 2000, 'step_m' => 100 ) );
		$_REQUEST = $_POST;
		$admin->handle_save();
		check( 'sin nonce no guarda', 'siguió', 'cortó' );
	} catch ( SF_Die $e ) {
		check( 'sin nonce no guarda', 'cortó', 'cortó' );
	}

	check( 'y el rango no cambió', Radius::all(), array( 'min_m' => 100, 'max_m' => 1000, 'step_m' => 100 ) );

	/**
	 * Guarda con nonce y devuelve la URL de destino.
	 */
	$guardar = function ( array $valores ) use ( $admin ) {
		$GLOBALS['sf_redirect'] = '';

		$_POST    = array(
			'sf_radius_nonce' => wp_create_nonce( 'sf_save_radius' ),
			'radius'          => $valores,
		);
		$_REQUEST = $_POST;

		try {
			$admin->handle_save();
		} catch ( SF_Die $e ) {
			// La redirección corta el proceso: es lo esperado.
		}

		return (string) $GLOBALS['sf_redirect'];
	};

	$destino = $guardar( array( 'min_m' => 500, 'max_m' => 3000, 'step_m' => 250 ) );

	check( 'guardado correcto redirige con updated=1', false !== strpos( $destino, 'updated=1' ), true );
	check( 'el rango quedó guardado', Radius::all(), array( 'min_m' => 500, 'max_m' => 3000, 'step_m' => 250 ) );
	check( 'el km del mínimo', Radius::min_km(), 0.5 );

	check( 'mínimo mayor que máximo: error', false !== strpos( $guardar( array( 'min_m' => 2000, 'max_m' => 1000, 'step_m' => 100 ) ), 'error=min' ), true );
	check( 'y no se guardó nada', Radius::all(), array( 'min_m' => 500, 'max_m' => 3000, 'step_m' => 250 ) );

	check( 'máximo sobre el techo: error', false !== strpos( $guardar( array( 'min_m' => 100, 'max_m' => 60000, 'step_m' => 100 ) ), 'error=max' ), true );
	check( 'paso mayor que el rango: error', false !== strpos( $guardar( array( 'min_m' => 100, 'max_m' => 200, 'step_m' => 5000 ) ), 'error=step' ), true );
	check( 'paso de menos de 10 m: error', false !== strpos( $guardar( array( 'min_m' => 100, 'max_m' => 900, 'step_m' => 5 ) ), 'error=step' ), true );
	check( 'mínimo bajo el piso: error', false !== strpos( $guardar( array( 'min_m' => 1, 'max_m' => 900, 'step_m' => 100 ) ), 'error=min' ), true );

	// El caso real que reportó el dueño: 10 m de mínimo y 100 m de máximo con
	// paso 100 (mayor que el rango de 90). Se rechaza, pero el formulario tiene
	// que volver con lo que escribió y no con lo guardado.
	$destino = $guardar( array( 'min_m' => 10, 'max_m' => 100, 'step_m' => 100 ) );

	check( 'paso mayor que el rango: error', false !== strpos( $destino, 'error=step' ), true );

	$intento = get_transient( 'sf_radius_intento_' . get_current_user_id() );

	check( 'el intento rechazado se conserva para el formulario', is_array( $intento ) ? $intento['valores'] : null, array( 'min_m' => 10, 'max_m' => 100, 'step_m' => 100 ) );
	check( 'y el rango guardado no cambió', Radius::all(), array( 'min_m' => 500, 'max_m' => 3000, 'step_m' => 250 ) );

	delete_transient( 'sf_radius_intento_' . get_current_user_id() );

	// Con el paso corregido, esos mismos valores son válidos y se guardan.
	$destino = $guardar( array( 'min_m' => 10, 'max_m' => 100, 'step_m' => 10 ) );

	check( '10/100/10 con paso 10: guarda', false !== strpos( $destino, 'updated=1' ), true );
	check( 'y el rango queda 10 a 100 de a 10', Radius::all(), array( 'min_m' => 10, 'max_m' => 100, 'step_m' => 10 ) );

	// El slider del formulario sale con ese rango.
	update_option( Radius::OPTION, array( 'min_m' => 10, 'max_m' => 100, 'step_m' => 10 ) );

	// Shortcode::$rendered es estático (un formulario por página): para volver a
	// renderizar en este mismo proceso hay que soltarlo.
	$flag = new ReflectionProperty( Shortcode::class, 'rendered' );
	$flag->setAccessible( true );
	$flag->setValue( null, false );

	$html = ( new Shortcode( new Assets( 'test' ) ) )->render( array() );

	check( 'slider con mínimo 10', preg_match( '/id="pn-map-radius"[^>]*min="10"/s', $html ), 1 );
	check( 'slider con máximo 100', preg_match( '/id="pn-map-radius"[^>]*max="100"/s', $html ), 1 );
	check( 'slider con paso 10', preg_match( '/id="pn-map-radius"[^>]*step="10"/s', $html ), 1 );
	check( 'campo en km en 0.01', preg_match( '/id="pn-radius"[^>]*value="0.01"/s', $html ), 1 );
} catch ( Throwable $e ) {
	echo "\nERROR EN LA PRUEBA: " . $e->getMessage() . "\n";
	++$failures;
} finally {
	if ( null === $radio_before ) {
		delete_option( Radius::OPTION );
	} else {
		update_option( Radius::OPTION, $radio_before );
	}

	if ( null === $services_before ) {
		delete_option( 'simple_form_services' );
	} else {
		update_option( 'simple_form_services', $services_before );
	}

	// Los borradores que crea la prueba no se quedan en el sitio.
	$wpdb->query( $wpdb->prepare( "DELETE FROM `{$tabla_pedidos}` WHERE id > %d", $max_pedido ) );

	echo "\nradio y servicios restaurados; pedidos de prueba borrados\n";
}

printf( "\n%s\n", 0 === $failures ? 'TODO OK' : "FALLAS: {$failures}" );
exit( $failures > 0 ? 1 : 0 );
