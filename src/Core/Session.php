<?php

namespace SimpleForm\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Session {

	const TRANSIENT_PREFIX = 'sf_client_session_';
	const EXPIRATION      = HOUR_IN_SECONDS * 2;

	/**
	 * Crea un nuevo ID de sesión y su transient inicial.
	 */
	public static function create(): string {
		$session_id = wp_generate_password( 32, false, false );
		$data       = array(
			'user_id'      => get_current_user_id(),
			'current_step' => 1,
			'created_at'   => time(),
		);

		set_transient( self::TRANSIENT_PREFIX . self::sanitize_id( $session_id ), $data, self::EXPIRATION );

		return $session_id;
	}

	/**
	 * Recupera los datos guardados en el transient de la sesión.
	 *
	 * @param string $session_id
	 * @return array|null
	 */
	public static function get( string $session_id ) {
		$clean_id = self::sanitize_id( $session_id );
		if ( empty( $clean_id ) ) {
			return null;
		}

		$data = get_transient( self::TRANSIENT_PREFIX . $clean_id );

		if ( ! is_array( $data ) ) {
			return null;
		}

		$current_user_id = get_current_user_id();

		// Solo invalidar si AMBOS son usuarios autenticados mayores a 0 y no coinciden.
		if ( $current_user_id > 0 && isset( $data['user_id'] ) && $data['user_id'] > 0 ) {
			if ( $current_user_id !== (int) $data['user_id'] ) {
				return null;
			}
		}

		return $data;
	}

	/**
	 * Guarda el payload del paso actual y refresca el tiempo de vida del transient.
	 *
	 * @param string $session_id
	 * @param int    $step
	 * @param array  $payload
	 * @return array|null 
	 */
	public static function save_step( string $session_id, int $step, array $payload ) {
		$data = self::get( $session_id );

		if ( null === $data ) {
			return null;
		}

		$data[ 'step' . $step ]           = $payload;
		$data[ 'step' . $step . '_valid' ] = true;

		$current              = isset( $data['current_step'] ) ? (int) $data['current_step'] : 1;
		$data['current_step'] = max( $current, $step + 1 );

		set_transient( self::TRANSIENT_PREFIX . self::sanitize_id( $session_id ), $data, self::EXPIRATION );

		return $data;
	}

	/**
	 * Valida si los pasos anteriores fueron guardados exitosamente.
	 *
	 * @param array $data
	 * @param int $step
	 * @return bool
	 */
	public static function previous_steps_valid( array $data, int $step ): bool {
		for ( $i = 1; $i < $step; $i++ ) {
			if ( empty( $data[ 'step' . $i . '_valid' ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Sanitiza el ID de sesión permitiendo únicamente caracteres alfanuméricos.
	 *
	 * @param string $session_id
	 * @return string 
	 */
	public static function sanitize_id( string $session_id ): string {
		return preg_replace( '/[^a-zA-Z0-9]/', '', $session_id );
	}
}