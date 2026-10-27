<?php
/**
 * Canal de log propio del plugin.
 *
 * Un solo punto para todos los avisos de diagnóstico, apagado por defecto. Se
 * enciende con el filtro simple_form_logging_enabled (o con la constante
 * SIMPLE_FORM_LOGGING en wp-config.php, que manda sobre el filtro). Escribe con
 * el prefijo [simple-form][canal] en el log de errores del sitio.
 *
 * Nunca escribe credenciales ni datos personales completos: quien llama ya
 * entrega un mensaje acotado y sin secretos.
 *
 * @package SimpleForm\Core
 */

namespace SimpleForm\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Logger {

	/**
	 * Filtro que enciende el log. Por defecto apagado.
	 */
	const FILTER_ENABLED = 'simple_form_logging_enabled';

	/**
	 * Constante de wp-config.php que enciende el log y manda sobre el filtro.
	 */
	const CONSTANT_ENABLED = 'SIMPLE_FORM_LOGGING';

	/**
	 * Escribe un mensaje al canal indicado, solo si el log está encendido.
	 *
	 * @param string $channel Canal (mercado_pago, cleanup, geocoder…).
	 * @param string $message Mensaje sin credenciales ni datos personales.
	 * @return void
	 */
	public static function log( string $channel, string $message ): void {
		if ( ! self::enabled() ) {
			return;
		}

		error_log( sprintf( '[simple-form][%s] %s', $channel, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * La constante de wp-config.php manda; si no está, decide el filtro.
	 *
	 * @return bool
	 */
	private static function enabled(): bool {
		if ( defined( self::CONSTANT_ENABLED ) ) {
			return (bool) constant( self::CONSTANT_ENABLED );
		}

		return (bool) apply_filters( self::FILTER_ENABLED, false );
	}
}
