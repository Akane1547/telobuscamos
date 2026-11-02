<?php
/**
 * Catálogos del objeto que se busca: tipo, tamaño y material.
 *
 * Una sola fuente de verdad para las tres listas. Las edita el administrador en
 * wp-admin y el formulario las muestra en el Paso 1, pero el navegador nunca
 * manda texto libre: manda el **id** de una entrada de la lista, y el servidor
 * comprueba que exista antes de guardarlo.
 *
 * @package SimpleForm\Core
 */

namespace SimpleForm\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Catalog
 */
final class Catalog {

	/**
	 * Opción con las tres listas.
	 */
	const OPTION = 'simple_form_catalogs';

	/**
	 * Las tres listas, en el orden en que se piden en el formulario.
	 */
	const KINDS = array( 'types', 'sizes', 'materials' );

	/**
	 * Nombre visible de cada lista.
	 */
	const KIND_LABELS = array(
		'types'     => 'Tipo de objeto',
		'sizes'     => 'Tamaño del objeto',
		'materials' => 'Material del objeto',
	);

	/**
	 * Columna del pedido y campo del formulario de cada lista.
	 */
	const FIELDS = array(
		'types'     => 'object_type',
		'sizes'     => 'object_size',
		'materials' => 'object_material',
	);

	/**
	 * Tope de entradas por lista: la opción se autoloada en cada petición.
	 */
	const MAX_ITEMS = 60;

	/**
	 * Las tres listas normalizadas.
	 *
	 * @return array<string, array<int, array{id:string,label:string}>>
	 */
	public static function all(): array {
		$guardado = get_option( self::OPTION, array() );
		$guardado = is_array( $guardado ) ? $guardado : array();
		$salida   = array();

		foreach ( self::KINDS as $kind ) {
			$lista = isset( $guardado[ $kind ] ) && is_array( $guardado[ $kind ] ) ? $guardado[ $kind ] : array();
			$salida[ $kind ] = self::normalize( $lista );
		}

		return $salida;
	}

	/**
	 * Entradas de una lista.
	 *
	 * @param string $kind types|sizes|materials.
	 * @return array<int, array{id:string,label:string}>
	 */
	public static function items( string $kind ): array {
		$all = self::all();

		return isset( $all[ $kind ] ) ? $all[ $kind ] : array();
	}

	/**
	 * Mapa id => etiqueta de una lista.
	 *
	 * @param string $kind types|sizes|materials.
	 * @return array<string, string>
	 */
	public static function labels( string $kind ): array {
		$mapa = array();

		foreach ( self::items( $kind ) as $item ) {
			$mapa[ $item['id'] ] = $item['label'];
		}

		return $mapa;
	}

	/**
	 * Está ese id en la lista.
	 *
	 * @param string $kind types|sizes|materials.
	 * @param string $id   Clave a buscar.
	 * @return bool
	 */
	public static function has( string $kind, string $id ): bool {
		$mapa = self::labels( $kind );

		return '' !== $id && isset( $mapa[ $id ] );
	}

	/**
	 * Etiqueta de un id, ya guardado en el pedido.
	 *
	 * Devuelve el id si la entrada se borró del catálogo después de la compra:
	 * el pedido histórico no se reescribe, así que hay que poder mostrarlo.
	 *
	 * @param string $kind types|sizes|materials.
	 * @param string $id   Clave guardada.
	 * @return string
	 */
	public static function label( string $kind, string $id ): string {
		if ( '' === $id ) {
			return '';
		}

		$mapa = self::labels( $kind );

		return isset( $mapa[ $id ] ) ? $mapa[ $id ] : $id;
	}

	/**
	 * La lista tiene opciones, así que elegir una es obligatorio.
	 *
	 * Si el administrador deja una lista vacía, el formulario esconde ese campo
	 * y el paso se completa sin él.
	 *
	 * @param string $kind types|sizes|materials.
	 * @return bool
	 */
	public static function is_required( string $kind ): bool {
		return array() !== self::items( $kind );
	}

	/**
	 * Listas por defecto, para que el formulario funcione desde el primer día.
	 *
	 * Son un punto de partida editable: el administrador cambia, agrega o borra
	 * en wp-admin y el formulario sigue lo que diga el catálogo.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'types'     => array(
				array( 'id' => 'llaves', 'label' => 'Llaves' ),
				array( 'id' => 'billetera', 'label' => 'Billetera' ),
				array( 'id' => 'celular', 'label' => 'Celular' ),
				array( 'id' => 'documentos', 'label' => 'Documentos' ),
				array( 'id' => 'mochila', 'label' => 'Mochila' ),
				array( 'id' => 'joyas', 'label' => 'Joyas' ),
				array( 'id' => 'herramienta', 'label' => 'Herramienta' ),
				array( 'id' => 'otro', 'label' => 'Otro' ),
			),
			'sizes'     => array(
				array( 'id' => 'pequeno', 'label' => 'Pequeño (cabe en un bolsillo)' ),
				array( 'id' => 'mediano', 'label' => 'Mediano (como un cuaderno)' ),
				array( 'id' => 'grande', 'label' => 'Grande (como una mochila)' ),
				array( 'id' => 'muy-grande', 'label' => 'Muy grande (no se levanta solo)' ),
			),
			'materials' => array(
				array( 'id' => 'metal', 'label' => 'Metal' ),
				array( 'id' => 'cuero', 'label' => 'Cuero' ),
				array( 'id' => 'tela', 'label' => 'Tela' ),
				array( 'id' => 'plastico', 'label' => 'Plástico' ),
				array( 'id' => 'vidrio', 'label' => 'Vidrio' ),
				array( 'id' => 'electronico', 'label' => 'Electrónico' ),
				array( 'id' => 'madera', 'label' => 'Madera' ),
				array( 'id' => 'no-se', 'label' => 'No lo sé' ),
			),
		);
	}

	/**
	 * Siembra las listas por defecto solo si la opción no existe.
	 *
	 * Una lista vacía guardada a propósito no se vuelve a llenar: si el
	 * administrador deja una sin entradas, se respeta.
	 */
	public static function install(): void {
		if ( null !== get_option( self::OPTION, null ) ) {
			return;
		}

		update_option( self::OPTION, self::defaults() );
	}

	/**
	 * Limpia una lista: ids seguros, sin repetidos, sin vacíos, con tope.
	 *
	 * El id es la clave que viaja en el formulario y se guarda en el pedido, así
	 * que se normaliza con sanitize_title(): nunca lleva HTML ni SQL.
	 *
	 * @param array $raw Filas como llegan del formulario del admin.
	 * @return array<int, array{id:string,label:string}>
	 */
	public static function normalize( array $raw ): array {
		$salida = array();
		$vistos = array();

		foreach ( $raw as $fila ) {
			if ( ! is_array( $fila ) ) {
				continue;
			}

			$id    = sanitize_title( isset( $fila['id'] ) ? (string) $fila['id'] : '' );
			$label = sanitize_text_field( isset( $fila['label'] ) ? (string) $fila['label'] : '' );

			if ( '' === $id || isset( $vistos[ $id ] ) ) {
				continue;
			}

			if ( count( $salida ) >= self::MAX_ITEMS ) {
				break;
			}

			$vistos[ $id ] = true;

			$salida[] = array(
				'id'    => $id,
				'label' => '' !== $label ? $label : $id,
			);
		}

		return $salida;
	}
}
