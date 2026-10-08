<?php
/**
 * Cifrado en reposo de las credenciales de pago.
 *
 * Las credenciales que se gestionan desde wp-admin nunca se guardan en claro:
 * se cifran con libsodium usando una clave derivada de los salts de
 * wp-config.php, así que un volcado de la base de datos no basta para usarlas
 * y mover la copia a otro sitio las invalida.
 *
 * Si libsodium no está disponible, o el sitio no tiene salts, el admin no
 * permite guardarlas: el plugin funciona entonces con las constantes definidas
 * en wp-config.php (§16).
 *
 * @package SimpleForm\Security
 */

namespace SimpleForm\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Crypto {

	/**
	 * Contexto de la derivación: separa esta clave de cualquier otra que use los
	 * mismos salts.
	 */
	const CONTEXT = 'simple-form/credentials/v1';

	/**
	 * @return bool
	 */
	public static function available(): bool {
		return function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'sodium_crypto_secretbox_open' )
			&& function_exists( 'sodium_crypto_generichash' )
			&& function_exists( 'random_bytes' );
	}

	/**
	 * Cifra un valor. Devuelve '' si no se puede cifrar, para que el llamador no
	 * guarde nunca un valor en claro por accidente.
	 *
	 * @param string $plain
	 * @return string Base64 de nonce + criptograma.
	 */
	public static function encrypt( string $plain ): string {
		$key = self::key();

		if ( '' === $plain || '' === $key ) {
			return '';
		}

		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) );
	}

	/**
	 * Descifra un valor guardado.
	 *
	 * Devuelve '' si el dato está alterado, es de otra instalación (otros salts)
	 * o no se puede descifrar. Aquí no se inventa un valor ni se avisa: decide el
	 * llamador.
	 *
	 * @param string $stored
	 * @return string
	 */
	public static function decrypt( string $stored ): string {
		$key = self::key();

		if ( '' === $stored || '' === $key ) {
			return '';
		}

		$raw = base64_decode( $stored, true );

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$nonce,
			$key
		);

		return is_string( $plain ) ? $plain : '';
	}

	/**
	 * Clave de 32 bytes derivada de los salts de wp-config.php.
	 *
	 * Sin salts no hay cifrado posible. Devolver una clave por defecto sería peor
	 * que no cifrar: daría una seguridad que no existe.
	 *
	 * @return string
	 */
	private static function key(): string {
		if ( ! self::available() || ! function_exists( 'wp_salt' ) ) {
			return '';
		}

		$salt = (string) wp_salt( 'auth' );

		if ( '' === $salt ) {
			return '';
		}

		// sha256 crudo: 32 bytes, justo lo que espera secretbox.
		return hash( 'sha256', self::CONTEXT . '|' . $salt, true );
	}
}
