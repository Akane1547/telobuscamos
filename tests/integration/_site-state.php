<?php
/**
 * Foto del estado del sitio antes y después de la suite de integración.
 *
 * Cada suite guarda y restaura lo que toca, pero eso solo funciona si el punto
 * de partida estaba limpio: si una suite deja su plan de prueba y la siguiente
 * "restaura" lo que vio, la basura pasa de mano en mano y el sitio termina con
 * el plan de prueba, el radio de fábrica y un pedido huérfano.
 *
 * Este script es el punto único:
 *
 *   php _site-state.php save /ruta/estado.json
 *   php _site-state.php restore /ruta/estado.json
 *
 * Restaura las opciones que las suites tocan y borra los pedidos de prueba
 * creados mientras corrían (solo los de correos @example.test con id mayor al
 * de partida, para no tocar un pedido real que entre en ese rato).
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$accion = $argv[1] ?? '';
$ruta   = $argv[2] ?? '';

if ( ! in_array( $accion, array( 'save', 'restore' ), true ) || '' === $ruta ) {
	fwrite( STDERR, "uso: _site-state.php save|restore <archivo>\n" );
	exit( 1 );
}

$sitio = (string) getenv( 'SF_SITE_PATH' );
require ( '' !== $sitio ? rtrim( $sitio, '/' ) : dirname( __DIR__, 5 ) ) . '/wp-load.php';

global $wpdb;

$opciones = array(
	'simple_form_services',
	'simple_form_radius',
	'simple_form_payment_credentials',
	'simple_form_payment_methods',
	'simple_form_catalogs',
	'simple_form_custom_methods',
	'simple_form_gateway_methods',
);

$tabla = $wpdb->prefix . 'sf_orders';

if ( 'save' === $accion ) {
	$foto = array( 'opciones' => array(), 'pedido_max' => (int) $wpdb->get_var( "SELECT MAX(id) FROM `{$tabla}`" ) );

	foreach ( $opciones as $nombre ) {
		$valor = get_option( $nombre, null );

		// null = la opción no existía (se borra al restaurar); false = existe y
		// vale vacío, distinto caso.
		$foto['opciones'][ $nombre ] = array(
			'existe' => null !== $valor,
			'valor'  => $valor,
		);
	}

	file_put_contents( $ruta, wp_json_encode( $foto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

	printf( "estado guardado: %d opciones, último pedido #%d\n", count( $opciones ), $foto['pedido_max'] );
	exit( 0 );
}

// --- restore -----------------------------------------------------------------

$foto = json_decode( (string) file_get_contents( $ruta ), true );

if ( ! is_array( $foto ) || empty( $foto['opciones'] ) ) {
	fwrite( STDERR, "el archivo de estado está vacío o roto: {$ruta}\n" );
	exit( 1 );
}

foreach ( $foto['opciones'] as $nombre => $dato ) {
	if ( empty( $dato['existe'] ) ) {
		delete_option( $nombre );
	} else {
		update_option( $nombre, $dato['valor'] );
	}
}

$borrados = (int) $wpdb->query(
	$wpdb->prepare(
		"DELETE FROM `{$tabla}` WHERE id > %d AND ( email LIKE '%%@example.test' OR email LIKE '%%@example.com' )",
		(int) $foto['pedido_max']
	)
);

printf( "estado restaurado: %d opciones, %d pedido(s) de prueba borrados\n", count( $foto['opciones'] ), $borrados );
exit( 0 );
