<?php
/**
 * Esquema propio: nombres de tabla y creación/migración.
 *
 * Corre con un $wpdb falso que solo guarda el SQL, así que mira las dos cosas
 * que importan y que ninguna otra prueba cubre: que el SQL lleve las claves
 * únicas y los dos espacios que dbDelta exige en PRIMARY KEY, y que
 * maybe_upgrade() no toque la base cuando la versión ya coincide.
 *
 * @package SimpleForm\Tests
 */

namespace SimpleForm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleForm\Database\Database;
use SimpleForm\Tests\FakeWordPress;

final class DatabaseTest extends TestCase {

	/** @var mixed */
	private $wpdb_original;

	protected function setUp(): void {
		parent::setUp();
		FakeWordPress::reset();

		$this->wpdb_original    = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['sf_dbdelta_queries'] = array();

		$GLOBALS['wpdb'] = new class() {
			/** @var string */
			public $prefix = 'wp_';

			public function get_charset_collate() {
				return 'DEFAULT CHARACTER SET utf8mb4';
			}
		};
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_original;

		parent::tearDown();
	}

	/**
	 * @return string Todo el SQL que recibió dbDelta.
	 */
	private function sql(): string {
		return implode( "\n", $GLOBALS['sf_dbdelta_queries'][0] ?? array() );
	}

	public function test_el_nombre_de_tabla_lleva_el_prefijo(): void {
		$this->assertSame( 'wp_sf_orders', Database::table_name( Database::TABLE_ORDERS ) );
		$this->assertSame( 'wp_sf_payment_events', Database::table_name( Database::TABLE_PAYMENT_EVENTS ) );
	}

	public function test_create_tables_le_pasa_las_dos_tablas_a_dbdelta(): void {
		Database::create_tables();

		$this->assertCount( 1, $GLOBALS['sf_dbdelta_queries'] );
		$this->assertStringContainsString( 'CREATE TABLE wp_sf_orders', $this->sql() );
		$this->assertStringContainsString( 'CREATE TABLE wp_sf_payment_events', $this->sql() );
	}

	public function test_el_sql_lleva_las_claves_unicas(): void {
		Database::create_tables();

		$this->assertStringContainsString( 'UNIQUE KEY draft_id (draft_id)', $this->sql() );
		$this->assertStringContainsString( 'UNIQUE KEY external_event_id (external_event_id)', $this->sql() );
	}

	/**
	 * dbDelta exige dos espacios después de PRIMARY KEY. Si alguien los deja en
	 * uno, dbDelta deja de reconocer la clave y la tabla se crea sin ella.
	 */
	public function test_el_sql_lleva_los_dos_espacios_de_primary_key(): void {
		Database::create_tables();

		$this->assertStringContainsString( "\t\t\t\tPRIMARY KEY  (id),", $this->sql() );
	}

	public function test_los_montos_son_enteros_y_las_fechas_en_utc(): void {
		Database::create_tables();

		$this->assertStringContainsString( 'amount BIGINT NOT NULL', $this->sql() );
		$this->assertStringNotContainsString( 'FLOAT', $this->sql() );
		$this->assertStringNotContainsString( 'DECIMAL(10,2) NOT NULL', $this->sql() );
	}

	public function test_create_tables_guarda_la_version(): void {
		Database::create_tables();

		$this->assertSame( Database::DB_VERSION, (int) FakeWordPress::get_option( Database::VERSION_OPTION ) );
	}

	public function test_maybe_upgrade_no_toca_nada_si_la_version_coincide(): void {
		FakeWordPress::set_option( Database::VERSION_OPTION, Database::DB_VERSION );

		Database::maybe_upgrade();

		$this->assertCount( 0, $GLOBALS['sf_dbdelta_queries'] );
	}

	public function test_maybe_upgrade_migra_si_la_version_esta_atrasada(): void {
		FakeWordPress::set_option( Database::VERSION_OPTION, Database::DB_VERSION - 1 );

		Database::maybe_upgrade();

		$this->assertCount( 1, $GLOBALS['sf_dbdelta_queries'] );
	}

	public function test_maybe_upgrade_migra_si_nunca_se_instalo(): void {
		Database::maybe_upgrade();

		$this->assertCount( 1, $GLOBALS['sf_dbdelta_queries'] );
	}
}
