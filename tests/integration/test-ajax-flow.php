<?php
/**
 * Prueba REAL de Fase 2: ejecuta los endpoints de Ajax contra el WordPress
 * del sitio y audita la base de datos.
 *
 * wp_send_json_* termina en wp_die(), así que se intercepta wp_die() con los
 * filtros wp_die_handler / wp_die_ajax_handler para convertir la salida en una
 * excepción y poder leer el JSON y el status HTTP.
 *
 * Al terminar borra los pedidos que creó y restaura la opción de servicios.
 */

define( 'WP_USE_THEMES', false );

// admin-ajax.php define DOING_AJAX antes de cargar WordPress. Sin esto,
// wp_send_json() termina en `die;` (wp-includes/functions.php) y no pasa por
// wp_die(), que es donde se engancha el handler para leer la respuesta.
define( 'DOING_AJAX', true );

// La raíz del sitio, cinco niveles arriba de tests/integration/. Se puede
// apuntar a otra instalación con SF_SITE_PATH=/ruta/al/sitio.
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

// Bufferizar toda la salida: si algo llega a stdout antes de una llamada,
// headers_sent() pasa a true y wp_send_json() se salta el status_header(),
// con lo que el código HTTP deja de ser observable desde la prueba.
ob_start();

$failures = 0;

function check( $label, $actual, $expected ) {
	global $failures;

	$ok = ( $actual === $expected );
	if ( ! $ok ) {
		$failures++;
	}

	printf( "%s %-50s esperado=%-16s obtenido=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/**
 * Ejecuta un endpoint y devuelve [ status HTTP, JSON ].
 */
function call( $ajax, string $method, array $post ) {
	$GLOBALS['sf_status'] = null;

	$_POST    = array_merge( array( 'nonce' => wp_create_nonce( 'simple_form_nonce' ) ), $post );
	$_REQUEST = $_POST;

	ob_start();

	try {
		$ajax->{$method}();
	} catch ( SF_Die $e ) {
		$out = ob_get_clean();

		return array( $GLOBALS['sf_status'], json_decode( $out, true ) );
	}

	return array( 'no-die', json_decode( ob_get_clean(), true ) );
}

$Ajax   = 'SimpleForm\Core\Ajax';
$Repo   = 'SimpleForm\Database\OrderRepository';
$ajax   = new $Ajax();
$table  = \SimpleForm\Database\Database::table_name( \SimpleForm\Database\Database::TABLE_ORDERS );
$max_id = (int) $wpdb->get_var( "SELECT MAX(id) FROM `{$table}`" );

// Servicio temporal: la opción guarda floats a propósito para probar el redondeo.
$services_before = get_option( 'simple_form_services', null );
update_option(
	'simple_form_services',
	array(
		array(
			'id'           => 'sf-test-plan',
			'label'        => 'Plan de prueba',
			'base_price'   => 10000.6,
			'price_per_km' => 500.4,
		),
	)
);

$santiago = array( 'lat' => '-33.4489', 'lng' => '-70.6693' );

echo "=== Paso 1: entrada inválida no crea datos ===\n";
list( $status, $json ) = call( $ajax, 'save_step1', array( 'name' => '', 'email' => 'no-es-correo', 'phone' => '123' ) );
check( 'sin nombre -> error 422', $status, 422 );
check( 'success = false', $json['success'], false );
check(
	'no se creó ningún pedido',
	(int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE id > {$max_id}" ),
	0
);

echo "\n=== Paso 1: entrada válida crea el borrador ===\n";
list( $status, $json ) = call(
	$ajax,
	'save_step1',
	array(
		'name'        => 'Ana Pérez',
		'email'       => 'ana@example.test',
		'phone'       => '912345678',
		'description' => 'Prueba de integración',
	)
);
check( 'success = true', $json['success'], true );
$session = $json['data']['session_id'];
check( 'devuelve session_id', is_string( $session ) && strlen( $session ) > 20, true );
check( 'current_step = 2', $json['data']['progress']['current_step'], 2 );
check( 'step1.name', $json['data']['progress']['step1']['name'], 'Ana Pérez' );
check( 'step2 todavía es null', $json['data']['progress']['step2'], null );
check( 'step3 todavía es null', $json['data']['progress']['step3'], null );

$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id > %d ORDER BY id ASC LIMIT 1", $max_id ), ARRAY_A );
$order_id = (int) $row['id'];
check( 'la fila nace en draft', $row['status'], 'draft' );
check( 'la fila nace en current_step 2', (int) $row['current_step'], 2 );
check( 'el correo quedó guardado', $row['email'], 'ana@example.test' );
check( 'draft_id con formato uuid', (bool) preg_match( '/^[0-9a-f-]{36}$/', $row['draft_id'] ), true );

echo "\n=== El transient es un puntero, no datos ===\n";
$raw = get_transient( 'sf_client_session_' . $session );
check( 'claves del transient', is_array( $raw ) ? array_keys( $raw ) : $raw, array( 'draft_id', 'user_id' ) );
check( 'apunta al draft_id de la fila', $raw['draft_id'], $row['draft_id'] );
check( 'no guarda el nombre', isset( $raw['name'] ) || isset( $raw['step1'] ), false );
check( 'no guarda el correo', isset( $raw['email'] ) || isset( $raw['step2'] ), false );

echo "\n=== Repetir el paso 1 reutiliza el pedido ===\n";
list( $status, $json ) = call( $ajax, 'save_step1', array( 'session_id' => $session, 'name' => 'Ana P. Modificado', 'email' => 'ana@example.test', 'phone' => '912345678' ) );
check( 'mismo session_id', $json['data']['session_id'], $session );
check(
	'sigue habiendo un solo pedido nuevo',
	(int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE id > {$max_id}" ),
	1
);
check( 'el nombre se actualizó', $json['data']['progress']['step1']['name'], 'Ana P. Modificado' );

echo "\n=== Paso 2: exige sesión y paso 1 ===\n";
list( $status, $json ) = call( $ajax, 'save_step2', array( 'service_id' => 'sf-test-plan' ) + $santiago + array( 'radius_km' => 5 ) );
check( 'sin sesión -> 410', $status, 410 );

list( $status, $json ) = call( $ajax, 'save_step2', array( 'session_id' => 'sesionInventada123', 'service_id' => 'sf-test-plan' ) + $santiago + array( 'radius_km' => 5 ) );
check( 'sesión inexistente -> 410', $status, 410 );

echo "\n=== Paso 2: precio y snapshot ===\n";
list( $status, $json ) = call( $ajax, 'save_step2', array( 'session_id' => $session, 'service_id' => 'sf-test-plan', 'estimated_price' => 1 ) + $santiago + array( 'radius_km' => 5 ) );
check( 'success = true', $json['success'], true );
check( 'current_step = 3', $json['data']['progress']['current_step'], 3 );
// 10000.6 -> 10001 y 500.4 -> 500: 10001 + 500*5 = 12501
check( 'precio redondeado a CLP entero', $json['data']['progress']['step2']['estimated_price'], 12501 );
check( 'etiqueta del servicio guardada', $json['data']['progress']['step2']['service_label'], 'Plan de prueba' );

$row2 = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $order_id ), ARRAY_A );
check( 'el precio enviado por el cliente se ignoró', (int) $row2['amount'], 12501 );
check( 'snapshot del precio base', (int) $row2['service_base_price'], 10001 );
check( 'snapshot del precio por km', (int) $row2['service_price_per_km'], 500 );
check( 'lat persistida', (float) $row2['lat'], -33.4489 );
check( 'radius_km persistido', (int) $row2['radius_km'], 5 );
check( 'current_step en la fila', (int) $row2['current_step'], 3 );

echo "\n=== Paso 2: validaciones de ubicación ===\n";
list( $status, $json ) = call( $ajax, 'save_step2', array( 'session_id' => $session, 'service_id' => 'sf-test-plan', 'lat' => 'abc', 'lng' => 'def', 'radius_km' => 5 ) );
check( 'coordenadas no numéricas -> 422', $status, 422 );

list( $status, $json ) = call( $ajax, 'save_step2', array( 'session_id' => $session, 'service_id' => 'sf-test-plan', 'lat' => '-40.0', 'lng' => '-70.0', 'radius_km' => 5 ) );
check( 'fuera de cobertura -> 422', $status, 422 );

list( $status, $json ) = call( $ajax, 'save_step2', array( 'session_id' => $session, 'service_id' => 'no-existe', ) + $santiago + array( 'radius_km' => 5 ) );
check( 'servicio inexistente -> 404', $status, 404 );

list( $status, $json ) = call( $ajax, 'save_step2', array( 'session_id' => $session, 'service_id' => 'sf-test-plan' ) + $santiago + array( 'radius_km' => 99 ) );
check( 'radio fuera de rango -> 422', $status, 422 );

$row2b = $wpdb->get_row( $wpdb->prepare( "SELECT amount, radius_km FROM `{$table}` WHERE id = %d", $order_id ), ARRAY_A );
check( 'ninguna prueba fallida tocó el monto', (int) $row2b['amount'], 12501 );

echo "\n=== calculate_price coincide con lo persistido ===\n";
list( $status, $json ) = call( $ajax, 'calculate_price', array( 'service_id' => 'sf-test-plan' ) + $santiago + array( 'radius_km' => 5 ) );
check( 'la vista previa da el mismo entero', $json['data']['price'], 12501 );

echo "\n=== Cobertura: Chile completo, no el círculo de 50 km ===\n";
list( $status, $json ) = call( $ajax, 'get_coverage_area', array() );
check( 'el área es un polígono', $json['data']['type'], 'polygon' );
check( 'anillos del contorno de dibujo', count( $json['data']['outline'] ), 31 );
check( 'ya no viaja el contorno de validación', isset( $json['data']['rings'] ), false );
check( 'las bounds traen las 4 claves', array_keys( $json['data']['bounds'] ), array( 'south', 'north', 'west', 'east' ) );

list( $status, $json ) = call( $ajax, 'calculate_price', array( 'service_id' => 'sf-test-plan', 'lat' => '-53.1638', 'lng' => '-70.9171', 'radius_km' => 5 ) );
check( 'Punta Arenas se acepta (fuera del círculo viejo)', $json['success'], true );

list( $status, $json ) = call( $ajax, 'calculate_price', array( 'service_id' => 'sf-test-plan', 'lat' => '-32.8895', 'lng' => '-68.8458', 'radius_km' => 5 ) );
check( 'Mendoza se rechaza (dentro del bbox)', $status, 422 );

echo "\n=== Paso 3: el medio sale de la lista del admin ===\n";
$methods_before = get_option( 'simple_form_payment_methods', null );

list( $status, $json ) = call( $ajax, 'save_step3', array( 'session_id' => $session ) );
check( 'sin medio de pago -> 422', $status, 422 );

list( $status, $json ) = call( $ajax, 'save_step3', array( 'session_id' => $session, 'payment_method' => 'paypal' ) );
check( 'medio que no está en el registro -> 422', $status, 422 );

update_option( 'simple_form_payment_methods', array() );
list( $status, $json ) = call( $ajax, 'save_step3', array( 'session_id' => $session, 'payment_method' => 'mercado_pago' ) );
check( 'registrado pero inactivo -> 422', $status, 422 );

update_option( 'simple_form_payment_methods', array( 'mercado_pago' ) );

// --- Interceptor de HTTP del paso 3 -----------------------------------------
// Crear el pago llama a la API de Mercado Pago. Acá se intercepta con
// pre_http_request: se prueba el cableado y, de paso, se puede afirmar qué
// payload sale, que es donde se verían nombres de columna equivocados.
$GLOBALS['sf_http'] = array( 'mode' => 'ok', 'url' => '', 'body' => null );

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		if ( false === strpos( $url, 'api.mercadopago.com' ) ) {
			return $preempt;
		}

		$GLOBALS['sf_http']['url']  = $url;
		$GLOBALS['sf_http']['body'] = json_decode( (string) ( $args['body'] ?? '' ), true );

		if ( 'fail' === $GLOBALS['sf_http']['mode'] ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'message' => 'invalid_back_urls' ) ),
				'response' => array( 'code' => 400, 'message' => 'Bad Request' ),
			);
		}

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'id'          => '123456',
					'init_point'  => 'https://www.mercadopago.cl/checkout/start?pref_id=123456-abc',
				)
			),
			'response' => array( 'code' => 201, 'message' => 'Created' ),
		);
	},
	10,
	3
);

echo "\n=== Paso 3: deja el pedido en pending ===\n";
list( $status, $json ) = call( $ajax, 'save_step3', array( 'session_id' => $session, 'payment_method' => 'mercado_pago' ) );
check( 'success = true', $json['success'], true );
check( 'current_step = 4', $json['data']['progress']['current_step'], 4 );
check( 'step3.final_price', $json['data']['progress']['step3']['final_price'], 12501 );

echo "\n=== Paso 3: lo que se le manda a Mercado Pago ===\n";
$payload = $GLOBALS['sf_http']['body'];

check( 'se llamó al endpoint de preferencias', $GLOBALS['sf_http']['url'], 'https://api.mercadopago.com/checkout/preferences' );
check( 'devuelve el init_point', $json['data']['redirect_url'], 'https://www.mercadopago.cl/checkout/start?pref_id=123456-abc' );
check( 'external_reference = id del pedido', $payload['external_reference'], (string) $order_id );
check( 'unit_price = monto del servidor', $payload['items'][0]['unit_price'], 12501 );
check( 'el monto va entero', is_int( $payload['items'][0]['unit_price'] ), true );
check( 'moneda CLP', $payload['items'][0]['currency_id'], 'CLP' );
check( 'cantidad 1', $payload['items'][0]['quantity'], 1 );
check( 'auto_return approved', $payload['auto_return'], 'approved' );
check( 'las tres back_urls', count( $payload['back_urls'] ), 3 );
check( 'notification_url al webhook', $payload['notification_url'], home_url( '/wp-json/simple-form/webhook' ) );
check( 'el draft_id en metadata', $payload['metadata']['draft_id'], (string) $wpdb->get_var( $wpdb->prepare( "SELECT draft_id FROM `{$table}` WHERE id = %d", $order_id ) ) );

$row3 = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $order_id ), ARRAY_A );
check( 'estado pending (nunca approved)', $row3['status'], 'pending' );
check( 'medio de pago guardado', $row3['payment_method'], 'mercado_pago' );
check( 'paid_at sigue vacío', $row3['paid_at'], null );

echo "\n=== Paso 3: approved no vuelve a pending ===\n";
$wpdb->update( $table, array( 'status' => 'approved' ), array( 'id' => $order_id ) );
list( $status, $json ) = call( $ajax, 'save_step3', array( 'session_id' => $session, 'payment_method' => 'mercado_pago' ) );
check( 'aprobado -> 409 conflicto de estado', $status, 409 );
check(
	'el estado no se degradó',
	$wpdb->get_var( $wpdb->prepare( "SELECT status FROM `{$table}` WHERE id = %d", $order_id ) ),
	'approved'
);

$wpdb->update( $table, array( 'status' => 'rejected' ), array( 'id' => $order_id ) );
list( $status, $json ) = call( $ajax, 'save_step3', array( 'session_id' => $session, 'payment_method' => 'mercado_pago' ) );
check( 'rechazado -> pending permite reintento', $json['success'], true );
check( 'sin error de estado', $status, null );
check( 'vuelve a pending', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `{$table}` WHERE id = %d", $order_id ) ), 'pending' );

echo "\n=== Paso 3: si la pasarela falla, nada se marca como pagado ===\n";
$GLOBALS['sf_http']['mode'] = 'fail';
$wpdb->update( $table, array( 'status' => 'draft' ), array( 'id' => $order_id ) );

list( $status, $json ) = call( $ajax, 'save_step3', array( 'session_id' => $session, 'payment_method' => 'mercado_pago' ) );
check( 'la API rechazó -> 502', $status, 502 );
check( 'sin redirect_url', isset( $json['data']['redirect_url'] ), false );
check(
	'el pedido queda pending, jamás approved',
	$wpdb->get_var( $wpdb->prepare( "SELECT status FROM `{$table}` WHERE id = %d", $order_id ) ),
	'pending'
);
check( 'paid_at sigue vacío', $wpdb->get_var( $wpdb->prepare( "SELECT paid_at FROM `{$table}` WHERE id = %d", $order_id ) ), null );
$GLOBALS['sf_http']['mode'] = 'ok';

echo "\n=== get_progress ===\n";
list( $status, $json ) = call( $ajax, 'get_progress', array( 'session_id' => $session ) );
check( 'success = true', $json['success'], true );
check( 'current_step', $json['data']['progress']['current_step'], 4 );
check( 'status del pedido en el payload', $json['data']['progress']['status'], 'pending' );
check( 'etiqueta del estado ya traducida', $json['data']['progress']['status_label'], 'Pago pendiente' );
check( 'la fecha del recibo llega del servidor', (bool) preg_match( '/\d/', (string) $json['data']['progress']['date'] ), true );
check( 'step1 tiene las 4 claves', array_keys( $json['data']['progress']['step1'] ), array( 'name', 'email', 'phone', 'description' ) );
check( 'step2 tiene las 6 claves', array_keys( $json['data']['progress']['step2'] ), array( 'service_id', 'service_label', 'lat', 'lng', 'radius_km', 'estimated_price' ) );
check( 'step3 tiene las 2 claves', array_keys( $json['data']['progress']['step3'] ), array( 'payment_method', 'final_price' ) );
check( 'session_id en la respuesta', $json['data']['session_id'], $session );

list( $status, $json ) = call( $ajax, 'get_progress', array( 'session_id' => 'noexiste' ) );
check( 'sesión desconocida -> 404', $status, 404 );

echo "\n=== Paso 3: los radios salen de la lista del admin, no de un literal ===\n";
update_option( 'simple_form_payment_methods', array( 'mercado_pago' ) );
$html_form = do_shortcode( '[simple_form_form]' );
check( 'sale un radio con el id del registro', (bool) preg_match( '/name="payment_method" value="mercado_pago"/', $html_form ), true );
check( 'con la etiqueta del registro', false !== strpos( $html_form, 'Mercado Pago' ), true );
check( 'y sin el aviso de que no hay medios', false === strpos( $html_form, 'No hay medios de pago disponibles' ), true );
check( 'el campo de error del medio sigue ahí', false !== strpos( $html_form, 'data-error-for="payment_method"' ), true );

echo "\n=== Limpieza ===\n";
$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE id > %d", $max_id ) );
delete_transient( 'sf_client_session_' . $session );

if ( null === $methods_before ) {
	delete_option( 'simple_form_payment_methods' );
} else {
	update_option( 'simple_form_payment_methods', $methods_before );
}

if ( null === $services_before ) {
	delete_option( 'simple_form_services' );
} else {
	update_option( 'simple_form_services', $services_before );
}

check( 'pedidos de prueba borrados', (int) $deleted, 1 );
check( 'sin filas de prueba restantes', (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE id > {$max_id}" ), 0 );
check( 'opción de servicios restaurada', get_option( 'simple_form_services', null ), $services_before );

printf( "\n%s\n", 0 === $failures ? 'TODO OK' : "FALLAS: {$failures}" );
exit( $failures > 0 ? 1 : 0 );
