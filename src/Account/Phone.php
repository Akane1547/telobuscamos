<?php
/**
 * Formato del teléfono del cliente.
 *
 * El formato es uno solo en todo el plugin: nueve dígitos de celular chileno,
 * empezando con 9, sin código de país. Lo guardado (meta del usuario y columna
 * del pedido) es siempre esa cadena de dígitos; lo que se muestra al cliente es
 * "+56 9 1234 5678". Vivía dentro de Ajax::normalize_phone() y ahora lo usan
 * también el registro y el perfil, así que no puede haber dos copias.
 *
 * @package SimpleForm\Account
 */

namespace SimpleForm\Account;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Phone
 */
final class Phone {

	/**
	 * Celular chileno sin código de país: 9 y ocho dígitos.
	 */
	const PATTERN = '/^9\d{8}$/';

	/**
	 * Normaliza lo que escribió el cliente a los nueve dígitos canónicos.
	 *
	 * @param string $raw Entrada libre ("+56 9 1234 5678", "9 1234 5678", ...).
	 * @return string Nueve dígitos, o '' si no es un celular chileno válido.
	 */
	public static function normalize( string $raw ): string {
		$digits = (string) preg_replace( '/\D+/', '', $raw );

		// "56912345678" es el mismo número con código de país: se descarta.
		if ( 11 === strlen( $digits ) && 0 === strpos( $digits, '56' ) ) {
			$digits = substr( $digits, 2 );
		}

		return preg_match( self::PATTERN, $digits ) ? $digits : '';
	}

	/**
	 * Presenta los nueve dígitos con el espaciado del formulario.
	 *
	 * @param string $value Dígitos, o una entrada libre que se normaliza antes.
	 * @return string Ej. "9 1234 5678", o '' si no es válido.
	 */
	public static function local( string $value ): string {
		$digits = self::normalize( $value );

		if ( '' === $digits ) {
			return '';
		}

		return substr( $digits, 0, 1 ) . ' ' . substr( $digits, 1, 4 ) . ' ' . substr( $digits, 5, 4 );
	}

	/**
	 * Presenta los dígitos completos, con código de país.
	 *
	 * @param string $value Dígitos, o una entrada libre que se normaliza antes.
	 * @return string Ej. "+56 9 1234 5678", o '' si no es válido.
	 */
	public static function format( string $value ): string {
		$local = self::local( $value );

		return '' === $local ? '' : '+56 ' . $local;
	}
}
