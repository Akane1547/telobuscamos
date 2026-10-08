<?php
/**
 * La ruta del webhook, contra WordPress de verdad.
 *
 * Comprueba lo que las pruebas unitarias no pueden: que la ruta exista en
 * /wp-json/simple-form/webhook, que acepte POST y que rechace lo que no venga
 * firmado.
 *
 * No llama a la API de Mercado Pago a propósito: todos los casos de acá se
 * rechazan antes de consultar nada, así que la suite no depende de que Mercado
 * Pago esté disponible. El caso con firma válida llega hasta la API y se prueba
 * a mano: con el botón "Simular notificación" del panel, o con
 * mercado-pago-live.php.
 */

define( 'WP_USE_THEMES', false );

// La raíz del sitio, cinco niveles arriba de tests/integration/. Se puede
// apuntar a otra instalación con SF_SITE_PATH=/ruta/al/sitio.
$sf_site_root = (string) getenv( 'SF_SITE_PATH' );
require ( '' !== $sf_site_root ? rtrim( $sf_site_root, '/' ) : dirname( __DIR__, 5 ) ) . '/wp-load.php';

$failures = 0;

function check( $label, $actual, $expected ) {
	global $failures;

	$ok = ( $actual === $expected );
	if ( ! $ok ) {
		$failures++;
	}

	printf( "%s %-46s esperado=%-14s obtenido=%s\n", $ok ? 'PASS' : 'FAIL', $label, var_export( $expected, true ), var_export( $actual, true ) );
}

$url = home_url( '/wp-json/simple-form/webhook' );
echo "ruta: {$url}\n\n";

// --- 1. sin firma ------------------------------------------------------------

echo "=== 1. sin firma ===\n";

$respuesta = wp_remote_post(
	$url,
	array(
		'timeout' => 20,
		'body'    => array(
			'type' => 'payment',
			'data' => array( 'id' => '123456' ),
		),
	)
);

$codigo = (int) wp_remote_retrieve_response_code( $respuesta );
$cuerpo = json_decode( (string) wp_remote_retrieve_body( $respuesta ), true );

// Si la ruta no existiera, WordPress respondería su propio 404 con code
// rest_no_route. Que responda otra cosa es la prueba de que está registrada.
$es_de_wp = is_array( $cuerpo ) && isset( $cuerpo['code'] ) && 'rest_no_route' === $cuerpo['code'];

check( 'la ruta está registrada', $es_de_wp ? 'no registrada' : 'registrada', 'registrada' );
check( 'sin firma -> 401', $codigo, 401 );
check( 'responde ok = false', is_array( $cuerpo ) ? ( $cuerpo['ok'] ?? null ) : null, false );

// --- 2. firma inventada ------------------------------------------------------

echo "\n=== 2. firma inventada ===\n";

$respuesta = wp_remote_post(
	$url,
	array(
		'timeout' => 20,
		'headers' => array(
			'x-signature'  => 'ts=1704908010,v1=' . str_repeat( 'a', 64 ),
			'x-request-id' => 'req-de-prueba',
		),
		'body'    => array(
			'type' => 'payment',
			'data' => array( 'id' => '123456' ),
		),
	)
);

check( 'firma que no es de Mercado Pago -> 401', (int) wp_remote_retrieve_response_code( $respuesta ), 401 );

// --- 2b. firma válida: la verificación tiene que pasar -----------------------

echo "\n=== 2b. firma valida, calculada con el secreto del sitio ===\n";

$secreto = (string) \SimpleForm\Payments\PaymentConfig::credential( 'mercado_pago', 'webhook_secret' );

if ( '' === $secreto ) {
	echo "  (sin secreto configurado: no se puede probar la firma)\n";
} else {
	// El manifiesto se arma con el data.id de la query, el x-request-id y el ts.
	// Esta prueba cubre de paso que `data.id` llegue con su nombre y no como
	// `data_id` (PHP cambia los puntos en $_GET).
	$ts      = (string) ( time() * 1000 );
	$rid     = 'req-integracion-' . wp_generate_uuid4();
	$mensaje = sprintf( 'id:%s;request-id:%s;ts:%s;', '123456', $rid, $ts );

	$respuesta = wp_remote_post(
		$url . '?data.id=123456&type=payment',
		array(
			'timeout' => 30,
			'headers' => array(
				'x-signature'  => 'ts=' . $ts . ',v1=' . hash_hmac( 'sha256', $mensaje, $secreto ),
				'x-request-id' => $rid,
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'type' => 'payment',
					'data' => array( 'id' => '123456' ),
				)
			),
		)
	);

	$codigo = (int) wp_remote_retrieve_response_code( $respuesta );

	// Con una firma válida el handler sigue: consulta el pago en la API, y con un
	// id inventado eso da 500. Lo que se comprueba es que NO sea 401.
	check( 'una firma valida no se rechaza con 401', 401 === $codigo ? '401' : 'paso', 'paso' );
}

// --- 3. header malformado ----------------------------------------------------

echo "\n=== 3. header malformado ===\n";

$respuesta = wp_remote_post(
	$url,
	array(
		'timeout' => 20,
		'headers' => array(
			'x-signature'  => 'v1=solov1sin-ts',
			'x-request-id' => 'req-de-prueba',
		),
		'body'    => array(
			'type' => 'payment',
			'data' => array( 'id' => '123456' ),
		),
	)
);

check( 'header sin ts -> 401', (int) wp_remote_retrieve_response_code( $respuesta ), 401 );

// --- 4. sin id de pago -------------------------------------------------------

echo "\n=== 4. sin id de pago ===\n";

$respuesta = wp_remote_post(
	$url,
	array(
		'timeout' => 20,
		'headers' => array(
			'x-signature'  => 'ts=1704908010,v1=' . str_repeat( 'a', 64 ),
			'x-request-id' => 'req-de-prueba',
		),
		'body'    => array( 'type' => 'payment' ),
	)
);

check( 'sin id -> 400', (int) wp_remote_retrieve_response_code( $respuesta ), 400 );

// --- 5. un tipo que no procesamos -------------------------------------------

echo "\n=== 5. tipo de notificación que no nos interesa ===\n";

$respuesta = wp_remote_post(
	$url,
	array(
		'timeout' => 20,
		'body'    => array(
			'type' => 'plan',
			'data' => array( 'id' => '123456' ),
		),
	)
);

$cuerpo = json_decode( (string) wp_remote_retrieve_body( $respuesta ), true );

// 200 a propósito: que Mercado Pago no reintente algo que no vamos a procesar.
check( 'un tipo que no procesamos -> 200', (int) wp_remote_retrieve_response_code( $respuesta ), 200 );
check( 'y no se procesó', is_array( $cuerpo ) ? ( $cuerpo['ok'] ?? null ) : null, true );

// --- 6. el método ------------------------------------------------------------

echo "\n=== 6. método ===\n";

$respuesta = wp_remote_get( $url, array( 'timeout' => 20 ) );

$cuerpo = json_decode( (string) wp_remote_retrieve_body( $respuesta ), true );

// WordPress responde su 404 rest_no_route cuando el método no coincide con el
// registrado, no un 405. Lo que importa es que no lo atienda.
check( 'GET no lo atiende', (int) wp_remote_retrieve_response_code( $respuesta ), 404 );
check( 'y responde el 404 genérico de la API de WordPress', is_array( $cuerpo ) ? ( $cuerpo['code'] ?? '' ) : '', 'rest_no_route' );

printf( "\n%s\n", 0 === $failures ? 'TODO OK' : "FALLAS: {$failures}" );
exit( $failures > 0 ? 1 : 0 );
