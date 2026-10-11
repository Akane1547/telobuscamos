<?php
/**
 * Búsqueda de direcciones contra Photon (datos de OpenStreetMap).
 *
 * El navegador nunca habla con el proveedor: consulta nuestro endpoint
 * (simple_form_geocode) y este resuelve. Así el filtro "solo Chile" vive en el
 * servidor, se puede cachear —el servicio es gratuito y su política pide uso
 * razonable— y cambiar de proveedor es tocar solo este archivo.
 *
 * @package SimpleForm\Core
 */

namespace SimpleForm\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Geocoder {

	/**
	 * Servidor público de Photon (komoot). Es el demo oficial: no pide clave,
	 * pide uso razonable y no garantiza disponibilidad.
	 */
	const ENDPOINT = 'https://photon.komoot.io/api/';

	/**
	 * Las direcciones cambian poco: una semana de caché por consulta.
	 */
	const CACHE_TTL = WEEK_IN_SECONDS;

	/**
	 * Un resultado vacío se guarda poco tiempo a propósito: puede ser "esa
	 * dirección no existe" (bueno cachearlo) o "el proveedor está caído"
	 * (malo cachearlo). Diez minutos cubren lo primero sin envenenar la semana.
	 */
	const CACHE_TTL_EMPTY = 10 * MINUTE_IN_SECONDS;

	const CACHE_PREFIX = 'sf_geo_';

	/**
	 * Resultados que se le devuelven al cliente.
	 */
	const LIMIT = 5;

	/**
	 * Mínimo de caracteres para consultar.
	 */
	const MIN_CHARS = 3;

	const TIMEOUT = 8;

	/**
	 * Caja de Chile: sesga la relevancia hacia el país.
	 *
	 * Ojo: Photon NO filtra por esta caja, solo ordena con ella. Verificado
	 * contra la API real: "Mendoza 100" con la caja de Chile devuelve resultados
	 * argentinos. El filtro de verdad es countrycode, en to_result().
	 */
	const CHILE_BBOX = '-109.5,-56.0,-66.0,-17.5';

	/**
	 * Busca direcciones y devuelve solo las que están en Chile.
	 *
	 * @param string $query Lo que escribió el cliente.
	 * @return array Lista de [ 'label' => string, 'lat' => float, 'lng' => float ].
	 */
	public function search( string $query ): array {
		$query = trim( $query );

		if ( strlen( $query ) < self::MIN_CHARS ) {
			return array();
		}

		$key    = self::CACHE_PREFIX . md5( strtolower( $query ) );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$results = $this->fetch( $query );

		// También se cachea el resultado vacío (una dirección que no existe no
		// se vuelve a consultar en cada letra), pero por poco tiempo: ver
		// CACHE_TTL_EMPTY.
		set_transient( $key, $results, array() === $results ? self::CACHE_TTL_EMPTY : self::CACHE_TTL );

		return $results;
	}

	/**
	 * Consulta al proveedor y traduce la respuesta.
	 *
	 * @param string $query
	 * @return array
	 */
	private function fetch( string $query ): array {
		$url = self::ENDPOINT . '?' . http_build_query(
			array(
				'q'     => $query,
				'limit' => self::LIMIT,
				'bbox'  => self::CHILE_BBOX, // solo sesgo de relevancia, ver la constante.
			)
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'User-Agent' => $this->user_agent() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'la consulta falló: ' . $response->get_error_message() );

			return array();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$this->log( 'el proveedor respondió ' . $code );

			return array();
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || ! isset( $data['features'] ) || ! is_array( $data['features'] ) ) {
			$this->log( 'la respuesta no trae features' );

			return array();
		}

		$results = array();

		foreach ( $data['features'] as $feature ) {
			if ( ! is_array( $feature ) ) {
				continue;
			}

			$item = $this->to_result( $feature );

			if ( null !== $item ) {
				$results[] = $item;
			}
		}

		return $results;
	}

	/**
	 * Traduce una feature de GeoJSON a lo que necesita el buscador.
	 *
	 * Público para poder probarlo con respuestas reales del proveedor.
	 *
	 * @param array $feature
	 * @return array|null Null si está fuera de Chile, sin nombre o sin coordenadas.
	 */
	public function to_result( array $feature ): ?array {
		$props = isset( $feature['properties'] ) && is_array( $feature['properties'] ) ? $feature['properties'] : array();

		// Acá se sostiene el "solo direcciones de Chile": el proveedor devuelve
		// el mundo entero, ordenado por relevancia.
		if ( ! isset( $props['countrycode'] ) || 'CL' !== $props['countrycode'] ) {
			return null;
		}

		$coords = isset( $feature['geometry']['coordinates'] ) && is_array( $feature['geometry']['coordinates'] )
			? $feature['geometry']['coordinates']
			: array();

		// GeoJSON: [ longitud, latitud ].
		if ( ! isset( $coords[0], $coords[1] ) || ! is_numeric( $coords[0] ) || ! is_numeric( $coords[1] ) ) {
			return null;
		}

		$label = $this->label( $props );

		if ( '' === $label ) {
			return null;
		}

		return array(
			'label' => $label,
			'lat'   => (float) $coords[1],
			'lng'   => (float) $coords[0],
		);
	}

	/**
	 * Etiqueta que ve el cliente: "Avenida Providencia 1234, Providencia".
	 *
	 * @param array $props properties de la feature.
	 * @return string '' si no hay nada que mostrar.
	 */
	public function label( array $props ): string {
		$parts = array();

		$street = isset( $props['street'] ) ? trim( (string) $props['street'] ) : '';
		$number = isset( $props['housenumber'] ) ? trim( (string) $props['housenumber'] ) : '';

		if ( '' !== $street ) {
			// En Chile hay calles sin numeración cargada en OSM: se muestra la
			// calle sola antes que inventar una altura.
			$parts[] = '' !== $number ? $street . ' ' . $number : $street;
		} elseif ( isset( $props['name'] ) && '' !== trim( (string) $props['name'] ) ) {
			$parts[] = trim( (string) $props['name'] );
		}

		if ( array() === $parts ) {
			return '';
		}

		// La comuna (o la ciudad) desambigua: hay una "Avenida España" en varias
		// ciudades. La región se omite a propósito; repetirla en cada sugerencia
		// solo agrega ruido.
		foreach ( array( 'district', 'city', 'county' ) as $key ) {
			$value = isset( $props[ $key ] ) ? trim( (string) $props[ $key ] ) : '';

			if ( '' === $value ) {
				continue;
			}

			if ( $value !== $parts[0] ) {
				$parts[] = $value;
			}

			break;
		}

		return implode( ', ', $parts );
	}

	/**
	 * Se identifica como pide la política de uso de los servicios de OSM.
	 *
	 * @return string
	 */
	private function user_agent(): string {
		$version = defined( 'SIMPLE_FORM_VERSION' ) ? SIMPLE_FORM_VERSION : '1.0.0';

		return 'simple-form/' . $version . ' (+' . home_url( '/' ) . ')';
	}

	/**
	 * @param string $message
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[simple-form] geocoder: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
