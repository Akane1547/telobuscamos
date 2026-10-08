<?php
/**
 * Aplica el resultado de un pago al pedido.
 *
 * Es el único lugar donde un pago mueve el estado de un pedido, y lo usan los
 * dos caminos que se enteran de un pago: el webhook (servidor a servidor) y el
 * retorno del comprador. Ninguno de los dos confía en lo que recibió — los dos
 * pasan por acá, que consulta el pago en la pasarela y valida antes de mover.
 *
 * No está en Ajax ni en el controlador del webhook a propósito: la lógica de
 * pedidos, estados y transiciones vive en su capa.
 *
 * @package SimpleForm\Payments
 */

namespace SimpleForm\Payments;

use SimpleForm\Database\OrderRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PaymentProcessor {

	/**
	 * Consulta el pago en la pasarela y lo aplica al pedido.
	 *
	 * Devuelve un array con dos cosas, porque el que llama necesita saber algo
	 * más que "pasó o no pasó":
	 *
	 *   status => el estado interno en el que quedó el pedido, o '' si no se
	 *             aplicó nada.
	 *   retry  => true solo cuando el pago no se pudo consultar, que es el único
	 *             caso en el que vale la pena que la pasarela reintente.
	 *
	 * @param array   $order               Pedido tal como está en la base.
	 * @param Gateway $gateway             Pasarela que lo creó.
	 * @param string  $external_payment_id Id del pago en la pasarela.
	 * @return array status y retry.
	 */
	public static function apply( array $order, Gateway $gateway, string $external_payment_id ): array {
		$vacio = array(
			'status' => '',
			'retry'  => false,
		);

		$order_id = (int) ( $order['id'] ?? 0 );

		if ( $order_id <= 0 || '' === $external_payment_id ) {
			return $vacio;
		}

		$payment = $gateway->fetch_payment( $external_payment_id );

		if ( array() === $payment ) {
			// No se pudo consultar: acá sí queremos que reintente.
			return array(
				'status' => '',
				'retry'  => true,
			);
		}

		// El pago tiene que ser de ESTE pedido. Sin esta comprobación, cualquiera
		// podría mandar el id de pago de un tercero y marcar su propio pedido
		// como pagado.
		$referencia = isset( $payment['external_reference'] ) ? (string) $payment['external_reference'] : '';

		if ( (string) $order_id !== $referencia ) {
			return $vacio;
		}

		$nuevo = $gateway->map_status( (string) ( $payment['status'] ?? '' ) );

		// La pasarela puede reportar un estado sin equivalente en nuestro
		// conjunto (una mediación, por ejemplo): el pedido no se toca.
		if ( '' === $nuevo ) {
			return $vacio;
		}

		$actual = (string) ( $order['status'] ?? '' );

		// Ya está en ese estado: no hay nada que escribir, pero tampoco es un
		// error. Se devuelve el estado para que quien llama pueda responder bien.
		if ( $actual === $nuevo ) {
			return array(
				'status' => $nuevo,
				'retry'  => false,
			);
		}

		// Un evento viejo no puede degradar un estado que ya avanzó: si la
		// transición no es válida, se ignora.
		if ( ! OrderStatus::can_transition( $actual, $nuevo ) ) {
			return $vacio;
		}

		$fields = array(
			'status'              => $nuevo,
			'external_payment_id' => $external_payment_id,
		);

		if ( OrderStatus::APPROVED === $nuevo ) {
			$fields['paid_at'] = current_time( 'mysql', true );
		}

		if ( ! OrderRepository::update( $order_id, $fields ) ) {
			return $vacio;
		}

		return array(
			'status' => $nuevo,
			'retry'  => false,
		);
	}
}
