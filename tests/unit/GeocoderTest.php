<?php
/**
 * Geocoder: traducción de la respuesta de Photon y filtro de Chile.
 *
 * Las features de las pruebas son respuestas reales del proveedor, recortadas:
 * Providencia con altura exacta, una calle sin numeración cargada en OSM, una
 * dirección argentina que tiene que quedar afuera y una entrada sin país.
 *
 * @package SimpleForm\Tests
 */

namespace SimpleForm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleForm\Core\Geocoder;
use SimpleForm\Tests\FakeWordPress;

final class GeocoderTest extends TestCase {

	/**
	 * Respuesta real de photon.komoot.io para "Providencia 1234, Santiago"
	 * (2026, con la caja de Chile): la primera es la chilena, la segunda es
	 * argentina y viene igual porque la caja solo sesga, no filtra.
	 */
	private const FEATURES = array(
		array(
			'type'       => 'Feature',
			'properties' => array(
				'osm_type'    => 'W',
				'osm_id'      => 123456,
				'osm_key'     => 'highway',
				'osm_value'   => 'residential',
				'type'        => 'house',
				'name'        => 'Avenida Providencia',
				'street'      => 'Avenida Providencia',
				'housenumber' => '1234',
				'district'    => 'Providencia',
				'city'        => 'Santiago',
				'state'       => 'Región Metropolitana de Santiago',
				'country'     => 'Chile',
				'countrycode' => 'CL',
				'postcode'    => '7500000',
			),
			'geometry'   => array( 'type' => 'Point', 'coordinates' => array( -70.6211027, -33.4290115 ) ),
		),
		array(
			'type'       => 'Feature',
			'properties' => array(
				'type'        => 'house',
				'name'        => 'Mendoza',
				'street'      => 'Mendoza',
				'housenumber' => '100',
				'city'        => 'Neuquén',
				'country'     => 'Argentina',
				'countrycode' => 'AR',
			),
			'geometry'   => array( 'type' => 'Point', 'coordinates' => array( -68.05339, -38.95412 ) ),
		),
		array(
			'type'       => 'Feature',
			'properties' => array( 'name' => 'Sin país declarado', 'type' => 'city' ),
			'geometry'   => array( 'type' => 'Point', 'coordinates' => array( 2.35, 48.85 ) ),
		),
	);

	protected function setUp(): void {
		parent::setUp();
		FakeWordPress::reset();
	}

	/**
	 * @param array $extra
	 * @return array
	 */
	private function feature( array $extra ): array {
		return array_merge( self::FEATURES[0], array( 'properties' => array_merge( self::FEATURES[0]['properties'], $extra ) ) );
	}

	// --- Traducción de una feature ---

	public function test_una_direccion_chilena_se_traduce_con_sus_coordenadas(): void {
		$result = ( new Geocoder() )->to_result( self::FEATURES[0] );

		$this->assertSame( 'Avenida Providencia 1234, Providencia', $result['label'] );
		$this->assertSame( -33.4290115, $result['lat'] );
	}

	/**
	 * GeoJSON viene [ longitud, latitud ]: invertirlo deja el pin en el océano.
	 */
	public function test_las_coordenadas_no_se_invierten(): void {
		$result = ( new Geocoder() )->to_result( self::FEATURES[0] );

		$this->assertSame( -33.4290115, $result['lat'] );
		$this->assertSame( -70.6211027, $result['lng'] );
	}

	public function test_una_direccion_de_otro_pais_queda_afuera(): void {
		$this->assertNull( ( new Geocoder() )->to_result( self::FEATURES[1] ) );
	}

	public function test_sin_pais_declarado_queda_afuera(): void {
		$this->assertNull( ( new Geocoder() )->to_result( self::FEATURES[2] ) );
	}

	public function test_sin_coordenadas_queda_afuera(): void {
		$feature                = self::FEATURES[0];
		$feature['geometry']    = array( 'type' => 'Point', 'coordinates' => array() );

		$this->assertNull( ( new Geocoder() )->to_result( $feature ) );
	}

	public function test_sin_nombre_queda_afuera(): void {
		$feature = $this->feature(
			array(
				'street'      => '',
				'name'        => '',
				'housenumber' => '',
			)
		);

		$this->assertNull( ( new Geocoder() )->to_result( $feature ) );
	}

	// --- Etiqueta ---

	/**
	 * En Chile hay calles sin numeración cargada en OSM (Punta Arenas, por
	 * ejemplo): se muestra la calle sola antes que inventar una altura.
	 */
	public function test_una_calle_sin_altura_muestra_solo_la_calle(): void {
		$label = ( new Geocoder() )->label( $this->feature( array( 'housenumber' => '' ) )['properties'] );

		$this->assertSame( 'Avenida Providencia, Providencia', $label );
	}

	public function test_sin_calle_se_usa_el_nombre_del_lugar(): void {
		$label = ( new Geocoder() )->label(
			array(
				'name'     => 'Puerto Williams',
				'type'     => 'district',
				'district' => 'Cabo de Hornos',
				'countrycode' => 'CL',
			)
		);

		$this->assertSame( 'Puerto Williams, Cabo de Hornos', $label );
	}

	public function test_la_comuna_no_se_repite(): void {
		$label = ( new Geocoder() )->label(
			array(
				'name'        => 'Maipú',
				'type'        => 'district',
				'district'    => 'Maipú',
				'countrycode' => 'CL',
			)
		);

		$this->assertSame( 'Maipú', $label );
	}

	public function test_sin_nada_que_mostrar_la_etiqueta_es_vacia(): void {
		$this->assertSame( '', ( new Geocoder() )->label( array( 'countrycode' => 'CL' ) ) );
	}

	// --- search(): el mínimo y el caché, sin tocar la red ---

	public function test_una_consulta_muy_corta_no_consulta_al_proveedor(): void {
		$this->assertSame( array(), ( new Geocoder() )->search( 'ab' ) );
		$this->assertFalse( get_transient( Geocoder::CACHE_PREFIX . md5( 'ab' ) ) );
	}

	public function test_una_consulta_corta_con_espacios_tampoco(): void {
		$this->assertSame( array(), ( new Geocoder() )->search( '   a   ' ) );
	}

	/**
	 * Si el caché está, no se llama al proveedor (acá no hay red que doblar:
	 * una llamada real reventaría la prueba).
	 */
	public function test_una_consulta_cacheada_no_va_al_proveedor(): void {
		$cacheada = array( array( 'label' => 'Avenida Providencia 1234, Providencia', 'lat' => -33.4290115, 'lng' => -70.6211027 ) );

		set_transient( Geocoder::CACHE_PREFIX . md5( 'providencia 1234' ), $cacheada, Geocoder::CACHE_TTL );

		// La clave se calcula en minúsculas: la consulta con mayúsculas pega en
		// el mismo caché.
		$this->assertSame( $cacheada, ( new Geocoder() )->search( 'Providencia 1234' ) );
	}

	public function test_la_consulta_se_recorta_antes_de_buscar(): void {
		$cacheada = array( array( 'label' => 'Maipú', 'lat' => -33.50944, 'lng' => -70.75618 ) );

		set_transient( Geocoder::CACHE_PREFIX . md5( 'maipu' ), $cacheada, Geocoder::CACHE_TTL );

		$this->assertSame( $cacheada, ( new Geocoder() )->search( '  Maipu  ' ) );
	}
}
