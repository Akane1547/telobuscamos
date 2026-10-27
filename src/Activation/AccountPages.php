<?php
/**
 * Crea la página /perfil si no existe.
 *
 * Se ejecuta una sola vez (lo llama la activación del plugin). Es idempotente:
 * si la página ya existe no la toca, para no pisar lo que el usuario edite.
 *
 * @package SimpleForm\Activation
 */

namespace SimpleForm\Activation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AccountPages
 */
final class AccountPages {

	/**
	 * Option que recuerda que la página ya se creó.
	 */
	const OPTION_DONE = 'simple_form_profile_page';

	/**
	 * Crea la página si todavía no se creó nunca.
	 *
	 * Se mira la option para no recrearla si el usuario la borra a propósito:
	 * la option se guarda la primera vez que la página existe.
	 */
	public static function maybe_install(): void {
		if ( false !== get_option( self::OPTION_DONE, false ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Crea la página de perfil con el slug que espera Account\Pages.
	 */
	public static function install(): void {
		// Sin rewrite cargado, wp_insert_post() no puede calcular el permalink y
		// mata la petición. Instalar antes de wp_loaded no tiene sentido.
		if ( empty( $GLOBALS['wp_rewrite'] ) ) {
			return;
		}

		$existing = get_page_by_path( 'perfil' );

		if ( null !== $existing ) {
			update_option( self::OPTION_DONE, (int) $existing->ID, false );
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Perfil', 'simple-form' ),
				'post_name'    => 'perfil',
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '',
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return;
		}

		update_option( self::OPTION_DONE, (int) $page_id, false );
	}
}
