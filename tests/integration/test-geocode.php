<?php
/**
 * Endpoint simple_form_geocode contra WordPress real.
 *
 * La red no se toca: el filtro pre_http_request devuelve una respuesta de
 * Photon armada acá (recortada de una real de photon.komoot.io). Se prueba lo
 * que puede fallar: la seguridad, el mínimo de letras, el filtro de Chile, el
 * caché y qué pasa cuando el proveedor se cae.
 *
 * @package SimpleForm\Tests
 */

define( 'WP_USE_THEMES', false );

// admin-ajax.php define DOING_AJAX antes de cargar WordPress. Sin esto,
// wp_send_json() termina en `die;` (wp-includes/functions.php) y no pasa por
// wp_die(), que es donde se engancha el handler para leer la respuesta.
define( 'DOING_AJAX', true );

// Todo el script en un buffer: si algo se imprime antes de un wp_send_json(),
// headers_sent() se vuelve true y WordPress deja de mandar status_header, con lo
// que todos los códigos HTTP de las aserciones salen NULL.
ob_start();

// La raíz del sitio, cinco niveles arriba de tests/integration/.
$sf_site_root = (string) getenv( 'SF_SITE_PATH' );
require ( '' !== $sf_site_root ? rtrim( $sf_site_root, '/' ) : dirname( __DIR__, 5 ) ) . '/wp-load.php';

global $wpdb;

class SF_Die extends Exception {}

function sf_die_handler( $message = '', $title = '', $args = array() ) {
	throw new SF_Die( is_string( $message ) ? $message : '' );
}

add_filter( 'wp_die_handler', function () { return 'sf_die_handler'; } );
add_filter( 'wp_die_ajax_handler', function () { return 'sf_die_handler'; } );

$GLOBALS['sf_geocode_status'] = null;
add_filter( 'status_header', function ( $header, $code ) {
	$GLOBALS['sf_geocode_status'] = $code;
	return $header;
}, 10, 2 );

// Cliente propio y distinto en cada corrida: el rate limit de este endpoint
// comparte ventana de un minuto con las otras suites.
add_filter( 'simple_form_client_ip', function () {
	static $ip = null;
	if ( null === $ip ) {
		$ip = '198.51.100.' . wp_rand( 10, 250 );
	}
	return $ip;
} );

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
 * Respuesta de Photon para las pruebas: una chilena, una argentina y una sin país.
 */
function sf_geocode_body(): string {
	return wp_json_encode(
		array(
			'type'     => 'FeatureCollection',
			'features' => array(
				array(
					'type'       => 'Feature',
					'properties' => array(
						'type'        => 'house',
						'name'        => 'Avenida Providencia',
						'street'      => 'Avenida Providencia',
						'housenumber' => '1234',
						'district'    => 'Providencia',
						'city'        => 'Santiago',
						'country'     => 'Chile',
						'countrycode' => 'CL',
					),
					'geometry'   => array( 'type' => 'Point', 'coordinates' => array( -70.6211027, -33.4290115 ) ),
				),
				array(
					'type'       => 'Feature',
					'properties' => array(
						'type'        => 'house',
						'street'      => 'Mendoza',
						'housenumber' => '100',
						'city'        => 'Neuquén',
						'country'     => 'Argentina',
						'countrycode' => 'AR',
					),
					'geometry'   => array( 'type' => 'Point', 'coordinates' => array( -68.05339, -38.95412 ) ),
				),
			),
		)
	);
}

/**
 * Cuenta cuántas veces se le pidió algo al proveedor.
 */
$GLOBALS['sf_geocode_http'] = 0;

add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
	if ( false === strpos( (string) $url, 'photon.komoot.io' ) ) {
		return $preempt;
	}

	$GLOBALS['sf_geocode_http']++;

	$modo = isset( $GLOBALS['sf_geocode_modo'] ) ? $GLOBALS['sf_geocode_modo'] : 'ok';

	if ( 'caido' === $modo ) {
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array( 'code' => 503, 'message' => 'Service Unavailable' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	if ( 'basura' === $modo ) {
		return array(
			'headers'  => array(),
			'body'     => 'no es json',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	return array(
		'headers'  => array(),
		'body'     => sf_geocode_body(),
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'cookies'  => array(),
		'filename' => null,
	);
}, 10, 3 );

$ajax  = new SimpleForm\Core\Ajax();
$nonce = wp_create_nonce( 'simple_form_nonce' );

/**
 * @param array $post
 * @return array [ status, json ]
 */
function llamar( $ajax, array $post ): array {
	$_POST = $post;

	ob_start();

	try {
		$ajax->geocode();
	} catch ( SF_Die $e ) {
		// wp_send_json_* corta con wp_die(): acá ya está capturado.
	}

	$salida = ob_get_clean();
	$status = $GLOBALS['sf_geocode_status'];
	$GLOBALS['sf_geocode_status'] = null;

	return array( $status, json_decode( (string) $salida, true ) );
}

echo "=== 1. sin nonce ===\n";

list( $status, $json ) = llamar( $ajax, array( 'query' => 'Providencia 1234' ) );
check( 'sin nonce -> 403', $status, 403 );
check( 'success = false', $json['success'], false );
check( 'no se consultó al proveedor', $GLOBALS['sf_geocode_http'], 0 );

echo "\n=== 2. consulta demasiado corta ===\n";

list( $status, $json ) = llamar( $ajax, array( 'nonce' => $nonce, 'query' => 'ab' ) );
check( 'menos de 3 letras -> 422', $status, 422 );
check( 'con mensaje', (bool) strpos( (string) $json['data']['message'], '3 letras' ), true );
check( 'no se consultó al proveedor', $GLOBALS['sf_geocode_http'], 0 );

echo "\n=== 3. consulta válida: solo Chile ===\n";

list( $status, $json ) = llamar( $ajax, array( 'nonce' => $nonce, 'query' => 'Providencia 1234' ) );

check( 'success = true', $json['success'], true );
check( 'solo queda la chilena', count( $json['data']['results'] ), 1 );
check( 'con la etiqueta armada', $json['data']['results'][0]['label'], 'Avenida Providencia 1234, Providencia' );
check( 'con la latitud correcta', $json['data']['results'][0]['lat'], -33.4290115 );
check( 'con la longitud correcta', $json['data']['results'][0]['lng'], -70.6211027 );
check( 'se consultó una vez', $GLOBALS['sf_geocode_http'], 1 );

echo "\n=== 4. el caché evita la segunda consulta ===\n";

list( $status, $json ) = llamar( $ajax, array( 'nonce' => $nonce, 'query' => 'Providencia 1234' ) );
check( 'sigue respondiendo', $json['success'], true );
check( 'con el mismo resultado', $json['data']['results'][0]['label'], 'Avenida Providencia 1234, Providencia' );
check( 'sin volver a consultar al proveedor', $GLOBALS['sf_geocode_http'], 1 );

echo "\n=== 5. el proveedor caído no rompe el paso ===\n";

$GLOBALS['sf_geocode_modo'] = 'caido';

list( $status, $json ) = llamar( $ajax, array( 'nonce' => $nonce, 'query' => 'Punta Arenas 9999' ) );
check( 'responde igual', $json['success'], true );
check( 'con lista vacía', $json['data']['results'], array() );
check( 'se consultó', $GLOBALS['sf_geocode_http'], 2 );

echo "\n=== 6. respuesta ilegible del proveedor ===\n";

$GLOBALS['sf_geocode_modo'] = 'basura';

list( $status, $json ) = llamar( $ajax, array( 'nonce' => $nonce, 'query' => 'Valparaiso 1234' ) );
check( 'responde igual', $json['success'], true );
check( 'con lista vacía', $json['data']['results'], array() );

echo "\n=== Limpieza ===\n";

// Claves de caché que dejaron las consultas de esta corrida.
foreach ( array( 'providencia 1234', 'punta arenas 9999', 'valparaiso 1234' ) as $consulta ) {
	delete_transient( SimpleForm\Core\Geocoder::CACHE_PREFIX . md5( $consulta ) );
}

$restantes = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", '%sf_geo_%' )
);
check( 'sin caché de prueba restante', $restantes, 0 );

printf( "\n%s\n", 0 === $failures ? 'TODO OK' : "FALLAS: {$failures}" );
exit( $failures > 0 ? 1 : 0 );
