<?php
/**
 * Prueba REAL del guardado de la pantalla "Medios de pago" (AdminPayments).
 *
 * El handler termina en wp_safe_redirect() + exit; se intercepta wp_redirect con
 * un filtro que lanza una excepción, así la prueba recupera el control antes del
 * exit y puede mirar el destino de la redirección y lo que quedó en la base.
 *
 * REGLA: esta prueba no compara ni imprime el valor de una credencial real. Se
 * comprueba de dónde sale (constante o guardada) y que lo guardado esté cifrado.
 */

define( 'WP_USE_THEMES', false );

// La raíz del sitio, cinco niveles arriba de tests/integration/. Se puede
// apuntar a otra instalación con SF_SITE_PATH=/ruta/al/sitio.
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
	// credencial, la salida no puede acabar con el secreto dentro.
	$muestra = static function ( $v ) {
		if ( is_string( $v ) && strlen( $v ) > 24 ) {
			return '[cadena de ' . strlen( $v ) . ' caracteres, no se imprime]';
		}

		return var_export( $v, true );
	};

	printf( "%s %-56s esperado=%-40s obtenido=%s\n", $ok ? 'PASS' : 'FAIL', $label, $muestra( $expected ), $muestra( $actual ) );
}

$AdminPayments = 'SimpleForm\Admin\AdminPayments';
$PaymentConfig = 'SimpleForm\Payments\PaymentConfig';

$admin = new $AdminPayments();

/**
 * Ejecuta un handler con un $_POST dado.
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

// --- estado previo, para restaurar al final ---------------------------------

$prev_methods     = get_option( 'simple_form_payment_methods', null );
$prev_credentials = get_option( 'simple_form_payment_credentials', null );

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin_id = (int) ( $admins[0] ?? 0 );

echo "=== preparación ===\n";
check( 'hay un administrador en el sitio', $admin_id > 0, true );
check( 'el hook del guardado está registrado', has_action( 'admin_post_sf_save_payment_methods' ) !== false, true );
check( 'el hook de credenciales está registrado', has_action( 'admin_post_sf_save_payment_credentials' ) !== false, true );
check( 'el menú está registrado', has_action( 'admin_menu' ) !== false, true );

echo "\n=== sin permiso ===\n";
wp_set_current_user( 0 );
list( $tipo, $valor ) = ejecutar( array( $admin, 'handle_save_methods' ), array() );
check( 'invitado -> muere sin permiso', $tipo, 'die' );
check( 'y dice que no está autorizado', $valor, 'No autorizado.' );

wp_set_current_user( $admin_id );

echo "\n=== sin nonce válido ===\n";
list( $tipo, $valor ) = ejecutar(
	array( $admin, 'handle_save_methods' ),
	array( $AdminPayments::NONCE_METHODS => 'nonce-falso', 'methods' => array( 'mercado_pago' ) )
);
check( 'nonce inválido -> muere', $tipo, 'die' );

echo "\n=== guardar medios: lista sucia ===\n";
$post = array(
	$AdminPayments::NONCE_METHODS => wp_create_nonce( $AdminPayments::ACTION_METHODS ),
	'methods'                     => array( 'paypal', 'mercado_pago', 'MERCADO_PAGO', '', 'mercado-pago!' ),
);
list( $tipo, $valor ) = ejecutar( array( $admin, 'handle_save_methods' ), $post );
check( 'redirige', $tipo, 'redirect' );
check( 'a la pantalla de medios con updated=methods', ( false !== strpos( $valor, 'page=simple-form-payments' ) && false !== strpos( $valor, 'updated=methods' ) ), true );
check( 'solo queda lo registrado y sin repetir', get_option( 'simple_form_payment_methods' ), array( 'mercado_pago' ) );

echo "\n=== guardar medios: ninguno marcado es una elección válida ===\n";
$post = array( $AdminPayments::NONCE_METHODS => wp_create_nonce( $AdminPayments::ACTION_METHODS ) );
list( $tipo, $valor ) = ejecutar( array( $admin, 'handle_save_methods' ), $post );
check( 'redirige igual', $tipo, 'redirect' );
check( 'la lista queda vacía', get_option( 'simple_form_payment_methods' ), array() );

echo "\n=== guardar credenciales ===\n";
$post = array(
	$AdminPayments::NONCE_CREDENTIALS => wp_create_nonce( $AdminPayments::ACTION_CREDENTIALS ),
	'credentials'                     => array(
		'mercado_pago' => array(
			'mode'           => 'sandbox',
			'access_token'   => '  TEST-token-de-prueba  ',
			'webhook_secret' => 'secreto-web-2',
		),
		'paypal'       => array( 'mode' => 'sandbox', 'access_token' => 'no-debe-guardarse' ),
	),
);
list( $tipo, $valor ) = ejecutar( array( $admin, 'handle_save_credentials' ), $post );
check( 'redirige', $tipo, 'redirect' );
check( 'con updated=credentials', false !== strpos( $valor, 'updated=credentials' ), true );
check( 'el secreto del webhook se lee de vuelta', $PaymentConfig::credential( 'mercado_pago', 'webhook_secret' ), 'secreto-web-2' );

// El Access Token no se compara nunca contra su valor: con una constante en
// wp-config.php la fuente es la constante, y el valor no se imprime. Se
// comprueba de dónde sale, más que lo que vale.
check(
	'el Access Token lo sirve la constante de wp-config.php',
	$PaymentConfig::source( 'mercado_pago', 'access_token' ),
	defined( 'SIMPLE_FORM_MERCADO_PAGO_ACCESS_TOKEN' ) ? 'constant' : 'stored'
);

$crudo = json_encode( get_option( 'simple_form_payment_credentials' ) );
check( 'el token no está en claro en la base', false === strpos( $crudo, 'TEST-token-de-prueba' ), true );
check( 'una pasarela no registrada no se guardó', false === strpos( $crudo, 'no-debe-guardarse' ), true );

echo "\n=== producción sin HTTPS ===\n";
$post = array(
	$AdminPayments::NONCE_CREDENTIALS => wp_create_nonce( $AdminPayments::ACTION_CREDENTIALS ),
	'credentials'                     => array( 'mercado_pago' => array( 'mode' => 'production' ) ),
);
list( $tipo, $valor ) = ejecutar( array( $admin, 'handle_save_credentials' ), $post );
check( 'redirige con error', false !== strpos( $valor, 'error=https' ), true );
check( 'y el modo no cambió', $PaymentConfig::mode( 'mercado_pago' ), 'sandbox' );

echo "\n=== payload sucio no rompe ===\n";
$post = array(
	$AdminPayments::NONCE_CREDENTIALS => wp_create_nonce( $AdminPayments::ACTION_CREDENTIALS ),
	'credentials'                     => 'esto no es un array',
);
list( $tipo, $valor ) = ejecutar( array( $admin, 'handle_save_credentials' ), $post );
check( 'redirige sin romper', $tipo, 'redirect' );
check( 'el secreto del webhook sigue ahí', $PaymentConfig::credential( 'mercado_pago', 'webhook_secret' ), 'secreto-web-2' );

echo "\n=== la pantalla: lo que se pinta ===\n";
update_option( 'simple_form_payment_methods', array( 'mercado_pago' ) );

wp_set_current_user( $admin_id );
ob_start();
$admin->render_page();
$html = ob_get_clean();

check( 'titula la pantalla', false !== strpos( $html, 'Medios de pago' ), true );
check( 'el checkbox de Mercado Pago sale marcado', (bool) preg_match( '/name="methods\[\]" value="mercado_pago"\s+checked/', $html ), true );
check( 'no avisa de falta de medios activos', false === strpos( $html, 'No hay ningún medio activo' ), true );
check( 'avisa de que está en modo de pruebas', false !== strpos( $html, 'Modo de pruebas' ), true );
check( 'el campo guardado sale vacío', (bool) preg_match( '/name="credentials\[mercado_pago\]\[webhook_secret\]" value=""/', $html ), true );
check( 'el Access Token sale bloqueado', false !== strpos( $html, 'definida en wp-config.php' ), true );
check( 'y dice de qué constante sale', false !== strpos( $html, 'SIMPLE_FORM_MERCADO_PAGO_ACCESS_TOKEN' ), true );
check( 'el modo también sale bloqueado por la constante', false !== strpos( $html, 'Lo fija SIMPLE_FORM_MERCADO_PAGO_MODE' ), true );
check( 'las comprobaciones acusan la falta de HTTPS', false !== strpos( $html, 'Con HTTP solo se puede probar' ), true );
check( 'y confirman que hay cifrado', false !== strpos( $html, 'Las credenciales se guardan cifradas' ), true );

// Lo importante: por la pantalla no sale ninguna credencial.
$token_const = defined( 'SIMPLE_FORM_MERCADO_PAGO_ACCESS_TOKEN' ) ? (string) constant( 'SIMPLE_FORM_MERCADO_PAGO_ACCESS_TOKEN' ) : '';

if ( '' !== $token_const ) {
	check( 'la pantalla no imprime el Access Token', false === strpos( $html, $token_const ), true );
}

check( 'la pantalla no imprime la credencial guardada', false === strpos( $html, 'secreto-web-2' ), true );

update_option( 'simple_form_payment_methods', array() );
ob_start();
$admin->render_page();
$html_vacio = ob_get_clean();
check( 'sin medios activos lo avisa en la pantalla', false !== strpos( $html_vacio, 'No hay ningún medio activo' ), true );
check( 'y el checkbox ya no sale marcado', (bool) preg_match( '/name="methods\[\]" value="mercado_pago"\s+checked/', $html_vacio ), false );

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
check( 'credenciales restauradas', get_option( 'simple_form_payment_credentials', null ), $prev_credentials );

printf( "\n%s\n", 0 === $failures ? 'TODO OK' : "FALLAS: {$failures}" );
exit( $failures > 0 ? 1 : 0 );
