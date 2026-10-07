<?php
/**
 * Esquema propio del plugin y su versionado.
 *
 * Única fuente de verdad de las tablas: la activación y la migración en
 * caliente llaman al mismo create_tables(), así que no hay dos definiciones
 * del esquema que puedan divergir.
 *
 * @package SimpleForm\Database
 */

namespace SimpleForm\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Database {

	/**
	 * Opción donde se guarda la versión del esquema aplicada.
	 */
	const VERSION_OPTION = 'simple_form_db_version';

	/**
	 * Versión del esquema. Subir al cambiar cualquier tabla.
	 */
	const DB_VERSION = 1;

	const TABLE_ORDERS         = 'sf_orders';
	const TABLE_PAYMENT_EVENTS = 'sf_payment_events';

	/**
	 * Nombre completo de una tabla con el prefijo de WordPress.
	 *
	 * Solo debe recibir una de las constantes TABLE_*: nunca entrada del
	 * usuario, porque el valor se interpola en SQL.
	 *
	 * @param string $table Constante TABLE_*.
	 * @return string
	 */
	public static function table_name( string $table ): string {
		global $wpdb;

		return $wpdb->prefix . $table;
	}

	/**
	 * Crea o actualiza el esquema. Idempotente: dbDelta() solo aplica las
	 * diferencias que encuentra, así que se puede llamar en cada migración.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset        = $wpdb->get_charset_collate();
		$orders         = self::table_name( self::TABLE_ORDERS );
		$payment_events = self::table_name( self::TABLE_PAYMENT_EVENTS );

		// Snapshot del servicio y del precio: editar o borrar un servicio no
		// puede reescribir pedidos históricos. Los montos CLP son enteros.
		// Las fechas se guardan en UTC.
		$sql = array(
			"CREATE TABLE {$orders} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				draft_id VARCHAR(64) NOT NULL,
				full_name VARCHAR(191) NOT NULL DEFAULT '',
				email VARCHAR(191) NOT NULL DEFAULT '',
				phone VARCHAR(32) NOT NULL DEFAULT '',
				description TEXT NULL,
				service_id VARCHAR(191) NOT NULL DEFAULT '',
				service_label VARCHAR(191) NOT NULL DEFAULT '',
				service_base_price BIGINT NOT NULL DEFAULT 0,
				service_price_per_km BIGINT NOT NULL DEFAULT 0,
				lat DECIMAL(10,7) NULL,
				lng DECIMAL(10,7) NULL,
				radius_km SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				amount BIGINT NOT NULL DEFAULT 0,
				payment_method VARCHAR(64) NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT 'draft',
				external_payment_id VARCHAR(191) NULL,
				current_step TINYINT UNSIGNED NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				paid_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY draft_id (draft_id),
				KEY status (status),
				KEY external_payment_id (external_payment_id)
			) {$charset};",
			"CREATE TABLE {$payment_events} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				order_id BIGINT UNSIGNED NOT NULL,
				gateway VARCHAR(64) NOT NULL DEFAULT '',
				external_event_id VARCHAR(191) NOT NULL,
				type VARCHAR(64) NOT NULL DEFAULT '',
				raw_status VARCHAR(64) NOT NULL DEFAULT '',
				payload LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY external_event_id (external_event_id),
				KEY order_id (order_id)
			) {$charset};",
		);

		dbDelta( $sql );

		update_option( self::VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Migra el esquema si la versión guardada no coincide con DB_VERSION.
	 * Se llama en plugins_loaded: get_option() sobre una opción autoload
	 * cuesta una lectura en memoria, no una consulta.
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION === (int) get_option( self::VERSION_OPTION, 0 ) ) {
			return;
		}

		self::create_tables();
	}
}
