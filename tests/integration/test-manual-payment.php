<?php
/**
 * Medio de pago propio (transferencia, efectivo) de punta a punta contra
 * WordPress de verdad.
 *
 * Cubre las cuatro piezas que se tocan:
 *   - el alta del medio en la pantalla del admin (nombre + "cómo pagar");
 *   - el Paso 3: el pedido queda pendiente, sin pasarela y sin redirección;
 *   - el recibo: nombre del medio e instrucciones;
 *   - Pedidos: "Marcar pago recibido" (pending -> approved) y que no se pueda
 *     confirmar a mano un cobro online.
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

require_once ABSPATH . 'wp-admin/includes/admin.php';

use SimpleForm\Admin\AdminOrders;
use SimpleForm\Admin\AdminPayments;
use SimpleForm\Core\Assets;
use SimpleForm\Core\Shortcode;
use SimpleForm\Payments\Methods;
use SimpleForm\Payments\OrderStatus;
use SimpleForm\Payments\PaymentConfig;

global $wpdb;

class SF_Die extends Exception {}
class SF_Redirect extends Exception {}

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

add_filter(
	'wp_redirect',
	function ( $location ) {
		$GLOBALS['sf_redirect'] = (string) $location;

		throw new SF_Redirect( 'redirect' );
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

	printf( "%s %-58s esperado=%-26s obtenido=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/** Ejecuta un endpoint AJAX y devuelve [status, JSON]. */
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

/** Ejecuta un admin-post handler y devuelve [tipo, valor]. */
function admin_call( $handler, array $post ) {
	$_POST    = $post;
	$_REQUEST = $post;

	try {
		call_user_func( $handler );
	} catch ( SF_Redirect $e ) {
		return array( 'redirect', (string) $GLOBALS['sf_redirect'] );
	} catch ( SF_Die $e ) {
		return array( 'die', strip_tags( $e->getMessage() ) );
	}

	return array( 'ninguno', '' );
}

$Ajax  = 'SimpleForm\Core\Ajax';
$ajax  = new $Ajax();
$table = \SimpleForm\Database\Database::table_name( \SimpleForm\Database\Database::TABLE_ORDERS );
$max_id = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

// --- estado previo -----------------------------------------------------------

$before = array(
	'services'    => get_option( 'simple_form_services', null ),
	'methods'     => get_option( 'simple_form_payment_methods', null ),
	'custom'      => get_option( Methods::OPTION_CUSTOM, null ),
	'radius'      => get_option( 'simple_form_radius', null ),
);

$admins   = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin_id = (int) ( $admins[0] ?? 0 );

$ultimo_id = (int) $wpdb->get_var( "SELECT MAX(id) FROM `{$table}`" );

try {
	echo "\n=== el admin da de alta el medio propio ===\n";

	wp_set_current_user( 0 );

	$admin_payments = new AdminPayments();

	list( $tipo, ) = admin_call( array( $admin_payments, 'handle_save' ), array() );
	check( 'sin permiso no guarda', $tipo, 'die' );

	wp_set_current_user( $admin_id );

	$_POST = array(
		AdminPayments::NONCE_SAVE => wp_create_nonce( AdminPayments::ACTION_SAVE ),
		'sf_payments_form'        => '1',
		'custom'                  => array(
			'nuevo-1' => array( 'id' => '', 'label' => 'Transferencia bancaria', 'note' => "Banco Estado\nCuenta 12345678\nA nombre de TeLoBuscamos" ),
			'nuevo-2' => array( 'id' => '', 'label' => 'Efectivo en oficina', 'note' => 'Solo con cita previa.' ),
		),
	);
	$_REQUEST = $_POST;

	try {
		$admin_payments->handle_save();
	} catch ( SF_Redirect $e ) {
		// La redirección corta el proceso: es lo esperado.
	}

	$propias = Methods::custom();

	check( 'se crearon los dos medios', count( $propias ), 2 );
	check( 'el id sale del nombre', $propias[0]['id'], 'transferencia-bancaria' );
	check( 'la nota se guardó tal cual', $propias[0]['note'], "Banco Estado\nCuenta 12345678\nA nombre de TeLoBuscamos" );
	check( 'quedan habilitados', PaymentConfig::enabled_ids(), array( 'transferencia-bancaria', 'efectivo-en-oficina' ) );
	check( 'son manuales', Methods::is_manual( 'transferencia-bancaria' ), true );

	// Editar el nombre no cambia el identificador.
	$_POST = array(
		AdminPayments::NONCE_SAVE => wp_create_nonce( AdminPayments::ACTION_SAVE ),
		'sf_payments_form'        => '1',
		'custom'                  => array(
			'transferencia-bancaria' => array( 'id' => 'transferencia-bancaria', 'label' => 'Transferencia o depósito', 'note' => 'Misma cuenta.' ),
		),
	);
	$_REQUEST = $_POST;

	try {
		$admin_payments->handle_save();
	} catch ( SF_Redirect $e ) {
		// idem
	}

	check( 'el id no cambió al renombrar', Methods::custom_ids(), array( 'transferencia-bancaria' ) );
	check( 'y el nombre sí', Methods::label( 'transferencia-bancaria' ), 'Transferencia o depósito' );

	echo "\n=== el formulario ofrece el medio ===\n";

	// El shortcode es estático (un formulario por página): se suelta la bandera.
	$flag = new ReflectionProperty( Shortcode::class, 'rendered' );
	$flag->setAccessible( true );
	$flag->setValue( null, false );

	update_option( 'simple_form_services', array( array( 'id' => 'sf-manual-plan', 'label' => 'Plan manual', 'base_price' => 10000, 'price_per_km' => 500 ) ) );
	update_option( 'simple_form_radius', array( 'min_m' => 100, 'max_m' => 1000, 'step_m' => 100 ) );

	$html = ( new Shortcode( new Assets( 'test' ) ) )->render( array() );

	check( 'el radio del medio propio está en el Paso 3', (bool) preg_match( '/name="payment_method" value="transferencia-bancaria"/', $html ), true );
	check( 'con su nombre visible', str_contains( $html, 'Transferencia o depósito' ), true );

	echo "\n=== Paso 3 con un medio propio ===\n";

	list( $status, $json ) = call(
		$ajax,
		'save_step1',
		array(
			'name'            => 'Cliente Manual',
			'email'           => 'manual@example.test',
			'phone'           => '912345680',
			'object_type'     => 'llaves',
			'object_size'     => 'pequeno',
			'object_material' => 'metal',
			'description'     => 'Llavero con cinta azul',
		)
	);

	$session = (string) ( $json['data']['session_id'] ?? '' );

	check( 'paso 1 guardado', $status, null );
	check( 'hay sesión', '' !== $session, true );

	list( $status, $json ) = call(
		$ajax,
		'save_step2',
		array(
			'session_id' => $session,
			'service_id' => 'sf-manual-plan',
			'lat'        => '-33.4489',
			'lng'        => '-70.6693',
			'radius_km'  => 0.5,
		)
	);

	check( 'paso 2 guardado', $json['success'] ?? null, true );

	list( $status, $json ) = call( $ajax, 'save_step3', array( 'session_id' => $session, 'payment_method' => 'transferencia-bancaria' ) );

	check( 'el pedido se completa', $json['success'] ?? null, true );
	check( 'sin redirección', isset( $json['data']['redirect_url'] ), false );
	check( 'marcado como manual', $json['data']['manual'] ?? null, true );
	check( 'con las instrucciones', $json['data']['instructions'] ?? null, 'Misma cuenta.' );
	check( 'el estado queda pendiente', $json['data']['progress']['status'] ?? null, OrderStatus::PENDING );
	check( 'y el paso en 4', $json['data']['progress']['current_step'] ?? null, 4 );
	check( 'el recibo trae el nombre del medio', $json['data']['progress']['step3']['payment_method_label'] ?? null, 'Transferencia o depósito' );
	check( 'y su cómo pagar', $json['data']['progress']['step3']['manual_instructions'] ?? null, 'Misma cuenta.' );

	$draft  = \SimpleForm\Core\Session::find_draft_id( $session );
	$order  = '' !== (string) $draft ? \SimpleForm\Database\OrderRepository::find_by_draft_id( $draft ) : null;
	$order_id = (int) ( $order['id'] ?? 0 );

	check( 'el pedido guarda el medio', $order['payment_method'] ?? null, 'transferencia-bancaria' );
	check( 'y el monto calculado por el servidor', (int) ( $order['amount'] ?? 0 ), 10250 );

	echo "\n=== el servidor no acepta cualquier medio ===\n";

	list( $status, $json ) = call( $ajax, 'save_step3', array( 'session_id' => $session, 'payment_method' => 'inventado' ) );
	check( 'un id que no existe: 422', $status, 422 );

	list( $status, $json ) = call( $ajax, 'save_step3', array( 'session_id' => $session, 'payment_method' => 'mercado_pago' ) );
	check( 'una pasarela no habilitada: 422', $status, 422 );

	echo "\n=== marcar el pago recibido (Pedidos) ===\n";

	wp_set_current_user( 0 );

	list( $tipo, ) = admin_call( array( new AdminOrders(), 'handle_mark_paid' ), array() );
	check( 'sin permiso no confirma', $tipo, 'die' );

	wp_set_current_user( $admin_id );

	$orders_admin = new AdminOrders();

	$_POST = array(
		AdminOrders::NONCE_MARK_PAID => 'nonce-falso',
		'order_id'                   => (string) $order_id,
	);
	$_REQUEST = $_POST;

	list( $tipo, ) = admin_call( array( $orders_admin, 'handle_mark_paid' ), $_POST );
	check( 'sin nonce válido no confirma', $tipo, 'die' );

	$GLOBALS['sf_redirect'] = '';

	try {
		$_POST    = array(
			AdminOrders::NONCE_MARK_PAID => wp_create_nonce( AdminOrders::ACTION_MARK_PAID ),
			'order_id'                   => (string) $order_id,
		);
		$_REQUEST = $_POST;
		$orders_admin->handle_mark_paid();
	} catch ( SF_Redirect $e ) {
		// idem
	}

	check( 'redirige con updated=paid', false !== strpos( (string) $GLOBALS['sf_redirect'], 'updated=paid' ), true );

	$order = \SimpleForm\Database\OrderRepository::find( $order_id );

	check( 'el pedido queda aprobado', $order['status'] ?? null, OrderStatus::APPROVED );
	check( 'con fecha de pago', '' !== (string) ( $order['paid_at'] ?? '' ), true );

	// Ya está aprobado: no se puede volver a confirmar.
	$GLOBALS['sf_redirect'] = '';

	try {
		$_POST    = array(
			AdminOrders::NONCE_MARK_PAID => wp_create_nonce( AdminOrders::ACTION_MARK_PAID ),
			'order_id'                   => (string) $order_id,
		);
		$_REQUEST = $_POST;
		$orders_admin->handle_mark_paid();
	} catch ( SF_Redirect $e ) {
		// idem
	}

	check( 'un pedido ya aprobado: error=state', false !== strpos( (string) $GLOBALS['sf_redirect'], 'error=state' ), true );

	echo "\n=== un cobro online no se confirma a mano ===\n";

	// Pedido de prueba con una pasarela (no se cobra nada: se inserta la fila).
	$wpdb->insert(
		$table,
		array(
			'draft_id'             => 'manual-test-' . wp_generate_uuid4(),
			'full_name'            => 'Online',
			'email'                => 'online@example.test',
			'phone'                => '912345681',
			'service_id'           => 'sf-manual-plan',
			'service_label'        => 'Plan manual',
			'service_base_price'   => 10000,
			'service_price_per_km' => 500,
			'lat'                  => -33.4489,
			'lng'                  => -70.6693,
			'radius_km'            => 0.5,
			'amount'               => 10250,
			'payment_method'       => 'mercado_pago',
			'status'               => OrderStatus::PENDING,
			'current_step'         => 4,
			'created_at'           => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'           => gmdate( 'Y-m-d H:i:s' ),
		)
	);

	$online_id = (int) $wpdb->insert_id;
	$GLOBALS['sf_redirect'] = '';

	try {
		$_POST    = array(
			AdminOrders::NONCE_MARK_PAID => wp_create_nonce( AdminOrders::ACTION_MARK_PAID ),
			'order_id'                   => (string) $online_id,
		);
		$_REQUEST = $_POST;
		$orders_admin->handle_mark_paid();
	} catch ( SF_Redirect $e ) {
		// idem
	}

	check( 'un pago online: error=not-manual', false !== strpos( (string) $GLOBALS['sf_redirect'], 'error=not-manual' ), true );

	$online = \SimpleForm\Database\OrderRepository::find( $online_id );

	check( 'y sigue pendiente', $online['status'] ?? null, OrderStatus::PENDING );
} catch ( Throwable $e ) {
	echo "\nERROR EN LA PRUEBA: " . $e->getMessage() . "\n";
	++$failures;
} finally {
	foreach ( $before as $clave => $valor ) {
		$opcion = array(
			'services' => 'simple_form_services',
			'methods'  => 'simple_form_payment_methods',
			'custom'   => Methods::OPTION_CUSTOM,
			'radius'   => 'simple_form_radius',
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
