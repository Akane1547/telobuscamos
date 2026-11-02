<?php
/**
 * Configuración de pagos: qué pasarelas se ofrecen y con qué credenciales.
 *
 * Es el único sitio que conoce los dos options de esta fase y el orden de
 * resolución de credenciales:
 *
 *   1. las constantes de wp-config.php, que mandan siempre (§16);
 *   2. el valor cifrado que se guarda desde el admin, **del modo activo**.
 *
 * Cada pasarela guarda un juego de credenciales por modo:
 *
 *   simple_form_payment_credentials = array(
 *       'mercado_pago' => array(
 *           'mode'       => 'production',        // lo que eligió el admin
 *           'sandbox'    => array( 'access_token' => '…cifrado…', … ),
 *           'production' => array( 'access_token' => '…cifrado…', … ),
 *       ),
 *   );
 *
 * Guardar en un modo no toca las claves del otro: pasar a comercial y volver a
 * pruebas no borra ninguna, que es justo lo que hace falta para alternar.
 *
 * Lo leen la pantalla de pagos, el shortcode (radios del Paso 3), los endpoints
 * y la pasarela al cobrar.
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
	 * Los dos modos, en el orden en que se muestran.
	 *
	 * @return array
	 */
	public static function modes(): array {
		return array( self::MODE_SANDBOX, self::MODE_PRODUCTION );
	}

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
	 * Modo que eligió el admin (la constante manda). Puede no ser el que está
	 * en uso: en producción sin HTTPS el sitio sigue en pruebas.
	 *
	 * Por defecto sandbox, que es el lado seguro: una producción no se cuela por
	 * olvido.
	 *
	 * @param string $gateway_id
	 * @return string
	 */
	public static function desired_mode( string $gateway_id ): string {
		$name = self::constant_name( $gateway_id, 'mode' );

		if ( defined( $name ) ) {
			return ( self::MODE_PRODUCTION === constant( $name ) ) ? self::MODE_PRODUCTION : self::MODE_SANDBOX;
		}

		$stored = self::stored( $gateway_id );

		return ( isset( $stored['mode'] ) && self::MODE_PRODUCTION === $stored['mode'] )
			? self::MODE_PRODUCTION
			: self::MODE_SANDBOX;
	}

	/**
	 * Modo efectivo: producción solo si de verdad se puede cobrar.
	 *
	 * Mercado Pago no acepta retornos ni notificaciones por HTTP, así que un
	 * sitio sin HTTPS no puede estar en producción. La elección del admin queda
	 * guardada (y sus claves también); simplemente no se activa.
	 *
	 * @param string $gateway_id
	 * @return string
	 */
	public static function mode( string $gateway_id ): string {
		if ( self::MODE_PRODUCTION !== self::desired_mode( $gateway_id ) ) {
			return self::MODE_SANDBOX;
		}

		return self::site_is_https() ? self::MODE_PRODUCTION : self::MODE_SANDBOX;
	}

	/**
	 * ¿El modo lo fija wp-config.php? La pantalla lo usa para bloquear el select.
	 *
	 * @param string $gateway_id
	 * @return bool
	 */
	public static function mode_is_locked( string $gateway_id ): bool {
		return defined( self::constant_name( $gateway_id, 'mode' ) );
	}

	/**
	 * Valor utilizable de una credencial: la constante primero, luego el modo
	 * activo. Devuelve '' si no hay ninguna de las dos.
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

		return self::stored_credential( $gateway_id, self::mode( $gateway_id ), $field );
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

		return '' !== self::stored_credential( $gateway_id, self::mode( $gateway_id ), $field ) ? 'stored' : 'none';
	}

	/**
	 * ¿Ese modo tiene todas las credenciales de la pasarela?
	 *
	 * @param string $gateway_id
	 * @param string $mode
	 * @return bool
	 */
	public static function has_keys( string $gateway_id, string $mode ): bool {
		$cuenta = self::keys_count( $gateway_id, $mode );

		return $cuenta['total'] > 0 && $cuenta['ok'] === $cuenta['total'];
	}

	/**
	 * Cuántas credenciales tiene guardadas un modo (para el resumen de la
	 * pantalla, sin enseñar ningún valor).
	 *
	 * @param string $gateway_id
	 * @param string $mode
	 * @return array{ok:int,total:int}
	 */
	public static function keys_count( string $gateway_id, string $mode ): array {
		$fields = Gateways::credential_fields( $gateway_id );
		$ok     = 0;

		foreach ( $fields as $field ) {
			$name = self::constant_name( $gateway_id, $field );

			if ( ( defined( $name ) && '' !== (string) constant( $name ) ) || '' !== self::stored_credential( $gateway_id, $mode, $field ) ) {
				$ok++;
			}
		}

		return array( 'ok' => $ok, 'total' => count( $fields ) );
	}

	/**
	 * Guarda el modo y las credenciales de una pasarela en el juego del modo
	 * indicado. Las claves del otro modo no se tocan.
	 *
	 * Un campo vacío conserva lo que ya había **en ese modo**: el admin nunca ve
	 * el valor, así que en blanco significa "no lo cambio".
	 *
	 * Guardar en producción sin HTTPS no se rechaza: las claves se guardan (si
	 * no, el admin las perdería al escribir) pero el modo no se activa y la
	 * pantalla lo avisa. El código de retorno es 'https' en ese caso.
	 *
	 * @param string $gateway_id
	 * @param string $mode
	 * @param array  $plain_fields field => valor en claro (solo lo que se escribió).
	 * @return string '' si se guardó y quedó activo; si no, el motivo:
	 *                'unknown' (pasarela no registrada), 'crypto' (no hay
	 *                cifrado disponible) o 'https' (guardado, pero producción no
	 *                se activa sin HTTPS).
	 */
	public static function save( string $gateway_id, string $mode, array $plain_fields = array() ): string {
		if ( ! Gateways::has( $gateway_id ) ) {
			return 'unknown';
		}

		$mode = ( self::MODE_PRODUCTION === $mode ) ? self::MODE_PRODUCTION : self::MODE_SANDBOX;

		$all = get_option( self::OPTION_CREDENTIALS, array() );
		$all = is_array( $all ) ? $all : array();

		$set = isset( $all[ $gateway_id ] ) && is_array( $all[ $gateway_id ] ) ? $all[ $gateway_id ] : array();
		$set = self::normalize_entry( $gateway_id, $set );

		$cifrados = array();

		foreach ( Gateways::credential_fields( $gateway_id ) as $field ) {
			if ( ! isset( $plain_fields[ $field ] ) || '' === $plain_fields[ $field ] ) {
				continue;
			}

			$cipher = Crypto::encrypt( (string) $plain_fields[ $field ] );

			// Había algo que cifrar y no se pudo (sin sodium o sin salts): no se
			// guarda nada, ni el modo, antes que dejar un valor en claro o dar por
			// guardado algo que no lo está.
			if ( '' === $cipher ) {
				return 'crypto';
			}

			$cifrados[ $field ] = $cipher;
		}

		$set['mode'] = $mode;

		foreach ( $cifrados as $field => $cipher ) {
			$set[ $mode ][ $field ] = $cipher;
		}

		$all[ $gateway_id ] = $set;

		// Autoload en false: es un criptograma que solo necesita quien va a pagar,
		// no cada petición del sitio (B-1).
		update_option( self::OPTION_CREDENTIALS, $all, false );

		if ( self::MODE_PRODUCTION === $mode && ! self::site_is_https() ) {
			return 'https';
		}

		return '';
	}

	/**
	 * Forja una entrada en la forma `mode` + un array por modo, moviendo los
	 * campos planos del formato viejo al modo que tenían guardado.
	 *
	 * @param string $gateway_id
	 * @param array  $set
	 * @return array
	 */
	private static function normalize_entry( string $gateway_id, array $set ): array {
		$mode = ( isset( $set['mode'] ) && self::MODE_PRODUCTION === $set['mode'] ) ? self::MODE_PRODUCTION : self::MODE_SANDBOX;

		$planos = array();

		foreach ( Gateways::credential_fields( $gateway_id ) as $field ) {
			if ( isset( $set[ $field ] ) && is_string( $set[ $field ] ) && '' !== $set[ $field ] ) {
				$planos[ $field ] = $set[ $field ];
			}
		}

		$nuevo = array( 'mode' => $mode );

		foreach ( self::modes() as $m ) {
			$bucket = isset( $set[ $m ] ) && is_array( $set[ $m ] ) ? $set[ $m ] : array();

			// Los campos planos viejos pertenecen al modo que estaba guardado.
			if ( $m === $mode ) {
				$bucket = array_merge( $bucket, $planos );
			}

			$nuevo[ $m ] = $bucket;
		}

		return $nuevo;
	}

	/**
	 * Migra el formato viejo (campos planos junto a 'mode') al de un juego por
	 * modo. Idempotente: si ya está migrado no hace nada.
	 */
	public static function install(): void {
		$all = get_option( self::OPTION_CREDENTIALS, null );

		if ( ! is_array( $all ) || empty( $all ) ) {
			return;
		}

		$cambio = false;

		foreach ( $all as $gateway_id => $set ) {
			if ( ! is_string( $gateway_id ) || ! is_array( $set ) || ! Gateways::has( $gateway_id ) ) {
				continue;
			}

			$normalizado = self::normalize_entry( $gateway_id, $set );

			if ( $normalizado !== $set ) {
				$all[ $gateway_id ] = $normalizado;
				$cambio             = true;
			}
		}

		if ( $cambio ) {
			update_option( self::OPTION_CREDENTIALS, $all, false );
		}
	}

	/**
	 * Credencial guardada y descifrada de un modo concreto. '' si no hay o no se
	 * puede descifrar.
	 *
	 * @param string $gateway_id
	 * @param string $mode
	 * @param string $field
	 * @return string
	 */
	private static function stored_credential( string $gateway_id, string $mode, string $field ): string {
		$stored = self::stored( $gateway_id );

		if ( ! isset( $stored[ $mode ] ) || ! is_array( $stored[ $mode ] ) ) {
			return '';
		}

		if ( ! isset( $stored[ $mode ][ $field ] ) || ! is_string( $stored[ $mode ][ $field ] ) ) {
			return '';
		}

		return Crypto::decrypt( $stored[ $mode ][ $field ] );
	}

	/**
	 * Entrada guardada de una pasarela, siempre en el formato por modo.
	 *
	 * @param string $gateway_id
	 * @return array
	 */
	private static function stored( string $gateway_id ): array {
		$all = get_option( self::OPTION_CREDENTIALS, array() );

		if ( ! is_array( $all ) || ! isset( $all[ $gateway_id ] ) || ! is_array( $all[ $gateway_id ] ) ) {
			return array();
		}

		// Lectura tolerante: una instalación vieja trae los campos planos y tiene
		// que seguir funcionando.
		return self::normalize_entry( $gateway_id, $all[ $gateway_id ] );
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
	public static function site_is_https(): bool {
		if ( ! function_exists( 'home_url' ) || ! function_exists( 'wp_parse_url' ) ) {
			return false;
		}

		return 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
	}
}
