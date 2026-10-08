<?php
/**
 * Configuración de pagos: medios activos, credenciales y su resolución.
 *
 * El bootstrap define SIMPLE_FORM_MERCADO_PAGO_ACCESS_TOKEN como lo haría
 * wp-config.php, así que las rutas almacenadas se prueban con webhook_secret
 * —que no tiene constante— y la precedencia de la constante tiene sus propias
 * aserciones.
 *
 * @package SimpleForm\Tests
 */

namespace SimpleForm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleForm\Payments\PaymentConfig;
use SimpleForm\Tests\FakeWordPress;

final class PaymentConfigTest extends TestCase {

	/** Campo sin constante asociada: por aquí se prueba la ruta almacenada. */
	private const CAMPOS = array( 'webhook_secret' => 'secreto-web-de-prueba' );

	protected function setUp(): void {
		parent::setUp();
		FakeWordPress::reset();
	}

	public function test_sin_configurar_no_hay_medios(): void {
		$this->assertSame( array(), PaymentConfig::enabled_ids() );
	}

	public function test_sin_configurar_el_modo_es_sandbox(): void {
		$this->assertSame( 'sandbox', PaymentConfig::mode( 'mercado_pago' ) );
	}

	public function test_sin_configurar_no_hay_credencial_guardada(): void {
		$this->assertSame( '', PaymentConfig::credential( 'mercado_pago', 'webhook_secret' ) );
		$this->assertSame( 'none', PaymentConfig::source( 'mercado_pago', 'webhook_secret' ) );
	}

	public function test_el_nombre_de_la_constante_sale_del_id_y_el_campo(): void {
		$this->assertSame( 'SIMPLE_FORM_MERCADO_PAGO_ACCESS_TOKEN', PaymentConfig::constant_name( 'mercado_pago', 'access_token' ) );
		$this->assertSame( 'SIMPLE_FORM_MERCADO_PAGO_MODE', PaymentConfig::constant_name( 'mercado_pago', 'mode' ) );
	}

	public function test_la_constante_gana_al_valor_guardado(): void {
		$this->assertSame( 'CONST-DE-PRUEBA-EN-EL-BOOTSTRAP', PaymentConfig::credential( 'mercado_pago', 'access_token' ) );
		$this->assertSame( 'constant', PaymentConfig::source( 'mercado_pago', 'access_token' ) );
	}

	public function test_la_lista_de_medios_se_sanea_contra_el_registro(): void {
		PaymentConfig::save_enabled_ids( array( 'mercado_pago', 'MERCADO_PAGO', 'paypal', '', 42, array() ) );

		$this->assertSame( array( 'mercado_pago' ), PaymentConfig::enabled_ids() );
	}

	public function test_un_option_corrupto_no_rompe(): void {
		FakeWordPress::set_option( PaymentConfig::OPTION_METHODS, 'no es un array' );

		$this->assertSame( array(), PaymentConfig::enabled_ids() );
	}

	public function test_una_lista_sin_medios_registrados_queda_vacia(): void {
		PaymentConfig::save_enabled_ids( array( 'paypal' ) );

		$this->assertSame( array(), PaymentConfig::enabled_ids() );
	}

	public function test_produccion_por_http_no_se_guarda(): void {
		FakeWordPress::set_home( 'http://telobuscamos.local' );

		$this->assertFalse( PaymentConfig::save( 'mercado_pago', 'production', array() ) );
		$this->assertSame( 'sandbox', PaymentConfig::mode( 'mercado_pago' ) );
	}

	public function test_produccion_por_https_si_se_guarda(): void {
		FakeWordPress::set_home( 'https://ejemplo.cl' );

		$this->assertTrue( PaymentConfig::save( 'mercado_pago', 'production', array() ) );
		$this->assertSame( 'production', PaymentConfig::mode( 'mercado_pago' ) );
	}

	public function test_un_modo_inventado_se_normaliza_a_sandbox(): void {
		$this->assertTrue( PaymentConfig::save( 'mercado_pago', 'cualquiera', array() ) );
		$this->assertSame( 'sandbox', PaymentConfig::mode( 'mercado_pago' ) );
	}

	public function test_una_pasarela_no_registrada_no_se_guarda(): void {
		$this->assertFalse( PaymentConfig::save( 'paypal', 'sandbox', self::CAMPOS ) );
		$this->assertSame( '', PaymentConfig::credential( 'paypal', 'webhook_secret' ) );
	}

	public function test_la_credencial_guardada_se_lee_de_vuelta(): void {
		PaymentConfig::save( 'mercado_pago', 'sandbox', self::CAMPOS );

		$this->assertSame( 'secreto-web-de-prueba', PaymentConfig::credential( 'mercado_pago', 'webhook_secret' ) );
		$this->assertSame( 'stored', PaymentConfig::source( 'mercado_pago', 'webhook_secret' ) );
	}

	public function test_lo_guardado_no_contiene_el_texto_claro(): void {
		PaymentConfig::save( 'mercado_pago', 'sandbox', self::CAMPOS );

		$guardado = FakeWordPress::get_option( PaymentConfig::OPTION_CREDENTIALS );

		$this->assertStringNotContainsString( 'secreto-web-de-prueba', json_encode( $guardado ) );
		$this->assertGreaterThan( 40, strlen( $guardado['mercado_pago']['webhook_secret'] ) );
	}

	public function test_un_campo_en_blanco_conserva_el_anterior(): void {
		PaymentConfig::save( 'mercado_pago', 'sandbox', self::CAMPOS );
		PaymentConfig::save( 'mercado_pago', 'sandbox', array( 'webhook_secret' => '' ) );

		$this->assertSame( 'secreto-web-de-prueba', PaymentConfig::credential( 'mercado_pago', 'webhook_secret' ) );
	}

	public function test_el_modo_guardado_se_lee_de_vuelta(): void {
		PaymentConfig::save( 'mercado_pago', 'sandbox', array() );

		$this->assertSame( 'sandbox', PaymentConfig::mode( 'mercado_pago' ) );
	}

	/**
	 * Sin sodium o sin salts no hay cifrado: antes que dar por guardado lo que no
	 * lo está, save() falla y no escribe nada.
	 */
	public function test_sin_cifrado_posible_no_guarda_nada(): void {
		FakeWordPress::set_salt( '' );

		$this->assertFalse( PaymentConfig::save( 'mercado_pago', 'sandbox', self::CAMPOS ) );
		$this->assertNull( FakeWordPress::get_option( PaymentConfig::OPTION_CREDENTIALS, null ) );
	}
}
