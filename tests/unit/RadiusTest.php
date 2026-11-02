<?php
/**
 * Rango del radio de búsqueda: la opción que mueve el slider y las dos
 * validaciones (navegador y servidor).
 *
 * @package SimpleForm\Tests
 */

namespace SimpleForm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleForm\Core\Radius;
use SimpleForm\Tests\FakeWordPress;

final class RadiusTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		FakeWordPress::reset();
		delete_option( Radius::OPTION );
	}

	public function test_sin_opcion_usa_los_valores_de_fabrica(): void {
		$this->assertSame( Radius::DEFAULTS, Radius::all() );
		$this->assertSame( 0.1, Radius::min_km() );
		$this->assertSame( 1.0, Radius::max_km() );
	}

	public function test_normaliza_a_multiplos_de_diez_metros(): void {
		$rango = Radius::normalize( array( 'min_m' => 15, 'max_m' => 1004, 'step_m' => 33 ) );

		$this->assertSame( array( 'min_m' => 20, 'max_m' => 1000, 'step_m' => 30 ), $rango );
	}

	public function test_un_minimo_igual_o_mayor_que_el_maximo_se_corrige(): void {
		$rango = Radius::normalize( array( 'min_m' => 2000, 'max_m' => 1000, 'step_m' => 100 ) );

		$this->assertLessThan( $rango['max_m'], $rango['min_m'] );
		$this->assertSame( 990, $rango['min_m'] );
	}

	public function test_no_sale_del_piso_ni_del_techo(): void {
		$this->assertSame( Radius::FLOOR_M, Radius::normalize( array( 'min_m' => 0 ) )['min_m'] );
		$this->assertSame( Radius::CEIL_M, Radius::normalize( array( 'max_m' => 999999 ) )['max_m'] );
	}

	public function test_basura_en_la_opcion_no_rompe_nada(): void {
		update_option( Radius::OPTION, 'esto no es un array' );
		$this->assertSame( Radius::DEFAULTS, Radius::all() );

		update_option( Radius::OPTION, array( 'min_m' => 'abc', 'max_m' => null, 'step_m' => array( 1 ) ) );
		$this->assertSame( Radius::DEFAULTS, Radius::all() );
	}

	public function test_contains_acepta_los_extremos(): void {
		update_option( Radius::OPTION, array( 'min_m' => 200, 'max_m' => 5000, 'step_m' => 100 ) );

		$this->assertTrue( Radius::contains( 0.2 ) );
		$this->assertTrue( Radius::contains( 5.0 ) );
		$this->assertTrue( Radius::contains( 2.5 ) );
		$this->assertFalse( Radius::contains( 0.19 ) );
		$this->assertFalse( Radius::contains( 5.01 ) );
		$this->assertFalse( Radius::contains( 0.0 ) );
	}

	public function test_format_es_el_mismo_texto_que_ve_el_cliente(): void {
		$this->assertSame( '100 m', Radius::format( 0.1 ) );
		$this->assertSame( '500 m', Radius::format( 0.5 ) );
		$this->assertSame( '1 km', Radius::format( 1.0 ) );
		$this->assertSame( '1,5 km', Radius::format( 1.5 ) );
		$this->assertSame( '12 km', Radius::format( 12 ) );
	}

	public function test_install_solo_siembra_si_no_hay_opcion(): void {
		Radius::install();
		$this->assertSame( Radius::DEFAULTS, get_option( Radius::OPTION ) );

		update_option( Radius::OPTION, array( 'min_m' => 300, 'max_m' => 3000, 'step_m' => 300 ) );
		Radius::install();

		$this->assertSame( 300, Radius::min_m() );
	}
}
