<?php
/**
 * Controlador AJAX para SimpleForm.
 *
 * @package SimpleForm\Core
 */

namespace SimpleForm\Core;

use SimpleForm\Database\OrderRepository;
use SimpleForm\Payments\OrderStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ajax {

	const COVERAGE_LAT       = -33.4489;
	const COVERAGE_LNG       = -70.6693;
	const COVERAGE_RADIUS_KM = 50;


	public function __construct() {
		add_action( 'wp_ajax_simple_form_get_progress', array( $this, 'get_progress' ) );
		add_action( 'wp_ajax_nopriv_simple_form_get_progress', array( $this, 'get_progress' ) );

		add_action( 'wp_ajax_simple_form_save_step1', array( $this, 'save_step1' ) );
		add_action( 'wp_ajax_nopriv_simple_form_save_step1', array( $this, 'save_step1' ) );

		add_action( 'wp_ajax_simple_form_save_step2', array( $this, 'save_step2' ) );
		add_action( 'wp_ajax_nopriv_simple_form_save_step2', array( $this, 'save_step2' ) );

		add_action( 'wp_ajax_simple_form_save_step3', array( $this, 'save_step3' ) );
		add_action( 'wp_ajax_nopriv_simple_form_save_step3', array( $this, 'save_step3' ) );

		add_action( 'wp_ajax_simple_form_calculate_price', array( $this, 'calculate_price' ) );
		add_action( 'wp_ajax_nopriv_simple_form_calculate_price', array( $this, 'calculate_price' ) );

		add_action( 'wp_ajax_simple_form_get_coverage_area', array( $this, 'get_coverage_area' ) );
		add_action( 'wp_ajax_nopriv_simple_form_get_coverage_area', array( $this, 'get_coverage_area' ) );



	}

	/**
	 * Valida la seguridad de la petición mediante Nonce.
	 */
	private function verify_request_nonce(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( empty( $nonce ) && isset( $_POST['_ajax_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['_ajax_nonce'] ) );
		}

		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'simple_form_nonce' ) ) {
			wp_send_json_error(
				array( 'message' => 'Validación de seguridad fallida (Nonce inválido).' ),
				403
			);
		}
	}

	/**
	 * Busca un servicio en la lista de wp_options sin alterar la estructura que espera tu JS.
	 */
	private function get_service_by_id( string $service_id ): ?array {
		$all_services = get_option( \SimpleForm\Admin\AdminServices::OPTION_KEY, array() );

		if ( empty( $all_services ) || ! is_array( $all_services ) ) {
			return null;
		}

		foreach ( $all_services as $service ) {
			if ( isset( $service['id'] ) && $service['id'] === $service_id ) {
				return $service;
			}
		}

		return null;
	}

		/**
	 * Lee y valida lat/lng del POST. Devuelve [lat, lng] o null.
	 */
	private function read_coordinates(): ?array {
		$raw_lat = isset( $_POST['lat'] ) ? wp_unslash( $_POST['lat'] ) : '';
		$raw_lng = isset( $_POST['lng'] ) ? wp_unslash( $_POST['lng'] ) : '';

		// Sin is_numeric() un valor no numérico se castea a 0.0 y entra como coordenada válida.
		if ( ! is_numeric( $raw_lat ) || ! is_numeric( $raw_lng ) ) {
			return null;
		}

		$lat = (float) $raw_lat;
		$lng = (float) $raw_lng;

		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return null;
		}

		return array( $lat, $lng );
	}

	/**
	 * Lee y valida el radio en km (1 a 20).
	 */
	private function read_radius_km(): int {
		$raw_radius = isset( $_POST['radius_km'] ) ? wp_unslash( $_POST['radius_km'] ) : '';

		if ( ! is_numeric( $raw_radius ) ) {
			return 0;
		}

		$radius = (int) $raw_radius;

		return ( $radius >= 1 && $radius <= 20 ) ? $radius : 0;
	}

	/**
	 * Precios del servicio en CLP entero.
	 *
	 * La opción los guarda como float, pero el dinero del plugin es entero
	 * (souls.md §8.3). Se redondea una sola vez, aquí: ese valor redondeado es
	 * el que se calcula y el que se guarda como snapshot en el pedido.
	 *
	 * @param array $service
	 * @return array [ precio base, precio por km ].
	 */
	private function service_prices( array $service ): array {
		return array(
			(int) round( (float) ( $service['base_price'] ?? 0 ) ),
			(int) round( (float) ( $service['price_per_km'] ?? 0 ) ),
		);
	}

	/**
	 * Única fórmula de precio del plugin. CLP entero, nunca float.
	 */
	private function compute_price( int $base_price, int $price_per_km, int $radius_km ): int {
		return $base_price + ( $price_per_km * $radius_km );
	}

	/**
	 * Lee y sanitiza el id de sesión del POST.
	 */
	private function read_session_id(): string {
		$raw = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';

		return Session::sanitize_id( $raw );
	}

	/**
	 * Resuelve la sesión a su pedido: el puntero del transient y la fila.
	 *
	 * @param string $session_id
	 * @return array|null
	 */
	private function find_order_by_session( string $session_id ): ?array {
		if ( '' === $session_id ) {
			return null;
		}

		$draft_id = Session::find_draft_id( $session_id );

		if ( null === $draft_id ) {
			return null;
		}

		return OrderRepository::find_by_draft_id( $draft_id );
	}

	/**
	 * Obtiene el progreso leyendo el pedido persistido.
	 */
	public function get_progress(): void {
		$this->verify_request_nonce();

		$session_id = $this->read_session_id();
		$order      = $this->find_order_by_session( $session_id );

		if ( null === $order ) {
			wp_send_json_error( array( 'message' => __( 'No hay sesión guardada.', 'simple-form' ) ), 404 );
		}

		wp_send_json_success(
			array(
				'session_id' => $session_id,
				'progress'   => OrderRepository::to_progress( $order ),
			)
		);
	}

		/**
	 * Calcula el precio en servidor. Fuente de verdad del monto.
	 */
	public function calculate_price(): void {
		$this->verify_request_nonce();

		$service_id = sanitize_text_field( wp_unslash( $_POST['service_id'] ?? '' ) );
		$coords     = $this->read_coordinates();
		$radius_km  = $this->read_radius_km();

		if ( empty( $service_id ) || null === $coords || 0 === $radius_km ) {
			wp_send_json_error( array( 'message' => __( 'Datos inválidos para calcular el precio.', 'simple-form' ) ), 422 );
		}

		if ( ! $this->is_within_coverage( $coords ) ) {
			wp_send_json_error( array( 'message' => __( 'La ubicación está fuera del área de cobertura.', 'simple-form' ) ), 422 );
		}

		$service = $this->get_service_by_id( $service_id );

		if ( null === $service ) {
			wp_send_json_error( array( 'message' => __( 'Servicio no disponible.', 'simple-form' ) ), 404 );
		}

		list( $base_price, $price_per_km ) = $this->service_prices( $service );

		wp_send_json_success(
			array( 'price' => $this->compute_price( $base_price, $price_per_km, $radius_km ) )
		);
	}

	/**
	 * Devuelve el área de cobertura para pintar en el mapa.
	 * Por ahora es un círculo fijo; se puede cambiar por polígono sin tocar el JS del formulario.
	 */
	public function get_coverage_area(): void {
		$this->verify_request_nonce();

		wp_send_json_success(
			array(
				'type'     => 'circle',
				'center'   => array(
					'lat' => self::COVERAGE_LAT,
					'lng' => self::COVERAGE_LNG,
				),
				'radius_m' => self::COVERAGE_RADIUS_KM * 1000,
			)
		);
	}

	/**
	 * Guarda el Paso 1 en el pedido persistido.
	 */
	public function save_step1(): void {
		$this->verify_request_nonce();

		$session_id = $this->read_session_id();

		$name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );

		if ( '' === $name || ! is_email( $email ) || '' === $phone ) {
			wp_send_json_error( array( 'message' => __( 'Por favor completa todos los campos requeridos.', 'simple-form' ) ), 422 );
		}

		$customer = array(
			'name'        => $name,
			'email'       => $email,
			'phone'       => $phone,
			'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
		);

		$order = $this->find_order_by_session( $session_id );

		if ( null !== $order ) {
			// Mismo pedido: se reescribe el paso 1, sin crear filas nuevas.
			OrderRepository::update(
				(int) $order['id'],
				array(
					'full_name'    => $customer['name'],
					'email'        => $customer['email'],
					'phone'        => $customer['phone'],
					'description'  => $customer['description'],
					'current_step' => max( 2, (int) $order['current_step'] ),
				)
			);

			Session::refresh( $session_id );
		} else {
			// El pedido se crea recién con la entrada ya validada (B-06).
			$draft_id = OrderRepository::create_draft( $customer );

			if ( '' === $draft_id ) {
				wp_send_json_error( array( 'message' => __( 'No se pudo crear el pedido.', 'simple-form' ) ), 500 );
			}

			$session_id = Session::create( $draft_id );
		}

		$order = $this->find_order_by_session( $session_id );

		if ( null === $order ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo guardar el pedido.', 'simple-form' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'message'    => __( 'Paso 1 guardado correctamente.', 'simple-form' ),
				'session_id' => $session_id,
				'progress'   => OrderRepository::to_progress( $order ),
			)
		);
	}

	public function save_step2(): void {
		$this->verify_request_nonce();

		$session_id = $this->read_session_id();
		$order      = $this->find_order_by_session( $session_id );

		if ( null === $order ) {
			wp_send_json_error( array( 'message' => __( 'Sesión expirada. Recarga el formulario.', 'simple-form' ) ), 410 );
		}

		if ( ! OrderRepository::is_step_complete( $order, 1 ) ) {
			wp_send_json_error( array( 'message' => __( 'Debes completar el paso 1 antes de continuar.', 'simple-form' ) ), 403 );
		}


		$service_id = sanitize_text_field( wp_unslash( $_POST['service_id'] ?? '' ) );
		$coords     = $this->read_coordinates();
		$radius_km  = $this->read_radius_km();


		if ( empty( $service_id ) || null === $coords || 0 === $radius_km ) {
			wp_send_json_error( array( 'message' => __( 'Faltan datos de ubicación o servicio.', 'simple-form' ) ), 422 );
		}

		if ( ! $this->is_within_coverage( $coords ) ) {
			wp_send_json_error( array( 'message' => __( 'La ubicación está fuera del área de cobertura.', 'simple-form' ) ), 422 );
		}


		$service = $this->get_service_by_id( $service_id );

		if ( null === $service ) {
			wp_send_json_error( array( 'message' => __( 'Servicio no disponible.', 'simple-form' ) ), 404 );
		}

		list( $base_price, $price_per_km ) = $this->service_prices( $service );

		// Snapshot del servicio y de sus precios: editar el servicio después no
		// puede reescribir este pedido. El monto lo calcula el servidor.
		OrderRepository::update(
			(int) $order['id'],
			array(
				'service_id'           => $service_id,
				'service_label'        => sanitize_text_field( $service['label'] ?? $service['id'] ),
				'service_base_price'   => $base_price,
				'service_price_per_km' => $price_per_km,
				'lat'                  => $coords[0],
				'lng'                  => $coords[1],
				'radius_km'            => $radius_km,
				'amount'               => $this->compute_price( $base_price, $price_per_km, $radius_km ),
				'current_step'         => max( 3, (int) $order['current_step'] ),
			)
		);

		Session::refresh( $session_id );

		$order = $this->find_order_by_session( $session_id );

		if ( null === $order ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo guardar el pedido.', 'simple-form' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'message'    => __( 'Paso 2 guardado correctamente.', 'simple-form' ),
				'session_id' => $session_id,
				'progress'   => OrderRepository::to_progress( $order ),
			)
		);
	}

	/**
	 * Guarda el Paso 3: valida el medio de pago y deja el pedido en pending.
	 *
	 * Nunca marca el pedido como pagado: eso solo lo hace la pasarela.
	 */
	public function save_step3(): void {
		$this->verify_request_nonce();

		$session_id = $this->read_session_id();
		$order      = $this->find_order_by_session( $session_id );

		if ( null === $order ) {
			wp_send_json_error( array( 'message' => __( 'Sesión no encontrada o expirada.', 'simple-form' ) ), 410 );
		}

		if ( ! OrderRepository::is_step_complete( $order, 1 ) || ! OrderRepository::is_step_complete( $order, 2 ) ) {
			wp_send_json_error( array( 'message' => __( 'Debes completar los pasos 1 y 2 antes de finalizar.', 'simple-form' ) ), 403 );
		}

		$payment_method = isset( $_POST['payment_method'] ) ? sanitize_key( wp_unslash( $_POST['payment_method'] ) ) : '';

		if ( '' === $payment_method ) {
			wp_send_json_error( array( 'message' => __( 'Selecciona un medio de pago.', 'simple-form' ) ), 422 );
		}

		// El monto se recalcula con el snapshot guardado, nunca con lo que envíe el cliente.
		$amount = $this->compute_price(
			(int) $order['service_base_price'],
			(int) $order['service_price_per_km'],
			(int) $order['radius_km']
		);

		$fields = array(
			'payment_method' => $payment_method,
			'amount'         => $amount,
			'current_step'   => 4,
		);

		// draft -> pending y rejected -> pending (reintento). approved nunca
		// vuelve a pending: eso lo decide OrderStatus, no este endpoint.
		if ( OrderStatus::PENDING !== $order['status'] ) {
			if ( ! OrderStatus::can_transition( (string) $order['status'], OrderStatus::PENDING ) ) {
				wp_send_json_error( array( 'message' => __( 'El pedido ya no admite un nuevo pago.', 'simple-form' ) ), 409 );
			}

			$fields['status'] = OrderStatus::PENDING;
		}

		if ( ! OrderRepository::update( (int) $order['id'], $fields ) ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo guardar el paso 3.', 'simple-form' ) ), 500 );
		}

		Session::refresh( $session_id );

		$order = $this->find_order_by_session( $session_id );

		if ( null === $order ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo guardar el pedido.', 'simple-form' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'message'    => __( 'Formulario completado exitosamente.', 'simple-form' ),
				'session_id' => $session_id,
				'progress'   => OrderRepository::to_progress( $order ),
			)
		);
	}

	private function distance_km( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$d_lat = deg2rad( $lat2 - $lat1 );
		$d_lng = deg2rad( $lng2 - $lng1 );

		$a = sin( $d_lat / 2 ) ** 2
			+ cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $d_lng / 2 ) ** 2;

		return 6371 * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	private function is_within_coverage( array $coords ): bool {
		return $this->distance_km( self::COVERAGE_LAT, self::COVERAGE_LNG, $coords[0], $coords[1] ) <= self::COVERAGE_RADIUS_KM;
	}
}