<?php
/**
 * Prueba real contra la API de sandbox de Mercado Pago.
 *
 * Crea una preferencia de verdad y muestra la URL que devuelve. No cobra nada:
 * una preferencia no es un pago, y las credenciales de prueba no permiten
 * transacciones reales.
 *
 * La fila del pedido se inserta en la tabla real para que los nombres de las
 * columnas salgan de la base y no de mi cabeza. Se borra al terminar.
 */

// La raíz del sitio, cinco niveles arriba de tests/integration/. Se puede
// apuntar a otra instalación con SF_SITE_PATH=/ruta/al/sitio.
$sf_site_root = (string) getenv( 'SF_SITE_PATH' );
require ( '' !== $sf_site_root ? rtrim( $sf_site_root, '/' ) : dirname( __DIR__, 5 ) ) . '/wp-load.php';

$public = 'https://unheated-trapping-uncouple.ngrok-free.dev';

// El script corre por CLI: no hay Referer ni host publico. Se simula el contexto
// del tunel para que las back_urls sean las que veria Mercado Pago en produccion.
$_SERVER['HTTP_REFERER'] = $public . '/mapa/';

add_filter(
	'option_home',
	function () use ( $public ) {
		return $public;
	},
	99
);

add_filter(
	'option_siteurl',
	function () use ( $public ) {
		return $public;
	},
	99
);

global $wpdb;

$table    = $wpdb->prefix . 'sf_orders';
$inserted = $wpdb->insert(
	$table,
	array(
		'draft_id'             => 'live-' . wp_generate_uuid4(),
		'full_name'            => 'Prueba Automatica',
		'email'                => 'prueba@ejemplo.cl',
		'phone'                => '912345678',
		'service_id'           => '1',
		'service_label'        => 'plan basico',
		'service_base_price'   => 10000,
		'service_price_per_km' => 40000,
		'lat'                  => -33.4489,
		'lng'                  => -70.6693,
		'radius_km'            => 8,
		'amount'               => 50000,
		'payment_method'       => 'mercado_pago',
		'status'               => 'pending',
		'current_step'         => 4,
		'created_at'           => gmdate( 'Y-m-d H:i:s' ),
		'updated_at'           => gmdate( 'Y-m-d H:i:s' ),
	)
);

if ( ! $inserted ) {
	echo "no se pudo insertar la fila de prueba: {$wpdb->last_error}\n";
	exit( 1 );
}

$order_id = (int) $wpdb->insert_id;
$order    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $order_id ), ARRAY_A );

$gateway = new SimpleForm\Payments\MercadoPagoGateway();

echo "=== payload que se le manda a Mercado Pago ===\n";
print_r( $gateway->build_preference( $order ) );

echo "\n=== llamando a la API real (sandbox) ===\n";
$url = $gateway->create_payment( $order );

if ( '' === $url ) {
	echo "FALLO: la API rechazo la preferencia\n";
} else {
	$parts = wp_parse_url( $url );

	echo "OK: la API acepto la preferencia\n";
	echo '  host del init_point: ' . ( $parts['host'] ?? '?' ) . "\n";
	echo '  path: ' . substr( (string) ( $parts['path'] ?? '' ), 0, 40 ) . "\n";
	echo '  query: ' . substr( (string) ( $parts['query'] ?? '' ), 0, 30 ) . "...\n";
}

$wpdb->delete( $table, array( 'id' => $order_id ) );
echo "\nfila de prueba borrada (id {$order_id})\n";
