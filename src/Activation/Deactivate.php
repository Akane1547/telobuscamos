<?php
/**
 * Lógica de desactivación del plugin.
 *
 * @package SimpleForm\Activation
 */

namespace SimpleForm\Activation;

use SimpleForm\Core\Cleanup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Deactivate {

	/**
	 * Punto de entrada llamado por register_deactivation_hook().
	 *
	 * Se desengancha la limpieza programada. El esquema y los datos se quedan:
	 * desactivar no es desinstalar. Los punteros de sesión vencidos expiran
	 * solos, y los que ya vencieron los recoge la limpieza al reactivar.
	 */
	public static function deactivate(): void {
		Cleanup::unschedule();
	}
}