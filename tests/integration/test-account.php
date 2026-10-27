<?php
/**
 * Cuenta del cliente contra WordPress de verdad.
 *
 * Comprueba el contrato que sostiene todo lo demás: que el teléfono vive en una
 * sola clave de user meta, que los datos salen de la cuenta autenticada, que el
 * formulario se pinta prellenado para quien tiene sesión y vacío para un
 * invitado, y que el enlace del menú cambia según is_user_logged_in().
 *
 * Crea su propio usuario y lo borra al final: no toca cuentas reales.
 */

define( 'WP_USE_THEMES', false );

$sf_site_root = (string) getenv( 'SF_SITE_PATH' );
require ( '' !== $sf_site_root ? rtrim( $sf_site_root, '/' ) : dirname( __DIR__, 5 ) ) . '/wp-load.php';

use SimpleForm\Account\Confirmation;
use SimpleForm\Account\Customer;
use SimpleForm\Account\Pages;
use SimpleForm\Database\Database;
use SimpleForm\Database\OrderRepository;
use SimpleForm\Mail\Mailer;

$sf_fallos = 0;

function check( $label, $actual, $expected ) {
	global $sf_fallos;

	$ok = $actual === $expected;

	if ( ! $ok ) {
		++$sf_fallos;
	}

	printf( "%s  %s\n", $ok ? 'OK  ' : 'FAIL', $label );

	if ( ! $ok ) {
		printf( "      esperado: %s\n      obtenido: %s\n", var_export( $expected, true ), var_export( $actual, true ) );
	}
}

$sf_email = 'qa-integracion-' . wp_rand( 1000, 9999 ) . '@example.com';
$sf_pass  = wp_generate_password( 20, false, false );

// --- Registro ---------------------------------------------------------------

$sf_id = wp_insert_user(
	array(
		'user_login'   => 'qa-integracion-' . wp_rand( 1000, 9999 ),
		'user_email'   => $sf_email,
		'user_pass'    => $sf_pass,
		'first_name'   => 'Cliente Integración',
		'display_name' => 'Cliente Integración',
		'role'         => 'subscriber',
	)
);

if ( is_wp_error( $sf_id ) ) {
	echo "No se pudo crear el usuario de prueba: " . $sf_id->get_error_message() . "\n";
	exit( 1 );
}

update_user_meta( $sf_id, Customer::META_PHONE, '987654321' );

$sf_guest_id = get_current_user_id();

// --- Datos del cliente ------------------------------------------------------

$sf_datos = Customer::data( $sf_id );

check( 'nombre desde first_name', $sf_datos['name'], 'Cliente Integración' );
check( 'correo desde user_email', $sf_datos['email'], $sf_email );
check( 'teléfono en nueve dígitos', $sf_datos['phone'], '987654321' );

// Una sola clave: no debe existir una segunda copia del teléfono.
$sf_metas = get_user_meta( $sf_id );
$sf_claves_telefono = array_values(
	array_filter(
		array_keys( $sf_metas ),
		function ( $clave ) {
			return false !== strpos( $clave, 'phone' );
		}
	)
);
check( 'una sola clave de teléfono', $sf_claves_telefono, array( Customer::META_PHONE ) );

// Save() escribe los dos datos juntos y normaliza el teléfono.
check( 'save() acepta un teléfono válido', Customer::save( $sf_id, 'Cliente Renombrado', '+56 9 1111 2222' ), true );
check( 'nombre actualizado', Customer::name( $sf_id ), 'Cliente Renombrado' );
check( 'teléfono normalizado al guardar', Customer::phone( $sf_id ), '911112222' );
check( 'save() rechaza un teléfono inválido', Customer::save( $sf_id, 'Otro', '12345678' ), false );
check( 'el teléfono inválido no pisó el bueno', Customer::phone( $sf_id ), '911112222' );

// Un id que no existe no inventa datos ni rompe.
check( 'user_id inexistente no devuelve datos', Customer::data( 999999 ), array( 'name' => '', 'email' => '', 'phone' => '' ) );

// --- Invitado ---------------------------------------------------------------

wp_set_current_user( 0 );

check( 'invitado no tiene datos de cuenta', Customer::current(), array( 'name' => '', 'email' => '', 'phone' => '' ) );

// --- Formulario prellenado --------------------------------------------------

wp_set_current_user( $sf_id );

$sf_html = do_shortcode( '[simple_form_form]' );

check( 'el formulario trae el nombre de la cuenta', false !== strpos( $sf_html, 'value="Cliente Renombrado"' ), true );
check( 'el formulario trae el correo de la cuenta', false !== strpos( $sf_html, 'value="' . $sf_email . '"' ), true );
check( 'el formulario trae el teléfono sin el +56', false !== strpos( $sf_html, 'value="9 1111 2222"' ), true );

wp_set_current_user( 0 );
// El shortcode se pinta una sola vez por proceso (IDs fijos en el HTML), así
// que hay que soltar ese cerrojo para poder comparar las dos caras.
$sf_render = new ReflectionProperty( 'SimpleForm\\Core\\Shortcode', 'rendered' );
$sf_render->setAccessible( true );
$sf_render->setValue( null, false );

$sf_html_invitado = do_shortcode( '[simple_form_form]' );

check( 'invitado no recibe datos prellenados', false !== strpos( $sf_html_invitado, 'id="pn-email" name="email" class="pn-form__input" value=""' ), true );

// --- Navegación -------------------------------------------------------------

$sf_item = new stdClass();
$sf_item->object_id = 0;
$sf_item->object    = 'custom';
$sf_item->type      = 'custom';
$sf_item->title     = 'Iniciar sesion';
$sf_item->url       = home_url( '/iniciar-sesion/' );

$sf_pages = new Pages();

$sf_invitado = $sf_pages->nav_item( clone $sf_item );
check( 'invitado sigue viendo "Iniciar sesion"', $sf_invitado->title, 'Iniciar sesion' );

wp_set_current_user( $sf_id );
$sf_autenticado = $sf_pages->nav_item( clone $sf_item );
check( 'autenticado ve "Perfil"', $sf_autenticado->title, 'Perfil' );
check( 'autenticado apunta a /perfil/', $sf_autenticado->url, Pages::profile_url() );

// Un enlace que no es el de la cuenta no se toca.
$sf_otro = clone $sf_item;
$sf_otro->url = home_url( '/mapa/' );
$sf_otro->title = 'Mapa';
check( 'otro enlace del menú no se toca', $sf_pages->nav_item( $sf_otro )->title, 'Mapa' );

// --- Página de perfil -------------------------------------------------------

$sf_perfil = get_page_by_path( 'perfil' );
check( 'la página /perfil existe', null !== $sf_perfil, true );

if ( null !== $sf_perfil ) {
	$sf_perfil_url = (string) get_permalink( $sf_perfil );
	check( 'la URL del perfil es la de la página', Pages::profile_url(), $sf_perfil_url );
}

// --- Confirmación de correo (doble opt-in) -----------------------------------

$sf_token = Confirmation::issue( $sf_id );

check( 'la cuenta recién creada queda pendiente', Confirmation::is_pending( $sf_id ), true );
check( 'el token del correo resuelve a la cuenta', Confirmation::verify( $sf_token ), $sf_id );
check( 'un token inventado no resuelve', Confirmation::verify( 'inventado' ), 0 );

// El filtro de autenticación rechaza una cuenta pendiente, también fuera de
// nuestro formulario (wp-login.php).
$sf_auth = wp_authenticate( (string) get_userdata( $sf_id )->user_login, $sf_pass );
check( 'una cuenta pendiente no se autentica', is_wp_error( $sf_auth ), true );

// El correo de confirmación sale con el enlace y sin la contraseña.
$sf_enviados = array();
$sf_captura  = function ( $return, $atts ) use ( &$sf_enviados ) {
	$sf_enviados[] = $atts;

	return true;
};
add_filter( 'pre_wp_mail', $sf_captura, 10, 2 );

Mailer::account_confirmation( $sf_email, 'http://telobuscamos.local/iniciar-sesion/?sf_confirm=ABC', 'Cliente Integración' );

remove_filter( 'pre_wp_mail', $sf_captura, 10 );

check( 'el correo de confirmación se envió', count( $sf_enviados ), 1 );
check( 'y lleva el enlace', count( $sf_enviados ) > 0 ? ( false !== strpos( (string) $sf_enviados[0]['message'], 'sf_confirm=ABC' ) ) : null, true );
check( 'y no lleva la contraseña', count( $sf_enviados ) > 0 ? ( false !== strpos( (string) $sf_enviados[0]['message'], $sf_pass ) ) : null, false );

Confirmation::activate( $sf_id );

check( 'después de confirmar ya no está pendiente', Confirmation::is_pending( $sf_id ), false );
check( 'y el token no se puede reutilizar', Confirmation::verify( $sf_token ), 0 );

$sf_auth = wp_authenticate( (string) get_userdata( $sf_id )->user_login, $sf_pass );
check( 'una cuenta confirmada sí se autentica', $sf_auth instanceof WP_User, true );

// --- Pedido ligado a la cuenta (souls.md §37) --------------------------------

wp_set_current_user( $sf_id );

$sf_draft = OrderRepository::create_draft(
	array(
		'name'        => 'Cliente Integración',
		'email'       => $sf_email,
		'phone'       => '911112222',
		'description' => 'prueba de integración de cuenta',
	)
);
$sf_fila = OrderRepository::find_by_draft_id( $sf_draft );

check( 'el pedido se creó', null !== $sf_fila, true );
check( 'el pedido guarda la cuenta que lo hizo', null !== $sf_fila ? (int) $sf_fila['user_id'] : -1, $sf_id );

// Un invitado sigue pudiendo pedir sin cuenta: queda en 0, no en NULL.
wp_set_current_user( 0 );

$sf_draft_invitado = OrderRepository::create_draft(
	array(
		'name'        => 'Invitado Integración',
		'email'       => 'qa-invitado-' . wp_rand( 1000, 9999 ) . '@example.com',
		'phone'       => '911112223',
		'description' => 'prueba de invitado',
	)
);
$sf_fila_invitado = OrderRepository::find_by_draft_id( $sf_draft_invitado );

check( 'el pedido de un invitado no tiene cuenta', null !== $sf_fila_invitado ? (int) $sf_fila_invitado['user_id'] : -1, 0 );

// Los dos pedidos de prueba no se quedan en el sitio.
if ( null !== $sf_fila ) {
	$wpdb->delete( Database::table_name( Database::TABLE_ORDERS ), array( 'id' => (int) $sf_fila['id'] ) );
}

if ( null !== $sf_fila_invitado ) {
	$wpdb->delete( Database::table_name( Database::TABLE_ORDERS ), array( 'id' => (int) $sf_fila_invitado['id'] ) );
}

check(
	'los pedidos de prueba se borraron',
	(int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Database::table_name( Database::TABLE_ORDERS ) . " WHERE description LIKE 'prueba de %cuenta%' OR description = 'prueba de invitado'" ),
	0
);

// --- Limpieza ---------------------------------------------------------------

wp_set_current_user( 0 );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $sf_id );

check( 'el usuario de prueba se borró', false === get_userdata( $sf_id ), true );

echo "\nFALLAS: {$sf_fallos}\n";
exit( $sf_fallos > 0 ? 1 : 0 );
