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
	 * Lee un parámetro del query string tal como llegó en la URL.
	 *
	 * PHP convierte los puntos en guiones bajos en $_GET, así que `data.id` no
	 * sobrevive a los parámetros ya procesados de WordPress: hay que leerlo del
	 * query string crudo, que es el nombre con el que Mercado Pago lo firma.
	 *
	 * @param string $name Nombre tal cual aparece en la URL.
	 * @return string Valor, o '' si no está.
	 */
	private static function query_param( string $name ): string {
		$raw = (string) ( $_SERVER['QUERY_STRING'] ?? '' );

		foreach ( explode( '&', $raw ) as $par ) {
			$trozos = explode( '=', $par, 2 );

			if ( 2 === count( $trozos ) && urldecode( $trozos[0] ) === $name ) {
				return urldecode( $trozos[1] );
			}
		}

		return '';
	}

	/**
	 * Procesa la notificación.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function handle( $request ) {
		$data = $request->get_param( 'data' );

		// El valor que se firma es el `data.id` de la query, tal cual lo leen los
		// SDK oficiales. Se lee del query string sin procesar porque PHP convierte
		// los puntos en guiones bajos en $_GET: `data.id` llega como `data_id`, y
		// los parámetros ya procesados de WordPress no lo tienen con el nombre que
		// firma Mercado Pago.
		$data_id = self::query_param( 'data.id' );

		// Se guarda aparte del id que se usa para consultar el pago, porque la
		// notificación del estilo `?id=...&topic=payment` no trae `data.id`, y en
		// ese caso el par `id:` no va en el manifiesto.
		$payment_id = $data_id;

		if ( '' === $payment_id ) {
			$payment_id = self::query_param( 'id' );
		}

		if ( '' === $payment_id && is_array( $data ) && isset( $data['id'] ) ) {
			$payment_id = (string) $data['id'];
		}

		if ( '' === $payment_id ) {
			$payment_id = (string) $request->get_param( 'id' );
		}

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

		if ( ! self::signature_matches( $_SERVER, $data_id, $secreto ) ) {
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
		if ( '' === $secret ) {
			return false;
		}

		$header     = (string) ( $server['HTTP_X_SIGNATURE'] ?? '' );
		$request_id = (string) ( $server['HTTP_X_REQUEST_ID'] ?? '' );

		if ( '' === $header ) {
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

		// El manifiesto lleva solo los pares que vinieron en la notificación: si
		// un valor no está, ese par se omite. Lo documenta Mercado Pago y pasa de
		// verdad: las notificaciones del estilo `?id=...&topic=payment` no traen
		// `data.id` en la query, así que el par `id:` no va. El id va en
		// minúsculas, como piden los SDK oficiales.
		$partes = array();

		if ( '' !== $data_id ) {
			$partes[] = 'id:' . strtolower( $data_id );
		}

		if ( '' !== $request_id ) {
			$partes[] = 'request-id:' . $request_id;
		}

		$partes[] = 'ts:' . $ts;

		$mensaje  = implode( ';', $partes ) . ';';
		$esperado = hash_hmac( 'sha256', $mensaje, $secret );

		// hash_equals compara en tiempo constante.
		return hash_equals( $esperado, strtolower( $v1 ) );
	}
}
