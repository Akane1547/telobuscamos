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

// Los medios de pago y las credenciales de Fase 3 se agregan a esta lista
// cuando existan: no se borran nombres que el plugin todavía no escribe.

// Punteros de sesión de invitados, con su timeout.
$session_like = $wpdb->esc_like( '_transient_sf_client_session_' ) . '%';
$timeout_like = $wpdb->esc_like( '_transient_timeout_sf_client_session_' ) . '%';

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$session_like,
		$timeout_like
	)
);
