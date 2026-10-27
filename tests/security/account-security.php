<?php
/**
 * Pruebas de seguridad del sistema de cuentas (souls.md §48-51).
 *
 * Ataca el sitio por HTTP real, con cookies y nonces de verdad, y usa
 * WordPress cargado en el mismo proceso solo para mirar el estado del servidor
 * (qué quedó guardado, qué usuario se creó) y para limpiar al final.
 *
 * Va aparte de `composer test:all` a propósito: necesita el sitio levantado.
 *
 *   SF_SECURITY_URL=http://127.0.0.1 SF_SECURITY_HOST=telobuscamos.local \
 *     <php de Local> tests/security/account-security.php
 *
 * Los límites de peticiones se reinician entre grupos (borrando el transient
 * del contador) para que un grupo no invalide al siguiente; el grupo de fuerza
 * bruta es el único que los mide, y va al final. Se dice en la salida cada vez.
 *
 * Nunca imprime contraseñas ni credenciales: todo valor de test largo o
 * sensible sale redactado.
 */

$sf_site_root = (string) getenv( 'SF_SITE_PATH' );
require ( '' !== $sf_site_root ? rtrim( $sf_site_root, '/' ) : dirname( __DIR__, 5 ) ) . '/wp-load.php';

use SimpleForm\Account\Customer;

// ---------------------------------------------------------------- configuración

$SF_BASE = rtrim( (string) ( getenv( 'SF_SECURITY_URL' ) ?: 'http://127.0.0.1' ), '/' );
$SF_HOST = (string) ( getenv( 'SF_SECURITY_HOST' ) ?: 'telobuscamos.local' );
$SF_TMP  = rtrim( sys_get_temp_dir(), '/' ) . '/sf-security-' . wp_rand( 10000, 99999 );
$SF_LOG  = (string) ( getenv( 'SF_SECURITY_LOG' ) ?: dirname( __DIR__, 7 ) . '/logs/php/error.log' );

if ( ! is_dir( $SF_TMP ) ) {
	wp_mkdir_p( $SF_TMP );
}

$SF_FAILS  = 0;
$SF_RUNS   = 0;
$SF_SKIPS  = 0;
$SF_BLOQUE = '';

/**
 * Redacta lo que no debe quedar en el transcript: contraseñas y cadenas largas.
 *
 * @param mixed $value Valor a mostrar.
 * @return string
 */
function sf_show( $value ): string {
	if ( is_bool( $value ) ) {
		return $value ? 'true' : 'false';
	}

	if ( is_object( $value ) ) {
		return is_a( $value, 'WP_User' ) ? 'WP_User #' . $value->ID : get_class( $value );
	}

	if ( is_array( $value ) ) {
		return json_encode( $value );
	}

	$text = (string) $value;

	if ( strlen( $text ) > 60 ) {
		return '[cadena de ' . strlen( $text ) . ' caracteres, no se imprime]';
	}

	return $text;
}

/**
 * Aserción con el formato del resto de las suites.
 *
 * @param string $label    Qué se probó.
 * @param mixed  $actual   Lo que devolvió el sitio.
 * @param mixed  $expected Lo que tenía que devolver.
 */
function check( string $label, $actual, $expected ): void {
	global $SF_FAILS, $SF_RUNS;

	++$SF_RUNS;
	$ok = $actual === $expected;

	if ( ! $ok ) {
		++$SF_FAILS;
	}

	printf(
		"%s  %s\n",
		$ok ? 'PASS' : 'FAIL',
		$label
	);

	if ( ! $ok ) {
		printf( "      esperado: %s\n      obtenido: %s\n", sf_show( $expected ), sf_show( $actual ) );
	}
}

/**
 * Prueba que no se puede ejecutar (se dice, no se marca como aprobada).
 *
 * @param string $label  Qué se quería probar.
 * @param string $motivo Por qué no se ejecutó.
 */
function skip( string $label, string $motivo ): void {
	global $SF_SKIPS;

	++$SF_SKIPS;
	printf( "LÍMITE  %s — no ejecutada: %s\n", $label, $motivo );
}

/**
 * Encabezado de grupo.
 *
 * @param string $title Título.
 */
function grupo( string $title ): void {
	echo "\n=== {$title} ===\n";
}

/**
 * Petición HTTP real contra el sitio. No sigue redirecciones: se miran.
 *
 * @param string      $method  GET|POST.
 * @param string      $path    Ruta con query.
 * @param array|null  $data    Campos del POST.
 * @param string|null $jar     Archivo de cookies (identidad).
 * @param array       $headers Cabeceras extra.
 * @return array{status:int,headers:array,body:string,location:string}
 */
function http( string $method, string $path, $data = null, $jar = null, array $headers = array() ): array {
	global $SF_BASE, $SF_HOST;

	$ch = curl_init( $SF_BASE . $path );

	$hdrs = array_merge( array( 'Host: ' . $SF_HOST ), $headers );

	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_TIMEOUT        => 25,
			CURLOPT_HTTPHEADER     => $hdrs,
		)
	);

	if ( null !== $jar ) {
		curl_setopt( $ch, CURLOPT_COOKIEJAR, $jar );
		curl_setopt( $ch, CURLOPT_COOKIEFILE, $jar );
	}

	if ( 'POST' === $method ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( (array) $data ) );
	}

	$raw  = (string) curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	curl_close( $ch );

	$head   = substr( $raw, 0, $size );
	$body   = substr( $raw, $size );
	$parsed = array();

	foreach ( explode( "\r\n", $head ) as $line ) {
		if ( false !== strpos( $line, ':' ) ) {
			list( $k, $v ) = explode( ':', $line, 2 );
			$parsed[ strtolower( trim( $k ) ) ] = trim( $v );
		}
	}

	return array(
		'status'   => $code,
		'headers'  => $parsed,
		'body'     => $body,
		'location' => isset( $parsed['location'] ) ? (string) $parsed['location'] : '',
	);
}

/**
 * Nonce del formulario de cuenta.
 *
 * @param string $html HTML de la página.
 * @return string
 */
function nonce_from( string $html ): string {
	return preg_match( '/name="_sf_nonce"\s+value="([^"]+)"/', $html, $m ) ? $m[1] : '';
}

/**
 * Inicia sesión por HTTP con una identidad (archivo de cookies).
 *
 * @param string $jar   Archivo de cookies.
 * @param string $email Correo.
 * @param string $pass  Contraseña.
 * @param array  $extra Campos extra que se quieran colar.
 * @return array
 */
function login( string $jar, string $email, string $pass, array $extra = array() ): array {
	$page  = http( 'GET', '/iniciar-sesion/', null, $jar );
	$nonce = nonce_from( $page['body'] );

	return http(
		'POST',
		'/iniciar-sesion/',
		array_merge(
			array(
				'_sf_nonce'        => $nonce,
				'sf_account_action' => 'login',
				'sf_email'         => $email,
				'sf_password'      => $pass,
			),
			$extra
		),
		$jar
	);
}

/**
 * Registra una cuenta por HTTP, como lo haría el navegador.
 *
 * @param string $jar   Archivo de cookies.
 * @param string $name  Nombre.
 * @param string $email Correo.
 * @param string $phone Teléfono.
 * @param string $pass  Contraseña.
 * @param array  $extra Campos extra que se quieran colar.
 * @return array
 */
function register( string $jar, string $name, string $email, string $phone, string $pass, array $extra = array() ): array {
	$page  = http( 'GET', '/iniciar-sesion/?form=registro', null, $jar );
	$nonce = nonce_from( $page['body'] );

	return http(
		'POST',
		'/iniciar-sesion/',
		array_merge(
			array(
				'_sf_nonce'         => $nonce,
				'sf_account_action' => 'register',
				'sf_name'           => $name,
				'sf_email'          => $email,
				'sf_phone'          => $phone,
				'sf_password'       => $pass,
			),
			$extra
		),
		$jar
	);
}

/**
 * Dice si una respuesta de formulario trae un mensaje de error.
 *
 * @param string $body HTML.
 * @return string Texto del aviso, o ''.
 */
function alerta( string $body ): string {
	if ( ! preg_match( '/sf-account__alert[^>]*>(.*?)<\/p>/s', $body, $m ) ) {
		return '';
	}

	return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $m[1] ) ) );
}

/**
 * Reinicia los contadores de límite del sitio (aislamiento entre grupos).
 *
 * @param string $accion account_login|account_register|save_step1.
 */
function reset_limite( string $accion ): void {
	delete_transient( 'sf_rate_limit_' . md5( $accion . '|127.0.0.1' ) );
}

/**
 * Borra todo lo que dejó esta corrida: cuentas sec-* y sus pedidos.
 */
function limpiar(): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/user.php';

	// Filtro por login, no por correo: los correos de prueba se guardan
	// saneados y a veces el dominio queda raro ("example.comscriptalert1script"
	// en el caso de HTML pegado al correo), así que exigir @example.com dejaba
	// residuos. Todos los de esta suite empiezan con sec y su correo contiene
	// "example".
	foreach ( get_users( array( 'number' => 300 ) ) as $u ) {
		if ( ! preg_match( '/^sec/', (string) $u->user_login ) ) {
			continue;
		}

		if ( false === strpos( (string) $u->user_email, 'example' ) ) {
			continue;
		}

		wp_delete_user( $u->ID );
	}

	$tabla = esc_sql( $wpdb->prefix . 'sf_orders' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$tabla} WHERE email LIKE %s", 'sec-%@example.com' ) );

	delete_transient( 'sf_rate_limit_' . md5( 'account_login|127.0.0.1' ) );
	delete_transient( 'sf_rate_limit_' . md5( 'account_register|127.0.0.1' ) );
	delete_transient( 'sf_rate_limit_' . md5( 'save_step1|127.0.0.1' ) );
}

printf( "Sitio: %s (Host: %s)\n", $SF_BASE, $SF_HOST );
printf( "Límite de peticiones reiniciado entre grupos; el grupo de fuerza bruta lo mide.\n" );

$sf_jar_a    = $SF_TMP . '/a.txt';
$sf_jar_b    = $SF_TMP . '/b.txt';
$sf_jar_anon = $SF_TMP . '/anon.txt';
$sf_jar_xss  = $SF_TMP . '/xss.txt';

$sf_rand  = (string) wp_rand( 100000, 999999 );
$SF_A_MAIL = 'sec-a-' . $sf_rand . '@example.com';
$SF_B_MAIL = 'sec-b-' . $sf_rand . '@example.com';
$SF_XSS_MAIL = 'sec-xss-' . $sf_rand . '@example.com';
$SF_A_PASS = wp_generate_password( 24, false, false );
$SF_B_PASS = wp_generate_password( 24, false, false );

$SF_A_NAME  = 'Usuario A';
$SF_B_NAME  = 'Usuario B';
$SF_A_PHONE = '911111111';
$SF_B_PHONE = '922222222';

try {
	// ------------------------------------------------------------- preparación

	grupo( 'Preparación: dos cuentas reales' );

	reset_limite( 'account_register' );
	reset_limite( 'account_login' );

	$r = register( $sf_jar_a, $SF_A_NAME, $SF_A_MAIL, $SF_A_PHONE, $SF_A_PASS );
	check( 'cuenta A creada (302 a /perfil)', $r['status'] . ' ' . wp_parse_url( $r['location'], PHP_URL_PATH ), '302 /perfil/' );

	$r = register( $sf_jar_b, $SF_B_NAME, $SF_B_MAIL, $SF_B_PHONE, $SF_B_PASS );
	check( 'cuenta B creada (302 a /perfil)', $r['status'] . ' ' . wp_parse_url( $r['location'], PHP_URL_PATH ), '302 /perfil/' );

	$sf_a = get_user_by( 'email', $SF_A_MAIL );
	$sf_b = get_user_by( 'email', $SF_B_MAIL );

	check( 'A existe en WordPress', $sf_a instanceof WP_User, true );
	check( 'B existe en WordPress', $sf_b instanceof WP_User, true );

	if ( ! $sf_a || ! $sf_b ) {
		throw new RuntimeException( 'sin las dos cuentas no se puede seguir' );
	}

	$SF_A_ID = (int) $sf_a->ID;
	$SF_B_ID = (int) $sf_b->ID;

	// ------------------------------------------------------- 48.1 autenticación

	grupo( '48.1 Autenticación' );

	reset_limite( 'account_login' );

	$anon = $sf_jar_anon;
	$r    = http( 'GET', '/perfil/', null, $anon );
	check( '/perfil sin sesión redirige a /iniciar-sesion', $r['status'] . ' ' . wp_parse_url( $r['location'], PHP_URL_PATH ), '302 /iniciar-sesion/' );
	check( 'y no filtra datos de la cuenta', false === strpos( $r['body'], $SF_A_MAIL ), true );

	$jar = $SF_TMP . '/t1.txt';
	$r   = login( $jar, $SF_A_MAIL, 'clave-equivocada-123' );
	check( 'login con contraseña incorrecta: sigue como invitado', $r['status'] . ' ' . alerta( $r['body'] ), '200 Correo o contraseña incorrectos.' );

	$r = login( $jar, 'sec-noexiste-' . $sf_rand . '@example.com', $SF_A_PASS );
	check( 'login con correo inexistente: mismo mensaje', alerta( $r['body'] ), 'Correo o contraseña incorrectos.' );

	$r = login( $jar, '', '' );
	check( 'login con campos vacíos: rechazado', alerta( $r['body'] ), 'Correo o contraseña incorrectos.' );

	$r = login( $jar, 'esto-no-es-un-correo', $SF_A_PASS );
	check( 'login con correo malformado: rechazado', alerta( $r['body'] ), 'Correo o contraseña incorrectos.' );

	// wp_authenticate() hace trim() de la contraseña y sanitize_user() del
	// login: los espacios alrededor no cambian quién eres, pero tampoco dejan
	// entrar sin la contraseña correcta.
	$r = login( $jar, '  ' . $SF_A_MAIL . '  ', ' clave-mala-con-espacios ' );
	check( 'espacios alrededor con contraseña incorrecta: sigue rechazado', $r['status'], 200 );

	$r = login( $jar, '  ' . $SF_A_MAIL . '  ', ' ' . $SF_A_PASS . ' ' );
	check( 'espacios alrededor de la contraseña correcta: WordPress los recorta (comportamiento del core)', $r['status'], 302 );

	$r = login( $jar, $SF_A_MAIL, $SF_A_PASS );
	check( 'login válido tras el intento con espacios', $r['status'], 302 );

	$r = login( $jar, $SF_A_MAIL, $SF_A_PASS );
	check( 'login válido: 302 a /perfil', $r['status'] . ' ' . wp_parse_url( $r['location'], PHP_URL_PATH ), '302 /perfil/' );

	$r = http( 'GET', '/perfil/', null, $jar );
	check( 'con sesión, /perfil responde 200', $r['status'], 200 );
	check( 'y muestra los datos de A', false !== strpos( $r['body'], $SF_A_MAIL ), true );

	// Logout real (nonce de WordPress) y acceso posterior.
	$salir = '';
	if ( preg_match( '/href="([^"]*action=logout[^"]*)"/', $r['body'], $m ) ) {
		$salir = html_entity_decode( $m[1], ENT_QUOTES );
	}
	check( 'el perfil ofrece un enlace de salida', '' !== $salir, true );

	$antes = (string) @file_get_contents( $jar );

	$r = http( 'GET', (string) wp_parse_url( $salir, PHP_URL_PATH ) . '?' . (string) wp_parse_url( $salir, PHP_URL_QUERY ), null, $jar );
	check( 'logout: 302 a la portada', $r['status'] . ' ' . wp_parse_url( $r['location'], PHP_URL_PATH ), '302 /' );

	$r = http( 'GET', '/perfil/', null, $jar );
	check( 'después del logout /perfil vuelve a mandar al login', $r['status'] . ' ' . wp_parse_url( $r['location'], PHP_URL_PATH ), '302 /iniciar-sesion/' );

	// Volver a usar la cookie anterior (el "atrás" del navegador, o una copia).
	$jar_viejo = $SF_TMP . '/viejo.txt';
	file_put_contents( $jar_viejo, $antes );
	$r = http( 'GET', '/perfil/', null, $jar_viejo );
	check( 'la cookie previa al logout ya no sirve', $r['status'], 302 );

	// --------------------------------------------------- 48.2 enumeración

	grupo( '48.2 Enumeración de cuentas' );

	reset_limite( 'account_login' );

	$r_existente = login( $SF_TMP . '/t2.txt', $SF_A_MAIL, 'clave-equivocada-456' );
	$r_inexiste  = login( $SF_TMP . '/t3.txt', 'sec-nadie-' . $sf_rand . '@example.com', 'clave-equivocada-456' );

	check( 'correo existente y clave mala: mismo mensaje que correo inexistente', alerta( $r_existente['body'] ), alerta( $r_inexiste['body'] ) );
	check( 'mismo código HTTP en los dos casos', $r_existente['status'], $r_inexiste['status'] );
	// Comparar el cuerpo tal cual no sirve: el sitio mete identificadores
	// aleatorios por petición (Forminator) y repite en el value el correo que
	// escribió el atacante. Se normalizan las dos cosas antes de comparar.
	$normal = function ( string $html, string $correo ): string {
		$html = str_replace( $correo, '[correo]', $html );
		$html = (string) preg_replace( '/[0-9a-f]{10,}/i', '[hex]', $html );

		return $html;
	};

	check(
		'el cuerpo es idéntico (no delata si la cuenta existe)',
		$normal( $r_existente['body'], $SF_A_MAIL ),
		$normal( $r_inexiste['body'], 'sec-nadie-' . $sf_rand . '@example.com' )
	);

	reset_limite( 'account_register' );
	$dup = register( $SF_TMP . '/t4.txt', 'Otro', $SF_A_MAIL, '933333333', $SF_A_PASS );
	check(
		'el registro SÍ revela que el correo ya existe (decisión del sitio)',
		alerta( $dup['body'] ),
		'Ya existe una cuenta con este correo. Inicia sesión.'
	);

	// ------------------------------------------------- 48.3 / 48.4 user_id e IDOR

	grupo( '48.3 / 48.4 Manipulación de user_id e IDOR' );

	reset_limite( 'account_login' );
	$r = login( $sf_jar_a, $SF_A_MAIL, $SF_A_PASS );
	check( 'A vuelve a entrar', $r['status'], 302 );

	$variantes = array(
		'/perfil/?user_id=' . $SF_B_ID,
		'/perfil/?user_id=' . $SF_B_ID . '&email=' . rawurlencode( $SF_B_MAIL ),
		'/perfil/?user=' . $SF_B_ID,
		'/?user_id=' . $SF_B_ID,
	);

	foreach ( $variantes as $ruta ) {
		$r = http( 'GET', $ruta, null, $sf_jar_a );
		$filtra = false !== strpos( $r['body'], $SF_B_MAIL ) || false !== strpos( $r['body'], $SF_B_NAME ) || false !== strpos( $r['body'], $SF_B_PHONE );
		check( "GET {$ruta} no filtra datos de B", $filtra, false );
	}

	// El perfil sigue siendo el de A.
	$r = http( 'GET', '/perfil/?user_id=' . $SF_B_ID, null, $sf_jar_a );
	check( 'con ?user_id=B el perfil sigue siendo el de A', false !== strpos( $r['body'], $SF_A_MAIL ), true );

	// Rutas por id y por correo. En la variante con el correo, WordPress
	// devuelve 404 y repite en el HTML la ruta pedida: eso es lo que escribió
	// el atacante. Lo que se exige es que no haya perfil ni datos de B.
	foreach ( array( '/perfil/' . $SF_B_ID . '/', '/profile/' . $SF_B_ID . '/' ) as $ruta ) {
		$r = http( 'GET', $ruta, null, $sf_jar_a );
		$filtra = false !== strpos( $r['body'], $SF_B_MAIL );
		check( "GET {$ruta} no entrega datos de B (HTTP {$r['status']})", $filtra, false );
	}

	$r = http( 'GET', '/perfil/' . $SF_B_MAIL . '/', null, $sf_jar_a );
	check( 'GET /perfil/<correo de B>/ no pinta un perfil', false !== strpos( $r['body'], 'sf-profile' ), false );
	check( 'ni el teléfono ni el nombre de B', false !== strpos( $r['body'], $SF_B_PHONE ) || false !== strpos( $r['body'], $SF_B_NAME ), false );

	// POST con identidad ajena.
	$r = http(
		'POST',
		'/perfil/',
		array(
			'user_id' => $SF_B_ID,
			'sf_email' => 'sec-cambiada@example.com',
			'sf_phone' => '999999999',
		),
		$sf_jar_a
	);
	check( 'POST a /perfil con user_id ajeno no cambia nada', Customer::phone( $SF_B_ID ), $SF_B_PHONE );
	check( 'ni el correo de B', Customer::email( $SF_B_ID ), $SF_B_MAIL );

	// --------------------------------------------------- 48.5 edición inexistente

	grupo( '48.5 Edición del perfil' );

	check( 'la cuenta no cambió por los POST anteriores (teléfono de A)', Customer::phone( $SF_A_ID ), $SF_A_PHONE );
	check( 'el correo de A sigue igual', Customer::email( $SF_A_ID ), $SF_A_MAIL );

	// --------------------------------------------------------------- 48.6 nonce

	grupo( '48.6 Nonce' );

	reset_limite( 'account_register' );
	$jar = $SF_TMP . '/t5.txt';

	$r = http(
		'POST',
		'/iniciar-sesion/',
		array(
			'sf_account_action' => 'register',
			'sf_name'           => 'Sin Nonce',
			'sf_email'          => 'sec-sinnonce-' . $sf_rand . '@example.com',
			'sf_phone'          => '944444444',
			'sf_password'       => $SF_A_PASS,
		),
		$jar
	);
	check( 'sin nonce: rechazado', alerta( $r['body'] ), 'La página expiró. Recárgala y vuelve a intentarlo.' );

	$r = http(
		'POST',
		'/iniciar-sesion/',
		array(
			'_sf_nonce'         => '',
			'sf_account_action' => 'register',
			'sf_name'           => 'Nonce Vacío',
			'sf_email'          => 'sec-noncevacio-' . $sf_rand . '@example.com',
			'sf_phone'          => '944444444',
			'sf_password'       => $SF_A_PASS,
		),
		$jar
	);
	check( 'nonce vacío: rechazado', alerta( $r['body'] ), 'La página expiró. Recárgala y vuelve a intentarlo.' );

	$r = http(
		'POST',
		'/iniciar-sesion/',
		array(
			'_sf_nonce'         => 'deadbeef00',
			'sf_account_action' => 'register',
			'sf_name'           => 'Nonce Malo',
			'sf_email'          => 'sec-noncemalo-' . $sf_rand . '@example.com',
			'sf_phone'          => '944444444',
			'sf_password'       => $SF_A_PASS,
		),
		$jar
	);
	check( 'nonce inválido: rechazado', alerta( $r['body'] ), 'La página expiró. Recárgala y vuelve a intentarlo.' );

	$otro_nonce = wp_create_nonce( 'simple_form_nonce' );
	$r          = http(
		'POST',
		'/iniciar-sesion/',
		array(
			'_sf_nonce'         => $otro_nonce,
			'sf_account_action' => 'register',
			'sf_name'           => 'Nonce Ajeno',
			'sf_email'          => 'sec-nonceajeno-' . $sf_rand . '@example.com',
			'sf_phone'          => '944444444',
			'sf_password'       => $SF_A_PASS,
		),
		$jar
	);
	check( 'nonce de otra acción (formulario de pedido): rechazado', alerta( $r['body'] ), 'La página expiró. Recárgala y vuelve a intentarlo.' );

	$creados = array_merge(
		get_users( array( 'search' => 'sec-sinnonce*', 'search_columns' => array( 'user_email' ) ) ),
		get_users( array( 'search' => 'sec-noncevacio*', 'search_columns' => array( 'user_email' ) ) ),
		get_users( array( 'search' => 'sec-noncemalo*', 'search_columns' => array( 'user_email' ) ) ),
		get_users( array( 'search' => 'sec-nonceajeno*', 'search_columns' => array( 'user_email' ) ) )
	);
	check( 'ningún registro sin nonce válido creó cuenta', count( $creados ), 0 );

	// ---------------------------------------------------------------- 48.7 CSRF

	grupo( '48.7 CSRF' );

	reset_limite( 'account_login' );
	$jar = $SF_TMP . '/t6.txt';
	$r   = http( 'GET', '/iniciar-sesion/', null, $jar );
	$n   = nonce_from( $r['body'] );

	// Origen externo con el nonce y la cookie de la víctima (el ataque realista
	// no puede leer el nonce: la cookie SameSite=Lax del login lo frena).
	$r = http(
		'POST',
		'/iniciar-sesion/',
		array(
			'_sf_nonce'         => $n,
			'sf_account_action' => 'login',
			'sf_email'          => $SF_A_MAIL,
			'sf_password'       => $SF_A_PASS,
		),
		$jar,
		array( 'Origin: https://sitio-malicioso.example', 'Referer: https://sitio-malicioso.example/x.html' )
	);
	check( 'POST con Origin externo y nonce válido: el nonce es la barrera (entra)', $r['status'], 302 );

	// Sin nonce desde otro origen: rechazado. Va con un navegador limpio porque
	// con la sesión abierta la página de login redirige y no hay formulario.
	$r = http(
		'POST',
		'/iniciar-sesion/',
		array(
			'sf_account_action' => 'login',
			'sf_email'          => $SF_A_MAIL,
			'sf_password'       => $SF_A_PASS,
		),
		$SF_TMP . '/csrf-limpio.txt',
		array( 'Origin: https://sitio-malicioso.example' )
	);
	check( 'POST cross-origin sin nonce: rechazado', alerta( $r['body'] ), 'La página expiró. Recárgala y vuelve a intentarlo.' );

	// ------------------------------------------------------------- 48.8 / 48.9 XSS

	grupo( '48.8 / 48.9 XSS en el registro' );

	reset_limite( 'account_register' );
	reset_limite( 'account_login' );

	$payloads = array(
		'<script>alert(1)</script>',
		'<img src=x onerror=alert(1)>',
		'"><script>alert(1)</script>',
		'Ana <script>alert(1)</script> Pérez',
		'<svg/onload=alert(1)>Nombre',
	);

	foreach ( $payloads as $i => $payload ) {
		reset_limite( 'account_register' );

		$jar    = $SF_TMP . '/xss' . $i . '.txt';
		$mail   = 'sec-xss' . $i . '-' . $sf_rand . '@example.com';
		$r      = register( $jar, $payload, $mail, '95555555' . $i, $SF_A_PASS );
		$creado = get_user_by( 'email', $mail );
		$limpio = sanitize_text_field( $payload );

		// sanitize_text_field descarta las etiquetas: si el payload era solo
		// una etiqueta, el nombre queda vacío y el registro se rechaza.
		if ( '' === $limpio ) {
			check( "payload {$i}: se rechaza (no queda nombre después de limpiar)", alerta( $r['body'] ), 'Escribe tu nombre.' );
			check( "payload {$i}: y no creó cuenta", $creado, false );
			continue;
		}

		check( "payload {$i}: la cuenta se creó", $creado instanceof WP_User, true );

		if ( ! $creado instanceof WP_User ) {
			printf( "      aviso del sitio: %s\n", alerta( $r['body'] ) );
			continue;
		}

		$guardado = Customer::name( (int) $creado->ID );
		check( "payload {$i}: se guarda sin etiquetas", false === strpos( $guardado, '<' ) && false === strpos( $guardado, '>' ), true );

		$r = http( 'GET', '/perfil/', null, $jar );
		$ejecutable = array( '<script>alert', 'onerror=alert', 'onload=alert', '<img src=x' );
		$hay_ejecutable = false;
		foreach ( $ejecutable as $aguja ) {
			if ( false !== strpos( $r['body'], $aguja ) ) {
				$hay_ejecutable = true;
			}
		}
		check( "payload {$i}: el perfil no ejecuta HTML", $hay_ejecutable, false );
		check( "payload {$i}: el payload no aparece crudo en el HTML", false !== strpos( $r['body'], $payload ), false );

		// El formulario de pedido también pinta ese nombre en un atributo.
		$r = http( 'GET', '/mapa/', null, $jar );
		$hay_ejecutable = false;
		foreach ( $ejecutable as $aguja ) {
			if ( false !== strpos( $r['body'], $aguja ) ) {
				$hay_ejecutable = true;
			}
		}
		check( "payload {$i}: el prellenado de simple-form no ejecuta HTML", $hay_ejecutable, false );
		check( "payload {$i}: ni aparece crudo en el atributo value", false !== strpos( $r['body'], $payload ), false );

		// Cerrado y vuelto a abrir: lo persistido sigue neutralizado.
		$salida = '';
		if ( preg_match( '/href="([^"]*action=logout[^"]*)"/', $r['body'], $m ) ) {
			$salida = html_entity_decode( $m[1], ENT_QUOTES );
		}
		if ( '' !== $salida ) {
			http( 'GET', (string) wp_parse_url( $salida, PHP_URL_PATH ) . '?' . (string) wp_parse_url( $salida, PHP_URL_QUERY ), null, $jar );
		}

		reset_limite( 'account_login' );
		login( $jar, $mail, $SF_A_PASS );
		$r = http( 'GET', '/perfil/', null, $jar );
		check( "payload {$i}: después de volver a entrar sigue neutralizado", false !== strpos( $r['body'], $payload ), false );
	}

	// ---------------------------------------------------------------- 48.10 SQL

	grupo( '48.10 Inyección SQL' );

	reset_limite( 'account_register' );
	reset_limite( 'account_login' );

	$jar   = $SF_TMP . '/sql.txt';
	$antes = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->users}" );

	$r = register( $jar, "' OR 1=1 --", "sec-sqli-' OR '1'='1@example.com", "' OR 1=1 --", $SF_A_PASS );
	check( 'registro con payload SQL: no crea cuenta', (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->users}" ), $antes );
	check( 'y no muestra error de SQL', false === stripos( $r['body'], 'SQL syntax' ) && false === stripos( $r['body'], 'wp_users' ), true );
	check( 'y responde con un aviso de validación', '' !== alerta( $r['body'] ), true );

	$r = login( $SF_TMP . '/sql2.txt', $SF_A_MAIL . "' OR '1'='1", 'x' );
	check( 'login con payload SQL: mensaje genérico', alerta( $r['body'] ), 'Correo o contraseña incorrectos.' );
	check( 'sin error de SQL en la respuesta', false === stripos( $r['body'], 'You have an error in your SQL' ), true );

	// ------------------------------------------------------ 48.11 teléfono

	grupo( '48.11 Teléfono' );

	// Cada caso: entrada, etiqueta y los nueve dígitos que deben quedar (o ''
	// si el servidor tiene que rechazarlo).
	$telefonos = array(
		array( '', 'vacío', '' ),
		array( 'texto', 'texto', '' ),
		array( "' OR 1=1 --", 'SQL', '' ),
		array( str_repeat( '9', 30 ), '30 dígitos', '' ),
		array( '12345678', '8 dígitos', '' ),
		array( '1234567890', '10 dígitos', '' ),
		array( '888888888', 'no empieza con 9', '' ),
		array( '56987654321x', 'basura tras el número (el número vale)', '987654321' ),
		array( '<b>987654321</b>', 'HTML (las etiquetas se descartan, el número vale)', '987654321' ),
		array( '+56 9 8765 4321', 'con +56', '987654321' ),
	);

	$n = 0;
	foreach ( $telefonos as $caso ) {
		list( $telefono, $etiqueta, $esperado ) = $caso;

		reset_limite( 'account_register' );
		$mail = 'sec-tel' . $n . '-' . $sf_rand . '@example.com';
		++$n;
		register( $SF_TMP . '/tel' . $n . '.txt', 'Teléfono Prueba', $mail, (string) $telefono, $SF_A_PASS );

		$u = get_user_by( 'email', $mail );

		if ( '' === $esperado ) {
			check( "teléfono {$etiqueta}: rechazado", $u, false );
			continue;
		}

		check( "teléfono {$etiqueta}: aceptado", $u instanceof WP_User, true );
		check( "teléfono {$etiqueta}: guardado como {$esperado}", $u instanceof WP_User ? Customer::phone( (int) $u->ID ) : '', $esperado );
	}

	// -------------------------------------------------------- 48.12 correo

	grupo( '48.12 Correo' );

	reset_limite( 'account_register' );
	// El navegador no es la validación: lo que se exige es que el servidor
	// valide, y que lo que guarde sea un correo limpio (sin etiquetas, sin
	// espacios) o nada.
	$correos = array(
		array( 'no-es-correo', 'sin arroba' ),
		array( 'sec-correo-HTML-' . $sf_rand . '@example.com<script>alert(1)</script>', 'HTML pegado al dominio' ),
		array( 'sec con espacios ' . $sf_rand . '@example.com', 'con espacios' ),
		array( str_repeat( 'a', 250 ) . '@example.com', 'muy largo' ),
		array( 'sec-punto..doble-' . $sf_rand . '@example.com', 'puntos seguidos' ),
	);

	$n = 0;
	foreach ( $correos as $caso ) {
		list( $correo, $etiqueta ) = $caso;
		++$n;

		reset_limite( 'account_register' );
		$r = register( $SF_TMP . '/mail' . $n . '.txt', 'Correo Prueba', (string) $correo, '966666666', $SF_A_PASS );

		check( "correo {$etiqueta}: sin error de servidor", $r['status'] < 500, true );

		$aviso = alerta( $r['body'] );
		$u     = $aviso ? false : get_user_by( 'email', sanitize_email( (string) $correo ) );

		if ( false === $u ) {
			check( "correo {$etiqueta}: rechazado", '' !== $aviso, true );
			continue;
		}

		$guardado = (string) $u->user_email;
		check( "correo {$etiqueta}: si se guarda, queda limpio", false === strpos( $guardado, '<' ) && false === strpos( $guardado, '>' ) && false === strpos( $guardado, ' ' ), true );
	}

	// -------------------------------------------------------- 48.13 nombre

	grupo( '48.13 Nombre' );

	reset_limite( 'account_register' );
	$r = register( $SF_TMP . '/nom1.txt', '', 'sec-nom1-' . $sf_rand . '@example.com', '977777777', $SF_A_PASS );
	check( 'nombre vacío: rechazado', alerta( $r['body'] ), 'Escribe tu nombre.' );

	reset_limite( 'account_register' );
	$largo = str_repeat( 'A', 5000 );
	$r     = register( $SF_TMP . '/nom2.txt', $largo, 'sec-nom2-' . $sf_rand . '@example.com', '977777777', $SF_A_PASS );
	$u     = get_user_by( 'email', 'sec-nom2-' . $sf_rand . '@example.com' );
	check( 'nombre de 5000 caracteres: no rompe la petición', $r['status'] <= 302, true );

	if ( $u instanceof WP_User ) {
		check( 'y la cuenta se creó sin romper el perfil', Customer::name( (int) $u->ID ) !== '', true );
		$perfil = http( 'GET', '/perfil/', null, $SF_TMP . '/nom2.txt' );
		check( 'el perfil con un nombre larguísimo responde 200', $perfil['status'], 200 );
	}

	// ------------------------------------------------------ 48.14 contraseña

	grupo( '48.14 Contraseña' );

	reset_limite( 'account_register' );
	$r = register( $SF_TMP . '/pw1.txt', 'Clave Vacía', 'sec-pw1-' . $sf_rand . '@example.com', '988888888', '' );
	check( 'contraseña vacía: rechazada', alerta( $r['body'] ), 'La contraseña debe tener al menos 8 caracteres.' );

	reset_limite( 'account_register' );
	$r = register( $SF_TMP . '/pw2.txt', 'Clave Corta', 'sec-pw2-' . $sf_rand . '@example.com', '988888888', 'corta' );
	check( 'contraseña corta: rechazada', alerta( $r['body'] ), 'La contraseña debe tener al menos 8 caracteres.' );

	reset_limite( 'account_register' );
	$larga = str_repeat( 'Zz9!', 1000 );
	$r     = register( $SF_TMP . '/pw3.txt', 'Clave Larga', 'sec-pw3-' . $sf_rand . '@example.com', '988888888', $larga );
	$pw3   = get_user_by( 'email', 'sec-pw3-' . $sf_rand . '@example.com' );
	check( 'contraseña de 4000 caracteres: la cuenta se crea', $pw3 instanceof WP_User, true );
	check( 'y ninguna respuesta devuelve la contraseña', false === strpos( $r['body'], substr( $larga, 0, 12 ) ), true );

	// ---------------------------------------------------------- 48.15 fuerza bruta

	grupo( '48.15 Fuerza bruta' );

	reset_limite( 'account_login' );
	$jar      = $SF_TMP . '/bf.txt';
	$bloqueos = 0;

	for ( $i = 1; $i <= 15; $i++ ) {
		$r = login( $jar, $SF_A_MAIL, 'clave-mala-' . $i, array( 'user_agent_extra' => 'atacante-' . $i ) );
		if ( false !== strpos( $r['body'], 'Demasiados intentos' ) ) {
			++$bloqueos;
		}
	}

	check( 'el límite corta los intentos repetidos (mensaje de espera)', $bloqueos > 0, true );
	printf( "      intentos bloqueados: %d de 15\n", $bloqueos );

	$r = login( $jar, $SF_A_MAIL, $SF_A_PASS );
	check( 'y también frena la contraseña correcta mientras dura la ventana', false !== strpos( $r['body'], 'Demasiados intentos' ), true );

	reset_limite( 'account_login' );

	// ------------------------------------------------------ 48.16 sesión

	grupo( '48.16 Manipulación de sesión' );

	$jar = $SF_TMP . '/s1.txt';
	$r   = login( $jar, $SF_A_MAIL, $SF_A_PASS );
	check( 'A entra', $r['status'], 302 );

	$cookies = array();
	foreach ( explode( "\n", (string) @file_get_contents( $jar ) ) as $linea ) {
		if ( '' === trim( $linea ) ) {
			continue;
		}

		// curl escribe las HttpOnly con el prefijo #HttpOnly_ y no son comentario.
		if ( 0 === strpos( $linea, '#HttpOnly_' ) ) {
			$linea = substr( $linea, strlen( '#HttpOnly_' ) );
		} elseif ( 0 === strpos( $linea, '#' ) ) {
			continue;
		}
		$partes = explode( "\t", $linea );
		if ( count( $partes ) >= 7 ) {
			$cookies[ $partes[5] ] = $partes[6];
		}
	}

	check( 'la sesión va en cookies de WordPress', isset( $cookies[ 'wordpress_logged_in_' . COOKIEHASH ] ), true );

	$falsificada = $SF_TMP . '/falsa.txt';
	file_put_contents(
		$falsificada,
		"# Netscape HTTP Cookie File\n" . $SF_HOST . "\tFALSE\t/\tFALSE\t0\twordpress_logged_in_" . COOKIEHASH . "\t" . $SF_A_MAIL . '%7C0%7Cfalso' . "\n"
	);
	$r = http( 'GET', '/perfil/', null, $falsificada );
	check( 'cookie de sesión inventada: no autentica', $r['status'], 302 );

	// A → B en el mismo navegador.
	$r = http( 'GET', '/perfil/', null, $jar );
	$salida = '';
	if ( preg_match( '/href="([^"]*action=logout[^"]*)"/', $r['body'], $m ) ) {
		$salida = html_entity_decode( $m[1], ENT_QUOTES );
	}
	http( 'GET', (string) wp_parse_url( $salida, PHP_URL_PATH ) . '?' . (string) wp_parse_url( $salida, PHP_URL_QUERY ), null, $jar );

	reset_limite( 'account_login' );
	$r = login( $jar, $SF_B_MAIL, $SF_B_PASS );
	check( 'B entra en el mismo navegador', $r['status'], 302 );

	$r = http( 'GET', '/perfil/', null, $jar );
	check( 'el perfil es el de B', false !== strpos( $r['body'], $SF_B_MAIL ), true );
	check( 'y no queda nada de A', false === strpos( $r['body'], $SF_A_MAIL ) && false === strpos( $r['body'], $SF_A_PHONE ), true );

	// ------------------------------------------- 48.17 localStorage / sessionStorage

	grupo( '48.17 localStorage / sessionStorage' );

	// Regla de souls.md §17 y §26: el navegador no guarda ni decide la
	// identidad. El formulario sí usa localStorage, pero solo para el puntero
	// de sesión del pedido (sf_session_id), que el servidor valida.
	$needles_auth = array( 'is_logged_in', 'isLoggedIn', 'isAuthenticated', 'auth_token', 'authToken', 'user_id', 'user_email', 'wp_user' );

	foreach ( array( '/iniciar-sesion/', '/perfil/' ) as $ruta ) {
		$r = http( 'GET', $ruta, null, $jar );

		// WordPress mete un script propio (detección de emoji) que usa
		// sessionStorage para cachear su prueba: no es del sitio ni guarda
		// identidad. Se descarta antes de mirar.
		$limpio = (string) preg_replace(
			'#<script\b[^>]*>(?:(?!</script>).)*wpEmojiSettingsSupports(?:(?!</script>).)*</script>#s',
			'',
			$r['body']
		);

		$culpables = array();

		if ( false !== strpos( $limpio, 'localStorage' ) || false !== strpos( $limpio, 'sessionStorage' ) ) {
			$culpables[] = 'inline';
		}

		// Y los scripts que sí son del sitio: plugin y tema.
		if ( preg_match_all( '/<script[^>]+src="([^"]+)"/', $limpio, $mm ) ) {
			foreach ( $mm[1] as $src_url ) {
				$partes = wp_parse_url( html_entity_decode( $src_url, ENT_QUOTES ) );
				$ruta_js = isset( $partes['path'] ) ? $partes['path'] : '';

				if ( ! preg_match( '#/wp-content/(plugins/simple-form|themes/telobuscamos)/#', $ruta_js ) ) {
					continue;
				}

				// El puntero del pedido vive acá y está permitido (se valida en
				// el servidor); se comprueba aparte, más abajo.
				if ( false !== strpos( $ruta_js, '/assets/js/config.js' ) ) {
					continue;
				}

				$js = http( 'GET', $ruta_js . ( isset( $partes['query'] ) ? '?' . $partes['query'] : '' ), null, $jar );

				if ( false !== strpos( $js['body'], 'localStorage' ) || false !== strpos( $js['body'], 'sessionStorage' ) ) {
					$culpables[] = $ruta_js;
				}
			}
		}

		check( "{$ruta}: no guarda nada en el navegador", array_values( array_unique( $culpables ) ), array() );
	}

	$r = http( 'GET', '/mapa/', null, $jar );

	$js_formulario = '';
	if ( preg_match_all( '/<script[^>]+src="([^"]+)"/', $r['body'], $mm ) ) {
		foreach ( $mm[1] as $src_url ) {
			$partes = wp_parse_url( html_entity_decode( $src_url, ENT_QUOTES ) );
			if ( ! isset( $partes['path'] ) || ! preg_match( '/simple-form\/assets\/js/', $partes['path'] ) ) {
				continue;
			}
			$js_formulario .= http( 'GET', $partes['path'], null, $jar )['body'];
		}
	}

	check( '/mapa/ guarda solo el puntero del pedido (sf_session_id)', false !== strpos( $js_formulario, 'sf_session_id' ), true );

	$auth_en_js = array();
	foreach ( $needles_auth as $aguja ) {
		if ( '' !== $js_formulario && false !== strpos( $js_formulario, $aguja ) ) {
			$auth_en_js[] = $aguja;
		}
	}
	check( 'el JavaScript del formulario no guarda identidad', $auth_en_js, array() );

	// -------------------------------------------------- 48.18 simple-form

	grupo( '48.18 Manipulación de simple-form' );

	reset_limite( 'save_step1' );

	// La sesión de este grupo tiene que ser la de A: así el user_id que se cuela
	// en el POST (el de B) difiere de quien está autenticado y la prueba dice
	// algo. Con la sesión de B, el servidor guardaría B por las dos vías.
	$jar_a_form = $SF_TMP . '/sf' . $sf_rand . '-a-form.txt';
	reset_limite( 'account_login' );
	login( $jar_a_form, $SF_A_MAIL, $SF_A_PASS );
	$jar = $jar_a_form;

	$r = http( 'GET', '/mapa/', null, $jar );
	$n = preg_match( '/"nonce":"([^"]+)"/', $r['body'], $m ) ? $m[1] : '';
	check( 'el formulario publica su nonce', '' !== $n, true );

	$r = http(
		'POST',
		'/wp-admin/admin-ajax.php',
		array(
			'action'   => 'simple_form_save_step1',
			'nonce'    => $n,
			'session_id' => '',
			'name'     => 'Nombre Inyectado',
			'email'    => 'sec-inyectado@example.com',
			'phone'    => '900000001',
			// El ataque: el cliente dice ser otra cuenta.
			'user_id'  => (string) $SF_B_ID,
			'description' => 'prueba',
		),
		$jar
	);
	$json = json_decode( $r['body'], true );
	check( 'el pedido con datos cambiados se acepta (es un snapshot del pedido)', isset( $json['success'] ) && true === $json['success'], true );

	// El vínculo con la cuenta lo pone el servidor: el user_id del POST se ignora.
	$fila = $GLOBALS['wpdb']->get_row(
		$GLOBALS['wpdb']->prepare(
			'SELECT id, user_id FROM ' . $GLOBALS['wpdb']->prefix . 'sf_orders WHERE email = %s ORDER BY id DESC LIMIT 1',
			'sec-inyectado@example.com'
		),
		ARRAY_A
	);
	check( 'el pedido quedó ligado a la cuenta autenticada, no a la enviada', null !== $fila ? (int) $fila['user_id'] : -1, $SF_A_ID );

	check( 'pero la cuenta de A no cambió de nombre', Customer::name( $SF_A_ID ), $SF_A_NAME );
	check( 'ni de correo', Customer::email( $SF_A_ID ), $SF_A_MAIL );
	check( 'ni de teléfono', Customer::phone( $SF_A_ID ), $SF_A_PHONE );

	check( 'la respuesta AJAX no trae la contraseña', false === stripos( $r['body'], 'user_pass' ) && false === stripos( $r['body'], 'password' ), true );

	// ------------------------------------------- 48.19 / 48.20 aislamiento A/B

	grupo( '48.19 / 48.20 Aislamiento A → B y B → A' );

	reset_limite( 'account_login' );
	$jar_a = $SF_TMP . '/iso-a.txt';
	$jar_b = $SF_TMP . '/iso-b.txt';

	login( $jar_a, $SF_A_MAIL, $SF_A_PASS );
	login( $jar_b, $SF_B_MAIL, $SF_B_PASS );

	$rutas = array( '/perfil/', '/perfil/?user_id=' . $SF_B_ID, '/mapa/' );

	foreach ( $rutas as $ruta ) {
		$r = http( 'GET', $ruta, null, $jar_a );
		check( "A en {$ruta}: sin datos de B", false !== strpos( $r['body'], $SF_B_MAIL ) || false !== strpos( $r['body'], $SF_B_PHONE ), false );
	}

	foreach ( $rutas as $ruta ) {
		$r = http( 'GET', $ruta, null, $jar_b );
		check( "B en {$ruta}: sin datos de A", false !== strpos( $r['body'], $SF_A_MAIL ) || false !== strpos( $r['body'], $SF_A_PHONE ), false );
	}

	// ------------------------------------------------------------- 48.21 caché

	grupo( '48.21 Caché de la página privada' );

	$r = http( 'GET', '/perfil/', null, $jar_a );
	$cc = strtolower( (string) ( $r['headers']['cache-control'] ?? '' ) );
	check( '/perfil no es cacheable por un proxy', false !== strpos( $cc, 'no-store' ) || false !== strpos( $cc, 'private' ), true );
	check( 'y no trae cabecera de caché compartida', isset( $r['headers']['x-cache'] ), false );
	printf( "      Cache-Control: %s\n", '' !== $cc ? $cc : '(sin cabecera)' );

	// ---------------------------------------------- 48.22 / 48.23 simple-form

	grupo( '48.22 / 48.23 simple-form con cuenta y con invitado' );

	$r = http( 'GET', '/mapa/', null, $jar_a );
	check( 'A ve sus datos en el formulario', false !== strpos( $r['body'], 'value="' . $SF_A_PHONE . '"' ) || false !== strpos( $r['body'], 'value="9 1111 1111"' ), true );

	$r = http( 'GET', '/mapa/', null, $jar_b );
	check( 'B ve los suyos', false !== strpos( $r['body'], 'value="' . $SF_B_PHONE . '"' ) || false !== strpos( $r['body'], 'value="9 2222 2222"' ), true );
	check( 'y no los de A', false === strpos( $r['body'], $SF_A_MAIL ), true );

	$r = http( 'GET', '/mapa/', null, $sf_jar_anon );
	check( 'el invitado los ve vacíos', false !== strpos( $r['body'], 'id="pn-email" name="email" class="pn-form__input" value=""' ), true );

	// ------------------------------------------------------- 48.25 open redirect

	grupo( '48.25 Open redirect' );

	reset_limite( 'account_login' );
	$jar = $SF_TMP . '/or.txt';
	foreach ( array( 'https://sitio-malicioso.example', '//sitio-malicioso.example' ) as $destino ) {
		$r = login( $jar, $SF_A_MAIL, $SF_A_PASS, array( 'redirect_to' => $destino, 'next' => $destino, 'return_url' => $destino ) );
		check( "login con redirect_to={$destino}: va a /perfil", wp_parse_url( $r['location'], PHP_URL_HOST ), $SF_HOST );
		check( 'y la ruta es la del perfil', wp_parse_url( $r['location'], PHP_URL_PATH ), '/perfil/' );

		$r = http( 'GET', '/iniciar-sesion/?redirect_to=' . rawurlencode( $destino ) . '&next=' . rawurlencode( $destino ), null, $SF_TMP . '/or2.txt' );

		// El cuerpo repite el query en el _wp_http_referer (ruta relativa), así
		// que lo que se mira es a dónde apuntan el formulario y los enlaces.
		$externos = array();
		if ( preg_match_all( '/(?:action|href)="([^"]+)"/', $r['body'], $mm ) ) {
			foreach ( $mm[1] as $url_html ) {
				$host_destino = wp_parse_url( html_entity_decode( $url_html, ENT_QUOTES ), PHP_URL_HOST );
				if ( null !== $host_destino && $SF_HOST !== $host_destino ) {
					$externos[] = $host_destino;
				}
			}
		}
		check( 'la página de login no apunta a ningún host externo', array_values( array_unique( $externos ) ), array() );

		// Volver a dejarlo invitado para la siguiente vuelta.
		$r = http( 'GET', '/perfil/', null, $jar );
		if ( preg_match( '/href="([^"]*action=logout[^"]*)"/', $r['body'], $m ) ) {
			$salida = html_entity_decode( $m[1], ENT_QUOTES );
			http( 'GET', (string) wp_parse_url( $salida, PHP_URL_PATH ) . '?' . (string) wp_parse_url( $salida, PHP_URL_QUERY ), null, $jar );
		}
		reset_limite( 'account_login' );
	}

	// ------------------------------------------------- 48.26 respuestas

	grupo( '48.26 Información sensible en respuestas' );

	$jar = $SF_TMP . '/sens.txt';
	login( $jar, $SF_A_MAIL, $SF_A_PASS );
	$r = http( 'GET', '/perfil/', null, $jar );

	foreach ( array( 'user_pass', 'wp_password', 'secret', $SF_A_PASS ) as $aguja ) {
		check( "el HTML del perfil no contiene «{$aguja}»", false !== strpos( $r['body'], $aguja ), false );
	}

	// --------------------------------------------------------- 48.28 / 48.29 roles

	grupo( '48.28 / 48.29 Permisos y roles' );

	reset_limite( 'account_register' );
	$mail = 'sec-rol-' . $sf_rand . '@example.com';
	$r    = register(
		$SF_TMP . '/rol.txt',
		'Quiero Ser Admin',
		$mail,
		'901234567',
		$SF_A_PASS,
		array(
			'role'                       => 'administrator',
			'capabilities[administrator]' => '1',
			'user_id'                    => (string) $SF_A_ID,
		)
	);
	$u = get_user_by( 'email', $mail );
	check( 'el registro ignora role/capabilities del navegador', $u instanceof WP_User ? $u->roles : array(), array( 'subscriber' ) );

	// ------------------------------------------------------------- 48.27 logs

	grupo( '48.27 Información sensible en logs' );

	if ( is_readable( $SF_LOG ) ) {
		$log   = (string) file_get_contents( $SF_LOG );
		$fugas = array();

		foreach ( array( $SF_A_PASS, $SF_B_PASS, 'sf_password' ) as $aguja ) {
			if ( '' !== $aguja && false !== strpos( $log, $aguja ) ) {
				$fugas[] = $aguja === 'sf_password' ? 'nombre de campo sf_password' : 'una contraseña de prueba';
			}
		}

		check( 'el log no contiene contraseñas', $fugas, array() );
	} else {
		skip( 'revisión del log de PHP', 'no encuentro ' . $SF_LOG );
	}

	// --------------------------------------------- 48.29 manipulación de rol en admin

	grupo( '48.28 Capacidades: cliente no entra al admin' );

	foreach ( array( '/wp-admin/users.php', '/wp-admin/options-general.php', '/wp-admin/plugins.php' ) as $ruta ) {
		$r = http( 'GET', $ruta, null, $jar );
		check( "un suscriptor no abre {$ruta}", in_array( $r['status'], array( 302, 403 ), true ), true );
	}

	$r = http( 'GET', '/wp-admin/', null, $jar );
	check( 'el cliente que entra a /wp-admin/ va a su perfil', $r['status'] . ' ' . wp_parse_url( $r['location'], PHP_URL_PATH ), '302 /perfil/' );
	check( 'y no ve el escritorio de WordPress', false !== strpos( $r['body'], 'options-general.php' ), false );
} catch ( Throwable $e ) {
	echo "\nERROR EN EL ARNÉS: " . $e->getMessage() . "\n";
	++$SF_FAILS;
} finally {
	grupo( 'Limpieza' );
	limpiar();
	$restantes = 0;
	foreach ( get_users( array( 'number' => 300 ) ) as $u ) {
		if ( preg_match( '/^sec/', (string) $u->user_login ) && false !== strpos( (string) $u->user_email, 'example' ) ) {
			++$restantes;
		}
	}
	check( 'quedan cuentas de prueba', $restantes, 0 );
	echo "      (las cuentas y pedidos de prueba se borraron)\n";
}

printf( "\nPruebas ejecutadas: %d — FALLAS: %d — sin ejecutar: %d\n", $SF_RUNS, $SF_FAILS, $SF_SKIPS );
exit( $SF_FAILS > 0 ? 1 : 0 );
