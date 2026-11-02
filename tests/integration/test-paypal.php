<?php
/**
 * PayPal de punta a punta contra WordPress de verdad, con la API simulada.
 *
 * Cubre el cableado que no se puede probar en unitarios: que se pida el token,
 * que la orden salga con el monto en USD y el id del pedido, que el id externo
 * quede guardado, y que al volver del comprador el servidor capture y deje el
 * pedido aprobado (sin webhook: el retorno sincroniza).
 *
 * Restaura las opciones y borra los pedidos de la corrida al terminar.
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


use SimpleForm\Payments\OrderStatus;
use SimpleForm\Payments\PaymentConfig;

global $wpdb;

class SF_Die extends Exception {}

function sf_die_handler( $message = '', $title = '', $args = array() ) {
	throw new SF_Die( is_string( $message ) ? $message : '' );
}

add_filter( 'wp_die_handler', fn() => 'sf_die_handler' );
add_filter( 'wp_die_ajax_handler', fn() => 'sf_die_handler' );

// El pago aprobado dispara el correo al administrador: no se manda de verdad.
add_filter( 'pre_wp_mail', '__return_true' );

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

// --- API de PayPal simulada --------------------------------------------------

$GLOBALS['sf_pp'] = array( 'llamadas' => array(), 'orden' => '', 'capturada' => false );

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		if ( false === strpos( $url, 'api-m.sandbox.paypal.com' ) && false === strpos( $url, 'api-m.paypal.com' ) ) {
			return $preempt;
		}

		$GLOBALS['sf_pp']['llamadas'][] = array(
			'url'  => $url,
			'body' => is_array( $args['body'] ?? null ) ? $args['body'] : json_decode( (string) ( $args['body'] ?? '' ), true ),
			'auth' => $args['headers']['Authorization'] ?? '',
		);

		$responder = function ( $code, $body ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
				'response' => array( 'code' => $code, 'message' => 'simulado' ),
			);
		};

		if ( false !== strpos( $url, '/v1/oauth2/token' ) ) {
			return $responder( 200, array( 'access_token' => 'token-simulado', 'token_type' => 'Bearer' ) );
		}

		if ( false !== strpos( $url, '/capture' ) ) {
			$GLOBALS['sf_pp']['capturada'] = true;

			// A propósito sin custom_id: el servidor tiene que conservarlo.
			return $responder(
				201,
				array(
					'id'             => 'PAYPAL-ORDER-1',
					'status'         => 'COMPLETED',
					'purchase_units' => array( array( 'payments' => array( 'captures' => array( array( 'id' => 'CAP-1' ) ) ) ) ),
				)
			);
		}

		if ( false !== strpos( $url, '/v2/checkout/orders/' ) ) {
			return $responder(
				200,
				array(
					'id'             => 'PAYPAL-ORDER-1',
					'status'         => 'APPROVED',
					'purchase_units' => array( array( 'custom_id' => (string) $GLOBALS['sf_pp']['orden'] ) ),
				)
			);
		}

		return $responder(
			201,
			array(
				'id'     => 'PAYPAL-ORDER-1',
				'status' => 'CREATED',
				'links'  => array(
					array( 'rel' => 'self', 'href' => 'https://api.sandbox.paypal.com/v2/checkout/orders/PAYPAL-ORDER-1' ),
					array( 'rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-ORDER-1' ),
				),
			)
		);
	},
	10,
	3
);

ob_start();

$failures = 0;

function check( $label, $actual, $expected ) {
	global $failures;

	$ok = ( $actual === $expected );
	if ( ! $ok ) {
		$failures++;
	}

	printf( "%s %-58s esperado=%-30s obtenido=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export( $expected, true ), var_export( $actual, true ) );
}

function call( $ajax, string $method, array $post ) {
	$GLOBALS['sf_status'] = null;

	$_POST    = array_merge( array( 'nonce' => wp_create_nonce( 'simple_form_nonce' ) ), $post );
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
$table = \SimpleForm\Database\Database::table_name( \SimpleForm\Database\Database::TABLE_ORDERS );

$before = array(
	'services'    => get_option( 'simple_form_services', null ),
	'methods'     => get_option( 'simple_form_payment_methods', null ),
	'credentials' => get_option( 'simple_form_payment_credentials', null ),
	'radius'      => get_option( 'simple_form_radius', null ),
);

$ultimo_id = (int) $wpdb->get_var( "SELECT MAX(id) FROM `{$table}`" );

try {
	echo "\n=== credenciales de PayPal guardadas en wp_options ===\n";

	update_option( 'simple_form_radius', array( 'min_m' => 100, 'max_m' => 1000, 'step_m' => 100 ) );

	$resultado = PaymentConfig::save(
		'paypal',
		'sandbox',
		array(
			'client_id'     => 'client-de-prueba',
			'client_secret' => 'secreto-de-prueba',
			'usd_rate'      => '900',
		)
	);

	check( 'se guardaron las credenciales', $resultado, '' );
	check( 'quedan en su modo', PaymentConfig::has_keys( 'paypal', 'sandbox' ), true );
	check( 'la tasa se lee de vuelta', PaymentConfig::credential( 'paypal', 'usd_rate' ), '900' );

	$crudo = (string) wp_json_encode( get_option( 'simple_form_payment_credentials' ) );

	check( 'el secreto no está en claro', false !== strpos( $crudo, 'secreto-de-prueba' ), false );

	PaymentConfig::save_enabled_ids( array( 'paypal' ) );

	update_option(
		'simple_form_services',
		array( array( 'id' => 'sf-paypal-plan', 'label' => 'Plan PayPal', 'base_price' => 10000, 'price_per_km' => 500 ) )
	);

	echo "\n=== Paso 3 con PayPal: orden creada y redirección ===\n";

	list( $status, $json ) = call(
		$ajax,
		'save_step1',
		array(
			'name'            => 'Cliente PayPal',
			'email'           => 'paypal@example.test',
			'phone'           => '912345681',
			'object_type'     => 'llaves',
			'object_size'     => 'pequeno',
			'object_material' => 'metal',
			'description'     => 'Llavero con cinta roja',
		)
	);

	$session = (string) ( $json['data']['session_id'] ?? '' );

	check( 'paso 1 guardado', $status, null );

	list( $status, $json ) = call(
		$ajax,
		'save_step2',
		array(
			'session_id' => $session,
			'service_id' => 'sf-paypal-plan',
			'lat'        => '-33.4489',
			'lng'        => '-70.6693',
			'radius_km'  => 0.5,
		)
	);

	check( 'paso 2 guardado', $json['success'] ?? null, true );

	list( $status, $json ) = call( $ajax, 'save_step3', array( 'session_id' => $session, 'payment_method' => 'paypal' ) );

	check( 'el paso 3 responde', $json['success'] ?? null, true );
	check( 'redirige a PayPal', $json['data']['redirect_url'] ?? null, 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-ORDER-1' );

	$llamadas = $GLOBALS['sf_pp']['llamadas'];
	$creacion = null;

	foreach ( $llamadas as $llamada ) {
		if ( false !== strpos( $llamada['url'], '/v2/checkout/orders' ) && false === strpos( $llamada['url'], '/v2/checkout/orders/' ) ) {
			$creacion = $llamada;
		}
	}

	check( 'se pidió el token antes', false !== strpos( $llamadas[0]['url'] ?? '', '/v1/oauth2/token' ), true );
	check( 'la orden va en USD', $creacion['body']['purchase_units'][0]['amount']['currency_code'] ?? null, 'USD' );
	// 10.250 CLP / 900 = 11,39 USD.
	check( 'con el monto convertido', $creacion['body']['purchase_units'][0]['amount']['value'] ?? null, '11.39' );

	$draft    = \SimpleForm\Core\Session::find_draft_id( $session );
	$order    = '' !== (string) $draft ? \SimpleForm\Database\OrderRepository::find_by_draft_id( $draft ) : null;
	$order_id = (int) ( $order['id'] ?? 0 );

	check( 'la orden lleva el id del pedido', $creacion['body']['purchase_units'][0]['custom_id'] ?? null, (string) $order_id );
	check( 'el id externo queda guardado', (string) ( $order['external_payment_id'] ?? '' ), 'PAYPAL-ORDER-1' );
	check( 'y el pedido sigue pendiente', (string) ( $order['status'] ?? '' ), OrderStatus::PENDING );

	echo "\n=== al volver: el servidor captura y aprueba ===\n";

	$GLOBALS['sf_pp']['orden'] = (string) $order_id;

	list( $status, $json ) = call( $ajax, 'sync_payment', array( 'session_id' => $session ) );

	check( 'la sincronización responde', $json['success'] ?? null, true );
	check( 'se capturó la orden', $GLOBALS['sf_pp']['capturada'], true );
	check( 'el pedido queda aprobado', $json['data']['progress']['status'] ?? null, OrderStatus::APPROVED );

	$order = \SimpleForm\Database\OrderRepository::find( $order_id );

	check( 'y se sella la fecha de pago', '' !== (string) ( $order['paid_at'] ?? '' ), true );

	echo "\n=== sin nonce no se puede sincronizar ===\n";

	$GLOBALS['sf_status'] = null;
	$_POST                = array( 'session_id' => $session );
	$_REQUEST             = $_POST;
	ob_start();

	try {
		$ajax->sync_payment();
	} catch ( SF_Die $e ) {
		// wp_die: nonce inválido.
	}

	ob_end_clean();

	check( 'sin nonce: rechazado', $GLOBALS['sf_status'], 403 );

	echo "\n=== una sesión inventada no filtra nada ===\n";

	list( $status, $json ) = call( $ajax, 'sync_payment', array( 'session_id' => 'sesionInventada123' ) );

	check( 'sesión inexistente: 410', $status, 410 );

	echo "\n=== el Paso 3 no acepta medios que no estén habilitados ===\n";

	list( $status, $json ) = call( $ajax, 'save_step3', array( 'session_id' => $session, 'payment_method' => 'mercado_pago' ) );
	check( 'una pasarela no habilitada: 422', $status, 422 );

	list( $status, $json ) = call( $ajax, 'save_step3', array( 'session_id' => $session, 'payment_method' => 'stripe' ) );
	check( 'una pasarela desconocida: 422', $status, 422 );
} catch ( Throwable $e ) {
	echo "\nERROR EN LA PRUEBA: " . $e->getMessage() . "\n";
	++$failures;
} finally {
	foreach ( $before as $clave => $valor ) {
		$opcion = array(
			'services'    => 'simple_form_services',
			'methods'     => 'simple_form_payment_methods',
			'credentials' => 'simple_form_payment_credentials',
			'radius'      => 'simple_form_radius',
		)[ $clave ];

		if ( null === $valor ) {
			delete_option( $opcion );
		} else {
			update_option( $opcion, $valor );
		}
	}

	$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE id > %d", $ultimo_id ) );

	printf( "\nopciones restauradas y pedidos de prueba borrados (id > %d)\n", $ultimo_id );
}

printf( "\n%s\n", 0 === $failures ? 'TODO OK' : "FALLAS: {$failures}" );
exit( $failures > 0 ? 1 : 0 );
