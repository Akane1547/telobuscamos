<?php
/**
 * Acceso a wp_sf_payment_events: el registro de lo que dijo cada pasarela.
 *
 * La clave de idempotencia es external_event_id y la columna es única: el mismo
 * evento no puede entrar dos veces, aunque la pasarela lo mande cinco veces.
 * Quien procesa un webhook registra el evento y solo sigue si el registro es
 * nuevo; un evento viejo no puede degradar un estado que ya avanzó.
 *
 * @package SimpleForm\Database
 */

namespace SimpleForm\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PaymentEventRepository {

	/**
	 * El evento no estaba: se registró recién ahora.
	 */
	const INSERTED = 'inserted';

	/**
	 * El evento ya estaba registrado. Es un reintento de la pasarela: no se
	 * vuelve a procesar.
	 */
	const DUPLICATE = 'duplicate';

	/**
	 * No se pudo registrar. Nunca debe tratarse como duplicado: eso saltearía
	 * el procesamiento de un evento legítimo.
	 */
	const FAILED = 'failed';

	/**
	 * Tope del payload que se guarda.
	 *
	 * Lo que manda una pasarela puede ser mucho más grande que lo que hace
	 * falta, y esto es una columna LONGTEXT que crece sola. Es un recorte por
	 * tamaño, no una política de datos: quien registra el evento tiene que
	 * mandar solo los campos que necesita, sin datos personales del pagador.
	 */
	const MAX_PAYLOAD = 65535;

	/**
	 * Registra un evento de pago.
	 *
	 * @param array $event order_id, gateway, external_event_id, type, raw_status, payload.
	 * @return string Una de las constantes INSERTED, DUPLICATE o FAILED.
	 */
	public static function record( array $event ): string {
		global $wpdb;

		$external_id = (string) ( $event['external_event_id'] ?? '' );
		$order_id    = (int) ( $event['order_id'] ?? 0 );

		if ( '' === $external_id || $order_id <= 0 ) {
			return self::FAILED;
		}

		$inserted = $wpdb->insert(
			Database::table_name( Database::TABLE_PAYMENT_EVENTS ),
			array(
				'order_id'          => $order_id,
				'gateway'           => (string) ( $event['gateway'] ?? '' ),
				'external_event_id' => $external_id,
				'type'              => (string) ( $event['type'] ?? '' ),
				'raw_status'        => (string) ( $event['raw_status'] ?? '' ),
				'payload'           => self::payload( $event['payload'] ?? null ),
				'created_at'        => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false !== $inserted ) {
			return self::INSERTED;
		}

		// El insert falló: o el evento ya estaba (la clave es única) o es un
		// error de verdad. Se distinguen consultando, no leyendo el texto del
		// error de MySQL.
		return self::exists( $external_id ) ? self::DUPLICATE : self::FAILED;
	}

	/**
	 * ¿Está registrado ese evento?
	 *
	 * @param string $external_event_id
	 * @return bool
	 */
	public static function exists( string $external_event_id ): bool {
		global $wpdb;

		if ( '' === $external_event_id ) {
			return false;
		}

		$table = Database::table_name( Database::TABLE_PAYMENT_EVENTS );

		return null !== $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE external_event_id = %s LIMIT 1", $external_event_id )
		);
	}

	/**
	 * Eventos de un pedido, del más viejo al más nuevo.
	 *
	 * @param int $order_id
	 * @return array
	 */
	public static function find_by_order( int $order_id ): array {
		global $wpdb;

		if ( $order_id <= 0 ) {
			return array();
		}

		$table = Database::table_name( Database::TABLE_PAYMENT_EVENTS );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC", $order_id ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Serializa el payload sin dejarlo crecer sin control.
	 *
	 * @param mixed $payload
	 * @return string|null
	 */
	private static function payload( $payload ): ?string {
		if ( null === $payload ) {
			return null;
		}

		$json = wp_json_encode( $payload );

		if ( false === $json ) {
			return null;
		}

		return strlen( $json ) > self::MAX_PAYLOAD ? substr( $json, 0, self::MAX_PAYLOAD ) : $json;
	}
}
