<?php
/**
 * Aplicación de un pago a un pedido: las decisiones.
 *
 * Es el punto donde un evento de la pasarela se convierte (o no) en un cambio de
 * estado. Corre con una pasarela falsa y un $wpdb falso, así que se prueba lo
 * único que puede salir mal acá: la decisión.
 *
 * @package SimpleForm\Tests
 */

namespace SimpleForm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleForm\Payments\Gateway;
use SimpleForm\Payments\OrderStatus;
use SimpleForm\Payments\PaymentProcessor;
use SimpleForm\Tests\FakeWordPress;

final class PaymentProcessorTest extends TestCase {

	/** @var mixed */
	private $wpdb_original;

	protected function setUp(): void {
		parent::setUp();
		FakeWordPress::reset();

		$this->wpdb_original = $GLOBALS['wpdb'] ?? null;

		$GLOBALS['wpdb'] = new class() {
			/** @var string */
			public $prefix = 'wp_';

			/** @var int */
			public $update_resultado = 1;

			/** @var array */
			public $ultimo_update = array();

			public function prepare( $query, ...$args ) {
				return $query;
			}

			public function update( $table, $data, $where ) {
				$this->ultimo_update = array(
					'tabla' => $table,
					'datos' => $data,
					'where' => $where,
				);

				return $this->update_resultado;
			}
		};
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_original;

		parent::tearDown();
	}

	/**
	 * Pasarela de mentira: devuelve el pago que le digan y traduce con el mapa
	 * que le pasen, para que cada prueba hable de su escenario.
	 */
	private function gateway( array $payment, array $map = array() ): Gateway {
		return new class( $payment, $map ) implements Gateway {
			/** @var array */
			private $payment;

			/** @var array */
			private $map;

			public function __construct( array $payment, array $map ) {
				$this->payment = $payment;
				$this->map     = $map;
			}

			public function create_payment( array $order ): string {
				return '';
			}

			public function fetch_payment( string $external_id ): array {
				return $this->payment;
			}

			public function map_status( string $raw_status ): string {
				return $this->map[ $raw_status ] ?? '';
			}
		};
	}

	private function pedido( string $status = OrderStatus::PENDING ): array {
		return array(
			'id'     => 42,
			'status' => $status,
		);
	}

	public function test_un_pago_aprobado_mueve_el_pedido_y_sella_la_fecha(): void {
		$gateway = $this->gateway(
			array( 'id' => '900', 'status' => 'approved', 'external_reference' => '42' ),
			array( 'approved' => OrderStatus::APPROVED )
		);

		$resultado = PaymentProcessor::apply( $this->pedido(), $gateway, '900' );

		$this->assertSame( OrderStatus::APPROVED, $resultado['status'] );
		$this->assertFalse( $resultado['retry'] );
		$this->assertSame( 'wp_sf_orders', $GLOBALS['wpdb']->ultimo_update['tabla'] );
		$this->assertSame( OrderStatus::APPROVED, $GLOBALS['wpdb']->ultimo_update['datos']['status'] );
		$this->assertSame( '900', $GLOBALS['wpdb']->ultimo_update['datos']['external_payment_id'] );
		$this->assertNotEmpty( $GLOBALS['wpdb']->ultimo_update['datos']['paid_at'] );
	}

	public function test_un_pago_pendiente_no_sella_la_fecha_de_pago(): void {
		$gateway = $this->gateway(
			array( 'status' => 'pending', 'external_reference' => '42' ),
			array( 'pending' => OrderStatus::PENDING )
		);

		PaymentProcessor::apply( $this->pedido( OrderStatus::DRAFT ), $gateway, '900' );

		$this->assertArrayNotHasKey( 'paid_at', $GLOBALS['wpdb']->ultimo_update['datos'] );
	}

	public function test_un_rechazo_vuelve_a_pending_para_permitir_el_reintento(): void {
		$gateway = $this->gateway(
			array( 'status' => 'rejected', 'external_reference' => '42' ),
			array( 'rejected' => OrderStatus::REJECTED )
		);

		$this->assertSame( OrderStatus::REJECTED, PaymentProcessor::apply( $this->pedido(), $gateway, '900' )['status'] );

		$gateway2 = $this->gateway(
			array( 'status' => 'pending', 'external_reference' => '42' ),
			array( 'pending' => OrderStatus::PENDING )
		);

		$this->assertSame(
			OrderStatus::PENDING,
			PaymentProcessor::apply( $this->pedido( OrderStatus::REJECTED ), $gateway2, '900' )['status']
		);
	}

	/**
	 * El caso que evita el fraude más obvio: mandar el id de pago de un tercero.
	 */
	public function test_un_pago_de_otro_pedido_no_se_aplica(): void {
		$gateway = $this->gateway(
			array( 'status' => 'approved', 'external_reference' => '999' ),
			array( 'approved' => OrderStatus::APPROVED )
		);

		$resultado = PaymentProcessor::apply( $this->pedido(), $gateway, '900' );

		$this->assertSame( '', $resultado['status'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->ultimo_update );
	}

	public function test_un_estado_sin_equivalente_no_toca_el_pedido(): void {
		$gateway = $this->gateway(
			array( 'status' => 'in_mediation', 'external_reference' => '42' ),
			array()
		);

		$this->assertSame( '', PaymentProcessor::apply( $this->pedido(), $gateway, '900' )['status'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->ultimo_update );
	}

	/**
	 * Un evento viejo no puede degradar un estado que ya avanzó.
	 */
	public function test_un_evento_antiguo_no_degrada_un_estado_aprobado(): void {
		$gateway = $this->gateway(
			array( 'status' => 'pending', 'external_reference' => '42' ),
			array( 'pending' => OrderStatus::PENDING )
		);

		$resultado = PaymentProcessor::apply( $this->pedido( OrderStatus::APPROVED ), $gateway, '900' );

		$this->assertSame( '', $resultado['status'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->ultimo_update );
	}

	public function test_un_pago_ya_aplicado_no_vuelve_a_escribir(): void {
		$gateway = $this->gateway(
			array( 'status' => 'approved', 'external_reference' => '42' ),
			array( 'approved' => OrderStatus::APPROVED )
		);

		$resultado = PaymentProcessor::apply( $this->pedido( OrderStatus::APPROVED ), $gateway, '900' );

		$this->assertSame( OrderStatus::APPROVED, $resultado['status'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->ultimo_update );
	}

	/**
	 * Único caso en el que hay que pedirle a la pasarela que reintente.
	 */
	public function test_si_no_se_puede_consultar_el_pago_se_pide_reintento(): void {
		$resultado = PaymentProcessor::apply( $this->pedido(), $this->gateway( array(), array() ), '900' );

		$this->assertSame( '', $resultado['status'] );
		$this->assertTrue( $resultado['retry'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->ultimo_update );
	}

	public function test_si_la_escritura_falla_no_se_reporta_como_aplicado(): void {
		$GLOBALS['wpdb']->update_resultado = false;

		$gateway = $this->gateway(
			array( 'status' => 'approved', 'external_reference' => '42' ),
			array( 'approved' => OrderStatus::APPROVED )
		);

		$resultado = PaymentProcessor::apply( $this->pedido(), $gateway, '900' );

		$this->assertSame( '', $resultado['status'] );
		$this->assertFalse( $resultado['retry'] );
	}

	public function test_sin_id_de_pago_no_se_hace_nada(): void {
		$this->assertSame( '', PaymentProcessor::apply( $this->pedido(), $this->gateway( array() ), '' )['status'] );
	}

	public function test_sin_id_de_pedido_no_se_hace_nada(): void {
		$gateway = $this->gateway( array( 'status' => 'approved', 'external_reference' => '0' ) );

		$this->assertSame( '', PaymentProcessor::apply( array( 'id' => 0 ), $gateway, '900' )['status'] );
	}
}
