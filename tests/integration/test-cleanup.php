<?php
/**
 * La limpieza programada, contra WordPress y la base de verdad.
 *
 * Comprueba que el evento esté programado y que una corrida expire los pedidos
 * abandonados y borre los punteros de sesión vencidos, sin tocar un pedido
 * pagado.
 */

define( 'WP_USE_THEMES', false );

$sf_site_root = (string) getenv( 'SF_SITE_PATH' );
require ( '' !== $sf_site_root ? rtrim( $sf_site_root, '/' ) : dirname( __DIR__, 5 ) ) . '/wp-load.php';

global $wpdb;

$failures = 0;

function check( $label, $actual, $expected ) {
	global $failures;

	$ok = ( $actual === $expected );
	if ( ! $ok ) {
		$failures++;
	}

	printf( "%s %-52s esperado=%-14s obtenido=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/**
 * Estado actual de un pedido por su draft_id.
 */
function estado( string $draft_id ): string {
	global $wpdb;

	return (string) $wpdb->get_var(
		$wpdb->prepare( "SELECT status FROM {$wpdb->prefix}sf_orders WHERE draft_id = %s", $draft_id )
	);
}

$tabla   = $wpdb->prefix . 'sf_orders';
$prefijo = 'limpieza-' . wp_generate_uuid4() . '-';

$ahora   = current_time( 'mysql', true );
$viejo   = gmdate( 'Y-m-d H:i:s', time() - ( 8 * DAY_IN_SECONDS ) );
$antiguo = gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) );

$filas = array(
	'draft-viejo'    => array( 'status' => 'draft', 'cuando' => $viejo ),
	'pending-viejo'  => array( 'status' => 'pending', 'cuando' => $viejo ),
	'approved-viejo' => array( 'status' => 'approved', 'cuando' => $antiguo ),
	'draft-nuevo'    => array( 'status' => 'draft', 'cuando' => $ahora ),
);

foreach ( $filas as $clave => $fila ) {
	$wpdb->insert(
		$tabla,
		array(
			'draft_id'   => $prefijo . $clave,
			'status'     => $fila['status'],
			'created_at' => $fila['cuando'],
			'updated_at' => $fila['cuando'],
		),
		array( '%s', '%s', '%s', '%s' )
	);
}

echo "=== el evento programado ===\n";

check( 'el evento de limpieza esta programado', (bool) wp_next_scheduled( \SimpleForm\Core\Cleanup::HOOK ), true );

$programado_antes = wp_next_scheduled( \SimpleForm\Core\Cleanup::HOOK );
\SimpleForm\Core\Cleanup::maybe_schedule();
check( 'si ya esta, no se reprograma', wp_next_scheduled( \SimpleForm\Core\Cleanup::HOOK ) === $programado_antes, true );

// Un puntero de sesión vencido: su timeout ya pasó.
$clave_sesion = \SimpleForm\Core\Session::TRANSIENT_PREFIX . 'prueba' . wp_generate_password( 8, false, false );
set_transient( $clave_sesion, array( 'draft_id' => 'de-prueba' ), 1 );

echo "\n=== la limpieza ===\n";

$espera = 2;
sleep( $espera );

$limpieza = new \SimpleForm\Core\Cleanup();
$limpieza->run();

check( 'el borrador viejo expiro', estado( $prefijo . 'draft-viejo' ), 'expired' );
check( 'el pago sin confirmar viejo expiro', estado( $prefijo . 'pending-viejo' ), 'expired' );
check( 'el pedido aprobado NO se toco', estado( $prefijo . 'approved-viejo' ), 'approved' );
check( 'el borrador de hoy sigue como estaba', estado( $prefijo . 'draft-nuevo' ), 'draft' );
check( 'el puntero vencido se borro', get_transient( $clave_sesion ), false );

$timeout = $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", '_transient_timeout_' . $clave_sesion )
);
check( 'y su fila de timeout tambien', (int) $timeout, 0 );

echo "\n=== limpieza de las filas de prueba ===\n";

$borradas = $wpdb->query( $wpdb->prepare( "DELETE FROM {$tabla} WHERE draft_id LIKE %s", $prefijo . '%' ) );
printf( "  filas de prueba borradas: %d\n", (int) $borradas );

printf( "\n%s\n", 0 === $failures ? 'TODO OK' : "FALLAS: {$failures}" );
exit( $failures > 0 ? 1 : 0 );
