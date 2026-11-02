<?php
/**
 * PayPal — Checkout por Orders v2.
 *
 * Se crea una orden en PayPal con `intent: CAPTURE` y se redirige al comprador al
 * enlace `approve` que devuelve la API. Al volver, el servidor consulta la orden
 * y, si el comprador aprobó, **captura** el pago. El estado real sale siempre de
 * la API, nunca de la URL de retorno.
 *
 * Verificado contra la documentación oficial (2026-11-02):
 *
 * - POST /v1/oauth2/token con Basic `client_id:client_secret` y
 *   `grant_type=client_credentials` devuelve el `access_token`.
 * - POST /v2/checkout/orders crea la orden y devuelve `links[]` con
 *   `rel: "approve"` (a dónde mandar al comprador).
 * - POST /v2/checkout/orders/{id}/capture cobra una orden aprobada; la respuesta
 *   trae `status: COMPLETED` y los datos de la captura.
 * - GET /v2/checkout/orders/{id} consulta el estado (CREATED, APPROVED, COMPLETED,
 *   VOIDED…).
 * - **PayPal no admite CLP**: la lista de monedas de la API no incluye el peso
 *   chileno. El monto del pedido (CLP) se convierte a USD con la tasa que fija el
 *   administrador (CLP por USD). Sin tasa no se crea el pago: no se inventa un
 *   tipo de cambio.
 *
 * @package SimpleForm\Payments
 */

namespace SimpleForm\Payments;

use SimpleForm\Core\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PayPalGateway implements Gateway {

	const GATEWAY_ID = 'paypal';

	const API_SANDBOX = 'https://api-m.sandbox.paypal.com';
	const API_LIVE    = 'https://api-m.paypal.com';

	const TIMEOUT  = 20;
	const CURRENCY = 'USD';

	/** Nombre del argumento con el que se distingue la vuelta del comprador. */
	const RETURN_ARG = 'simple_form_return';

	/**
	 * Estados de PayPal traducidos al conjunto interno.
	 *
	 * APPROVED es "el comprador aprobó pero todavía no se capturó": eso no es un
	 * pago hecho. La captura se intenta en fetch_payment(); si sale bien, ese
	 * mismo método devuelve COMPLETED.
	 *
	 * @var array
	 */
	const STATUS_MAP = array(
		'completed'             => OrderStatus::APPROVED,
		'approved'              => OrderStatus::PENDING,
		'created'               => OrderStatus::PENDING,
		'pending'               => OrderStatus::PENDING,
		'payer_action_required' => OrderStatus::PENDING,
		'voided'                => OrderStatus::CANCELLED,
		'declined'              => OrderStatus::REJECTED,
		'failed'                => OrderStatus::REJECTED,
		'refunded'              => OrderStatus::REFUNDED,
		'partially_refunded'    => OrderStatus::REFUNDED,
	);

	/** @var string */
	private $client_id;

	/** @var string */
	private $client_secret;

	/** @var float CLP por USD. 0 = sin configurar. */
	private $rate;

	public function __construct() {
		$this->client_id     = (string) PaymentConfig::credential( self::GATEWAY_ID, 'client_id' );
		$this->client_secret = (string) PaymentConfig::credential( self::GATEWAY_ID, 'client_secret' );

		$tasa       = (string) PaymentConfig::credential( self::GATEWAY_ID, 'usd_rate' );
		$this->rate = is_numeric( $tasa ) ? (float) $tasa : 0.0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function create_payment( array $order ): string {
		if ( '' === $this->client_id || '' === $this->client_secret ) {
			$this->log( 'sin credenciales configuradas: no se crea la orden' );

			return '';
		}

		$monto = $this->amount_usd( (int) $order['amount'] );

		if ( '' === $monto ) {
			$this->log( 'sin tasa de cambio válida o monto fuera de rango: no se crea la orden' );

			return '';
		}

		$token = $this->token();

		if ( '' === $token ) {
			return '';
		}

		$response = $this->request(
			'POST',
			'/v2/checkout/orders',
			$this->build_order( $order, $monto ),
			$token,
			// Repetir el intento no puede crear dos órdenes para el mismo borrador.
			array( 'PayPal-Request-Id' => 'sf-pp-' . $order['draft_id'] )
		);

		$code = (int) ( $response['code'] ?? 0 );

		if ( $code < 200 || 201 < $code ) {
			$this->log( 'la API respondió ' . $code . ' al crear la orden: ' . $this->api_message( $response ) );

			return '';
		}

		$data = is_array( $response['body'] ?? null ) ? $response['body'] : array();

		if ( empty( $data['id'] ) || ! is_string( $data['id'] ) ) {
			$this->log( 'la respuesta no trae id de orden' );

			return '';
		}

		$approve = $this->link( $data, 'approve' );

		if ( '' === $approve ) {
			$this->log( 'la respuesta no trae el enlace approve' );

			return '';
		}

		// Se guarda el id de la orden de PayPal: es con el que se captura y se
		// consulta al volver, y lo usa PaymentProcessor para verificar el pago.
		\SimpleForm\Database\OrderRepository::update( (int) $order['id'], array( 'external_payment_id' => (string) $data['id'] ) );

		return $approve;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Si la orden está aprobada y sin capturar, la captura acá: es el único
	 * momento en que el servidor puede cobrar sin webhook. Después devuelve el
	 * estado ya capturado.
	 */
	public function fetch_payment( string $external_id ): array {
		if ( '' === $this->client_id || '' === $this->client_secret ) {
			return array();
		}

		$token = $this->token();

		if ( '' === $token ) {
			return array();
		}

		$response = $this->request( 'GET', '/v2/checkout/orders/' . rawurlencode( $external_id ), null, $token );
		$data     = is_array( $response['body'] ?? null ) ? $response['body'] : array();

		if ( 200 !== (int) ( $response['code'] ?? 0 ) || empty( $data['status'] ) ) {
			$this->log( 'no se pudo consultar la orden: ' . $this->api_message( $response ) );

			return array();
		}

		if ( 'APPROVED' === strtoupper( (string) $data['status'] ) ) {
			$referencia = isset( $data['purchase_units'][0]['custom_id'] ) ? $data['purchase_units'][0]['custom_id'] : null;

			$captura = $this->request( 'POST', '/v2/checkout/orders/' . rawurlencode( $external_id ) . '/capture', new \stdClass(), $token );

			$codigo = (int) ( $captura['code'] ?? 0 );
			$cuerpo = is_array( $captura['body'] ?? null ) ? $captura['body'] : array();

			if ( 200 === $codigo || 201 === $codigo ) {
				$data = $cuerpo;

				// La respuesta de la captura no siempre repite el custom_id: sin
				// él, PaymentProcessor no puede verificar que el pago sea de este
				// pedido y no escribiría nada.
				if ( null !== $referencia && ! isset( $data['purchase_units'][0]['custom_id'] ) ) {
					$data['purchase_units'][0]['custom_id'] = $referencia;
				}
			} elseif ( 'ORDER_ALREADY_CAPTURED' === (string) ( $cuerpo['details'][0]['issue'] ?? '' ) ) {
				// Otro proceso lo capturó primero: se vuelve a consultar y listo.
				$response = $this->request( 'GET', '/v2/checkout/orders/' . rawurlencode( $external_id ), null, $token );
				$data     = is_array( $response['body'] ?? null ) ? $response['body'] : $data;
			} else {
				$this->log( 'no se pudo capturar la orden: ' . $this->api_message( $captura ) );
			}
		}

		return $this->normalize( $data );
	}

	/**
	 * {@inheritDoc}
	 */
	public function map_status( string $raw_status ): string {
		$raw = strtolower( trim( $raw_status ) );

		return isset( self::STATUS_MAP[ $raw ] ) ? self::STATUS_MAP[ $raw ] : '';
	}

	/**
	 * Cuerpo de la orden para la API. Separado de create_payment() para poder
	 * probarlo sin red: acá no se decide nada, solo se traduce el pedido.
	 *
	 * @param array  $order  Pedido tal como está en la base.
	 * @param string $amount Monto en USD con dos decimales.
	 * @return array
	 */
	public function build_order( array $order, string $amount ): array {
		return array(
			'intent'              => 'CAPTURE',
			'purchase_units'      => array(
				array(
					// El id del pedido viaja acá: es contra lo que se verifica el
					// pago al volver.
					'custom_id'   => (string) $order['id'],
					'description' => sprintf(
						/* translators: 1: servicio, 2: radio. */
						__( '%1$s — búsqueda a %2$s', 'simple-form' ),
						(string) $order['service_label'],
						self::format_radius( (float) $order['radius_km'] )
					),
					'amount'      => array(
						'currency_code' => self::CURRENCY,
						'value'         => $amount,
					),
				),
			),
			'application_context' => array(
				// Sin brand_name: PayPal muestra el nombre de la cuenta, y así la
				// orden no depende de opciones del sitio.
				'user_action'         => 'PAY_NOW',
				// Un pedido de búsqueda no tiene envío: sin esto PayPal pide
				// dirección de entrega.
				'shipping_preference' => 'NO_SHIPPING',
				'return_url'          => $this->return_url(),
				'cancel_url'          => $this->return_url(),
			),
		);
	}

	/**
	 * Convierte el monto del pedido (CLP) a USD con la tasa configurada.
	 *
	 * Devuelve '' si no hay tasa válida o si el equivalente queda por debajo del
	 * mínimo que acepta PayPal (0,01 USD).
	 *
	 * @param int $amount_clp
	 * @return string
	 */
	public function amount_usd( int $amount_clp ): string {
		if ( $this->rate <= 0 || $amount_clp <= 0 ) {
			return '';
		}

		$usd = round( $amount_clp / $this->rate, 2 );

		if ( $usd < 0.01 ) {
			return '';
		}

		return number_format( $usd, 2, '.', '' );
	}

	/**
	 * Token de acceso (Basic auth + client_credentials). '' si falla.
	 *
	 * @return string
	 */
	private function token(): string {
		$response = $this->request(
			'POST',
			'/v1/oauth2/token',
			array( 'grant_type' => 'client_credentials' ),
			null,
			array( 'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->client_secret ) ),
			true
		);

		$code = (int) ( $response['code'] ?? 0 );
		$data = is_array( $response['body'] ?? null ) ? $response['body'] : array();

		if ( 200 !== $code || empty( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
			$this->log( 'no se pudo obtener el token: ' . $this->api_message( $response ) );

			return '';
		}

		return $data['access_token'];
	}

	/**
	 * Petición a la API con el host según el modo (sandbox / producción).
	 *
	 * @param string     $method   GET|POST.
	 * @param string     $path     Ruta relativa.
	 * @param array|null $body     Cuerpo (null = sin cuerpo).
	 * @param string     $token    Bearer, o '' para usar el Basic de $extra.
	 * @param array      $extra    Headers adicionales.
	 * @param bool       $form     Enviar como formulario en vez de JSON.
	 * @return array{code:int, body:mixed}
	 */
	private function request( string $method, string $path, $body, $token, array $extra = array(), bool $form = false ): array {
		$headers = array_merge(
			array(
				'Accept'      => 'application/json',
				'Prefer'      => 'return=representation',
				'Cache-Control' => 'no-store',
			),
			$extra
		);

		if ( '' !== $token && null !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$args = array(
			'timeout' => self::TIMEOUT,
			'headers' => $headers,
		);

		if ( null !== $body ) {
			if ( $form ) {
				$args['body'] = $body;
			} else {
				$headers['Content-Type'] = 'application/json';
				$args['headers']         = $headers;
				$args['body']            = wp_json_encode( $body );
			}
		}

		$url = $this->base_url() . $path;

		$raw = ( 'GET' === $method )
			? wp_remote_get( $url, $args )
			: wp_remote_post( $url, $args );

		if ( is_wp_error( $raw ) ) {
			$this->log( 'la petición a la API falló: ' . $raw->get_error_message() );

			return array( 'code' => 0, 'body' => array() );
		}

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $raw ),
			'body' => json_decode( (string) wp_remote_retrieve_body( $raw ), true ),
		);
	}

	/**
	 * @return string
	 */
	private function base_url(): string {
		return PaymentConfig::MODE_PRODUCTION === PaymentConfig::mode( self::GATEWAY_ID )
			? self::API_LIVE
			: self::API_SANDBOX;
	}

	/**
	 * Arma la respuesta con lo que PaymentProcessor necesita: el estado y la
	 * referencia del pedido, sin importar dónde los ponga PayPal.
	 *
	 * @param array $data Orden de PayPal (consultada o capturada).
	 * @return array
	 */
	private function normalize( array $data ): array {
		$referencia = '';

		if ( isset( $data['purchase_units'][0]['custom_id'] ) ) {
			$referencia = (string) $data['purchase_units'][0]['custom_id'];
		}

		return array(
			'status'             => (string) ( $data['status'] ?? '' ),
			'external_reference' => $referencia,
			'id'                 => (string) ( $data['id'] ?? '' ),
		);
	}

	/**
	 * Enlace de la API por relación.
	 *
	 * @param array  $data
	 * @param string $rel
	 * @return string
	 */
	private function link( array $data, string $rel ): string {
		$links = isset( $data['links'] ) && is_array( $data['links'] ) ? $data['links'] : array();

		foreach ( $links as $link ) {
			if ( isset( $link['rel'], $link['href'] ) && $rel === $link['rel'] && is_string( $link['href'] ) ) {
				return $link['href'];
			}
		}

		return '';
	}

	/**
	 * URL de vuelta al formulario. El referer lo pone el navegador, así que se
	 * valida contra el sitio: nunca se devuelve al comprador a un host ajeno.
	 *
	 * @return string
	 */
	private function return_url(): string {
		$referer = wp_get_referer();
		$base    = $referer ? wp_validate_redirect( $referer, home_url( '/' ) ) : home_url( '/' );

		return add_query_arg( self::RETURN_ARG, 'paypal', $base );
	}

	/**
	 * Mensaje de error de la API, recortado y sin credenciales.
	 *
	 * @param array $response
	 * @return string
	 */
	private function api_message( array $response ): string {
		$data = is_array( $response['body'] ?? null ) ? $response['body'] : array();

		foreach ( array( 'message', 'error_description', 'error' ) as $clave ) {
			if ( isset( $data[ $clave ] ) && is_string( $data[ $clave ] ) ) {
				return substr( $data[ $clave ], 0, 200 );
			}
		}

		return 'sin detalle';
	}

	/**
	 * Radio legible: metros hasta 1 km, después km.
	 *
	 * @param float $km
	 * @return string
	 */
	private static function format_radius( float $km ): string {
		$metros = (int) round( $km * 1000 );

		if ( $metros < 1000 ) {
			return $metros . ' m';
		}

		return (int) round( $metros / 1000 ) . ' km';
	}

	/**
	 * Log de diagnóstico. Nunca escribe credenciales ni datos personales.
	 *
	 * @param string $message
	 */
	private function log( string $message ): void {
		Logger::log( 'paypal', $message );
	}
}
