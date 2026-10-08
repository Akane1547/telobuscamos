<?php
/**
 * Configuración de pagos: qué pasarelas se ofrecen y con qué credenciales.
 *
 * Es el único sitio que conoce los dos options de esta fase y el orden de
 * resolución de credenciales:
 *
 *   1. las constantes de wp-config.php, que mandan siempre (§16);
 *   2. el valor cifrado que se guarda desde el admin.
 *
 * Lo leen la pantalla de pagos, el shortcode (radios del Paso 3) y los
 * endpoints (validar el medio elegido); en la Fase 4 lo leerá la pasarela para
 * cobrar.
 *
 * @package SimpleForm\Payments
 */

namespace SimpleForm\Payments;

use SimpleForm\Security\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PaymentConfig {

	const OPTION_METHODS     = 'simple_form_payment_methods';
	const OPTION_CREDENTIALS = 'simple_form_payment_credentials';

	const MODE_SANDBOX    = 'sandbox';
	const MODE_PRODUCTION = 'production';

	/**
	 * IDs de pasarela habilitados, saneados contra el registro.
	 *
	 * Sin option guardado devuelve vacío a propósito: un medio que no está en el
	 * registro no se ofrece ni se acepta desde el servidor (§14.3). Que el
	 * formulario no tenga medios hasta que se configuren es lo correcto, no un
	 * fallo: la pantalla del admin lo avisa.
	 *
	 * @return array
	 */
	public static function enabled_ids(): array {
		return self::sanitize_ids( get_option( self::OPTION_METHODS, array() ) );
	}

	/**
	 * @param array $ids
	 * @return void
	 */
	public static function save_enabled_ids( array $ids ): void {
		update_option( self::OPTION_METHODS, self::sanitize_ids( $ids ) );
	}

	/**
	 * Nombre de la constante de wp-config.php de un campo de una pasarela.
	 *
	 * mercado_pago + access_token -> SIMPLE_FORM_MERCADO_PAGO_ACCESS_TOKEN
	 *
	 * @param string $gateway_id
	 * @param string $field
	 * @return string
	 */
	public static function constant_name( string $gateway_id, string $field ): string {
		return 'SIMPLE_FORM_' . strtoupper( $gateway_id ) . '_' . strtoupper( $field );
	}

	/**
	 * Modo de una pasarela: la constante manda sobre lo guardado.
	 *
	 * Por defecto sandbox, que es el lado seguro: una producción no se cuela por
	 * olvido.
	 *
	 * @param string $gateway_id
	 * @return string
	 */
	public static function mode( string $gateway_id ): string {
		$name = self::constant_name( $gateway_id, 'mode' );

		if ( defined( $name ) && self::MODE_PRODUCTION === constant( $name ) ) {
			return self::MODE_PRODUCTION;
		}

		$stored = self::stored( $gateway_id );

		return ( isset( $stored['mode'] ) && self::MODE_PRODUCTION === $stored['mode'] )
			? self::MODE_PRODUCTION
			: self::MODE_SANDBOX;
	}

	/**
	 * Valor utilizable de una credencial: la constante primero, luego lo
	 * guardado. Devuelve '' si no hay ninguna de las dos.
	 *
	 * @param string $gateway_id
	 * @param string $field
	 * @return string
	 */
	public static function credential( string $gateway_id, string $field ): string {
		$name = self::constant_name( $gateway_id, $field );

		if ( defined( $name ) && '' !== (string) constant( $name ) ) {
			return (string) constant( $name );
		}

		return self::stored_credential( $gateway_id, $field );
	}

	/**
	 * De dónde sale un campo: 'constant', 'stored' o 'none'.
	 *
	 * La pantalla lo usa para decir de dónde se está leyendo cada valor sin
	 * enseñarlo.
	 *
	 * @param string $gateway_id
	 * @param string $field
	 * @return string
	 */
	public static function source( string $gateway_id, string $field ): string {
		$name = self::constant_name( $gateway_id, $field );

		if ( defined( $name ) && '' !== (string) constant( $name ) ) {
			return 'constant';
		}

		return '' !== self::stored_credential( $gateway_id, $field ) ? 'stored' : 'none';
	}

	/**
	 * Guarda el modo y las credenciales de una pasarela.
	 *
	 * Un campo vacío conserva lo que ya había: el admin nunca muestra el valor,
	 * así que en blanco significa "no lo cambio", no "bórralo".
	 *
	 * En producción exige HTTPS en la URL del sitio: sin eso Mercado Pago no
	 * acepta back_urls ni notificaciones.
	 *
	 * @param string $gateway_id
	 * @param string $mode
	 * @param array  $plain_fields field => valor en claro (solo lo que se escribió).
	 * @return bool False si no se guardó.
	 */
	public static function save( string $gateway_id, string $mode, array $plain_fields = array() ): bool {
		if ( ! Gateways::has( $gateway_id ) ) {
			return false;
		}

		$mode = ( self::MODE_PRODUCTION === $mode ) ? self::MODE_PRODUCTION : self::MODE_SANDBOX;

		if ( self::MODE_PRODUCTION === $mode && ! self::site_is_https() ) {
			return false;
		}

		$all = get_option( self::OPTION_CREDENTIALS, array() );
		$all = is_array( $all ) ? $all : array();

		$set = isset( $all[ $gateway_id ] ) && is_array( $all[ $gateway_id ] ) ? $all[ $gateway_id ] : array();

		$set['mode'] = $mode;

		foreach ( Gateways::credential_fields( $gateway_id ) as $field ) {
			if ( ! isset( $plain_fields[ $field ] ) || '' === $plain_fields[ $field ] ) {
				continue;
			}

			$cipher = Crypto::encrypt( (string) $plain_fields[ $field ] );

			// Sin cifrado posible (sin sodium o sin salts) no se guarda nada: un
			// valor en claro en la base es peor que no tenerlo.
			if ( '' !== $cipher ) {
				$set[ $field ] = $cipher;
			}
		}

		$all[ $gateway_id ] = $set;

		update_option( self::OPTION_CREDENTIALS, $all );

		return true;
	}

	/**
	 * Credencial guardada y descifrada. '' si no hay o no se puede descifrar.
	 *
	 * @param string $gateway_id
	 * @param string $field
	 * @return string
	 */
	private static function stored_credential( string $gateway_id, string $field ): string {
		$stored = self::stored( $gateway_id );

		if ( ! isset( $stored[ $field ] ) || ! is_string( $stored[ $field ] ) ) {
			return '';
		}

		return Crypto::decrypt( $stored[ $field ] );
	}

	/**
	 * @param string $gateway_id
	 * @return array
	 */
	private static function stored( string $gateway_id ): array {
		$all = get_option( self::OPTION_CREDENTIALS, array() );

		if ( ! is_array( $all ) || ! isset( $all[ $gateway_id ] ) || ! is_array( $all[ $gateway_id ] ) ) {
			return array();
		}

		return $all[ $gateway_id ];
	}

	/**
	 * Sanea una lista de IDs: claves válidas, solo registradas, sin repetir y
	 * conservando el orden recibido.
	 *
	 * @param mixed $ids
	 * @return array
	 */
	private static function sanitize_ids( $ids ): array {
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$clean = array();
		$seen  = array();

		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) ) {
				continue;
			}

			$id = sanitize_key( $id );

			if ( '' === $id || isset( $seen[ $id ] ) || ! Gateways::has( $id ) ) {
				continue;
			}

			$seen[ $id ] = true;
			$clean[]     = $id;
		}

		return $clean;
	}

	/**
	 * @return bool
	 */
	private static function site_is_https(): bool {
		if ( ! function_exists( 'home_url' ) || ! function_exists( 'wp_parse_url' ) ) {
			return false;
		}

		return 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
	}
}
