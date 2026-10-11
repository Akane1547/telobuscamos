<?php
/**
 * El límite de peticiones, contra WordPress de verdad.
 *
 * Baja el límite de save_step1 con el filtro y comprueba que la tercera llamada
 * se corte con 429. Las dos primeras van con el cuerpo vacío a propósito: no
 * llegan a crear nada, porque el contador corre antes de mirar la entrada. Lo
 * que se prueba es el contador.
 */

define( 'WP_USE_THEMES', false );

// admin-ajax.php define DOING_AJAX antes de cargar WordPress: sin esto,
// wp_send_json() termina en `die;` y no pasa por wp_die(), que es donde se
// engancha el handler para leer la respuesta.
define( 'DOING_AJAX', true );

$sf_site_root = (string) getenv( 'SF_SITE_PATH' );
require ( '' !== $sf_site_root ? rtrim( $sf_site_root, '/' ) : dirname( __DIR__, 5 ) ) . '/wp-load.php';

global $wpdb;

class SF_Die extends Exception {}

function sf_die_handler( $message = '', $title = '', $args = array() ) {
	throw new SF_Die( is_string( $message ) ? $message : '' );
}

add_filter(
	'wp_die_handler',
	function () {
		return 'sf_die_handler';
	}
);
add_filter(
	'wp_die_ajax_handler',
	function () {
		return 'sf_die_handler';
	}
);

$GLOBALS['sf_status'] = null;
add_filter(
	'status_header',
	function ( $status_header, $code ) {
		$GLOBALS['sf_status'] = $code;

		return $status_header;
	},
	10,
	2
);

// Límite chico solo para la acción que se prueba: el resto queda como está.
add_filter(
	'simple_form_rate_limit',
	function ( $limit, $action ) {
		return 'save_step1' === $action ? 2 : $limit;
	},
	10,
	2
);

// Cliente propio y distinto en cada corrida para esta prueba. El contador es
// por acción y por cliente: las otras suites corren en el mismo minuto desde la
// misma IP y dejan el contador de save_step1 ya gastado, y una corrida anterior
// de esta misma prueba también. Con una IP nueva cada vez, el contador arranca
// en cero y la prueba no depende del orden.
$cliente_de_prueba = '203.0.113.' . wp_rand( 10, 250 );

add_filter(
	'simple_form_client_ip',
	function () use ( $cliente_de_prueba ) {
		return $cliente_de_prueba;
	}
);

ob_start();

$failures = 0;

function check( $label, $actual, $expected ) {
	global $failures;

	$ok = ( $actual === $expected );
	if ( ! $ok ) {
		$failures++;
	}

	printf( "%s %-46s esperado=%-14s obtenido=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/**
 * Llama a un endpoint y devuelve el código de estado y el JSON de la respuesta.
 */
function llamar( $ajax, string $metodo ): array {
	$GLOBALS['sf_status'] = null;

	// Buffer propio para esta llamada: así el ob_get_clean() de abajo se lleva
	// SOLO lo que imprimió el endpoint y no la salida acumulada de la prueba.
	ob_start();

	try {
		$ajax->$metodo();
	} catch ( SF_Die $e ) {
		// wp_send_json_* corta con wp_die(): acá se lee lo que alcanzó a imprimir.
	}

	$salida = ob_get_clean();

	return array( $GLOBALS['sf_status'], json_decode( (string) $salida, true ) );
}

$max_id = (int) $wpdb->get_var( "SELECT COALESCE( MAX(id), 0 ) FROM {$wpdb->prefix}sf_orders" );

$_POST = array( 'nonce' => wp_create_nonce( 'simple_form_nonce' ) );

$ajax = new \SimpleForm\Core\Ajax();

echo "=== limite de 2 para save_step1 ===\n";

list( $primera ) = llamar( $ajax, 'save_step1' );
check( 'la primera pasa', 429 === $primera ? '429' : 'pasa', 'pasa' );

list( $segunda ) = llamar( $ajax, 'save_step1' );
check( 'la segunda pasa', 429 === $segunda ? '429' : 'pasa', 'pasa' );

list( $tercera, $json ) = llamar( $ajax, 'save_step1' );
check( 'la tercera se corta con 429', $tercera, 429 );
check(
	'y avisa en el mensaje',
	is_array( $json ) ? ( $json['data']['message'] ?? '' ) : '',
	'Demasiadas peticiones seguidas. Esperá un momento y volvé a intentar.'
);

echo "\n=== otra accion tiene su propio contador ===\n";

list( $otra ) = llamar( $ajax, 'save_step2' );
check( 'save_step2 no se ve afectada', 429 === $otra ? '429' : 'pasa', 'pasa' );

echo "\n=== no quedo ningun pedido de prueba ===\n";

$nuevos = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}sf_orders WHERE id > %d", $max_id ) );
check( 'sin pedidos nuevos', $nuevos, 0 );

printf( "\n%s\n", 0 === $failures ? 'TODO OK' : "FALLAS: {$failures}" );
exit( $failures > 0 ? 1 : 0 );
