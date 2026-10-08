<?php
/**
 * Prueba REAL del paso 4: ejecuta uninstall.php contra el MySQL del sitio,
 * comprueba que borra lo que debe, y después restaura el estado del sitio.
 *
 * Aborta sin tocar nada si encuentra pedidos: borrarlos sería perder datos.
 */

define( 'WP_USE_THEMES', false );

// La raíz del sitio, cinco niveles arriba de tests/integration/. Se puede
// apuntar a otra instalación con SF_SITE_PATH=/ruta/al/sitio.
$sf_site_root = (string) getenv( 'SF_SITE_PATH' );
require ( '' !== $sf_site_root ? rtrim( $sf_site_root, '/' ) : dirname( __DIR__, 5 ) ) . '/wp-load.php';

global $wpdb;

$plugin_dir = WP_PLUGIN_DIR . '/simple-form/';
$DB         = 'SimpleForm\Database\Database';

require_once $plugin_dir . 'src/Database/Database.php';

$failures = 0;

function check( $label, $actual, $expected ) {
	global $failures;

	$ok = ( $actual === $expected );
	if ( ! $ok ) {
		$failures++;
	}

	printf( "%s %-44s esperado=%-14s obtenido=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export( $expected, true ), var_export( $actual, true ) );
}

$orders = $DB::table_name( $DB::TABLE_ORDERS );
$events = $DB::table_name( $DB::TABLE_PAYMENT_EVENTS );
$like   = $wpdb->esc_like( '_transient_sf_client_session_' ) . '%';

function session_transients() {
	global $wpdb, $like;

	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
}

echo "=== estado previo ===\n";
$tables = (array) $wpdb->get_col( 'SHOW TABLES' );
check( 'sf_orders existe antes', in_array( $orders, $tables, true ), true );
check( 'sf_payment_events existe antes', in_array( $events, $tables, true ), true );

$pending  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$orders}`" );
$previous = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$events}`" );
printf( "filas en sf_orders: %d   filas en sf_payment_events: %d\n", $pending, $previous );

if ( 0 !== $pending || 0 !== $previous ) {
	echo "\nSKIP: hay pedidos/eventos guardados; no se ejecuta un DROP destructivo.\n";
	exit( 2 );
}

$services_before = get_option( 'simple_form_services', null );
printf( "simple_form_services antes: %s\n", var_export( $services_before, true ) );

// Opciones de la Fase 3: uninstall.php también las borra, así que se respaldan
// para poder devolver el sitio como estaba. Las credenciales no se imprimen
// (van cifradas, pero igual: no se vuelcan credenciales en una salida).
$methods_before     = get_option( 'simple_form_payment_methods', null );
$credentials_before = get_option( 'simple_form_payment_credentials', null );

$names = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
printf( "transients de sesion preexistentes: %d%s\n", count( $names ), $names ? ' [' . implode( ', ', $names ) . ']' : '' );

$baseline = session_transients();
set_transient( 'sf_client_session_TESTUNINSTALL', array( 'user_id' => 0, 'current_step' => 1 ), HOUR_IN_SECONDS );
check( 'transient de sesion de prueba creado', session_transients(), $baseline + 1 );

echo "\n=== ejecutando uninstall.php ===\n";
define( 'WP_UNINSTALL_PLUGIN', 'simple-form/simple-form.php' );

$wpdb->last_error = '';
include $plugin_dir . 'uninstall.php';
echo "uninstall.php incluido y ejecutado\n";

check( 'sin errores de SQL en uninstall', $wpdb->last_error, '' );

echo "\n=== despues de desinstalar ===\n";
$tables2 = (array) $wpdb->get_col( 'SHOW TABLES' );
check( 'sf_orders eliminada', in_array( $orders, $tables2, true ), false );
check( 'sf_payment_events eliminada', in_array( $events, $tables2, true ), false );
check( 'simple_form_services eliminada', get_option( 'simple_form_services', null ), null );
check( 'simple_form_db_version eliminada', get_option( 'simple_form_db_version', null ), null );
check( 'simple_form_payment_methods eliminada', get_option( 'simple_form_payment_methods', null ), null );
check( 'simple_form_payment_credentials eliminada', get_option( 'simple_form_payment_credentials', null ), null );
check( 'transients de sesion eliminados', session_transients(), 0 );

// Control: no se llevó por delante nada ajeno.
check( 'wp_options sigue existiendo', in_array( $wpdb->options, $tables2, true ), true );
check( 'wp_posts sigue existiendo', in_array( $wpdb->posts, $tables2, true ), true );

echo "\n=== restaurando el sitio ===\n";
$DB::create_tables();

if ( null === $services_before ) {
	delete_option( 'simple_form_services' );
} else {
	update_option( 'simple_form_services', $services_before );
}

if ( null === $methods_before ) {
	delete_option( 'simple_form_payment_methods' );
} else {
	update_option( 'simple_form_payment_methods', $methods_before );
}

if ( null === $credentials_before ) {
	delete_option( 'simple_form_payment_credentials' );
} else {
	update_option( 'simple_form_payment_credentials', $credentials_before );
}

$tables3 = (array) $wpdb->get_col( 'SHOW TABLES' );
check( 'sf_orders restaurada', in_array( $orders, $tables3, true ), true );
check( 'sf_payment_events restaurada', in_array( $events, $tables3, true ), true );
check( 'simple_form_services restaurada', get_option( 'simple_form_services', null ), $services_before );
check( 'simple_form_db_version restaurada', (int) get_option( 'simple_form_db_version' ), $DB::DB_VERSION );
// Sin volcar el valor: lo que importa es que volvió tal cual estaba.
check(
	'simple_form_payment_methods restaurada',
	get_option( 'simple_form_payment_methods', null ) === $methods_before,
	true
);
check(
	'simple_form_payment_credentials restaurada',
	get_option( 'simple_form_payment_credentials', null ) === $credentials_before,
	true
);

printf( "\n%s\n", 0 === $failures ? 'TODO OK' : "FALLAS: {$failures}" );
exit( $failures > 0 ? 1 : 0 );
