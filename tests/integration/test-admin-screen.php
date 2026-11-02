<?php
/**
 * Prueba REAL de la pantalla "Medios de pago" (AdminPayments).
 *
 * Cubre lo que se pidió: alta y baja de medios, credenciales guardadas en
 * wp_options por modo (pruebas y comercial) sin que una borre la otra, y el
 * cambio de modo.
 *
 * El handler termina en wp_safe_redirect() + exit; se intercepta wp_redirect con
 * un filtro que lanza una excepción, así la prueba recupera el control antes del
 * exit y puede mirar el destino y lo que quedó en la base.
 *
 * REGLA: esta prueba no compara ni imprime el valor de una credencial real. Se
 * comprueba de dónde sale (constante o guardada) y que lo guardado esté cifrado.
 */

define( 'WP_USE_THEMES', false );

$sf_site_root = (string) getenv( 'SF_SITE_PATH' );
require ( '' !== $sf_site_root ? rtrim( $sf_site_root, '/' ) : dirname( __DIR__, 5 ) ) . '/wp-load.php';

// Las pantallas del admin se pintan con la API de wp-admin cargada (submit_button
// y compañía); en un arranque por CLI no lo está.
require_once ABSPATH . 'wp-admin/includes/admin.php';

global $wpdb;

class SF_Die extends Exception {}
class SF_Redirect extends Exception {}

function sf_die_handler( $message = '', $title = '', $args = array() ) {
	throw new SF_Die( is_string( $message ) ? $message : '' );
}

add_filter( 'wp_die_handler', function () { return 'sf_die_handler'; } );
add_filter( 'wp_die_ajax_handler', function () { return 'sf_die_handler'; } );
add_filter( 'wp_redirect', function ( $location ) { throw new SF_Redirect( (string) $location ); } );

ob_start();

$failures = 0;

function check( $label, $actual, $expected ) {
	global $failures;

	$ok = ( $actual === $expected );
	if ( ! $ok ) {
		$failures++;
	}

	// Nunca imprimir un valor largo: si un día una aserción compara una
	// credencial —o un array de credenciales cifradas— la salida no puede
	// acabar con el secreto dentro.
	$limpiar = static function ( $v ) use ( &$limpiar ) {
		if ( is_string( $v ) && strlen( $v ) > 24 ) {
			return '[cadena de ' . strlen( $v ) . ' caracteres, no se imprime]';
		}

		if ( is_array( $v ) ) {
			$salida = array();

			foreach ( $v as $clave => $valor ) {
				$salida[ $clave ] = $limpiar( $valor );
			}

			return var_export( $salida, true );
		}

		return var_export( $v, true );
	};

	printf( "%s %-58s esperado=%-34s obtenido=%s\n", $ok ? 'PASS' : 'FAIL', $label, $limpiar( $expected ), $limpiar( $actual ) );
}

$AdminPayments = 'SimpleForm\Admin\AdminPayments';
$PaymentConfig = 'SimpleForm\Payments\PaymentConfig';

$admin = new $AdminPayments();

/**
 * Ejecuta el handler con un $_POST dado.
 *
 * @return array [ tipo, valor ]: 'redirect' con la URL, 'die' con el mensaje,
 *               o 'ninguno'.
 */
function ejecutar( $handler, array $post ) {
	$_POST    = $post;
	$_REQUEST = $post;

	try {
		call_user_func( $handler );
	} catch ( SF_Redirect $e ) {
		return array( 'redirect', $e->getMessage() );
	} catch ( SF_Die $e ) {
		return array( 'die', strip_tags( $e->getMessage() ) );
	}

	return array( 'ninguno', '' );
}

/** Descifrado de un campo guardado, para comparar sin imprimirlo. */
function descifrar( $gateway, $mode, $field ) {
	$all = get_option( 'simple_form_payment_credentials' );
	$raw = (string) ( $all[ $gateway ][ $mode ][ $field ] ?? '' );

	return '' === $raw ? '' : SimpleForm\Security\Crypto::decrypt( $raw );
}

// --- estado previo, para restaurar al final ---------------------------------

$prev_methods     = get_option( 'simple_form_payment_methods', null );
$prev_credentials = get_option( 'simple_form_payment_credentials', null );

$https = 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );

// El modo puede estar fijado por wp-config.php (§16: la constante manda). En ese
// caso el selector sale bloqueado y la elección guardada no se aplica.
$modo_bloqueado = $PaymentConfig::mode_is_locked( 'mercado_pago' );

$admins   = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin_id = (int) ( $admins[0] ?? 0 );

$nonce = function () use ( $AdminPayments ) {
	return array( $AdminPayments::NONCE_SAVE => wp_create_nonce( $AdminPayments::ACTION_SAVE ) );
};

echo "=== preparación ===\n";
check( 'hay un administrador en el sitio', $admin_id > 0, true );
check( 'el hook del guardado está registrado', has_action( 'admin_post_sf_save_payments' ) !== false, true );
check( 'el menú está registrado', has_action( 'admin_menu' ) !== false, true );

echo "\n=== sin permiso ===\n";
wp_set_current_user( 0 );
list( $tipo, $valor ) = ejecutar( array( $admin, 'handle_save' ), array() );
check( 'invitado -> muere sin permiso', $tipo, 'die' );
check( 'y dice que no está autorizado', $valor, 'No autorizado.' );

wp_set_current_user( $admin_id );

echo "\n=== sin nonce válido ===\n";
list( $tipo, $valor ) = ejecutar( array( $admin, 'handle_save' ), array( $AdminPayments::NONCE_SAVE => 'nonce-falso' ) );
check( 'nonce inválido -> muere', $tipo, 'die' );

echo "\n=== alta de un medio con credenciales ===\n";
$post = array_merge(
	$nonce(),
	array(
		'payments' => array(
			'mercado_pago' => array(
				'mode'           => 'sandbox',
				'access_token'   => '  TEST-token-de-prueba  ',
				'webhook_secret' => 'secreto-web-2',
			),
			'paypal'       => array( 'mode' => 'sandbox', 'access_token' => 'no-debe-guardarse' ),
			'MERCADO PAGO' => array( 'mode' => 'sandbox' ),
		),
	)
);
list( $tipo, $valor ) = ejecutar( array( $admin, 'handle_save' ), $post );
check( 'redirige', $tipo, 'redirect' );
check( 'con updated=payments', false !== strpos( $valor, 'updated=payments' ), true );
check( 'solo queda el medio registrado', get_option( 'simple_form_payment_methods' ), array( 'mercado_pago' ) );

$crudo = get_option( 'simple_form_payment_credentials' );
check( 'el secreto se guardó cifrado', strlen( (string) ( $crudo['mercado_pago']['sandbox']['webhook_secret'] ?? '' ) ) > 40, true );
check( 'y no en texto claro', false !== strpos( (string) json_encode( $crudo ), 'secreto-web-2' ), false );
check( 'una pasarela no registrada no se guardó', false !== strpos( (string) json_encode( $crudo ), 'no-debe-guardarse' ), false );
check( 'el token se recortó al guardar', 'TEST-token-de-prueba', descifrar( 'mercado_pago', 'sandbox', 'access_token' ) );
check( 'el secreto se descifra igual', 'secreto-web-2', descifrar( 'mercado_pago', 'sandbox', 'webhook_secret' ) );
check( 'sandbox tiene las dos claves', $PaymentConfig::has_keys( 'mercado_pago', 'sandbox' ), true );
check( 'producción todavía no tiene ninguna', $PaymentConfig::has_keys( 'mercado_pago', 'production' ), false );

echo "\n=== pasar a comercial guarda sus claves y no toca las de pruebas ===\n";
$hash_sandbox = md5( (string) ( $crudo['mercado_pago']['sandbox']['access_token'] ?? '' ) );

$post = array_merge(
	$nonce(),
	array(
		'payments' => array(
			'mercado_pago' => array(
				'mode'           => 'production',
				'access_token'   => 'PROD-token-de-prueba',
				'webhook_secret' => 'secreto-prod-1',
			),
		),
	)
);
list( $tipo, $valor ) = ejecutar( array( $admin, 'handle_save' ), $post );

check( 'redirige', $tipo, 'redirect' );

if ( $modo_bloqueado ) {
	// La constante decide: guardar no cambia el modo, pero las claves quedan.
	check( 'con el modo fijado por constante no se activa', false !== strpos( $valor, 'updated=payments' ), true );
	check( 'el modo sigue siendo el de wp-config.php', $PaymentConfig::desired_mode( 'mercado_pago' ), (string) constant( 'SIMPLE_FORM_MERCADO_PAGO_MODE' ) );
} elseif ( $https ) {
	check( 'en HTTPS se activa el modo comercial', false !== strpos( $valor, 'updated=payments' ), true );
	check( 'y el modo efectivo es producción', $PaymentConfig::mode( 'mercado_pago' ), 'production' );
} else {
	check( 'sin HTTPS avisa en vez de fallar', false !== strpos( $valor, 'warning=https' ), true );
	check( 'y el modo efectivo sigue en pruebas', $PaymentConfig::mode( 'mercado_pago' ), 'sandbox' );
	check( 'pero la elección de comercial queda guardada', $PaymentConfig::desired_mode( 'mercado_pago' ), 'production' );
}

check( 'producción tiene sus dos claves', $PaymentConfig::has_keys( 'mercado_pago', 'production' ), true );
check( 'las claves de pruebas siguen intactas', $hash_sandbox, md5( (string) ( get_option( 'simple_form_payment_credentials' )['mercado_pago']['sandbox']['access_token'] ?? '' ) ) );
check( 'el secreto de producción se descifra', 'secreto-prod-1', descifrar( 'mercado_pago', 'production', 'webhook_secret' ) );
check( 'el token de producción es el suyo', 'PROD-token-de-prueba', descifrar( 'mercado_pago', 'production', 'access_token' ) );

echo "\n=== volver a pruebas no borra las claves de comercial ===\n";
$hash_prod = md5( (string) ( get_option( 'simple_form_payment_credentials' )['mercado_pago']['production']['access_token'] ?? '' ) );

$post = array_merge(
	$nonce(),
	array(
		'payments' => array( 'mercado_pago' => array( 'mode' => 'sandbox' ) ),
	)
);
list( $tipo, $valor ) = ejecutar( array( $admin, 'handle_save' ), $post );

check( 'redirige', $tipo, 'redirect' );
check( 'el modo efectivo queda en pruebas', $PaymentConfig::mode( 'mercado_pago' ), 'sandbox' );
check( 'las claves de comercial siguen guardadas', $hash_prod, md5( (string) ( get_option( 'simple_form_payment_credentials' )['mercado_pago']['production']['access_token'] ?? '' ) ) );
check( 'y las de pruebas también', $hash_sandbox, md5( (string) ( get_option( 'simple_form_payment_credentials' )['mercado_pago']['sandbox']['access_token'] ?? '' ) ) );
check( 'un campo vacío conserva el valor del modo', 'TEST-token-de-prueba', descifrar( 'mercado_pago', 'sandbox', 'access_token' ) );

echo "\n=== el modo elegido es el que se usa para cobrar ===\n";
$token_sandbox = $PaymentConfig::credential( 'mercado_pago', 'access_token' );
$token_const   = defined( 'SIMPLE_FORM_MERCADO_PAGO_ACCESS_TOKEN' ) && '' !== (string) constant( 'SIMPLE_FORM_MERCADO_PAGO_ACCESS_TOKEN' );

if ( $token_const ) {
	check( 'la constante manda sobre lo guardado', $PaymentConfig::source( 'mercado_pago', 'access_token' ), 'constant' );
} else {
	check( 'sin constante se lee el modo activo (pruebas)', 'TEST-token-de-prueba', $token_sandbox );
	check( 'y su fuente es "stored"', $PaymentConfig::source( 'mercado_pago', 'access_token' ), 'stored' );
}

echo "\n=== quitar el medio lo da de baja sin borrar sus claves ===\n";
$post = $nonce();
list( $tipo, $valor ) = ejecutar( array( $admin, 'handle_save' ), $post );
check( 'redirige', $tipo, 'redirect' );
check( 'la lista de medios queda vacía', get_option( 'simple_form_payment_methods' ), array() );
check( 'las credenciales siguen ahí', $PaymentConfig::has_keys( 'mercado_pago', 'sandbox' ), true );

echo "\n=== payload sucio no rompe ===\n";
$post = array_merge( $nonce(), array( 'payments' => 'esto no es un array' ) );
list( $tipo, $valor ) = ejecutar( array( $admin, 'handle_save' ), $post );
check( 'redirige sin romper', $tipo, 'redirect' );
check( 'la lista sigue vacía', get_option( 'simple_form_payment_methods' ), array() );

echo "\n=== la pantalla: lo que se pinta ===\n";
update_option( 'simple_form_payment_methods', array() );

ob_start();
$admin->render_page();
$html_vacio = ob_get_clean();

check( 'titula la pantalla', false !== strpos( $html_vacio, 'Medios de pago' ), true );
check( 'sin medios cargados lo avisa', false !== strpos( $html_vacio, 'No hay ningún medio cargado' ), true );
check( 'y ofrece el botón de añadir', false !== strpos( $html_vacio, 'data-add-payment' ), true );
check( 'con la plantilla del medio disponible', false !== strpos( $html_vacio, 'id="sf-payment-tpl-mercado_pago"' ), true );
if ( $modo_bloqueado ) {
	check( 'la plantilla avisa que el modo lo fija wp-config.php', false !== strpos( $html_vacio, 'Lo fija SIMPLE_FORM_MERCADO_PAGO_MODE' ), true );
} else {
	check( 'la plantilla trae su selector de modo', false !== strpos( $html_vacio, 'payments[mercado_pago][mode]' ), true );
}
check( 'y sus campos de credenciales', false !== strpos( $html_vacio, 'payments[mercado_pago][webhook_secret]' ), true );

update_option( 'simple_form_payment_methods', array( 'mercado_pago' ) );

ob_start();
$admin->render_page();
$html = ob_get_clean();

check( 'con el medio cargado hay una fila', (bool) preg_match( '/data-payment="mercado_pago"/', $html ), true );
if ( ! $modo_bloqueado ) {
	check( 'la fila trae el selector de modo', (bool) preg_match( '/name="payments\[mercado_pago\]\[mode\]"/', $html ), true );
}
check( 'y el estado de las claves por modo', false !== strpos( $html, 'Cada modo guarda sus propias claves' ), true );
check( 'no imprime el secreto guardado', false === strpos( $html, 'secreto-web-2' ), true );
check( 'ni el de producción', false === strpos( $html, 'secreto-prod-1' ), true );
check( 'avisa de que está en modo de pruebas', false !== strpos( $html, 'Modo de pruebas' ), true );

$secreto_cargado = (string) $PaymentConfig::credential( 'mercado_pago', 'webhook_secret' );

check(
	'y tampoco imprime el valor que se va a usar',
	'' === $secreto_cargado || false === strpos( $html, $secreto_cargado ),
	true
);

if ( ! $https ) {
	check( 'las comprobaciones acusan la falta de HTTPS', false !== strpos( $html, 'Con HTTP solo se puede probar' ), true );
}

check( 'y confirman que hay cifrado', false !== strpos( $html, 'Las credenciales se guardan cifradas' ), true );

$token_const_value = defined( 'SIMPLE_FORM_MERCADO_PAGO_ACCESS_TOKEN' ) ? (string) constant( 'SIMPLE_FORM_MERCADO_PAGO_ACCESS_TOKEN' ) : '';

if ( '' !== $token_const_value ) {
	check( 'la pantalla no imprime el Access Token', false === strpos( $html, $token_const_value ), true );
}

echo "\n=== limpieza ===\n";
if ( null === $prev_methods ) {
	delete_option( 'simple_form_payment_methods' );
} else {
	update_option( 'simple_form_payment_methods', $prev_methods );
}
if ( null === $prev_credentials ) {
	delete_option( 'simple_form_payment_credentials' );
} else {
	update_option( 'simple_form_payment_credentials', $prev_credentials );
}

check( 'medios restaurados', get_option( 'simple_form_payment_methods', null ), $prev_methods );
check(
	'credenciales restauradas',
	md5( (string) wp_json_encode( get_option( 'simple_form_payment_credentials', null ) ) ),
	md5( (string) wp_json_encode( $prev_credentials ) )
);

printf( "\n%s\n", 0 === $failures ? 'TODO OK' : "FALLAS: {$failures}" );
exit( $failures > 0 ? 1 : 0 );
