<?php
/**
 * Puntero de sesión del formulario.
 *
 * El transient ya no guarda datos del cliente ni el estado de los pasos: es
 * solo un puntero hacia el draft_id del pedido persistido. Los datos
 * personales viven únicamente en wp_sf_orders.
 *
 * @package SimpleForm\Core
 */

namespace SimpleForm\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Session {

	const TRANSIENT_PREFIX = 'sf_client_session_';
	const EXPIRATION       = HOUR_IN_SECONDS * 2;

	/**
	 * Crea el puntero hacia el draft_id y devuelve el id de sesión que ve el cliente.
	 *
	 * @param string $draft_id Clave del pedido persistido.
	 * @return string
	 */
	public static function create( string $draft_id ): string {
		$session_id = wp_generate_password( 32, false, false );

		set_transient(
			self::TRANSIENT_PREFIX . self::sanitize_id( $session_id ),
			array(
				'draft_id' => $draft_id,
				'user_id'  => get_current_user_id(),
			),
			self::EXPIRATION
		);

		return $session_id;
	}

	/**
	 * Resuelve el puntero: devuelve el draft_id, o null si no hay sesión válida.
	 *
	 * El cliente solo conoce el id de sesión; el draft_id no sale del servidor.
	 *
	 * @param string $session_id
	 * @return string|null
	 */
	public static function find_draft_id( string $session_id ): ?string {
		$data = self::read( $session_id );

		if ( null === $data || empty( $data['draft_id'] ) ) {
			return null;
		}

		return (string) $data['draft_id'];
	}

	/**
	 * Renueva el tiempo de vida del puntero: 2 horas renovables por actividad.
	 *
	 * @param string $session_id
	 */
	public static function refresh( string $session_id ): void {
		$data = self::read( $session_id );

		if ( null === $data ) {
			return;
		}

		set_transient( self::TRANSIENT_PREFIX . self::sanitize_id( $session_id ), $data, self::EXPIRATION );
	}

	/**
	 * Lee y valida el puntero.
	 *
	 * Solo invalida si AMBOS son usuarios autenticados y no coinciden: los
	 * invitados siempre son id 0.
	 *
	 * @param string $session_id
	 * @return array|null
	 */
	private static function read( string $session_id ): ?array {
		$clean_id = self::sanitize_id( $session_id );

		if ( '' === $clean_id ) {
			return null;
		}

		$data = get_transient( self::TRANSIENT_PREFIX . $clean_id );

		if ( ! is_array( $data ) ) {
			return null;
		}

		$current_user_id = get_current_user_id();

		if ( $current_user_id > 0 && ! empty( $data['user_id'] ) && $current_user_id !== (int) $data['user_id'] ) {
			return null;
		}

		return $data;
	}

	/**
	 * Sanitiza el id de sesión permitiendo únicamente caracteres alfanuméricos.
	 *
	 * @param string $session_id
	 * @return string
	 */
	public static function sanitize_id( string $session_id ): string {
		return preg_replace( '/[^a-zA-Z0-9]/', '', $session_id );
	}
}
