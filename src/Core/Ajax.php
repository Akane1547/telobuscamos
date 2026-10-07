<?php
/**
 * Controlador AJAX para SimpleForm.
 *
 * @package SimpleForm\Core
 */

namespace SimpleForm\Core;

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
	 * Única fórmula de precio del plugin.
	 */
	private function compute_price( array $service, int $radius_km ): float {
		return (float) ( $service['base_price'] ?? 0 ) + ( (float) ( $service['price_per_km'] ?? 0 ) * $radius_km );
	}

	/**
	 * Obtiene el progreso desde el transient mediante Session::get() o crea uno nuevo.
	 */
	public function get_progress(): void {
		$this->verify_request_nonce();

		$raw_session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		$session_id     = Session::sanitize_id( $raw_session_id );

		$session_data = ! empty( $session_id ) ? Session::get( $session_id ) : null;

		if ( ! $session_data ) {
			wp_send_json_error( array( 'message' => 'No hay sesión guardada.' ), 404 );
		}

		$current_step = min( 4, max( 1, (int) ( $session_data['current_step'] ?? 1 ) ) );
		wp_send_json_success(
			array(
				'session_id' => $session_id,
				'progress'   => array(
					'current_step' => $current_step,
					'step1'        => $session_data['step1'] ?? null,
					'step2'        => $session_data['step2'] ?? null,
					'step3'        => $session_data['step3'] ?? null,
				),
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
			wp_send_json_error( array( 'message' => 'Datos inválidos para calcular el precio.' ), 422 );
		}

		if ( ! $this->is_within_coverage( $coords ) ) {
			wp_send_json_error( array( 'message' => __( 'La ubicación está fuera del área de cobertura.', 'simple-form' ) ), 422 );
		}

		$service = $this->get_service_by_id( $service_id );

		if ( null === $service ) {
			wp_send_json_error( array( 'message' => 'Servicio no disponible.' ), 404 );
		}

		wp_send_json_success(
			array( 'price' => $this->compute_price( $service, $radius_km ) )
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
		 * Guarda el Paso 1 en el transient mediante Session::save_step().
		 */
	public function save_step1(): void {
		$this->verify_request_nonce();

		$raw_session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		$session_id     = Session::sanitize_id( $raw_session_id );

		$name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );

		if ( empty( $name ) || ! is_email( $email ) || empty( $phone ) ) {
			wp_send_json_error( array( 'message' => 'Por favor completa todos los campos requeridos.' ), 422 );
		}

		// El transient se crea recién con la entrada ya validada.
		if ( empty( $session_id ) || null === Session::get( $session_id ) ) {
			$session_id = Session::create();
		}

		$payload = array(
			'name'        => $name,
			'email'       => $email,
			'phone'       => $phone,
			'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
		);

		// 1. Guardar en el Transient
		$updated_data = Session::save_step( $session_id, 1, $payload );

		if ( null === $updated_data ) {
			wp_send_json_error( array( 'message' => 'Error al guardar en el transient.' ), 500 );
		}

		// 2. Responder con el JSON estructurado para stepper.js
		wp_send_json_success(
			array(
				'message'    => 'Paso 1 guardado correctamente.',
				'session_id' => $session_id,
				'progress'   => array(
					'current_step' => 2,
					'step1'        => $payload,
				),
			)
		);
	}

	public function save_step2(): void {
		$this->verify_request_nonce();

		$raw_session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		$session_id     = Session::sanitize_id( $raw_session_id );

		if ( empty( $session_id ) ) {
			wp_send_json_error( array( 'message' => 'Sesión no válida.' ), 400 );
		}

		$session = Session::get( $session_id );

		if ( null === $session ) {
			wp_send_json_error( array( 'message' => __( 'Sesión expirada. Recarga el formulario.', 'simple-form' ) ), 410 );
		}

		if ( ! Session::previous_steps_valid( $session, 2 ) ) {
			wp_send_json_error( array( 'message' => __( 'Debes completar el paso 1 antes de continuar.', 'simple-form' ) ), 403 );
		}


		$service_id = sanitize_text_field( wp_unslash( $_POST['service_id'] ?? '' ) );
		$coords     = $this->read_coordinates();
		$radius_km  = $this->read_radius_km();


		if ( empty( $service_id ) || null === $coords || 0 === $radius_km ) {
			wp_send_json_error( array( 'message' => 'Faltan datos de ubicación o servicio.' ), 422 );
		}

		if ( ! $this->is_within_coverage( $coords ) ) {
			wp_send_json_error( array( 'message' => __( 'La ubicación está fuera del área de cobertura.', 'simple-form' ) ), 422 );
		}


		$service = $this->get_service_by_id( $service_id );

		if ( null === $service ) {
			wp_send_json_error( array( 'message' => 'Servicio no disponible.' ), 404 );
		}

		$payload = array(
			'service_id'      => $service_id,
			'service_label'   => sanitize_text_field( $service['label'] ?? $service['id'] ),
			'lat'             => $coords[0],
			'lng'             => $coords[1],
			'radius_km'       => $radius_km,
			'estimated_price' => $this->compute_price( $service, $radius_km ),
		);

		// Guardar en el Transient
		$updated_data = Session::save_step( $session_id, 2, $payload );

		if ( null === $updated_data ) {
			wp_send_json_error( array( 'message' => 'Sesión expirada. Recarga el formulario.' ), 410 );
		}

		wp_send_json_success(
			array(
				'message'    => 'Paso 2 guardado correctamente.',
				'session_id' => $session_id,
				'progress'   => array(
					'current_step' => 3,
					'step2'        => $payload,
				),
			)
		);
	}

	/**
	 * Guarda el Paso 3 y realiza la verificación final del transient.
	 */
	public function save_step3(): void {
		$this->verify_request_nonce();

		$raw_session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		$session_id     = Session::sanitize_id( $raw_session_id );
		$session        = ! empty( $session_id ) ? Session::get( $session_id ) : null;

		if ( null === $session ) {
			wp_send_json_error( array( 'message' => 'Sesión no encontrada o expirada.' ), 400 );
		}

		if ( ! Session::previous_steps_valid( $session, 3 ) ) {
			wp_send_json_error( array( 'message' => 'Debes completar los pasos 1 y 2 antes de finalizar.' ), 403 );
		}

		$service_id = $session['step2']['service_id'] ?? '';
		$radius_km  = (int) ( $session['step2']['radius_km'] ?? 0 );

		$service = $this->get_service_by_id( $service_id );

		if ( null === $service ) {
			wp_send_json_error( array( 'message' => 'El servicio guardado ya no se encuentra disponible.' ), 409 );
		}

		$final_price = $this->compute_price( $service, $radius_km );
		
		$payload = array(
			'payment_method' => sanitize_text_field( wp_unslash( $_POST['payment_method'] ?? 'mercadopago' ) ),
			'final_price'    => $final_price,
		);

		$updated = Session::save_step( $session_id, 3, $payload );

		if ( null === $updated ) {
			wp_send_json_error( array( 'message' => 'No se pudo guardar el paso 3.' ), 500 );
		}

		wp_send_json_success(
			array(
				'message'    => 'Formulario completado exitosamente.',
				'session_id' => $session_id,
				'progress'   => array(
					'current_step' => 4,
					'step1'        => $updated['step1'] ?? null,
					'step2'        => $updated['step2'] ?? null,
					'step3'        => $payload,
				),
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