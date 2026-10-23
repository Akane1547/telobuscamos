<?php
/**
 * Plugin Name: Simple Form - Endurecimiento del sitio
 * Description: Parches de configuración para los hallazgos de la auditoría (SF-07 a SF-11 y SF-15). No toca el formulario ni el flujo de pago.
 * Version: 1.0.0
 * Author: Telobuscamos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SF-11: no revelar la versión de PHP ni el generador de WordPress.
 */
add_action(
	'init',
	function () {
		if ( function_exists( 'header_remove' ) ) {
			header_remove( 'X-Powered-By' );
		}
	}
);

remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );

/**
 * SF-09: cabeceras de seguridad. HSTS solo sobre HTTPS.
 */
add_action(
	'send_headers',
	function () {
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: geolocation=(self), camera=(), microphone=()' );

		if ( is_ssl() ) {
			header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
		}
	},
	20
);

/**
 * SF-08: XML-RPC fuera de servicio, sin multicall ni pingback.
 */
add_action(
	'init',
	function () {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			status_header( 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'XML-RPC deshabilitado.';
			exit;
		}
	},
	1
);

add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter(
	'xmlrpc_methods',
	function ( $methods ) {
		unset( $methods['system.multicall'], $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );

		return $methods;
	}
);

/**
 * SF-07: sin enumeración de usuarios para visitantes.
 */
add_filter(
	'rest_endpoints',
	function ( $endpoints ) {
		if ( is_user_logged_in() ) {
			return $endpoints;
		}

		unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );

		return $endpoints;
	}
);

add_filter(
	'wp_sitemaps_add_provider',
	function ( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	},
	10,
	2
);

add_action(
	'template_redirect',
	function () {
		if ( is_author() ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}
);

/**
 * SF-10: CORS solo para el propio origen. WordPress refleja cualquier Origin
 * en `rest_send_cors_headers`; se retira la cabecera cuando el origen no es el
 * del sitio (este filtro corre después del de WordPress).
 */
add_filter(
	'rest_pre_serve_request',
	function ( $served ) {
		$origin = get_http_origin();

		if ( ! $origin ) {
			return $served;
		}

		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';

		if ( '' === $host ) {
			return $served;
		}

		$proto = 'http';

		if ( is_ssl() ) {
			$proto = 'https';
		} elseif ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
			$proto = 'https' === strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) ) ? 'https' : 'http';
		}

		if ( rtrim( $origin, '/' ) !== $proto . '://' . $host ) {
			header_remove( 'Access-Control-Allow-Origin' );
			header_remove( 'Access-Control-Allow-Credentials' );
		}

		return $served;
	},
	20
);

/**
 * SF-15: robots.txt sin la ruta interna de admin-ajax.
 */
add_filter(
	'robots_txt',
	function ( $output ) {
		return preg_replace( '/^Allow: \/wp-admin\/admin-ajax\.php\s*$/mi', '', $output );
	}
);
