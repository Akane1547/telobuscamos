<?php
/**
 * Límite de peticiones por acción y por cliente.
 *
 * Es lo único que separa a un invitado legítimo de alguien que quiere llenar la
 * tabla de borradores o gastar la cuota de la API de la pasarela, así que se
 * prueba el contador, el corte, y que cada acción y cada cliente tengan el suyo.
 *
 * @package SimpleForm\Tests
 */

namespace SimpleForm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleForm\Security\RateLimit;
use SimpleForm\Tests\FakeWordPress;

final class RateLimitTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		FakeWordPress::reset();

		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );

		parent::tearDown();
	}

	public function test_deja_pasar_hasta_el_limite(): void {
		$this->assertTrue( RateLimit::check( 'save_step1', 3 ) );
		$this->assertTrue( RateLimit::check( 'save_step1', 3 ) );
		$this->assertTrue( RateLimit::check( 'save_step1', 3 ) );
	}

	public function test_corta_al_pasarse(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			RateLimit::check( 'save_step1', 3 );
		}

		$this->assertFalse( RateLimit::check( 'save_step1', 3 ) );
	}

	public function test_cada_accion_tiene_su_propio_contador(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			RateLimit::check( 'save_step1', 3 );
		}

		$this->assertTrue( RateLimit::check( 'save_step2', 3 ) );
	}

	public function test_cada_cliente_tiene_su_propio_contador(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			RateLimit::check( 'save_step1', 3 );
		}

		FakeWordPress::set_filter(
			'simple_form_client_ip',
			function () {
				return '198.51.100.7';
			}
		);

		$this->assertTrue( RateLimit::check( 'save_step1', 3 ) );
	}

	public function test_el_limite_en_cero_no_limita(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertTrue( RateLimit::check( 'save_step1', 0 ) );
		}
	}

	public function test_el_filtro_puede_ajustar_el_limite_por_accion(): void {
		FakeWordPress::set_filter(
			'simple_form_rate_limit',
			function ( $limit, $action ) {
				return 'save_step1' === $action ? 1 : $limit;
			}
		);

		$this->assertTrue( RateLimit::check( 'save_step1', 60 ) );
		$this->assertFalse( RateLimit::check( 'save_step1', 60 ) );
		$this->assertTrue( RateLimit::check( 'save_step2', 60 ) );
	}

	/**
	 * Al rechazar no se renueva la ventana: si no, quien insiste nunca sale del
	 * bloqueo.
	 */
	public function test_la_ventana_vence_aunque_se_insista(): void {
		$this->assertTrue( RateLimit::check( 'save_step1', 1, 1 ) );
		$this->assertFalse( RateLimit::check( 'save_step1', 1, 1 ) );

		sleep( 2 );

		$this->assertTrue( RateLimit::check( 'save_step1', 1, 1 ) );
	}
}
