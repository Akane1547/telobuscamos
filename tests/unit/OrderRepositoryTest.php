<?php
/**
 * Expiración de pedidos abandonados.
 *
 * La limpieza programada se apoya en esto: un borrador o un pago sin confirmar
 * que quedó inactivo pasa a expirado. Se prueba contra un $wpdb falso, mirando
 * los argumentos que llegan a la consulta.
 *
 * @package SimpleForm\Tests
 */

namespace SimpleForm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleForm\Database\OrderRepository;
use SimpleForm\Payments\OrderStatus;
use SimpleForm\Tests\FakeWordPress;

final class OrderRepositoryTest extends TestCase {

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
			public $filas = 3;

			/** @var array */
			public $argumentos = array();

			/** @var string */
			public $consulta = '';

			public function prepare( $query, ...$args ) {
				$this->consulta   = (string) $query;
				$this->argumentos = $args;

				return $query;
			}

			public function query( $query ) {
				return $this->filas;
			}
		};
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_original;

		parent::tearDown();
	}

	public function test_marca_los_abandonados_y_devuelve_cuantos(): void {
		$filas = OrderRepository::expire_drafts( WEEK_IN_SECONDS );

		$this->assertSame( 3, $filas );

		$args = $GLOBALS['wpdb']->argumentos;

		// El primer argumento es el estado nuevo: nunca otro que no sea expirado.
		$this->assertSame( OrderStatus::EXPIRED, $args[0] );

		// Los dos estados que se tocan: borrador y pendiente de pago.
		$this->assertSame( OrderStatus::DRAFT, $args[2] );
		$this->assertSame( OrderStatus::PENDING, $args[3] );

		// Un pedido pagado no se expira nunca: no está entre los estados.
		$this->assertNotContains( OrderStatus::APPROVED, $args );
	}

	public function test_el_corte_es_el_ttl_hacia_atras(): void {
		OrderRepository::expire_drafts( WEEK_IN_SECONDS );

		$corte = (string) $GLOBALS['wpdb']->argumentos[4];
		$esperado = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );

		// Tolerancia de un minuto por el tiempo que corre entre las dos llamadas.
		$this->assertLessThanOrEqual( 60, abs( strtotime( $corte ) - strtotime( $esperado ) ) );
	}

	public function test_un_ttl_invalido_no_toca_nada(): void {
		$this->assertSame( 0, OrderRepository::expire_drafts( 0 ) );
		$this->assertSame( array(), $GLOBALS['wpdb']->argumentos );
	}
}
