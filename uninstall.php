<?php
/**
 * Limpieza al desinstalar el plugin.
 *
 * WordPress carga este archivo sin cargar el plugin, así que el autoloader
 * PSR-4 no está registrado: se incluye Database a mano. Así los nombres de las
 * tablas viven en un solo lugar y no se duplican aquí.
 *
 * @package SimpleForm
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

use SimpleForm\Database\Database;

require_once __DIR__ . '/src/Database/Database.php';

global $wpdb;

$tables = array(
	Database::table_name( Database::TABLE_ORDERS ),
	Database::table_name( Database::TABLE_PAYMENT_EVENTS ),
);

foreach ( $tables as $table ) {
	// El nombre sale de constantes de la clase, nunca de entrada del usuario.
	// MySQL no admite marcadores para identificadores, así que prepare() no
	// aplica aquí; se entrecomilla con backticks.
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

delete_option( 'simple_form_services' );
delete_option( 'simple_form_db_version' );

// Medios de pago y credenciales de la Fase 3 (cifradas).
delete_option( 'simple_form_payment_methods' );
delete_option( 'simple_form_payment_credentials' );

// La limpieza programada muere con el plugin.
wp_clear_scheduled_hook( 'simple_form_cleanup_event' );

// Punteros de sesión de invitados y contadores del límite de peticiones, con
// sus timeouts. Los prefijos son los mismos que usan Session y RateLimit: se
// repiten acá porque WordPress carga este archivo sin el autoloader.
foreach ( array( 'sf_client_session_', 'sf_rate_limit_' ) as $prefijo ) {
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_' . $prefijo ) . '%',
			$wpdb->esc_like( '_transient_timeout_' . $prefijo ) . '%'
		)
	);
}
