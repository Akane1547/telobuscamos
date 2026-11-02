<?php
/**
 * Catálogos del objeto: la lista es la fuente de verdad y el formulario manda
 * el id, nunca texto libre.
 *
 * Lo que se prueba acá es la normalización (lo que escribe el administrador) y
 * la lectura que usa el servidor para validar el Paso 1.
 *
 * @package SimpleForm\Tests
 */

namespace SimpleForm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleForm\Core\Catalog;
use SimpleForm\Tests\FakeWordPress;

final class CatalogTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		FakeWordPress::reset();
		delete_option( Catalog::OPTION );
	}

	public function test_las_listas_por_defecto_tienen_las_tres_partes(): void {
		$defaults = Catalog::defaults();

		$this->assertSame( Catalog::KINDS, array_keys( $defaults ) );

		foreach ( $defaults as $kind => $items ) {
			$this->assertNotEmpty( $items, "la lista {$kind} no puede nacer vacía" );

			foreach ( $items as $item ) {
				$this->assertArrayHasKey( 'id', $item );
				$this->assertArrayHasKey( 'label', $item );
				$this->assertNotSame( '', $item['id'] );
			}
		}
	}

	public function test_normalizar_sanea_el_id_y_descarta_lo_inutil(): void {
		$limpio = Catalog::normalize(
			array(
				array( 'id' => 'Llaves Del Auto', 'label' => 'Llaves del auto' ),
				array( 'id' => 'llaves-del-auto', 'label' => 'Repetido' ),
				array( 'id' => '', 'label' => 'Sin id' ),
				'no-es-una-fila',
				array( 'id' => 'billetera', 'label' => 'Billetera <b>negra</b>' ),
			)
		);

		$this->assertSame(
			array(
				array( 'id' => 'llaves-del-auto', 'label' => 'Llaves del auto' ),
				array( 'id' => 'billetera', 'label' => 'Billetera negra' ),
			),
			$limpio
		);
	}

	public function test_sin_etiqueta_se_usa_el_id(): void {
		$limpio = Catalog::normalize( array( array( 'id' => 'cuero', 'label' => '' ) ) );

		$this->assertSame( array( array( 'id' => 'cuero', 'label' => 'cuero' ) ), $limpio );
	}

	public function test_respeta_el_tope_de_entradas(): void {
		$filas = array();

		for ( $i = 0; $i < Catalog::MAX_ITEMS + 10; $i++ ) {
			$filas[] = array( 'id' => 'item-' . $i, 'label' => 'Item ' . $i );
		}

		$this->assertCount( Catalog::MAX_ITEMS, Catalog::normalize( $filas ) );
	}

	public function test_lee_la_opcion_guardada_y_valida_por_id(): void {
		update_option(
			Catalog::OPTION,
			array(
				'types'     => array( array( 'id' => 'llaves', 'label' => 'Llaves' ) ),
				'sizes'     => array(),
				'materials' => array( array( 'id' => 'metal', 'label' => 'Metal' ) ),
			)
		);

		$this->assertTrue( Catalog::has( 'types', 'llaves' ) );
		$this->assertFalse( Catalog::has( 'types', 'billetera' ) );
		$this->assertFalse( Catalog::has( 'types', '' ) );
		$this->assertSame( 'Metal', Catalog::label( 'materials', 'metal' ) );

		// Una lista vacía no exige nada en el formulario.
		$this->assertTrue( Catalog::is_required( 'types' ) );
		$this->assertFalse( Catalog::is_required( 'sizes' ) );
	}

	public function test_una_entrada_borrada_del_catalogo_se_muestra_por_su_id(): void {
		update_option( Catalog::OPTION, array( 'types' => array() ) );

		// El pedido histórico guardó 'joyas' y el admin ya la borró: se muestra
		// la clave en vez de vaciarse.
		$this->assertSame( 'joyas', Catalog::label( 'types', 'joyas' ) );
		$this->assertSame( '', Catalog::label( 'types', '' ) );
	}

	public function test_install_siembra_solo_si_no_hay_opcion(): void {
		Catalog::install();

		$this->assertSame( Catalog::defaults(), get_option( Catalog::OPTION ) );

		// El administrador dejó una lista vacía a propósito: no se vuelve a
		// sembrar.
		update_option( Catalog::OPTION, array( 'types' => array(), 'sizes' => array(), 'materials' => array() ) );
		Catalog::install();

		$this->assertSame(
			array( 'types' => array(), 'sizes' => array(), 'materials' => array() ),
			get_option( Catalog::OPTION )
		);
	}
}
