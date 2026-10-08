<?php
/**
 * Pasarela de Mercado Pago: traducción de estados y armado de la preferencia.
 *
 * Las llamadas HTTP no se prueban acá (necesitan la API real): esta suite cubre
 * lo que no toca la red, que es donde viven las decisiones — el monto, el
 * external_reference y las URLs de retorno.
 *
 * @package SimpleForm\Tests
 */

namespace SimpleForm\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleForm\Payments\MercadoPagoGateway;
use SimpleForm\Payments\OrderStatus;
use SimpleForm\Tests\FakeWordPress;

final class MercadoPagoGatewayTest extends TestCase {

	private const ORDEN = array(
		'id'                   => 7,
		'draft_id'             => 'abc123',
		'full_name'            => 'María González',
		'email'                => 'maria@ejemplo.cl',
		'service_id'           => 'traslado',
		'service_label'        => 'Traslado de carga',
		'service_base_price'   => 30000,
		'service_price_per_km' => 2500,
		'radius_km'            => 8,
		'amount'               => 50000,
	);

	protected function setUp(): void {
		parent::setUp();
		FakeWordPress::reset();
	}

	private function gateway(): MercadoPagoGateway {
		return new MercadoPagoGateway();
	}

	/**
	 * @return array
	 */
	public static function estadosDeMercadoPago(): array {
		return array(
			'approved -> approved'         => array( 'approved', OrderStatus::APPROVED ),
			'pending -> pending'           => array( 'pending', OrderStatus::PENDING ),
			'authorized -> in_process'     => array( 'authorized', OrderStatus::IN_PROCESS ),
			'in_process -> in_process'     => array( 'in_process', OrderStatus::IN_PROCESS ),
			'rejected -> rejected'         => array( 'rejected', OrderStatus::REJECTED ),
			'cancelled -> cancelled'       => array( 'cancelled', OrderStatus::CANCELLED ),
			'refunded -> refunded'         => array( 'refunded', OrderStatus::REFUNDED ),
			'charged_back -> charged_back' => array( 'charged_back', OrderStatus::CHARGED_BACK ),
		);
	}

	#[DataProvider( 'estadosDeMercadoPago' )]
	public function test_traduce_los_estados_con_equivalente( string $raw, string $esperado ): void {
		$this->assertSame( $esperado, $this->gateway()->map_status( $raw ) );
	}

	public function test_normaliza_mayusculas_y_espacios(): void {
		$this->assertSame( OrderStatus::APPROVED, $this->gateway()->map_status( '  APPROVED ' ) );
	}

	public function test_in_mediation_no_tiene_equivalente(): void {
		$this->assertSame( '', $this->gateway()->map_status( 'in_mediation' ) );
	}

	public function test_un_estado_inventado_no_tiene_equivalente(): void {
		$this->assertSame( '', $this->gateway()->map_status( 'aprobadisimo' ) );
	}

	/**
	 * Si algún día se agrega una traducción a un estado que no existe, se cae
	 * acá y no en producción.
	 */
	public function test_todos_los_estados_mapeados_son_estados_internos_validos(): void {
		foreach ( MercadoPagoGateway::STATUS_MAP as $externo => $interno ) {
			$this->assertTrue(
				OrderStatus::is_valid( $interno ),
				sprintf( '%s se traduce a %s, que no es un estado interno', $externo, $interno )
			);
		}
	}

	public function test_el_item_lleva_el_monto_que_calculo_el_servidor(): void {
		$item = $this->gateway()->build_preference( self::ORDEN )['items'][0];

		$this->assertSame( 'traslado', $item['id'] );
		$this->assertSame( 'Traslado de carga', $item['title'] );
		$this->assertSame( 'CLP', $item['currency_id'] );
		$this->assertSame( 1, $item['quantity'] );
		$this->assertSame( 50000, $item['unit_price'] );
		$this->assertIsInt( $item['unit_price'] );
	}

	public function test_el_monto_se_convierte_a_entero_aunque_llegue_como_texto(): void {
		$orden           = self::ORDEN;
		$orden['amount'] = '50000';

		$item = $this->gateway()->build_preference( $orden )['items'][0];

		$this->assertSame( 50000, $item['unit_price'] );
		$this->assertIsInt( $item['unit_price'] );
	}

	public function test_la_descripcion_del_item_dice_el_radio(): void {
		$this->assertSame(
			'Servicio a 8 km',
			$this->gateway()->build_preference( self::ORDEN )['items'][0]['description']
		);
	}

	public function test_el_external_reference_es_el_id_del_pedido(): void {
		$this->assertSame( '7', $this->gateway()->build_preference( self::ORDEN )['external_reference'] );
	}

	public function test_la_preferencia_lleva_el_draft_id_en_metadata(): void {
		$this->assertSame( array( 'draft_id' => 'abc123' ), $this->gateway()->build_preference( self::ORDEN )['metadata'] );
	}

	public function test_el_payer_sale_del_pedido(): void {
		$payer = $this->gateway()->build_preference( self::ORDEN )['payer'];

		$this->assertSame( 'María González', $payer['name'] );
		$this->assertSame( 'maria@ejemplo.cl', $payer['email'] );
	}

	public function test_las_tres_back_urls_apuntan_al_sitio_con_su_caso(): void {
		$back = $this->gateway()->build_preference( self::ORDEN )['back_urls'];

		$this->assertSame( array( 'success', 'pending', 'failure' ), array_keys( $back ) );

		foreach ( $back as $caso => $url ) {
			$this->assertStringContainsString( 'simple_form_return=' . $caso, $url );
			$this->assertStringStartsWith( 'https://telobuscamos.local/', $url );
		}
	}

	/**
	 * El Referer lo controla el navegador: si apunta a otro host, no se usa.
	 */
	public function test_una_back_url_de_otro_host_no_se_acepta(): void {
		FakeWordPress::set_referer( 'https://sitio-ajeno.example/mapa/' );

		foreach ( $this->gateway()->build_preference( self::ORDEN )['back_urls'] as $url ) {
			$this->assertStringNotContainsString( 'sitio-ajeno.example', $url );
		}
	}

	public function test_sin_referer_se_vuelve_a_la_home(): void {
		FakeWordPress::set_referer( '' );

		$url = $this->gateway()->build_preference( self::ORDEN )['back_urls']['success'];

		$this->assertStringStartsWith( 'http://telobuscamos.local/', $url );
		$this->assertStringContainsString( 'simple_form_return=success', $url );
	}

	public function test_pide_retorno_automatico_solo_al_aprobar(): void {
		$this->assertSame( 'approved', $this->gateway()->build_preference( self::ORDEN )['auto_return'] );
	}

	public function test_la_notification_url_apunta_a_la_ruta_del_webhook(): void {
		$this->assertSame(
			'http://telobuscamos.local/wp-json/simple-form/webhook',
			$this->gateway()->build_preference( self::ORDEN )['notification_url']
		);
	}
}
