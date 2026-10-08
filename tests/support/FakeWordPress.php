<?php
/**
 * Estado y dobles mínimos de WordPress para las pruebas unitarias.
 *
 * Guarda los options en memoria, la URL del sitio y el salt. Cada prueba llama a
 * reset() desde su setUp(): así ninguna arrastra lo que dejó la anterior.
 *
 * @package SimpleForm\Tests
 */

namespace SimpleForm\Tests;

final class FakeWordPress {

	/** @var array */
	private static $options = array();

	/** @var string */
	private static $home = 'http://telobuscamos.local';

	/** @var string */
	private static $salt = 'salt-de-prueba-0123456789';

	/** @var string */
	private static $referer = 'https://telobuscamos.local/mapa/';

	public static function reset(): void {
		self::$options = array();
		self::$home    = 'http://telobuscamos.local';
		self::$salt    = 'salt-de-prueba-0123456789';
		self::$referer = 'https://telobuscamos.local/mapa/';
	}

	public static function set_option( string $key, $value ): void {
		self::$options[ $key ] = $value;
	}

	public static function get_option( string $key, $default = false ) {
		return array_key_exists( $key, self::$options ) ? self::$options[ $key ] : $default;
	}

	public static function update_option( string $key, $value ) {
		self::$options[ $key ] = $value;

		return true;
	}

	public static function delete_option( string $key ) {
		unset( self::$options[ $key ] );

		return true;
	}

	public static function set_home( string $url ): void {
		self::$home = $url;
	}

	/**
	 * Mismo comportamiento que home_url() de WordPress: con ruta la agrega, sin
	 * ruta devuelve el home tal cual.
	 */
	public static function home_url( string $path = '' ): string {
		$base = rtrim( self::$home, '/' );

		return '' === $path ? $base : $base . '/' . ltrim( $path, '/' );
	}

	public static function set_salt( string $salt ): void {
		self::$salt = $salt;
	}

	public static function salt(): string {
		return self::$salt;
	}

	/**
	 * Mismo comportamiento que sanitize_key() de WordPress.
	 */
	public static function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) );
	}

	public static function set_referer( string $url ): void {
		self::$referer = $url;
	}

	public static function referer(): string {
		return self::$referer;
	}

	/**
	 * Mismo criterio que wp_validate_redirect(): solo el propio host.
	 */
	public static function validate_redirect( string $location, string $fallback ): string {
		$host = (string) parse_url( $location, PHP_URL_HOST );

		return ( '' !== $host && $host === parse_url( self::$home, PHP_URL_HOST ) ) ? $location : $fallback;
	}

	/**
	 * Caso simple de add_query_arg(): el que usa la pasarela.
	 */
	public static function add_query_arg( string $key, string $value, string $url ): string {
		$separator = ( false === strpos( $url, '?' ) ) ? '?' : '&';

		return $url . $separator . rawurlencode( $key ) . '=' . rawurlencode( $value );
	}
}
