<?php
/**
 * Medios de pago del sitio: las pasarelas registradas en el código más los
 * medios propios que el administrador agrega con un nombre.
 *
 * Un medio propio no cobra online: el cliente elige, el pedido queda pendiente y
 * el administrador confirma cuando recibió el dinero (transferencia, efectivo,
 * etc.). Por eso guarda un "cómo pagar" que ve el cliente en el recibo, en vez
 * de credenciales.
 *
 *   simple_form_custom_methods = array(
 *       array( 'id' => 'transferencia', 'label' => 'Transferencia bancaria', 'note' => 'Banco …' ),
 *   );
 *
 * El id sale del nombre la primera vez y **no cambia** si después se edita el
 * nombre: es lo que queda guardado en los pedidos.
 *
 * @package SimpleForm\Payments
 */

namespace SimpleForm\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Methods {

	const OPTION_CUSTOM = 'simple_form_custom_methods';

	/** Tope de medios propios. La opción se autoloada en cada petición. */
	const MAX_CUSTOM = 20;

	/**
	 * Medios propios guardados, ya saneados.
	 *
	 * @return array<int, array{id:string,label:string,note:string}>
	 */
	public static function custom(): array {
		$raw = get_option( self::OPTION_CUSTOM, array() );

		return self::normalize( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * IDs de los medios propios.
	 *
	 * @return array
	 */
	public static function custom_ids(): array {
		return array_column( self::custom(), 'id' );
	}

	/**
	 * Guarda los medios propios. Los ids existentes se conservan aunque cambie
	 * el nombre; los nuevos lo derivan del nombre.
	 *
	 * @param array $rows Filas con id (opcional), label y note.
	 * @return array Los medios guardados.
	 */
	public static function save_custom( array $rows ): array {
		$limpios = self::normalize( $rows );

		update_option( self::OPTION_CUSTOM, $limpios );

		return $limpios;
	}

	/**
	 * ¿El id corresponde a un medio que existe (pasarela registrada o propio)?
	 *
	 * @param string $id
	 * @return bool
	 */
	public static function has( string $id ): bool {
		return Gateways::has( $id ) || in_array( $id, self::custom_ids(), true );
	}

	/**
	 * @param string $id
	 * @return bool
	 */
	public static function is_gateway( string $id ): bool {
		return Gateways::has( $id );
	}

	/**
	 * Un medio propio: no cobra online, el pago se confirma a mano.
	 *
	 * @param string $id
	 * @return bool
	 */
	public static function is_manual( string $id ): bool {
		return self::has( $id ) && ! Gateways::has( $id );
	}

	/**
	 * Nombre visible.
	 *
	 * @param string $id
	 * @return string Vacío si el medio no existe.
	 */
	public static function label( string $id ): string {
		if ( Gateways::has( $id ) ) {
			return Gateways::label( $id );
		}

		foreach ( self::custom() as $method ) {
			if ( $method['id'] === $id ) {
				return $method['label'];
			}
		}

		return '';
	}

	/**
	 * "Cómo pagar" de un medio propio (lo que ve el cliente en el recibo).
	 *
	 * @param string $id
	 * @return string Vacío si el medio no existe o es una pasarela.
	 */
	public static function note( string $id ): string {
		if ( Gateways::has( $id ) ) {
			return '';
		}

		foreach ( self::custom() as $method ) {
			if ( $method['id'] === $id ) {
				return $method['note'];
			}
		}

		return '';
	}

	/**
	 * Todos los medios: los del registro y los propios.
	 *
	 * @return array<string, array{label:string,manual:bool}>
	 */
	public static function all(): array {
		$todos = array();

		foreach ( Gateways::ids() as $id ) {
			$todos[ $id ] = array(
				'label'  => Gateways::label( $id ),
				'manual' => false,
			);
		}

		foreach ( self::custom() as $method ) {
			if ( isset( $todos[ $method['id'] ] ) ) {
				continue;
			}

			$todos[ $method['id'] ] = array(
				'label'  => $method['label'],
				'manual' => true,
			);
		}

		return $todos;
	}

	/**
	 * Sanea y deduplica. El id se deriva del nombre solo cuando la fila todavía
	 * no tiene uno (fila nueva).
	 *
	 * @param array $rows
	 * @return array<int, array{id:string,label:string,note:string}>
	 */
	public static function normalize( array $rows ): array {
		$limpios     = array();
		$ids_propios = array(); // ids ya tomados por medios propios
		$ids_pasarela = Gateways::ids();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			// is_string en label/note: nunca se confía en el tipo que llega del POST.
			$label = isset( $row['label'] ) && is_string( $row['label'] ) ? trim( $row['label'] ) : '';
			$note  = isset( $row['note'] ) && is_string( $row['note'] ) ? trim( $row['note'] ) : '';

			$id = isset( $row['id'] ) && is_string( $row['id'] ) ? sanitize_key( $row['id'] ) : '';

			if ( '' === $id ) {
				$id = sanitize_title( $label );
			}

			$id = sanitize_key( $id );

			if ( '' === $id || '' === $label ) {
				continue;
			}

			// Un medio propio no puede pisar ni duplicar una pasarela registrada:
			// ni por identificador (mercado-pago / mercado_pago) ni por nombre
			// visible ("Mercado Pago" otra vez).
			$id_canonico = str_replace( '-', '_', $id );

			if ( in_array( $id, $ids_pasarela, true ) || in_array( $id_canonico, $ids_pasarela, true ) ) {
				continue;
			}

			$duplica_nombre = false;

			foreach ( $ids_pasarela as $gateway_id ) {
				if ( sanitize_title( Gateways::label( $gateway_id ) ) === sanitize_title( $label ) ) {
					$duplica_nombre = true;
					break;
				}
			}

			if ( $duplica_nombre ) {
				continue;
			}

			if ( in_array( $id, $ids_propios, true ) ) {
				continue;
			}

			$ids_propios[] = $id;
			$limpios[]     = array(
				'id'    => $id,
				'label' => $label,
				'note'  => $note,
			);

			if ( count( $limpios ) >= self::MAX_CUSTOM ) {
				break;
			}
		}

		return $limpios;
	}
}
