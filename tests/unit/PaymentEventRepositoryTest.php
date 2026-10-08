<?php
/**
 * Registro de eventos de pago: la idempotencia del webhook.
 *
 * Corre con un $wpdb falso, así que no toca la base: lo que se prueba es la
 * decisión, que es lo que importa. En particular que un error de escritura NO se
 * confunda con un duplicado — si se confundieran, el evento legítimo se
 * saltearía y el pedido quedaría sin actualizar.
 *
 * @package SimpleForm\Tests
 */

namespace SimpleForm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleForm\Database\PaymentEventRepository;
use SimpleForm\Tests\FakeWordPress;

final class PaymentEventRepositoryTest extends TestCase {

	/** @var mixed */
	private $wpdb_original;

	protected function setUp(): void {
		parent::setUp();
		FakeWordPress::reset();

		$this->wpdb_original = $GLOBALS['wpdb'] ?? null;

		$GLOBALS['wpdb'] = new class() {
			/** @var string */
			public $prefix = 'wp_';

			/** @var int|false */
			public $resultado_insert = 1;

			/** @var bool */
			public $existe = false;

			/** @var array */
			public $filas = array();

			/** @var array|null */
			public $ultimo_insert = null;

			public function prepare( $query, ...$args ) {
				return $query;
			}

			public function insert( $table, $data, $format = null ) {
				$this->ultimo_insert = array(
					'tabla'  => $table,
					'datos'  => $data,
					'formato' => $format,
				);

				return $this->resultado_insert;
			}

			public function get_var( $query = null ) {
				return $this->existe ? '5' : null;
			}

			public function get_results( $query = null, $output = null ) {
				return $this->filas;
			}
		};
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_original;

		parent::tearDown();
	}

	private function evento( array $extra = array() ): array {
		return $extra + array(
			'order_id'          => 42,
			'gateway'           => 'mercado_pago',
			'external_event_id' => 'mp-123456',
			'type'              => 'payment',
			'raw_status'        => 'approved',
			'payload'           => array( 'id' => 123456, 'status' => 'approved' ),
		);
	}

	public function test_un_evento_nuevo_se_registra(): void {
		$this->assertSame( PaymentEventRepository::INSERTED, PaymentEventRepository::record( $this->evento() ) );
	}

	public function test_escribe_en_la_tabla_de_eventos(): void {
		PaymentEventRepository::record( $this->evento() );

		$this->assertSame( 'wp_sf_payment_events', $GLOBALS['wpdb']->ultimo_insert['tabla'] );
	}

	public function test_guarda_los_campos_del_evento(): void {
		PaymentEventRepository::record( $this->evento() );

		$datos = $GLOBALS['wpdb']->ultimo_insert['datos'];

		$this->assertSame( 42, $datos['order_id'] );
		$this->assertSame( 'mercado_pago', $datos['gateway'] );
		$this->assertSame( 'mp-123456', $datos['external_event_id'] );
		$this->assertSame( 'payment', $datos['type'] );
		$this->assertSame( 'approved', $datos['raw_status'] );
		$this->assertSame( '{"id":123456,"status":"approved"}', $datos['payload'] );
		$this->assertNotSame( '', (string) $datos['created_at'] );
	}

	/**
	 * El caso que hace que el webhook sea idempotente.
	 */
	public function test_un_evento_repetido_no_se_registra_de_nuevo(): void {
		$GLOBALS['wpdb']->resultado_insert = false;
		$GLOBALS['wpdb']->existe           = true;

		$this->assertSame( PaymentEventRepository::DUPLICATE, PaymentEventRepository::record( $this->evento() ) );
	}

	/**
	 * Y el caso opuesto: un error de escritura no es un duplicado. Si se
	 * confundieran, el evento se saltearía y el pedido nunca se actualizaría.
	 */
	public function test_un_error_de_escritura_no_es_un_duplicado(): void {
		$GLOBALS['wpdb']->resultado_insert = false;
		$GLOBALS['wpdb']->existe           = false;

		$this->assertSame( PaymentEventRepository::FAILED, PaymentEventRepository::record( $this->evento() ) );
	}

	public function test_sin_order_id_no_registra(): void {
		$GLOBALS['wpdb']->ultimo_insert = null;

		$this->assertSame( PaymentEventRepository::FAILED, PaymentEventRepository::record( $this->evento( array( 'order_id' => 0 ) ) ) );
		$this->assertNull( $GLOBALS['wpdb']->ultimo_insert );
	}

	public function test_sin_identificador_externo_no_registra(): void {
		$GLOBALS['wpdb']->ultimo_insert = null;

		$this->assertSame( PaymentEventRepository::FAILED, PaymentEventRepository::record( $this->evento( array( 'external_event_id' => '' ) ) ) );
		$this->assertNull( $GLOBALS['wpdb']->ultimo_insert );
	}

	public function test_un_payload_gigante_se_recorta(): void {
		PaymentEventRepository::record(
			$this->evento( array( 'payload' => array( 'relleno' => str_repeat( 'x', PaymentEventRepository::MAX_PAYLOAD + 500 ) ) ) )
		);

		$this->assertSame(
			PaymentEventRepository::MAX_PAYLOAD,
			strlen( $GLOBALS['wpdb']->ultimo_insert['datos']['payload'] )
		);
	}

	public function test_un_payload_nulo_se_guarda_como_nulo(): void {
		PaymentEventRepository::record( $this->evento( array( 'payload' => null ) ) );

		$this->assertNull( $GLOBALS['wpdb']->ultimo_insert['datos']['payload'] );
	}

	public function test_exists_sin_identificador_no_consulta(): void {
		$this->assertFalse( PaymentEventRepository::exists( '' ) );
	}

	public function test_exists_encuentra_el_evento(): void {
		$GLOBALS['wpdb']->existe = true;

		$this->assertTrue( PaymentEventRepository::exists( 'mp-123456' ) );
	}

	public function test_find_by_order_sin_pedido_devuelve_vacio(): void {
		$this->assertSame( array(), PaymentEventRepository::find_by_order( 0 ) );
	}

	public function test_find_by_order_devuelve_las_filas(): void {
		$GLOBALS['wpdb']->filas = array( array( 'id' => 1, 'order_id' => 42 ) );

		$this->assertSame( array( array( 'id' => 1, 'order_id' => 42 ) ), PaymentEventRepository::find_by_order( 42 ) );
	}
}
