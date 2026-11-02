<?php
/**
 * PayPal: armado de la orden, conversión de moneda y traducción de estados.
 *
 * Las llamadas HTTP no se prueban acá (igual que Mercado Pago): esta suite cubre
 * lo que no toca la red, que es donde viven las decisiones — el monto en USD, el
 * custom_id y las URLs de retorno. El cableado con la API se prueba en
 * tests/integration/test-paypal.php con pre_http_request.
 *
 * @package SimpleForm\Tests
 */

namespace SimpleForm\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleForm\Payments\Gateways;
use SimpleForm\Payments\OrderStatus;
use SimpleForm\Payments\PayPalGateway;
use SimpleForm\Payments\PaymentConfig;
use SimpleForm\Tests\FakeWordPress;

final class PayPalGatewayTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		FakeWordPress::reset();

		PaymentConfig::save(
			'paypal',
			'sandbox',
			array(
				'client_id'     => 'client-de-prueba',
				'client_secret' => 'secreto-de-prueba',
				'usd_rate'      => '900',
			)
		);
	}

	private function pedido( int $monto = 18000 ): array {
		return array(
			'id'            => 77,
			'draft_id'      => 'borrador-abc',
			'service_id'    => '1',
			'service_label' => 'plan basico',
			'radius_km'     => 0.2,
			'amount'        => $monto,
		);
	}

	public function test_paypal_esta_registrado_con_sus_campos(): void {
		$this->assertTrue( Gateways::has( 'paypal' ) );
		$this->assertSame( 'PayPal', Gateways::label( 'paypal' ) );
		$this->assertSame( array( 'client_id', 'client_secret', 'usd_rate' ), Gateways::credential_fields( 'paypal' ) );
	}

	public function test_el_monto_se_convierte_a_usd_con_la_tasa(): void {
		$gateway = new PayPalGateway();

		// 18.000 CLP a 900 = 20,00 USD.
		$this->assertSame( '20.00', $gateway->amount_usd( 18000 ) );
		// 10.400 / 900 = 11,555… → 11,56 (dos decimales, como pide la API).
		$this->assertSame( '11.56', $gateway->amount_usd( 10400 ) );
		// Un monto que redondea a cero no se puede cobrar.
		$this->assertSame( '', $gateway->amount_usd( 0 ) );
		$this->assertSame( '', $gateway->amount_usd( -100 ) );
	}

	public function test_sin_tasa_no_hay_monto(): void {
		delete_option( 'simple_form_payment_credentials' );
		PaymentConfig::save( 'paypal', 'sandbox', array( 'client_id' => 'x', 'client_secret' => 'y' ) );

		$this->assertSame( '', ( new PayPalGateway() )->amount_usd( 18000 ) );
	}

	public function test_una_tasa_que_no_es_numero_no_sirve(): void {
		delete_option( 'simple_form_payment_credentials' );
		PaymentConfig::save( 'paypal', 'sandbox', array( 'client_id' => 'x', 'client_secret' => 'y', 'usd_rate' => 'novecientos' ) );

		$this->assertSame( '', ( new PayPalGateway() )->amount_usd( 18000 ) );
	}

	public function test_la_orden_que_se_manda_tiene_lo_que_paypal_pide(): void {
		$orden = ( new PayPalGateway() )->build_order( $this->pedido(), '20.00' );

		$this->assertSame( 'CAPTURE', $orden['intent'] );
		$this->assertSame( 'USD', $orden['purchase_units'][0]['amount']['currency_code'] );
		$this->assertSame( '20.00', $orden['purchase_units'][0]['amount']['value'] );
		// El id del pedido viaja para poder verificar el pago al volver.
		$this->assertSame( '77', $orden['purchase_units'][0]['custom_id'] );
		$this->assertStringContainsString( 'plan basico', $orden['purchase_units'][0]['description'] );
		$this->assertStringContainsString( '200 m', $orden['purchase_units'][0]['description'] );
		$this->assertSame( 'NO_SHIPPING', $orden['application_context']['shipping_preference'] );
		$this->assertSame( 'PAY_NOW', $orden['application_context']['user_action'] );
		$this->assertStringContainsString( 'simple_form_return=paypal', $orden['application_context']['return_url'] );
	}

	public function test_sin_credenciales_no_se_crea_el_pago(): void {
		delete_option( 'simple_form_payment_credentials' );

		$this->assertSame( '', ( new PayPalGateway() )->create_payment( $this->pedido() ) );
	}

	public function test_sin_tasa_no_se_crea_el_pago(): void {
		delete_option( 'simple_form_payment_credentials' );
		PaymentConfig::save( 'paypal', 'sandbox', array( 'client_id' => 'x', 'client_secret' => 'y' ) );

		$this->assertSame( '', ( new PayPalGateway() )->create_payment( $this->pedido() ) );
	}

	/**
	 * @return array
	 */
	public static function estadosDePayPal(): array {
		return array(
			'COMPLETED -> approved'              => array( 'COMPLETED', OrderStatus::APPROVED ),
			'completed -> approved (minúsculas)' => array( 'completed', OrderStatus::APPROVED ),
			'APPROVED -> pending'                => array( 'APPROVED', OrderStatus::PENDING ),
			'CREATED -> pending'                 => array( 'CREATED', OrderStatus::PENDING ),
			'PAYER_ACTION_REQUIRED -> pending'   => array( 'PAYER_ACTION_REQUIRED', OrderStatus::PENDING ),
			'VOIDED -> cancelled'                => array( 'VOIDED', OrderStatus::CANCELLED ),
			'DECLINED -> rejected'               => array( 'DECLINED', OrderStatus::REJECTED ),
			'FAILED -> rejected'                 => array( 'FAILED', OrderStatus::REJECTED ),
			'REFUNDED -> refunded'               => array( 'REFUNDED', OrderStatus::REFUNDED ),
			'PARTIALLY_REFUNDED -> refunded'     => array( 'PARTIALLY_REFUNDED', OrderStatus::REFUNDED ),
			'sin equivalente -> vacío'           => array( 'ALGO_RARO', '' ),
		);
	}

	#[DataProvider( 'estadosDePayPal' )]
	public function test_los_estados_se_traducen( string $crudo, string $esperado ): void {
		$this->assertSame( $esperado, ( new PayPalGateway() )->map_status( $crudo ) );
	}
}
