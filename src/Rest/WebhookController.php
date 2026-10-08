<?php
/**
 * Ruta REST que recibe las notificaciones de Mercado Pago.
 *
 * No confía en nada de lo que llega: verifica la firma, y después consulta el
 * pago contra la API. El cuerpo de la notificación solo aporta un id; el estado
 * del pedido lo decide la consulta, no el mensaje.
 *
 * Formato verificado en la documentación oficial (2026-10-08):
 *
 * - header `x-signature`: `ts=<ms>,v1=<hmac>`
 * - header `x-request-id`
 * - mensaje firmado: `id:[data.id];request-id:[x-request-id];ts:[ts];`
 *   con el id en minúsculas y HMAC-SHA256 en hexadecimal.
 *
 * No se valida la antigüedad del `ts` a propósito: una notificación repetida
 * trae el mismo contenido y la misma clave de idempotencia, así que un reintento
 * viejo no puede hacer daño; y rechazar por tiempo sí podría tirar abajo una
 * notificación legítima que llegó tarde.
 *
 * @package SimpleForm\Rest
 */

namespace SimpleForm\Rest;

use SimpleForm\Database\OrderRepository;
use SimpleForm\Database\PaymentEventRepository;
use SimpleForm\Payments\Gateways;
use SimpleForm\Payments\PaymentConfig;
use SimpleForm\Payments\PaymentProcessor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WebhookController {

	const NAMESPACE_ROUTE = 'simple-form';
	const ROUTE           = '/webhook';

	/**
	 * Registra la ruta. Queda en /wp-json/simple-form/webhook, que es la URL que
	 * se configura en el panel de Mercado Pago.
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE_ROUTE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				// La llamada la hace Mercado Pago sin credenciales: lo que
				// autoriza es la firma, que se verifica dentro del callback.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Procesa la notificación.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function handle( $request ) {
		$data       = $request->get_param( 'data' );
		$payment_id = is_array( $data ) && isset( $data['id'] )
			? (string) $data['id']
			: (string) $request->get_param( 'id' );

		$tipo = (string) ( $request->get_param( 'type' ) ?: $request->get_param( 'topic' ) );

		// Solo nos interesan los pagos. Devolver 200 evita que Mercado Pago
		// reintente algo que no vamos a procesar nunca.
		if ( '' !== $tipo && 'payment' !== $tipo && 'payments' !== $tipo ) {
			return rest_ensure_response(
				array(
					'ok'     => true,
					'motivo' => 'tipo de notificación ignorado',
				)
			);
		}

		if ( '' === $payment_id ) {
			return new \WP_REST_Response( array( 'ok' => false, 'motivo' => 'sin id de pago' ), 400 );
		}

		$secreto = (string) PaymentConfig::credential( 'mercado_pago', 'webhook_secret' );

		if ( ! self::signature_matches( $_SERVER, $payment_id, $secreto ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'motivo' => 'firma inválida' ), 401 );
		}

		// El estado real del pago, que es lo que decide todo.
		$gateway = new \SimpleForm\Payments\MercadoPagoGateway();
		$payment = $gateway->fetch_payment( $payment_id );

		if ( array() === $payment ) {
			// No se pudo consultar: que Mercado Pago reintente.
			return new \WP_REST_Response( array( 'ok' => false, 'motivo' => 'no se pudo consultar el pago' ), 500 );
		}

		$referencia = isset( $payment['external_reference'] ) ? (string) $payment['external_reference'] : '';
		$order      = '' !== $referencia ? OrderRepository::find( (int) $referencia ) : null;

		if ( null === $order ) {
			// El pago no es de un pedido nuestro, o el pedido todavía no está
			// escrito: 404 hace que reintente.
			return new \WP_REST_Response( array( 'ok' => false, 'motivo' => 'pedido no encontrado' ), 404 );
		}

		$vino_status = (string) ( $payment['status'] ?? '' );
		$evento_id   = sprintf( 'payment:%s:%s', $payment_id, $vino_status );

		// Camino rápido: si el evento ya está registrado, no se vuelve a consultar
		// ni a aplicar. El índice único sigue protegiendo de dos simultáneos.
		if ( PaymentEventRepository::exists( $evento_id ) ) {
			return rest_ensure_response(
				array(
					'ok'     => true,
					'motivo' => 'evento ya registrado',
				)
			);
		}

		$resultado = PaymentProcessor::apply( $order, $gateway, $payment_id );

		// El evento se registra DESPUÉS de aplicar. Al revés, una caída entre el
		// registro y la aplicación dejaría el pedido sin actualizar para siempre,
		// porque el reintento se vería como duplicado. Así, un reintento vuelve a
		// aplicar lo mismo, que no cambia nada.
		PaymentEventRepository::record(
			array(
				'order_id'          => (int) $order['id'],
				'gateway'           => 'mercado_pago',
				'external_event_id' => $evento_id,
				'type'              => 'payment',
				'raw_status'        => $vino_status,
				'payload'           => array(
					'id'                 => (string) ( $payment['id'] ?? $payment_id ),
					'status'             => $vino_status,
					'status_detail'      => (string) ( $payment['status_detail'] ?? '' ),
					'external_reference' => $referencia,
					'payment_method_id'  => (string) ( $payment['payment_method_id'] ?? '' ),
				),
			)
		);

		return rest_ensure_response(
			array(
				'ok'     => true,
				'estado' => $resultado['status'],
			)
		);
	}

	/**
	 * Verifica el header x-signature.
	 *
	 * Es estático y recibe todo por parámetro para poder probarlo sin HTTP.
	 *
	 * @param array  $server  $_SERVER, o su equivalente.
	 * @param string $data_id El id del recurso tal como llegó en la query.
	 * @param string $secret  Clave secreta de la aplicación.
	 * @return bool
	 */
	public static function signature_matches( array $server, string $data_id, string $secret ): bool {
		if ( '' === $secret || '' === $data_id ) {
			return false;
		}

		$header     = (string) ( $server['HTTP_X_SIGNATURE'] ?? '' );
		$request_id = (string) ( $server['HTTP_X_REQUEST_ID'] ?? '' );

		if ( '' === $header || '' === $request_id ) {
			return false;
		}

		$partes = array();

		foreach ( explode( ',', $header ) as $trozo ) {
			$par = explode( '=', $trozo, 2 );

			if ( 2 === count( $par ) ) {
				$partes[ trim( $par[0] ) ] = trim( $par[1] );
			}
		}

		$ts = (string) ( $partes['ts'] ?? '' );
		$v1 = (string) ( $partes['v1'] ?? '' );

		if ( '' === $ts || '' === $v1 ) {
			return false;
		}

		// El id va en minúsculas: así lo pide la documentación en el mensaje firmado.
		$mensaje  = sprintf( 'id:%s;request-id:%s;ts:%s;', strtolower( $data_id ), $request_id, $ts );
		$esperado = hash_hmac( 'sha256', $mensaje, $secret );

		// hash_equals compara en tiempo constante.
		return hash_equals( $esperado, strtolower( $v1 ) );
	}
}
