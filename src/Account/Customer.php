<?php
/**
 * Datos del cliente asociados a su cuenta de WordPress.
 *
 * La cuenta de WordPress es la fuente de verdad: el correo es user_email, el
 * nombre es first_name (nativo) y solo el teléfono necesita meta propio. No hay
 * tabla ni clave paralela para los mismos datos.
 *
 * @package SimpleForm\Account
 */

namespace SimpleForm\Account;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Customer
 *
 * Lectura y escritura de los datos del cliente. La identidad siempre la pone el
 * servidor: se pasa un user_id que ya salió de get_current_user_id() o de
 * wp_insert_user(), nunca de la petición del navegador.
 */
final class Customer {

	/**
	 * Clave única del teléfono del cliente.
	 */
	const META_PHONE = 'simple_form_phone';

	/**
	 * Nombre del cliente, en este orden: first_name y después display_name.
	 *
	 * @param int $user_id Id del usuario de WordPress.
	 * @return string
	 */
	public static function name( int $user_id ): string {
		$user = self::user( $user_id );

		if ( null === $user ) {
			return '';
		}

		$first_name = (string) get_user_meta( $user_id, 'first_name', true );

		if ( '' !== $first_name ) {
			return $first_name;
		}

		return (string) $user->display_name;
	}

	/**
	 * Correo de la cuenta.
	 *
	 * @param int $user_id Id del usuario de WordPress.
	 * @return string
	 */
	public static function email( int $user_id ): string {
		$user = self::user( $user_id );

		return null === $user ? '' : (string) $user->user_email;
	}

	/**
	 * Teléfono guardado, en los nueve dígitos canónicos.
	 *
	 * @param int $user_id Id del usuario de WordPress.
	 * @return string Nueve dígitos, o '' si la cuenta no tiene teléfono válido.
	 */
	public static function phone( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		return Phone::normalize( (string) get_user_meta( $user_id, self::META_PHONE, true ) );
	}

	/**
	 * Los tres datos que consume simple-form, desde una sola lectura.
	 *
	 * @param int $user_id Id del usuario de WordPress.
	 * @return array{name:string,email:string,phone:string}
	 */
	public static function data( int $user_id ): array {
		return array(
			'name'  => self::name( $user_id ),
			'email' => self::email( $user_id ),
			'phone' => self::phone( $user_id ),
		);
	}

	/**
	 * Datos del usuario autenticado. Vacío para invitados.
	 *
	 * @return array{name:string,email:string,phone:string}
	 */
	public static function current(): array {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return array(
				'name'  => '',
				'email' => '',
				'phone' => '',
			);
		}

		return self::data( $user_id );
	}

	/**
	 * Fecha de creación de la cuenta, tal como la guardó WordPress.
	 *
	 * @param int $user_id Id del usuario de WordPress.
	 * @return string Fecha en formato MySQL local, o ''.
	 */
	public static function created_at( int $user_id ): string {
		$user = self::user( $user_id );

		return null === $user ? '' : (string) $user->user_registered;
	}

	/**
	 * Guarda nombre y teléfono en la cuenta.
	 *
	 * @param int    $user_id   Id del usuario de WordPress.
	 * @param string $name      Nombre completo.
	 * @param string $raw_phone Teléfono como lo escribió el cliente.
	 * @return bool true si se guardaron los dos campos.
	 */
	public static function save( int $user_id, string $name, string $raw_phone ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$name  = sanitize_text_field( $name );
		$phone = Phone::normalize( $raw_phone );

		if ( '' === $name || '' === $phone ) {
			return false;
		}

		if ( false === wp_update_user( array( 'ID' => $user_id, 'first_name' => $name ) ) ) {
			return false;
		}

		update_user_meta( $user_id, self::META_PHONE, $phone );

		return true;
	}

	/**
	 * Usuario de WordPress, o null.
	 *
	 * @param int $user_id Id del usuario.
	 * @return \WP_User|null
	 */
	private static function user( int $user_id ): ?\WP_User {
		if ( $user_id <= 0 ) {
			return null;
		}

		$user = get_userdata( $user_id );

		return false === $user ? null : $user;
	}
}
