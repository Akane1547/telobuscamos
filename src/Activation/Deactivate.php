<?php
/**
 * Lógica de desactivación del plugin.
 *
 * @package SimpleForm\Activation
 */

namespace SimpleForm\Activation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Deactivate {

	/**
	 * Punto de entrada llamado por register_deactivation_hook().
	 *
	 * Sin tareas de limpieza por ahora: no hay cron ni esquema propio, y
	 * los transients de sesión expiran solos. La limpieza de borradores
	 * se agrega junto con su fase.
	 */
	public static function deactivate(): void {
	}
}