<?php
/**
 * Límite de peticiones para los endpoints públicos.
 *
 * Los endpoints del formulario los puede llamar cualquiera: el nonce no es
 * autenticación y viaja con la página. Esto corta el abuso —llenar la tabla de
 * borradores, gastar la cuota de la API de la pasarela— sin molestar a un
 * comprador real, que hace unas pocas peticiones por minuto.
 *
 * La clave del contador es la acción más el cliente. Se usa REMOTE_ADDR, que no
 * se puede falsificar. Detrás de un proxy inverso REMOTE_ADDR es el proxy, así
 * que quien despliegue puede aportar la IP real con el filtro
 * `simple_form_client_ip`: por defecto no se lee X-Forwarded-For, porque lo
 * puede inventar cualquiera.
 *
 * @package SimpleForm\Security
 */

namespace SimpleForm\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RateLimit {

	const PREFIX = 'sf_rate_limit_';
	const WINDOW = MINUTE_IN_SECONDS;

	/**
	 * Cuenta una petición y dice si está dentro del límite.
	 *
	 * @param string $action Acción que se está limitando.
	 * @param int    $limit  Peticiones permitidas por ventana. 0 o menos = sin límite.
	 * @param int    $window Ventana en segundos.
	 * @return bool true si la petición pasa.
	 */
	public static function check( string $action, int $limit, int $window = self::WINDOW ): bool {
		/**
		 * Ajusta el límite por sitio, o lo desactiva con 0 (por ejemplo en pruebas).
		 *
		 * @param int    $limit  Límite propuesto.
		 * @param string $action Acción limitada.
		 */
		$limit = (int) apply_filters( 'simple_form_rate_limit', $limit, $action );

		if ( $limit <= 0 ) {
			return true;
		}

		$key   = self::key( $action );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			// Al rechazar no se renueva la ventana: si no, quien insiste no sale
			// nunca del bloqueo.
			return false;
		}

		set_transient( $key, $count + 1, $window );

		return true;
	}

	/**
	 * Clave del contador para esta acción y este cliente.
	 *
	 * @param string $action
	 * @return string
	 */
	private static function key( string $action ): string {
		// El hash acorta la clave: los nombres de option tienen tope de largo.
		return self::PREFIX . md5( $action . '|' . self::client_id() );
	}

	/**
	 * Identifica al cliente por su IP de origen.
	 *
	 * @return string
	 */
	private static function client_id(): string {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );

		/**
		 * IP del cliente detrás de un proxy inverso.
		 *
		 * @param string $ip Por defecto, REMOTE_ADDR.
		 */
		return (string) apply_filters( 'simple_form_client_ip', $ip );
	}
}
