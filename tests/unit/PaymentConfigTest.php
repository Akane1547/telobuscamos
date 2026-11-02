<?php
/**
 * Configuración de pagos: medios activos, credenciales por modo y su resolución.
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

	/** Un "token" sin constante para el modo de producción. */
	private const CAMPOS_PROD = array( 'webhook_secret' => 'secreto-de-produccion' );

	protected function setUp(): void {
		parent::setUp();
		FakeWordPress::reset();
	}

	public function test_sin_configurar_no_hay_medios(): void {
		$this->assertSame( array(), PaymentConfig::enabled_ids() );
	}

	public function test_sin_configurar_el_modo_es_sandbox(): void {
		$this->assertSame( 'sandbox', PaymentConfig::mode( 'mercado_pago' ) );
		$this->assertSame( 'sandbox', PaymentConfig::desired_mode( 'mercado_pago' ) );
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
		PaymentConfig::save_enabled_ids( array( 'mercado_pago', 'MERCADO_PAGO', 'paypal', 'stripe', '', 42, array() ) );

		// 'paypal' es una pasarela registrada; 'stripe' no existe.
		$this->assertSame( array( 'mercado_pago', 'paypal' ), PaymentConfig::enabled_ids() );
	}

	public function test_un_option_corrupto_no_rompe(): void {
		FakeWordPress::set_option( PaymentConfig::OPTION_METHODS, 'no es un array' );

		$this->assertSame( array(), PaymentConfig::enabled_ids() );
	}

	public function test_una_lista_sin_medios_registrados_queda_vacia(): void {
		PaymentConfig::save_enabled_ids( array( 'stripe' ) );

		$this->assertSame( array(), PaymentConfig::enabled_ids() );
	}

	/**
	 * Sin HTTPS no se puede cobrar de verdad, pero las claves no se pierden: se
	 * guardan y el modo comercial queda elegido sin activarse.
	 */
	public function test_produccion_por_http_guarda_las_claves_pero_no_activa(): void {
		FakeWordPress::set_home( 'http://telobuscamos.local' );

		$this->assertSame( 'https', PaymentConfig::save( 'mercado_pago', 'production', self::CAMPOS_PROD ) );
		$this->assertSame( 'production', PaymentConfig::desired_mode( 'mercado_pago' ) );
		$this->assertSame( 'sandbox', PaymentConfig::mode( 'mercado_pago' ) );
		$this->assertTrue( PaymentConfig::has_keys( 'mercado_pago', 'production' ) );
	}

	public function test_produccion_por_https_si_se_activa(): void {
		FakeWordPress::set_home( 'https://ejemplo.cl' );

		$this->assertSame( '', PaymentConfig::save( 'mercado_pago', 'production', self::CAMPOS_PROD ) );
		$this->assertSame( 'production', PaymentConfig::mode( 'mercado_pago' ) );
	}

	public function test_un_modo_inventado_se_normaliza_a_sandbox(): void {
		$this->assertSame( '', PaymentConfig::save( 'mercado_pago', 'cualquiera', array() ) );
		$this->assertSame( 'sandbox', PaymentConfig::mode( 'mercado_pago' ) );
	}

	public function test_una_pasarela_no_registrada_no_se_guarda(): void {
		$this->assertSame( 'unknown', PaymentConfig::save( 'stripe', 'sandbox', self::CAMPOS ) );
		$this->assertSame( '', PaymentConfig::credential( 'stripe', 'webhook_secret' ) );
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
		$this->assertGreaterThan( 40, strlen( $guardado['mercado_pago']['sandbox']['webhook_secret'] ) );
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
	 * Lo que pidió el dueño: las claves de un modo no se resetean al cambiar al
	 * otro.
	 */
	public function test_cada_modo_guarda_sus_propias_claves(): void {
		FakeWordPress::set_home( 'https://ejemplo.cl' );

		PaymentConfig::save( 'mercado_pago', 'sandbox', self::CAMPOS );
		PaymentConfig::save( 'mercado_pago', 'production', self::CAMPOS_PROD );

		$guardado = FakeWordPress::get_option( PaymentConfig::OPTION_CREDENTIALS );

		$this->assertNotSame(
			$guardado['mercado_pago']['sandbox']['webhook_secret'],
			$guardado['mercado_pago']['production']['webhook_secret']
		);

		// En producción se lee su secreto, no el de pruebas.
		$this->assertSame( 'secreto-de-produccion', PaymentConfig::credential( 'mercado_pago', 'webhook_secret' ) );

		// Volver a pruebas sigue leyendo el suyo.
		PaymentConfig::save( 'mercado_pago', 'sandbox', array() );
		$this->assertSame( 'secreto-web-de-prueba', PaymentConfig::credential( 'mercado_pago', 'webhook_secret' ) );

		// Y las dos siguen guardadas.
		$this->assertTrue( PaymentConfig::has_keys( 'mercado_pago', 'sandbox' ) );
		$this->assertTrue( PaymentConfig::has_keys( 'mercado_pago', 'production' ) );
	}

	public function test_has_keys_pide_todas_las_credenciales(): void {
		$this->assertFalse( PaymentConfig::has_keys( 'mercado_pago', 'sandbox' ) );

		PaymentConfig::save( 'mercado_pago', 'sandbox', self::CAMPOS );

		// Con la constante del bootstrap, access_token ya cuenta; sin ella, pediría
		// también ese campo.
		$esperado = defined( 'SIMPLE_FORM_MERCADO_PAGO_ACCESS_TOKEN' ) && '' !== (string) constant( 'SIMPLE_FORM_MERCADO_PAGO_ACCESS_TOKEN' );

		$this->assertSame( $esperado, PaymentConfig::has_keys( 'mercado_pago', 'sandbox' ) );
	}

	/**
	 * Instalaciones viejas: los campos planos junto a 'mode' se migran al juego
	 * del modo que tenían, sin perder nada.
	 */
	public function test_install_migra_el_formato_plano(): void {
		$cifrado = \SimpleForm\Security\Crypto::encrypt( 'secreto-viejo' );

		FakeWordPress::set_option(
			PaymentConfig::OPTION_CREDENTIALS,
			array(
				'mercado_pago' => array(
					'mode'           => 'sandbox',
					'webhook_secret' => $cifrado,
				),
			)
		);

		PaymentConfig::install();

		$guardado = FakeWordPress::get_option( PaymentConfig::OPTION_CREDENTIALS );

		$this->assertSame( 'secreto-viejo', PaymentConfig::credential( 'mercado_pago', 'webhook_secret' ) );
		$this->assertArrayHasKey( 'sandbox', $guardado['mercado_pago'] );
		$this->assertSame( $cifrado, $guardado['mercado_pago']['sandbox']['webhook_secret'] );
		$this->assertArrayNotHasKey( 'webhook_secret', $guardado['mercado_pago'] );

		// Idempotente: correrlo otra vez no cambia nada.
		PaymentConfig::install();
		$this->assertSame( $guardado, FakeWordPress::get_option( PaymentConfig::OPTION_CREDENTIALS ) );
	}

	/**
	 * Sin sodium o sin salts no hay cifrado: antes que dar por guardado lo que no
	 * lo está, save() falla y no escribe nada.
	 */
	public function test_sin_cifrado_posible_no_guarda_nada(): void {
		FakeWordPress::set_salt( '' );

		$this->assertSame( 'crypto', PaymentConfig::save( 'mercado_pago', 'sandbox', self::CAMPOS ) );
		$this->assertNull( FakeWordPress::get_option( PaymentConfig::OPTION_CREDENTIALS, null ) );
	}
}
