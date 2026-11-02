<?php
/**
 * Cinco recorridos completos del pago, cada uno con una configuración distinta,
 * buscando errores de flujo: sandbox, pendiente, rechazado, credencial de
 * producción sin HTTPS y una configuración rara (radio mínimo + servicio caro)
 * con un webhook de otro pedido.
 *
 * La API de Mercado Pago se simula con pre_http_request: acá no se cobra nada.
 * Lo que se prueba es nuestro cableado y las reglas de negocio.
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


use SimpleForm\Database\OrderRepository;
use SimpleForm\Payments\Gateway;
use SimpleForm\Payments\Gateways;
use SimpleForm\Payments\OrderStatus;
use SimpleForm\Payments\PaymentConfig;
use SimpleForm\Payments\PaymentProcessor;

global $wpdb;

class SF_Die extends Exception {}

function sf_die_handler( $message = '', $title = '', $args = array() ) {
	throw new SF_Die( is_string( $message ) ? $message : '' );
}

add_filter( 'wp_die_handler', fn() => 'sf_die_handler' );
add_filter( 'wp_die_ajax_handler', fn() => 'sf_die_handler' );
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

// --- Mercado Pago simulado ---------------------------------------------------

$GLOBALS['mp'] = array( 'preferencias' => array(), 'pago' => array() );

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		if ( false === strpos( $url, 'api.mercadopago.com' ) ) {
			return $preempt;
		}

		$responder = static function ( $code, $body ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
				'response' => array( 'code' => $code, 'message' => 'simulado' ),
			);
		};

		if ( false !== strpos( $url, '/checkout/preferences' ) ) {
			$cuerpo = json_decode( (string) ( $args['body'] ?? '' ), true );

			$GLOBALS['mp']['preferencias'][] = $cuerpo;

			$n = count( $GLOBALS['mp']['preferencias'] );

			return $responder( 201, array( 'id' => 'PREF-' . $n, 'init_point' => 'https://www.mercadopago.cl/checkout/start?pref_id=' . $n ) );
		}

		if ( false !== strpos( $url, '/v1/payments/' ) ) {
			return $responder( 200, $GLOBALS['mp']['pago'] );
		}

		return $responder( 200, array() );
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

	printf( "%s %-62s esperado=%-26s obtenido=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export( $expected, true ), var_export( $actual, true ) );
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

$ajax  = new \SimpleForm\Core\Ajax();
$table = \SimpleForm\Database\Database::table_name( \SimpleForm\Database\Database::TABLE_ORDERS );

$before = array(
	'services'    => get_option( 'simple_form_services', null ),
	'methods'     => get_option( 'simple_form_payment_methods', null ),
	'credentials' => get_option( 'simple_form_payment_credentials', null ),
	'radius'      => get_option( 'simple_form_radius', null ),
);

$ultimo_id = (int) $wpdb->get_var( "SELECT MAX(id) FROM `{$table}`" );

/** Inicia un pedido hasta el paso 3 con la configuración dada. */
function hasta_el_paso_3( $ajax, array $datos, string $servicio, float $radio ): array {
	list( $status, $json ) = call(
		$ajax,
		'save_step1',
		array(
			'name'            => $datos['nombre'],
			'email'           => $datos['email'],
			'phone'           => $datos['telefono'],
			'object_type'     => 'llaves',
			'object_size'     => 'pequeno',
			'object_material' => 'metal',
			'description'     => 'Objeto de prueba del recorrido',
		)
	);

	$session = (string) ( $json['data']['session_id'] ?? '' );

	call(
		$ajax,
		'save_step2',
		array(
			'session_id' => $session,
			'service_id' => $servicio,
			'lat'        => '-33.4489',
			'lng'        => '-70.6693',
			'radius_km'  => $radio,
		)
	);

	list( $codigo, $respuesta ) = call( $ajax, 'save_step3', array( 'session_id' => $session, 'payment_method' => 'mercado_pago' ) );

	$draft = \SimpleForm\Core\Session::find_draft_id( $session );
	$order = '' !== (string) $draft ? OrderRepository::find_by_draft_id( $draft ) : null;

	return array( $session, $codigo, $respuesta, $order );
}

/** Aplica el estado del pago como lo haría el webhook. */
function confirmar_pago( array $order, string $estado_mp ): string {
	$GLOBALS['mp']['pago'] = array(
		'id'                 => 'PAY-' . $order['id'],
		'status'             => $estado_mp,
		'external_reference' => (string) $order['id'],
	);

	$clase   = Gateways::gateway_class( 'mercado_pago' );
	$gateway = new $clase();

	if ( ! $gateway instanceof Gateway ) {
		return '';
	}

	$resultado = PaymentProcessor::apply( OrderRepository::find( (int) $order['id'] ), $gateway, 'PAY-' . $order['id'] );

	return (string) ( $resultado['status'] ?? '' );
}

try {
	update_option( 'simple_form_radius', array( 'min_m' => 10, 'max_m' => 100, 'step_m' => 10 ) );
	update_option(
		'simple_form_services',
		array(
			array( 'id' => 'basico', 'label' => 'plan basico', 'base_price' => 10000, 'price_per_km' => 40000 ),
			array( 'id' => 'premium', 'label' => 'plan premium', 'base_price' => 25000, 'price_per_km' => 80000 ),
		)
	);
	PaymentConfig::save_enabled_ids( array( 'mercado_pago' ) );

	// =========================================================== 1. sandbox
	echo "\n=== 1. Sandbox (token TEST-) + radio mínimo del rango ===\n";

	PaymentConfig::save( 'mercado_pago', 'sandbox', array( 'access_token' => 'TEST-sandbox-1', 'webhook_secret' => 'secreto-1' ) );

	check( 'el modo es pruebas', PaymentConfig::mode( 'mercado_pago' ), PaymentConfig::MODE_SANDBOX );

	list( $s1, $codigo, $respuesta, $o1 ) = hasta_el_paso_3( $ajax, array( 'nombre' => 'Uno Sandbox', 'email' => 'uno@example.test', 'telefono' => '912345001' ), 'basico', 0.01 );

	check( 'el pago se crea', $respuesta['success'] ?? null, true );
	check( 'redirige al checkout', $respuesta['data']['redirect_url'] ?? null, 'https://www.mercadopago.cl/checkout/start?pref_id=1' );
	check( 'el monto del pedido es el del servidor', (int) ( $o1['amount'] ?? 0 ), 10400 );
	check( 'la preferencia lleva el monto', $GLOBALS['mp']['preferencias'][0]['items'][0]['unit_price'] ?? null, 10400 );
	check( 'el pedido queda pendiente', (string) ( $o1['status'] ?? '' ), OrderStatus::PENDING );

	check( 'el pago aprobado se aplica', confirmar_pago( $o1, 'approved' ), OrderStatus::APPROVED );

	$o1 = OrderRepository::find( (int) $o1['id'] );

	check( 'y queda con fecha de pago', '' !== (string) ( $o1['paid_at'] ?? '' ), true );

	// ====================================================== 2. pago pendiente
	echo "\n=== 2. Pago pendiente y reintento del cliente ===\n";

	list( $s2, $codigo, $respuesta, $o2 ) = hasta_el_paso_3( $ajax, array( 'nombre' => 'Dos Pendiente', 'email' => 'dos@example.test', 'telefono' => '912345002' ), 'premium', 0.05 );

	check( 'el pago se crea', $respuesta['success'] ?? null, true );
	check( 'monto del plan premium a 50 m', (int) ( $o2['amount'] ?? 0 ), 29000 );

	$GLOBALS['mp']['pago'] = array( 'id' => 'PAY-2', 'status' => 'pending', 'external_reference' => (string) $o2['id'] );

	$clase   = Gateways::gateway_class( 'mercado_pago' );
	$gateway = new $clase();
	$r       = PaymentProcessor::apply( OrderRepository::find( (int) $o2['id'] ), $gateway, 'PAY-2' );

	check( 'un pago pendiente no aprueba nada', (string) ( $r['status'] ?? '' ), OrderStatus::PENDING );
	check( 'el pedido sigue pendiente', (string) ( OrderRepository::find( (int) $o2['id'] )['status'] ?? '' ), OrderStatus::PENDING );

	// El cliente vuelve a elegir el medio y reintenta desde el paso 3.
	$preferencias_antes = count( $GLOBALS['mp']['preferencias'] );

	list( $codigo, $respuesta ) = call( $ajax, 'save_step3', array( 'session_id' => $s2, 'payment_method' => 'mercado_pago' ) );

	check( 'el reintento crea otra preferencia', count( $GLOBALS['mp']['preferencias'] ) > $preferencias_antes, true );
	check( 'y responde con la redirección', ! empty( $respuesta['data']['redirect_url'] ), true );

	// ===================================================== 3. pago rechazado
	echo "\n=== 3. Pago rechazado y segundo intento aprobado ===\n";

	list( $s3, $codigo, $respuesta, $o3 ) = hasta_el_paso_3( $ajax, array( 'nombre' => 'Tres Rechazado', 'email' => 'tres@example.test', 'telefono' => '912345003' ), 'basico', 0.02 );

	check( 'el pago se crea', $respuesta['success'] ?? null, true );
	check( 'el pago rechazado se registra', confirmar_pago( $o3, 'rejected' ), OrderStatus::REJECTED );

	// El cliente reintenta sin cargar todo de nuevo: el recibo vuelve al Paso 3 y
	// save_step3 reutiliza el pedido (rejected -> pending). Un rechazado NO puede
	// pasar directo a aprobado: la máquina de estados exige el reintento.
	check( 'un rechazado no salta a aprobado', confirmar_pago( $o3, 'approved' ), '' );

	list( $codigo, $reintento ) = call( $ajax, 'save_step3', array( 'session_id' => $s3, 'payment_method' => 'mercado_pago' ) );

	check( 'el reintento se acepta', $reintento['success'] ?? null, true );
	check( 'y deja el pedido pendiente otra vez', (string) ( OrderRepository::find( (int) $o3['id'] )['status'] ?? '' ), OrderStatus::PENDING );
	check( 'después de reintentar sí se puede aprobar', confirmar_pago( $o3, 'approved' ), OrderStatus::APPROVED );

	$o3 = OrderRepository::find( (int) $o3['id'] );

	check( 'y queda con su fecha de pago', '' !== (string) ( $o3['paid_at'] ?? '' ), true );

	// ==================================== 4. credencial de producción sin HTTPS
	echo "\n=== 4. Credencial de producción con el sitio sin HTTPS ===\n";

	PaymentConfig::save( 'mercado_pago', 'production', array( 'access_token' => 'APP_USR-produccion-1', 'webhook_secret' => 'secreto-prod' ) );

	check( 'el modo detectado es producción', PaymentConfig::mode( 'mercado_pago' ), PaymentConfig::MODE_PRODUCTION );

	$preferencias_antes = count( $GLOBALS['mp']['preferencias'] );

	list( $s4, $codigo, $respuesta, $o4 ) = hasta_el_paso_3( $ajax, array( 'nombre' => 'Cuatro Produccion', 'email' => 'cuatro@example.test', 'telefono' => '912345004' ), 'basico', 0.03 );

	check( 'sin HTTPS no se crea la preferencia', count( $GLOBALS['mp']['preferencias'] ), $preferencias_antes );
	check( 'y el cliente ve un error, no una redirección', $codigo, 502 );
	check( 'el mensaje es genérico', $respuesta['data']['message'] ?? null, 'No se pudo iniciar el pago. Intenta nuevamente.' );

	// ================================ 5. configuración rara + webhook de otro pedido
	echo "\n=== 5. Radio mínimo con servicio caro y pago de otro pedido ===\n";

	PaymentConfig::save( 'mercado_pago', 'sandbox', array( 'access_token' => 'TEST-sandbox-5', 'webhook_secret' => 'secreto-5' ) );

	list( $s5, $codigo, $respuesta, $o5 ) = hasta_el_paso_3( $ajax, array( 'nombre' => 'Cinco Raro', 'email' => 'cinco@example.test', 'telefono' => '912345005' ), 'premium', 0.01 );

	check( 'el pago se crea', $respuesta['success'] ?? null, true );
	check( 'monto = base + 1 % del km', (int) ( $o5['amount'] ?? 0 ), 25800 );

	// Un pago de OTRO pedido no puede tocar este.
	$GLOBALS['mp']['pago'] = array( 'id' => 'PAY-AJENO', 'status' => 'approved', 'external_reference' => (string) ( (int) $o5['id'] + 999 ) );

	$clase   = Gateways::gateway_class( 'mercado_pago' );
	$gateway = new $clase();
	$r       = PaymentProcessor::apply( OrderRepository::find( (int) $o5['id'] ), $gateway, 'PAY-AJENO' );

	check( 'el pago ajeno no cambia el estado', (string) ( $r['status'] ?? '' ), '' );
	check( 'y el pedido sigue pendiente', (string) ( OrderRepository::find( (int) $o5['id'] )['status'] ?? '' ), OrderStatus::PENDING );

	// Un estado sin equivalente tampoco mueve nada.
	check( 'un estado desconocido no mueve el pedido', confirmar_pago( $o5, 'in_mediation' ), '' );
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
