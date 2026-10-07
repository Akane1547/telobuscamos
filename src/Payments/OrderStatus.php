<?php
/**
 * Máquina de estados del pedido.
 *
 * Único árbitro de las transiciones válidas: ningún otro punto del plugin
 * debería cambiar el estado de un pedido sin pasar por can_transition().
 *
 * @package SimpleForm\Payments
 */

namespace SimpleForm\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderStatus {

	const DRAFT        = 'draft';
	const PENDING      = 'pending';
	const APPROVED     = 'approved';
	const IN_PROCESS   = 'in_process';
	const REJECTED     = 'rejected';
	const CANCELLED    = 'cancelled';
	const EXPIRED      = 'expired';
	const REFUNDED     = 'refunded';
	const CHARGED_BACK = 'charged_back';

	/**
	 * Transiciones permitidas: estado actual => estados alcanzables.
	 *
	 * Fuente única de verdad del grafo. De aquí salen all(), is_final() y
	 * can_transition(): un estado final es, literalmente, el que no tiene
	 * salidas. Los estados externos de cada pasarela se traducen a este
	 * conjunto; ninguno se guarda tal cual.
	 *
	 * @var array<string, string[]>
	 */
	private const TRANSITIONS = array(
		self::DRAFT        => array( self::PENDING, self::CANCELLED, self::EXPIRED ),
		self::PENDING      => array( self::APPROVED, self::IN_PROCESS, self::REJECTED, self::CANCELLED, self::EXPIRED ),
		self::APPROVED     => array( self::REFUNDED, self::CHARGED_BACK ),
		self::IN_PROCESS   => array( self::APPROVED, self::REJECTED, self::CANCELLED, self::EXPIRED ),
		self::REJECTED     => array( self::PENDING ),
		self::CANCELLED    => array(),
		self::EXPIRED      => array(),
		self::REFUNDED     => array(),
		self::CHARGED_BACK => array(),
	);

	/**
	 * Todos los estados internos válidos.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array_keys( self::TRANSITIONS );
	}

	/**
	 * Indica si un valor es uno de los estados internos.
	 *
	 * @param string $status Valor a comprobar.
	 * @return bool
	 */
	public static function is_valid( string $status ): bool {
		return array_key_exists( $status, self::TRANSITIONS );
	}

	/**
	 * Un estado final no admite ninguna transición posterior.
	 *
	 * Devuelve false para valores que no son estados internos.
	 *
	 * @param string $status Estado a comprobar.
	 * @return bool
	 */
	public static function is_final( string $status ): bool {
		return self::is_valid( $status ) && array() === self::TRANSITIONS[ $status ];
	}

	/**
	 * Valida una transición de estado.
	 *
	 * Devuelve false también cuando $from y $to son iguales: repetir el mismo
	 * estado no es una transición (la idempotencia de un evento repetido se
	 * resuelve con external_event_id, no aquí).
	 *
	 * @param string $from Estado actual persistido.
	 * @param string $to   Estado al que se quiere mover.
	 * @return bool
	 */
	public static function can_transition( string $from, string $to ): bool {
		if ( ! self::is_valid( $from ) || ! self::is_valid( $to ) ) {
			return false;
		}

		return in_array( $to, self::TRANSITIONS[ $from ], true );
	}

	/**
	 * Etiqueta visible de un estado, ya traducida.
	 *
	 * Los textos del recibo los decide el servidor: el front solo pinta lo que
	 * recibe. Un estado desconocido devuelve un texto genérico en vez de dejar
	 * el hueco vacío.
	 *
	 * @param string $status Estado interno.
	 * @return string
	 */
	public static function label( string $status ): string {
		$labels = array(
			self::DRAFT        => __( 'Pedido sin iniciar', 'simple-form' ),
			self::PENDING      => __( 'Pago pendiente', 'simple-form' ),
			self::IN_PROCESS   => __( 'Pago en revisión', 'simple-form' ),
			self::APPROVED     => __( 'Pago confirmado', 'simple-form' ),
			self::REJECTED     => __( 'Pago rechazado', 'simple-form' ),
			self::CANCELLED    => __( 'Pedido cancelado', 'simple-form' ),
			self::EXPIRED      => __( 'Pedido expirado', 'simple-form' ),
			self::REFUNDED     => __( 'Pago reembolsado', 'simple-form' ),
			self::CHARGED_BACK => __( 'Pago con contracargo', 'simple-form' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Estado desconocido', 'simple-form' );
	}
}
