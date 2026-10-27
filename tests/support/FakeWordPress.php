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

	/** @var array Transients en memoria, con su vencimiento. */
	private static $transients = array();

	/** @var array Filtros que registran las pruebas. */
	private static $filters = array();

	/** @var array Correos capturados por el doble de wp_mail. */
	private static $mails = array();

	public static function reset(): void {
		self::$options    = array();
		self::$home       = 'http://telobuscamos.local';
		self::$salt       = 'salt-de-prueba-0123456789';
		self::$transients = array();
		self::$filters    = array();
		self::$mails      = array();
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

	public static function set_filter( string $hook, callable $callback ): void {
		self::$filters[ $hook ] = $callback;
	}

	public static function apply_filters( string $hook, $value, array $args = array() ) {
		if ( ! isset( self::$filters[ $hook ] ) ) {
			return $value;
		}

		return call_user_func( self::$filters[ $hook ], $value, ...$args );
	}

	public static function set_transient( string $key, $value, int $expiration = 0 ): void {
		self::$transients[ $key ] = array(
			'value'      => $value,
			'expiration' => $expiration > 0 ? time() + $expiration : 0,
		);
	}

	public static function get_transient( string $key ) {
		if ( ! isset( self::$transients[ $key ] ) ) {
			return false;
		}

		$row = self::$transients[ $key ];

		if ( 0 < $row['expiration'] && $row['expiration'] < time() ) {
			unset( self::$transients[ $key ] );

			return false;
		}

		return $row['value'];
	}

	public static function delete_transient( string $key ): bool {
		$existia = isset( self::$transients[ $key ] );

		unset( self::$transients[ $key ] );

		return $existia;
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

	/**
	 * Fecha UTC fija, para que las pruebas no dependan del reloj.
	 */
	public static function current_time( string $type = 'mysql' ): string {
		return '2026-10-08 12:00:00';
	}

	/**
	 * Doble de wp_mail(): captura el envío en vez de mandarlo de verdad.
	 */
	public static function wp_mail( $to, $subject, $message, $headers = '' ): bool {
		self::$mails[] = array(
			'to'      => $to,
			'subject' => $subject,
			'message' => $message,
			'headers' => $headers,
		);

		return true;
	}

	/**
	 * Correos capturados por el doble, en orden de envío.
	 */
	public static function mails(): array {
		return self::$mails;
	}
}
