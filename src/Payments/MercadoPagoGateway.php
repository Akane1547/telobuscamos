<?php
/**
 * Mercado Pago — Checkout Pro por API de preferencias.
 *
 * Se crea una preferencia con el ítem, las URLs de retorno y el
 * external_reference (el id del pedido), y Mercado Pago devuelve el init_point
 * al que se redirige al comprador. El pago no se confirma nunca desde acá ni
 * desde el navegador: el estado real se consulta con fetch_payment().
 *
 * Todo esto está verificado contra la documentación oficial el 2026-10-08:
 *
 * - POST https://api.mercadopago.com/checkout/preferences con `Authorization: Bearer`.
 * - GET  https://api.mercadopago.com/v1/payments/{id}.
 * - Con credenciales de prueba se usa el mismo endpoint y el `init_point`: las
 *   credenciales de prueba no permiten transacciones reales y el
 *   `sandbox_init_point` es del flujo viejo.
 * - En CLP el `unit_price` va entero: "using decimal values in this field will
 *   result in processing errors".
 * - `X-Idempotency-Key` es obligatorio para las integraciones nuevas.
 * - Estados: approved, pending, authorized, in_process, in_mediation, rejected,
 *   cancelled, refunded, charged_back.
 *
 * @package SimpleForm\Payments
 */

namespace SimpleForm\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MercadoPagoGateway implements Gateway {

	const GATEWAY_ID = 'mercado_pago';
	const API_BASE   = 'https://api.mercadopago.com';
	const TIMEOUT    = 20;
	const CURRENCY   = 'CLP';

	/**
	 * Nombre del argumento con el que se distingue el caso al volver.
	 */
	const RETURN_ARG = 'simple_form_return';

	/**
	 * Estados de Mercado Pago traducidos al conjunto interno.
	 *
	 * `authorized` es "autorizado y esperando captura": eso es in_process.
	 * `in_mediation` no está a propósito: una mediación o disputa no tiene
	 * equivalente en nuestro conjunto y no debe mover el pedido.
	 */
	const STATUS_MAP = array(
		'approved'     => OrderStatus::APPROVED,
		'pending'      => OrderStatus::PENDING,
		'authorized'   => OrderStatus::IN_PROCESS,
		'in_process'   => OrderStatus::IN_PROCESS,
		'rejected'     => OrderStatus::REJECTED,
		'cancelled'    => OrderStatus::CANCELLED,
		'refunded'     => OrderStatus::REFUNDED,
		'charged_back' => OrderStatus::CHARGED_BACK,
	);

	/** @var string */
	private $access_token;

	public function __construct() {
		$this->access_token = (string) PaymentConfig::credential( self::GATEWAY_ID, 'access_token' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function create_payment( array $order ): string {
		if ( '' === $this->access_token ) {
			$this->log( 'sin access token configurado: no se crea la preferencia' );

			return '';
		}

		$response = wp_remote_post(
			self::API_BASE . '/checkout/preferences',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization'     => 'Bearer ' . $this->access_token,
					'Content-Type'      => 'application/json',
					'Accept'            => 'application/json',
					// Repetir el intento no puede crear dos preferencias para el
					// mismo borrador.
					'X-Idempotency-Key' => 'sf-pref-' . $order['draft_id'],
				),
				'body'    => wp_json_encode( $this->build_preference( $order ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'la petición a la API falló: ' . $response->get_error_message() );

			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code > 201 ) {
			$this->log( 'la API respondió ' . $code . ': ' . $this->api_message( $response ) );

			return '';
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['init_point'] ) || ! is_string( $data['init_point'] ) ) {
			$this->log( 'la respuesta de la API no trae init_point' );

			return '';
		}

		return $data['init_point'];
	}

	/**
	 * Arma el cuerpo de la preferencia.
	 *
	 * Está separado de create_payment() para poder probarlo sin red: acá no se
	 * decide nada, solo se traducen los datos del pedido al formato de Mercado
	 * Pago.
	 *
	 * @param array $order Pedido tal como está en la base.
	 * @return array
	 */
	public function build_preference( array $order ): array {
		return array(
			'items'              => array(
				array(
					'id'          => (string) $order['service_id'],
					'title'       => (string) $order['service_label'],
					'description' => sprintf(
						/* translators: %d es el radio en kilómetros. */
						__( 'Servicio a %d km', 'simple-form' ),
						(int) $order['radius_km']
					),
					'quantity'    => 1,
					'currency_id' => self::CURRENCY,
					// Entero: en CLP los decimales dan error de procesamiento.
					'unit_price'  => (int) $order['amount'],
				),
			),
			'payer'              => array(
				'name'  => (string) $order['full_name'],
				'email' => (string) $order['email'],
			),
			'back_urls'          => array(
				'success' => $this->return_url( 'success' ),
				'pending' => $this->return_url( 'pending' ),
				'failure' => $this->return_url( 'failure' ),
			),
			// Solo vuelve solo cuando el pago se aprueba; en los otros casos el
			// comprador usa el botón de Mercado Pago.
			'auto_return'        => 'approved',
			'notification_url'   => $this->notification_url(),
			'external_reference' => (string) $order['id'],
			'metadata'           => array( 'draft_id' => (string) $order['draft_id'] ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch_payment( string $external_id ): array {
		if ( '' === $this->access_token ) {
			$this->log( 'sin access token configurado: no se consulta el pago' );

			return array();
		}

		$response = wp_remote_get(
			self::API_BASE . '/v1/payments/' . rawurlencode( $external_id ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->access_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'la consulta del pago falló: ' . $response->get_error_message() );

			return array();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$this->log( 'al consultar el pago la API respondió ' . $code );

			return array();
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function map_status( string $raw_status ): string {
		$raw = strtolower( trim( $raw_status ) );

		return isset( self::STATUS_MAP[ $raw ] ) ? self::STATUS_MAP[ $raw ] : '';
	}

	/**
	 * URL de vuelta al formulario, con el caso en la query.
	 *
	 * El Referer lo pone el navegador, así que se valida contra el propio sitio:
	 * nunca se devuelve al comprador a un host ajeno. Si no llega, la home.
	 *
	 * @param string $case success|pending|failure.
	 * @return string
	 */
	private function return_url( string $case ): string {
		$referer = wp_get_referer();
		$base    = $referer ? wp_validate_redirect( $referer, home_url( '/' ) ) : home_url( '/' );

		return add_query_arg( self::RETURN_ARG, $case, $base );
	}

	private function notification_url(): string {
		// La ruta la sirve WebhookController (Fase 5).
		return home_url( '/wp-json/simple-form/webhook' );
	}

	/**
	 * Mensaje de error de la API, recortado y sin credenciales.
	 *
	 * @param array $response Respuesta de wp_remote_*.
	 * @return string
	 */
	private function api_message( array $response ): string {
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] ) ) {
			return substr( $data['message'], 0, 200 );
		}

		return 'sin detalle';
	}

	/**
	 * Log de diagnóstico. Nunca escribe credenciales ni datos personales.
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'SimpleForm MercadoPago: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
