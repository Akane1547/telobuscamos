<?php
/**
 * Acceso a wp_sf_orders.
 *
 * Aquí vive todo lo que sabe leer y escribir un pedido, incluida la
 * verificación de qué pasos están completos y la conversión de una fila al
 * snapshot que ya consume el front (stepper.js, ui.js, receipt.js).
 *
 * @package SimpleForm\Database
 */

namespace SimpleForm\Database;

use SimpleForm\Payments\OrderStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderRepository {

	/**
	 * Crea el pedido en borrador con los datos del paso 1 ya validados.
	 *
	 * Nace en current_step 2 a propósito: solo se llega aquí después de que
	 * el paso 1 pasó la validación del servidor.
	 *
	 * @param array $customer name, email, phone, description.
	 * @return string draft_id, o '' si la inserción falló.
	 */
	public static function create_draft( array $customer ): string {
		global $wpdb;

		$draft_id = wp_generate_uuid4();
		$now      = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			Database::table_name( Database::TABLE_ORDERS ),
			array(
				'draft_id'     => $draft_id,
				'full_name'    => (string) $customer['name'],
				'email'        => (string) $customer['email'],
				'phone'        => (string) $customer['phone'],
				'description'  => (string) $customer['description'],
				'status'       => OrderStatus::DRAFT,
				'current_step' => 2,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return '';
		}

		return $draft_id;
	}

	/**
	 * Busca un pedido por su draft_id.
	 *
	 * @param string $draft_id
	 * @return array|null Fila asociativa, o null.
	 */
	public static function find_by_draft_id( string $draft_id ): ?array {
		global $wpdb;

		if ( '' === $draft_id ) {
			return null;
		}

		$table = Database::table_name( Database::TABLE_ORDERS );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE draft_id = %s LIMIT 1", $draft_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Actualiza columnas de un pedido y refresca updated_at.
	 *
	 * @param int   $order_id
	 * @param array $fields Columna => valor.
	 * @return bool
	 */
	public static function update( int $order_id, array $fields ): bool {
		global $wpdb;

		if ( $order_id <= 0 || empty( $fields ) ) {
			return false;
		}

		$fields['updated_at'] = current_time( 'mysql', true );

		$updated = $wpdb->update(
			Database::table_name( Database::TABLE_ORDERS ),
			$fields,
			array( 'id' => $order_id )
		);

		return false !== $updated;
	}

	/**
	 * Indica si el pedido ya tiene guardados los datos de ese paso.
	 *
	 * Es la validación de "pasos anteriores" contra la base de datos: sustituye
	 * a los antiguos stepN_valid que vivían en el transient.
	 *
	 * @param array $order
	 * @param int   $step
	 * @return bool
	 */
	public static function is_step_complete( array $order, int $step ): bool {
		if ( 1 === $step ) {
			return '' !== (string) $order['full_name']
				&& '' !== (string) $order['email']
				&& '' !== (string) $order['phone'];
		}

		if ( 2 === $step ) {
			return '' !== (string) $order['service_id']
				&& null !== $order['lat']
				&& null !== $order['lng']
				&& (int) $order['radius_km'] >= 1;
		}

		return false;
	}

	/**
	 * Convierte una fila en el snapshot progress que espera el front.
	 *
	 * Los shapes de step1/step2/step3 son un contrato con stepper.js, ui.js y
	 * receipt.js: no se cambian aquí sin actualizarlos. Un paso sin completar
	 * viaja como null, para que fillStepData() no borre campos del formulario.
	 *
	 * @param array $order
	 * @return array current_step, step1, step2, step3.
	 */
	public static function to_progress( array $order ): array {
		$step1 = null;
		$step2 = null;
		$step3 = null;

		if ( self::is_step_complete( $order, 1 ) ) {
			$step1 = array(
				'name'        => (string) $order['full_name'],
				'email'       => (string) $order['email'],
				'phone'       => (string) $order['phone'],
				'description' => (string) $order['description'],
			);
		}

		if ( self::is_step_complete( $order, 2 ) ) {
			$step2 = array(
				'service_id'      => (string) $order['service_id'],
				'service_label'   => (string) $order['service_label'],
				'lat'             => (float) $order['lat'],
				'lng'             => (float) $order['lng'],
				'radius_km'       => (int) $order['radius_km'],
				'estimated_price' => (int) $order['amount'],
			);
		}

		if ( '' !== (string) $order['payment_method'] ) {
			$step3 = array(
				'payment_method' => (string) $order['payment_method'],
				'final_price'    => (int) $order['amount'],
			);
		}

		return array(
			'current_step' => max( 1, min( 4, (int) $order['current_step'] ) ),
			'step1'        => $step1,
			'step2'        => $step2,
			'step3'        => $step3,
		);
	}
}
