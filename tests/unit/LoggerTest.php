<?php
/**
 * Canal de log del plugin: encendido/apagado y formato.
 *
 * El log escribe al log de errores de PHP; acá se redirige a un archivo
 * temporal con ini_set para poder leer lo que escribe, que es la forma real de
 * comprobar un logger sin depender de la salida del proceso.
 *
 * @package SimpleForm\Tests
 */

namespace SimpleForm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleForm\Core\Logger;
use SimpleForm\Tests\FakeWordPress;

final class LoggerTest extends TestCase {

	/** @var string|false */
	private $tmp = false;

	protected function setUp(): void {
		parent::setUp();
		FakeWordPress::reset();

		$this->tmp = tempnam( sys_get_temp_dir(), 'sf-log-' );
		ini_set( 'error_log', (string) $this->tmp );
	}

	protected function tearDown(): void {
		ini_restore( 'error_log' );

		if ( false !== $this->tmp ) {
			@unlink( $this->tmp );
		}

		parent::tearDown();
	}

	private function contenido(): string {
		return false !== $this->tmp ? (string) file_get_contents( $this->tmp ) : '';
	}

	public function test_apagado_por_defecto_no_escribe(): void {
		Logger::log( 'mercado_pago', 'mensaje que no debería salir' );

		$this->assertSame( '', $this->contenido() );
	}

	public function test_el_filtro_lo_enciende_y_escribe_con_el_canal(): void {
		FakeWordPress::set_filter(
			Logger::FILTER_ENABLED,
			function () {
				return true;
			}
		);

		Logger::log( 'geocoder', 'un aviso de prueba' );

		$this->assertStringContainsString( '[simple-form][geocoder] un aviso de prueba', $this->contenido() );
	}

	public function test_el_filtro_en_falso_lo_mantiene_apagado(): void {
		FakeWordPress::set_filter(
			Logger::FILTER_ENABLED,
			function () {
				return false;
			}
		);

		Logger::log( 'cleanup', 'otro aviso' );

		$this->assertSame( '', $this->contenido() );
	}
}
