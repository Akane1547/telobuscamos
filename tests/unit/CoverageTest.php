<?php
/**
 * Cobertura: qué ubicaciones acepta el servicio.
 *
 * Los puntos de control son ciudades concretas. Se incluyen los que un simple
 * bounding box aceptaría por error (Mendoza, Bariloche), que son la razón de
 * usar el contorno real.
 *
 * @package SimpleForm\Tests
 */

namespace SimpleForm\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleForm\Core\Coverage;

final class CoverageTest extends TestCase {

	public function test_carga_los_anillos_de_validacion(): void {
		$rings = Coverage::rings();

		$this->assertCount( 163, $rings );
		$this->assertSame( 17197, array_sum( array_map( 'count', $rings ) ) );
	}

	public function test_carga_los_anillos_de_dibujo(): void {
		$outline = Coverage::outline();

		$this->assertCount( 31, $outline );
		$this->assertSame( 2006, array_sum( array_map( 'count', $outline ) ) );
	}

	public function test_el_contorno_de_dibujo_es_mas_liviano(): void {
		$validacion = array_sum( array_map( 'count', Coverage::rings() ) );
		$dibujo     = array_sum( array_map( 'count', Coverage::outline() ) );

		$this->assertLessThan( $validacion, $dibujo );
	}

	public function test_las_bounds_traen_las_cuatro_claves(): void {
		$this->assertSame( array( 'south', 'north', 'west', 'east' ), array_keys( Coverage::bounds() ) );
	}

	/**
	 * @return array
	 */
	public static function puntosDentroDeChile(): array {
		return array(
			'Arica'              => array( -18.4783, -70.3126 ),
			'Antofagasta'        => array( -23.6509, -70.3975 ),
			'Santiago'           => array( -33.4489, -70.6693 ),
			'Valparaiso'         => array( -33.0472, -71.6127 ),
			'Concepcion'         => array( -36.8269, -73.0498 ),
			'Coyhaique'          => array( -45.5752, -72.0662 ),
			'Punta Arenas'       => array( -53.1638, -70.9171 ),
			'Puerto Williams'    => array( -54.9333, -67.6167 ),
			'Hanga Roa (Pascua)' => array( -27.1547, -109.4267 ),
			'Juan Fernandez'     => array( -33.6372, -78.8287 ),
		);
	}

	/**
	 * @return array
	 */
	public static function puntosFueraDeChile(): array {
		return array(
			'Mendoza (dentro del bbox)'   => array( -32.8895, -68.8458 ),
			'Bariloche (dentro del bbox)' => array( -41.1335, -71.3103 ),
			'San Martin de los Andes'     => array( -40.1567, -71.3522 ),
			'Buenos Aires'                => array( -34.6037, -58.3816 ),
			'Montevideo'                  => array( -34.9011, -56.1645 ),
			'La Paz'                      => array( -16.5000, -68.1500 ),
			'Lima'                        => array( -12.0464, -77.0428 ),
			'Oceano frente a Antofagasta' => array( -23.6509, -72.0000 ),
			'Oceano frente a Valparaiso'  => array( -33.0472, -73.5000 ),
			'Origen 0,0'                  => array( 0.0, 0.0 ),
			'Polo sur'                    => array( -89.9, 0.0 ),
		);
	}

	#[DataProvider( 'puntosDentroDeChile' )]
	public function test_acepta_dentro_de_chile( float $lat, float $lng ): void {
		$this->assertTrue( Coverage::contains( $lat, $lng ) );
	}

	#[DataProvider( 'puntosFueraDeChile' )]
	public function test_rechaza_fuera_de_chile( float $lat, float $lng ): void {
		$this->assertFalse( Coverage::contains( $lat, $lng ) );
	}

	public function test_una_consulta_cuesta_menos_de_cinco_milisegundos(): void {
		$inicio = microtime( true );

		for ( $i = 0; $i < 100; $i++ ) {
			Coverage::contains( -33.4489 + ( $i % 100 ) / 1000, -70.6693 );
		}

		$ms = ( microtime( true ) - $inicio ) * 1000;

		$this->assertLessThan( 5.0, $ms / 100 );
	}
}
