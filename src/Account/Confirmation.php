<?php
/**
 * Confirmación del correo al crear la cuenta.
 *
 * El registro ya no deja la sesión abierta: crea la cuenta en estado pendiente
 * y manda un enlace al correo. Solo se activa cuando alguien abre ese enlace y
 * elige su contraseña, así que la cuenta no puede quedar a nombre de un correo
 * ajeno por haberlo escrito en el formulario.
 *
 * El token se guarda hasheado con `wp_salt('auth')`: si alguien lee la tabla de
 * user meta no puede reconstruir el enlace.
 *
 * @package SimpleForm\Account
 */

namespace SimpleForm\Account;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Confirmation
 */
final class Confirmation {

	/**
	 * Meta con el token hasheado.
	 */
	const META_TOKEN = 'simple_form_confirm_token';

	/**
	 * Meta con el vencimiento (timestamp Unix).
	 */
	const META_EXPIRES = 'simple_form_confirm_expires';

	/**
	 * Meta que marca la cuenta como pendiente.
	 */
	const META_PENDING = 'simple_form_account_pending';

	/**
	 * Vida del enlace: un día.
	 */
	const TTL = DAY_IN_SECONDS;

	/**
	 * Marca la cuenta como pendiente y devuelve el token en claro.
	 *
	 * El token en claro no se guarda nunca: solo viaja en el correo.
	 *
	 * @param int $user_id Cuenta recién creada.
	 * @return string Token para el enlace, o '' si el id no sirve.
	 */
	public static function issue( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$token = wp_generate_password( 40, false, false );

		update_user_meta( $user_id, self::META_TOKEN, self::hash( $token ) );
		update_user_meta( $user_id, self::META_EXPIRES, (string) ( time() + self::TTL ) );
		update_user_meta( $user_id, self::META_PENDING, '1' );

		return $token;
	}

	/**
	 * Dice si la cuenta todavía no confirmó el correo.
	 *
	 * @param int $user_id Cuenta.
	 * @return bool
	 */
	public static function is_pending( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		return '1' === (string) get_user_meta( $user_id, self::META_PENDING, true );
	}

	/**
	 * Resuelve un token al user_id que lo emitió.
	 *
	 * @param string $token Token tal como llegó por la URL.
	 * @return int user_id, o 0 si no vale o venció.
	 */
	public static function verify( string $token ): int {
		$token = trim( $token );

		if ( '' === $token ) {
			return 0;
		}

		$found = get_users(
			array(
				'meta_key'    => self::META_TOKEN,
				'meta_value'  => self::hash( $token ),
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => false,
			)
		);

		if ( empty( $found ) ) {
			return 0;
		}

		$user_id = (int) $found[0];
		$expires = (int) get_user_meta( $user_id, self::META_EXPIRES, true );

		if ( $expires > 0 && $expires < time() ) {
			return 0;
		}

		if ( ! self::is_pending( $user_id ) ) {
			return 0;
		}

		return $user_id;
	}

	/**
	 * Activa la cuenta: borra el token y la marca de pendiente.
	 *
	 * @param int $user_id Cuenta confirmada.
	 */
	public static function activate( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		delete_user_meta( $user_id, self::META_TOKEN );
		delete_user_meta( $user_id, self::META_EXPIRES );
		delete_user_meta( $user_id, self::META_PENDING );
	}

	/**
	 * Enlace de confirmación para el correo.
	 *
	 * @param string $token Token en claro.
	 * @return string URL absoluta de la página de inicio de sesión.
	 */
	public static function url( string $token ): string {
		return add_query_arg( 'sf_confirm', rawurlencode( $token ), Pages::login_url() );
	}

	/**
	 * Hash del token con la sal del sitio.
	 *
	 * @param string $token Token en claro.
	 * @return string
	 */
	private static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}
}
