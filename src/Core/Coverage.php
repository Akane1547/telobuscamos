<?php
/**
 * Cobertura geográfica: qué ubicaciones acepta el servicio.
 *
 * Hoy es todo el territorio de Chile, validado contra el contorno real del
 * país con point-in-polygon. Sustituye al círculo fijo de 50 km sobre
 * Santiago que traía el plugin.
 *
 * El contorno viene de Natural Earth 1:50m (dominio público) y vive en
 * src/Data/chile-boundary.json, sin simplificar.
 *
 * @package SimpleForm\Core
 */

namespace SimpleForm\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Coverage {

	const DATA_FILE = 'src/Data/chile-boundary.json';

	/**
	 * Margen de tolerancia sobre el contorno, en km.
	 *
	 * El contorno viene generalizado: una ciudad costera puede caer a unos
	 * cientos de metros del borde dibujado (Punta Arenas queda a 0,66 km con
	 * el dato de 1:10m). Sin este margen se rechazarían direcciones válidas.
	 * El costo es aceptar también puntos a esa distancia del lado argentino,
	 * que es preferible a rechazar clientes reales.
	 */
	const TOLERANCE_KM = 2.0;

	/**
	 * Anillos del contorno, en caché por petición.
	 *
	 * @var array|null
	 */
	private static $rings = null;

	/**
	 * @var array|null
	 */
	private static $bounds = null;

	/**
	 * Indica si un punto cae dentro del territorio.
	 *
	 * Ray casting: se cuentan las aristas del anillo que cruzan el rayo
	 * horizontal del punto. Un punto justo sobre el borde puede caer de
	 * cualquiera de los dos lados, lo que a efectos de decidir cobertura es
	 * irrelevante (metros de diferencia).
	 *
	 * Se recorre primero el anillo mayor (el continente), así que la mayoría
	 * de los puntos se resuelven en la primera pasada.
	 *
	 * @param float $lat
	 * @param float $lng
	 * @return bool
	 */
	public static function contains( float $lat, float $lng ): bool {
		foreach ( self::rings() as $ring ) {
			if ( self::point_in_ring( $lat, $lng, $ring ) ) {
				return true;
			}
		}

		// Fuera del contorno todavía puede ser Chile: se acepta cualquier punto
		// a menos de TOLERANCE_KM del borde dibujado.
		return self::distance_to_rings_km( $lat, $lng ) <= self::TOLERANCE_KM;
	}

	/**
	 * Anillos del contorno, cada uno como lista de pares [lat, lng].
	 *
	 * Devuelve un array vacío si el archivo de datos no se puede leer: sin
	 * contorno no se acepta ninguna ubicación, que es el lado seguro.
	 *
	 * @return array
	 */
	public static function rings(): array {
		if ( null !== self::$rings ) {
			return self::$rings;
		}

		self::$rings = array();

		$file = SIMPLE_FORM_PATH . self::DATA_FILE;

		if ( ! is_readable( $file ) ) {
			return self::$rings;
		}

		$raw = json_decode( (string) file_get_contents( $file ), true );

		if ( is_array( $raw ) && ! empty( $raw['rings'] ) && is_array( $raw['rings'] ) ) {
			self::$rings = $raw['rings'];
		}

		return self::$rings;
	}

	/**
	 * Encuadre inicial del mapa: south, north, west, east.
	 *
	 * Excluye Isla de Pascua y Salas y Gómez, que ensancharían la vista hasta
	 * dejar el continente minúsculo. La validación sí usa todos los anillos.
	 *
	 * @return array
	 */
	public static function bounds(): array {
		if ( null !== self::$bounds ) {
			return self::$bounds;
		}

		self::$bounds = array();

		$file = SIMPLE_FORM_PATH . self::DATA_FILE;

		if ( ! is_readable( $file ) ) {
			return self::$bounds;
		}

		$raw = json_decode( (string) file_get_contents( $file ), true );

		if ( is_array( $raw ) && ! empty( $raw['focus_bounds'] ) && is_array( $raw['focus_bounds'] ) ) {
			self::$bounds = $raw['focus_bounds'];
		}

		return self::$bounds;
	}

	/**
	 * Distancia mínima del punto al borde de cualquiera de los anillos, en km.
	 *
	 * @param float $lat
	 * @param float $lng
	 * @return float INF si no hay anillos.
	 */
	private static function distance_to_rings_km( float $lat, float $lng ): float {
		$best = INF;

		foreach ( self::rings() as $ring ) {
			$best = min( $best, self::distance_to_ring_km( $lat, $lng, $ring ) );
		}

		return $best;
	}

	/**
	 * Distancia del punto al segmento más cercano del anillo, en km.
	 *
	 * Aproximación equirectangular (x = lng · 111,32 · cos(lat), y = lat · 110,57):
	 * de sobra para decidir kilómetros, y mucho más barata que Haversine.
	 *
	 * @param float $lat
	 * @param float $lng
	 * @param array $ring Puntos [lat, lng].
	 * @return float
	 */
	private static function distance_to_ring_km( float $lat, float $lng, array $ring ): float {
		$kx   = 111.32 * cos( deg2rad( $lat ) );
		$ky   = 110.57;
		$x    = $lng * $kx;
		$y    = $lat * $ky;
		$best = INF;
		$count = count( $ring );

		for ( $i = 0, $j = $count - 1; $i < $count; $j = $i++ ) {
			$x1 = (float) $ring[ $j ][1] * $kx;
			$y1 = (float) $ring[ $j ][0] * $ky;
			$x2 = (float) $ring[ $i ][1] * $kx;
			$y2 = (float) $ring[ $i ][0] * $ky;

			$dx    = $x2 - $x1;
			$dy    = $y2 - $y1;
			$largo = ( $dx * $dx ) + ( $dy * $dy );

			if ( $largo <= 0.0 ) {
				$distancia = hypot( $x - $x1, $y - $y1 );
			} else {
				$t = ( ( $x - $x1 ) * $dx + ( $y - $y1 ) * $dy ) / $largo;
				$t = max( 0.0, min( 1.0, $t ) );

				$distancia = hypot( $x - ( $x1 + ( $t * $dx ) ), $y - ( $y1 + ( $t * $dy ) ) );
			}

			if ( $distancia < $best ) {
				$best = $distancia;
			}
		}

		return $best;
	}

	/**
	 * @param float $lat
	 * @param float $lng
	 * @param array $ring Puntos [lat, lng].
	 * @return bool
	 */
	private static function point_in_ring( float $lat, float $lng, array $ring ): bool {
		$inside = false;
		$count  = count( $ring );

		for ( $i = 0, $j = $count - 1; $i < $count; $j = $i++ ) {
			$lat_i = (float) $ring[ $i ][0];
			$lng_i = (float) $ring[ $i ][1];
			$lat_j = (float) $ring[ $j ][0];
			$lng_j = (float) $ring[ $j ][1];

			// Solo las aristas que cruzan el rayo horizontal del punto.
			if ( ( $lng_i > $lng ) !== ( $lng_j > $lng ) ) {
				$corte = ( $lat_j - $lat_i ) * ( $lng - $lng_i ) / ( $lng_j - $lng_i ) + $lat_i;

				if ( $lat < $corte ) {
					$inside = ! $inside;
				}
			}
		}

		return $inside;
	}
}
