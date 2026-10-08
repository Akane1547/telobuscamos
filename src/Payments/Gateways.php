<?php
/**
 * Registro de pasarelas de pago: id → etiqueta, clase y campos de credenciales.
 *
 * Es el único sitio donde se declara una pasarela. Lo lee la pantalla de pagos
 * para saber qué puede ofrecer y qué credenciales pedir, el shortcode para los
 * radios del Paso 3 y los endpoints para validar el medio elegido. Añadir una
 * pasarela es una entrada aquí más su clase (§14.3).
 *
 * @package SimpleForm\Payments
 */

namespace SimpleForm\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Gateways {

	/**
	 * Cada entrada: etiqueta visible, clase que la implementa y los campos de
	 * credenciales que el admin debe pedir (claves de texto; el modo
	 * sandbox/producción lo gestiona el admin para todas por igual).
	 *
	 * @var array
	 */
	const REGISTRY = array(
		'mercado_pago' => array(
			'label'       => 'Mercado Pago',
			'class'       => 'SimpleForm\Payments\MercadoPagoGateway',
			'credentials' => array( 'access_token', 'webhook_secret' ),
		),
	);

	/**
	 * IDs registrados, en el orden del registro.
	 *
	 * @return array
	 */
	public static function ids(): array {
		return array_keys( self::REGISTRY );
	}

	/**
	 * @param string $id
	 * @return bool
	 */
	public static function has( string $id ): bool {
		return isset( self::REGISTRY[ $id ] );
	}

	/**
	 * Etiqueta visible de una pasarela registrada.
	 *
	 * @param string $id
	 * @return string Vacío si no está registrada.
	 */
	public static function label( string $id ): string {
		return self::has( $id ) ? (string) self::REGISTRY[ $id ]['label'] : '';
	}

	/**
	 * Clase que implementa la pasarela. Se devuelve el nombre, no una instancia:
	 * la Fase 4 es quien la construye.
	 *
	 * @param string $id
	 * @return string Vacío si no está registrada.
	 */
	public static function gateway_class( string $id ): string {
		return self::has( $id ) ? (string) self::REGISTRY[ $id ]['class'] : '';
	}

	/**
	 * Campos de credenciales que hay que pedir para una pasarela.
	 *
	 * @param string $id
	 * @return array
	 */
	public static function credential_fields( string $id ): array {
		if ( ! self::has( $id ) || ! is_array( self::REGISTRY[ $id ]['credentials'] ) ) {
			return array();
		}

		return array_values( self::REGISTRY[ $id ]['credentials'] );
	}
}
