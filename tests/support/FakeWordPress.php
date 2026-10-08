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

	public static function reset(): void {
		self::$options = array();
		self::$home    = 'http://telobuscamos.local';
		self::$salt    = 'salt-de-prueba-0123456789';
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

	public static function home_url(): string {
		return self::$home;
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
}
