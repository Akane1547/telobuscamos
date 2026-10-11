<?php
/**
 * Limpieza programada: borradores abandonados y punteros de sesión vencidos.
 *
 * Un borrador es un pedido que se empezó y no se terminó. Sin esto, sf_orders
 * crece para siempre con formularios que nadie completó, y los punteros de
 * sesión de invitados se acumulan en wp_options.
 *
 * El cron se programa desde `maybe_schedule()`, que se llama en cada carga del
 * plugin y solo hace algo si el evento no existe: así una instalación que ya
 * estaba activa lo recibe sin desactivar y volver a activar.
 *
 * @package SimpleForm\Core
 */

namespace SimpleForm\Core;

use SimpleForm\Database\OrderRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Cleanup {

	const HOOK = 'simple_form_cleanup_event';

	/**
	 * Inactividad a partir de la cual un borrador o un pago sin confirmar pasa
	 * a expirado.
	 */
	const DRAFT_TTL = WEEK_IN_SECONDS;

	/**
	 * Engancha la tarea. Se instancia desde el arranque del plugin.
	 */
	public function __construct() {
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Asegura que el evento esté programado. Idempotente: si ya está, no hace nada.
	 */
	public static function maybe_schedule(): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
	}

	/**
	 * Desengancha el evento. Lo llama la desactivación del plugin.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * La tarea programada.
	 */
	public function run(): void {
		$pedidos  = OrderRepository::expire_drafts( self::DRAFT_TTL );
		$punteros = Session::delete_expired();

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf( 'SimpleForm limpieza: %d pedidos expirados, %d punteros borrados', $pedidos, $punteros )
			);
		}
	}
}
