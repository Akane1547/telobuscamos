<?php
/**
 * Medios de pago propios: el administrador los agrega con un nombre y quedan
 * junto a las pasarelas registradas.
 *
 * Lo que se prueba acá es la normalización (nombre → identificador estable) y la
 * resolución de nombre/nota/tipo.
 *
 * @package SimpleForm\Tests
 */

namespace SimpleForm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleForm\Payments\Gateways;
use SimpleForm\Payments\Methods;
use SimpleForm\Tests\FakeWordPress;

final class MethodsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		FakeWordPress::reset();
		delete_option( Methods::OPTION_CUSTOM );
	}

	public function test_sin_medios_propios_solo_esta_la_pasarela(): void {
		$this->assertSame( array(), Methods::custom() );
		$this->assertTrue( Methods::has( 'mercado_pago' ) );
		$this->assertFalse( Methods::has( 'transferencia' ) );
		$this->assertFalse( Methods::is_manual( 'mercado_pago' ) );
		$this->assertSame( 'Mercado Pago', Methods::label( 'mercado_pago' ) );
	}

	public function test_el_identificador_sale_del_nombre(): void {
		$guardados = Methods::save_custom(
			array( array( 'label' => 'Transferencia Bancaria', 'note' => 'Banco Estado, cuenta 123' ) )
		);

		$this->assertSame( 'transferencia-bancaria', $guardados[0]['id'] );
		$this->assertSame( 'Transferencia Bancaria', Methods::label( 'transferencia-bancaria' ) );
		$this->assertSame( 'Banco Estado, cuenta 123', Methods::note( 'transferencia-bancaria' ) );
		$this->assertTrue( Methods::is_manual( 'transferencia-bancaria' ) );
		$this->assertTrue( Methods::has( 'transferencia-bancaria' ) );
	}

	public function test_una_fila_sin_nombre_se_descarta(): void {
		$guardados = Methods::save_custom(
			array(
				array( 'label' => '   ' ),
				array( 'label' => 'Efectivo' ),
			)
		);

		$this->assertCount( 1, $guardados );
		$this->assertSame( 'efectivo', $guardados[0]['id'] );
	}

	public function test_dos_medios_con_el_mismo_nombre_no_se_reptien(): void {
		$guardados = Methods::save_custom(
			array(
				array( 'label' => 'Transferencia' ),
				array( 'label' => 'Transferencia' ),
			)
		);

		$this->assertCount( 1, $guardados );
	}

	public function test_un_medio_propio_no_puede_pisar_una_pasarela(): void {
		$guardados = Methods::save_custom( array( array( 'label' => 'Mercado Pago' ) ) );

		$this->assertSame( array(), $guardados );
		$this->assertSame( 'Mercado Pago', Gateways::label( 'mercado_pago' ) );
		$this->assertFalse( Methods::is_manual( 'mercado_pago' ) );
	}

	public function test_editar_el_nombre_no_cambia_el_identificador(): void {
		Methods::save_custom( array( array( 'label' => 'Transferencia' ) ) );

		// Llega con id y otro nombre: el id se conserva (los pedidos lo referencian).
		$guardados = Methods::save_custom(
			array( array( 'id' => 'transferencia', 'label' => 'Transferencia o depósito', 'note' => 'Cuenta nueva' ) )
		);

		$this->assertSame( 'transferencia', $guardados[0]['id'] );
		$this->assertSame( 'Transferencia o depósito', Methods::label( 'transferencia' ) );
		$this->assertSame( 'Cuenta nueva', Methods::note( 'transferencia' ) );
	}

	public function test_respeta_el_tope_de_medios_propios(): void {
		$filas = array();

		for ( $i = 0; $i < Methods::MAX_CUSTOM + 5; $i++ ) {
			$filas[] = array( 'label' => 'Medio ' . $i );
		}

		$this->assertCount( Methods::MAX_CUSTOM, Methods::save_custom( $filas ) );
	}

	public function test_all_mezcla_pasarelas_y_propios(): void {
		Methods::save_custom( array( array( 'label' => 'Efectivo' ) ) );

		$todos = Methods::all();

		$this->assertArrayHasKey( 'mercado_pago', $todos );
		$this->assertArrayHasKey( 'efectivo', $todos );
		$this->assertFalse( $todos['mercado_pago']['manual'] );
		$this->assertTrue( $todos['efectivo']['manual'] );
	}

	public function test_un_medio_borrado_no_tiene_nombre(): void {
		$this->assertSame( '', Methods::label( 'fantasma' ) );
		$this->assertSame( '', Methods::note( 'fantasma' ) );
		$this->assertFalse( Methods::has( 'fantasma' ) );
	}
}
