<?php
/**
 * Catálogos del objeto (tipo, tamaño y material) contra WordPress de verdad.
 *
 * Cubre las tres piezas:
 *   - el endpoint AJAX que alimenta los selects del Paso 1 (nonce y límite);
 *   - la validación del servidor en save_step1 (solo ids del catálogo);
 *   - la pantalla de wp-admin que los guarda (capacidad, nonce, saneado).
 *
 * Restaura el catálogo y borra los pedidos que crea al terminar.
 */

define( 'WP_USE_THEMES', false );
define( 'DOING_AJAX', true );

$sf_site_root = (string) getenv( 'SF_SITE_PATH' );
require ( '' !== $sf_site_root ? rtrim( $sf_site_root, '/' ) : dirname( __DIR__, 5 ) ) . '/wp-load.php';

use SimpleForm\Admin\AdminCatalogs;
use SimpleForm\Core\Catalog;

global $wpdb;

class SF_Die extends Exception {}

function sf_die_handler( $message = '', $title = '', $args = array() ) {
	throw new SF_Die( is_string( $message ) ? $message : '' );
}

add_filter( 'wp_die_handler', fn() => 'sf_die_handler' );
add_filter( 'wp_die_ajax_handler', fn() => 'sf_die_handler' );

$GLOBALS['sf_status']  = null;
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

// wp_safe_redirect() termina en exit; se intercepta para poder ver el destino.
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

	printf( "%s %-56s esperado=%-20s obtenido=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/**
 * Ejecuta un endpoint y devuelve [status, JSON].
 */
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
$table = \SimpleForm\Database\Database::table_name( \SimpleForm\Database\Database::TABLE_ORDERS );

$catalogs_before = get_option( Catalog::OPTION, null );
$max_id          = (int) $wpdb->get_var( "SELECT MAX(id) FROM `{$table}`" );

try {
	// ---------------------------------------------------------------- endpoint

	echo "\n=== el endpoint que alimenta los selects ===\n";

	list( $status, $json ) = call( $ajax, 'get_catalogs', array() );

	check( 'responde 200', $status, null );
	check( 'success = true', isset( $json['success'] ) ? $json['success'] : null, true );
	check( 'trae las tres listas', array_keys( (array) ( $json['data']['catalogs'] ?? array() ) ), Catalog::KINDS );
	check( 'los tipos tienen entradas', count( (array) ( $json['data']['catalogs']['types'] ?? array() ) ) > 0, true );
	check( 'cada entrada tiene id y etiqueta', array_keys( (array) ( $json['data']['catalogs']['types'][0] ?? array() ) ), array( 'id', 'label' ) );
	check(
		'no manda nada más que las listas',
		array_keys( (array) $json['data'] ),
		array( 'catalogs' )
	);

	list( $status, $json ) = call( $ajax, 'get_catalogs', array(), false );
	check( 'sin nonce: rechazado', $status, 403 );
	check( 'y no devuelve listas', isset( $json['data']['catalogs'] ), false );

	// ---------------------------------------------------- validación del paso 1

	echo "\n=== el servidor solo acepta ids del catálogo ===\n";

	$base = array(
		'name'            => 'Objeto de prueba',
		'email'           => 'catalogo@example.test',
		'phone'           => '912345679',
		'object_type'     => 'llaves',
		'object_size'     => 'pequeno',
		'object_material' => 'metal',
		'description'     => 'Llavero con cinta roja',
	);

	list( $status, $json ) = call( $ajax, 'save_step1', $base );
	check( 'con ids válidos guarda el paso 1', $status, null );
	check( 'y devuelve el session_id', ! empty( $json['data']['session_id'] ), true );

	$session = (string) ( $json['data']['session_id'] ?? '' );
	$order   = $session ? \SimpleForm\Core\Session::find_draft_id( $session ) : '';
	$fila    = $order ? \SimpleForm\Database\OrderRepository::find_by_draft_id( $order ) : null;

	check( 'el pedido guarda el tipo', $fila['object_type'] ?? null, 'llaves' );
	check( 'el pedido guarda el tamaño', $fila['object_size'] ?? null, 'pequeno' );
	check( 'el pedido guarda el material', $fila['object_material'] ?? null, 'metal' );
	check( 'y la descripción del objeto', $fila['description'] ?? null, 'Llavero con cinta roja' );

	// Id inventado: no entra ni como texto libre.
	$inventado                    = $base;
	$inventado['object_type']     = 'no-existe';
	$inventado['phone']           = '912345680';
	unset( $inventado['session_id'] );

	$_POST = array_merge( array( 'nonce' => wp_create_nonce( 'simple_form_nonce' ) ), $inventado );

	list( $status, $json ) = call( $ajax, 'save_step1', $inventado );
	check( 'un tipo inventado: 422', $status, 422 );
	check( 'con el campo señalado', $json['data']['field'] ?? null, 'object_type' );
	check( 'y sin crear pedido nuevo', (int) $wpdb->get_var( "SELECT MAX(id) FROM `{$table}`" ), (int) ( $fila['id'] ?? 0 ) );

	// Payload de formulario: texto libre donde va un id.
	$malicioso                = $base;
	$malicioso['object_type'] = '<script>alert(1)</script>';
	$malicioso['phone']       = '912345681';

	list( $status, $json ) = call( $ajax, 'save_step1', $malicioso );
	check( 'texto libre en el tipo: 422', $status, 422 );

	// Descripción: obligatoria y con un mínimo.
	$sin_descripcion                = $base;
	$sin_descripcion['description'] = '  a ';
	$sin_descripcion['phone']       = '912345682';

	list( $status, $json ) = call( $ajax, 'save_step1', $sin_descripcion );
	check( 'descripción demasiado corta: 422', $status, 422 );
	check( 'con el campo señalado', $json['data']['field'] ?? null, 'description' );

	// Catálogo vacío: el campo no se exige y se guarda vacío.
	echo "\n=== si la lista está vacía, el campo no se pide ===\n";

	$vacio = $catalogs_before;
	$vacio['sizes'] = array();
	update_option( Catalog::OPTION, $vacio );

	$sin_tamano                    = $base;
	$sin_tamano['object_size']     = '';
	$sin_tamano['phone']           = '912345683';
	$sin_tamano['email']           = 'catalogo2@example.test';

	list( $status, $json ) = call( $ajax, 'save_step1', $sin_tamano );
	check( 'sin tamaño (lista vacía) guarda igual', $status, null );

	$session2 = (string) ( $json['data']['session_id'] ?? '' );
	$draft2   = $session2 ? \SimpleForm\Core\Session::find_draft_id( $session2 ) : '';
	$fila2    = $draft2 ? \SimpleForm\Database\OrderRepository::find_by_draft_id( $draft2 ) : null;

	check( 'y el tamaño queda vacío', $fila2['object_size'] ?? null, '' );
	check( 'el tipo sigue guardado', $fila2['object_type'] ?? null, 'llaves' );

	update_option( Catalog::OPTION, $catalogs_before );

	// ------------------------------------------------------------- wp-admin

	echo "\n=== la pantalla de catálogos (wp-admin) ===\n";

	wp_set_current_user( 1 );

	$admin = new AdminCatalogs();

	// Sin capacidad: se corta con wp_die.
	wp_set_current_user( 0 );

	try {
		$admin->handle_save();
		check( 'sin permiso no guarda', 'siguió', 'cortó' );
	} catch ( SF_Die $e ) {
		check( 'sin permiso no guarda', 'cortó', 'cortó' );
	}

	wp_set_current_user( 1 );

	// Con permiso pero sin nonce.
	try {
		$_POST    = array( 'catalogs' => array( 'types' => array( array( 'id' => 'x', 'label' => 'X' ) ) ) );
		$_REQUEST = $_POST;
		$admin->handle_save();
		check( 'sin nonce no guarda', 'siguió', 'cortó' );
	} catch ( SF_Die $e ) {
		check( 'sin nonce no guarda', 'cortó', 'cortó' );
	}

	check( 'y el catálogo no cambió', get_option( Catalog::OPTION, null ), $catalogs_before );

	// Guardado real: ids saneados, duplicados y filas vacías descartadas.
	$_POST    = array(
		'sf_catalogs_nonce' => wp_create_nonce( 'sf_save_catalogs' ),
		'catalogs'          => array(
			'types'     => array(
				array( 'id' => 'Llaves Del Auto', 'label' => 'Llaves del auto' ),
				array( 'id' => 'llaves-del-auto', 'label' => 'Repetido' ),
				array( 'id' => '', 'label' => 'Sin id' ),
				array( 'id' => 'billetera', 'label' => 'Billetera' ),
			),
			'sizes'     => array( array( 'id' => 'chico', 'label' => 'Chico' ) ),
			'materials' => array( array( 'id' => 'cuero', 'label' => 'Cuero <b>grueso</b>' ) ),
		),
	);
	$_REQUEST = $_POST;

	$GLOBALS['sf_redirect'] = '';

	try {
		$admin->handle_save();
	} catch ( SF_Die $e ) {
		// La redirección corta el proceso: es lo esperado.
	}

	check( 'guarda y redirige', false !== strpos( (string) $GLOBALS['sf_redirect'], 'updated=1' ), true );

	$guardado = Catalog::all();

	check( 'el id se normaliza a slug', $guardado['types'][0]['id'], 'llaves-del-auto' );
	check( 'la etiqueta se respeta', $guardado['types'][0]['label'], 'Llaves del auto' );
	check( 'el duplicado y la fila vacía se descartan', count( $guardado['types'] ), 2 );
	check( 'la etiqueta se limpia de HTML', $guardado['materials'][0]['label'], 'Cuero grueso' );
} catch ( Throwable $e ) {
	echo "\nERROR EN LA PRUEBA: " . $e->getMessage() . "\n";
	++$failures;
} finally {
	// Restaurar catálogo y borrar los pedidos de esta corrida.
	if ( null === $catalogs_before ) {
		delete_option( Catalog::OPTION );
	} else {
		update_option( Catalog::OPTION, $catalogs_before );
	}

	$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE id > %d", $max_id ) );

	printf( "\ncatálogo restaurado y pedidos de prueba borrados (id > %d)\n", $max_id );
}

printf( "\n%s\n", 0 === $failures ? 'TODO OK' : "FALLAS: {$failures}" );
exit( $failures > 0 ? 1 : 0 );
