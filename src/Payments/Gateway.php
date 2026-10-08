<?php
/**
 * Contrato de una pasarela de pago.
 *
 * Cada pasarela traduce su vocabulario al nuestro y nada más: crear el pago y
 * devolver a dónde mandar al comprador, consultar el pago real y traducir su
 * estado. El flujo de pasos, el pedido y la máquina de estados no se tocan al
 * agregar un medio nuevo.
 *
 * Convención: una pasarela se instancia sin argumentos y resuelve su propia
 * configuración. Así el registro puede hacer `new $clase()` sin saber nada de
 * ninguna pasarela en particular.
 *
 * @package SimpleForm\Payments
 */

namespace SimpleForm\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Gateway {

	/**
	 * Crea el pago en la pasarela y devuelve la URL a la que se redirige al
	 * comprador.
	 *
	 * El monto ya viene recalculado por el servidor en el pedido: la pasarela no
	 * lo recalcula ni lo recibe del cliente.
	 *
	 * @param array $order Pedido tal como está en la base.
	 * @return string URL de redirección, o '' si no se pudo crear.
	 */
	public function create_payment( array $order ): string;

	/**
	 * Consulta el pago en la pasarela.
	 *
	 * Es la única fuente válida del estado: ni la URL de retorno ni el cuerpo
	 * del webhook alcanzan por sí solos.
	 *
	 * @param string $external_id Identificador del pago en la pasarela.
	 * @return array Respuesta de la pasarela, o array() si no se pudo obtener.
	 */
	public function fetch_payment( string $external_id ): array;

	/**
	 * Traduce un estado externo al conjunto interno de OrderStatus.
	 *
	 * @param string $raw_status Estado tal como lo reporta la pasarela.
	 * @return string Estado interno, o '' si no hay equivalente (y entonces el
	 *                pedido no debe cambiar de estado).
	 */
	public function map_status( string $raw_status ): string;
}
