<?php

namespace SimpleForm\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rango del radio de búsqueda del mapa (Paso 2).
 *
 * Es la única fuente de verdad: el slider, el validador del navegador y la
 * validación del servidor leen de acá. El administrador mueve el mínimo y el
 * máximo desde *SimpleForm → Radio de búsqueda* y los tres se mueven juntos.
 *
 * El slider trabaja en metros (lo que ve el cliente) y lo que viaja al servidor
 * y se guarda en el pedido está en km. Los valores se cuantizan a 10 metros
 * porque `sf_orders.radius_km` es DECIMAL(5,2): con esa granularidad el km
 * guardado es exacto y el precio no cambia por redondeo.
 */
class Radius {

	const OPTION = 'simple_form_radius';

	/** Piso y techo duros: ninguna configuración puede salir de acá. */
	const FLOOR_M = 10;
	const CEIL_M  = 50000;

	/** Granularidad del slider, en metros. */
	const STEP_MIN_M = 10;

	const DEFAULTS = array(
		'min_m'  => 100,
		'max_m'  => 1000,
		'step_m' => 100,
	);

	/**
	 * @return array{min_m:int,max_m:int,step_m:int}
	 */
	public static function defaults(): array {
		return self::DEFAULTS;
	}

	/**
	 * La configuración vigente, ya normalizada. Nunca devuelve algo inválido.
	 *
	 * @return array{min_m:int,max_m:int,step_m:int}
	 */
	public static function all(): array {
		$raw = get_option( self::OPTION, null );

		return self::normalize( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Deja cualquier entrada dentro de los límites y en múltiplos de 10 metros.
	 *
	 * Es la red de seguridad, no la validación del formulario: la pantalla de
	 * admin rechaza lo inválido con un aviso en vez de corregirlo en silencio.
	 *
	 * @param array $raw
	 * @return array{min_m:int,max_m:int,step_m:int}
	 */
	public static function normalize( array $raw ): array {
		$max = self::quantize( self::int_or( $raw['max_m'] ?? null, self::DEFAULTS['max_m'], self::FLOOR_M + self::STEP_MIN_M, self::CEIL_M ) );
		$min = self::quantize( self::int_or( $raw['min_m'] ?? null, self::DEFAULTS['min_m'], self::FLOOR_M, $max - self::STEP_MIN_M ) );

		if ( $min >= $max ) {
			$min = max( self::FLOOR_M, $max - self::STEP_MIN_M );
		}

		$step = self::quantize( self::int_or( $raw['step_m'] ?? null, self::DEFAULTS['step_m'], self::STEP_MIN_M, max( self::STEP_MIN_M, $max - $min ) ) );

		return array(
			'min_m'  => $min,
			'max_m'  => $max,
			'step_m' => $step,
		);
	}

	public static function min_m(): int {
		return self::all()['min_m'];
	}

	public static function max_m(): int {
		return self::all()['max_m'];
	}

	public static function step_m(): int {
		return self::all()['step_m'];
	}

	public static function min_km(): float {
		return self::min_m() / 1000;
	}

	public static function max_km(): float {
		return self::max_m() / 1000;
	}

	/**
	 * ¿El radio que llegó del navegador está dentro del rango configurado?
	 *
	 * @param float $km
	 * @return bool
	 */
	public static function contains( float $km ): bool {
		// Tolerancia para el ruido de coma flotante al pasar metros a km.
		return $km >= self::min_km() - 0.0001 && $km <= self::max_km() + 0.0001;
	}

	/**
	 * "100 m", "1 km", "1,5 km": el mismo texto que ve el cliente en el slider.
	 *
	 * @param float $km
	 * @return string
	 */
	public static function format( float $km ): string {
		$metros = (int) round( $km * 1000 );

		if ( $metros < 1000 ) {
			return sprintf( '%d m', $metros );
		}

		return rtrim( rtrim( number_format( $metros / 1000, 1, ',', '.' ), '0' ), ',' ) . ' km';
	}

	/**
	 * Siembra el rango por defecto la primera vez. No pisa una configuración
	 * existente (aunque tenga valores distintos a los de fábrica).
	 */
	public static function install(): void {
		if ( null !== get_option( self::OPTION, null ) ) {
			return;
		}

		update_option( self::OPTION, self::DEFAULTS );
	}

	/**
	 * @param mixed $value
	 * @param int   $default
	 * @param int   $min
	 * @param int   $max
	 * @return int
	 */
	private static function int_or( $value, int $default, int $min, int $max ): int {
		if ( ! is_numeric( $value ) ) {
			return $default;
		}

		return (int) max( $min, min( $max, (int) round( (float) $value ) ) );
	}

	/**
	 * Redondea a múltiplos de STEP_MIN_M (10 m) sin bajar del piso.
	 *
	 * El redondeo va ANTES del piso: si se aplica el piso al cociente, cualquier
	 * valor menor a 100 m termina convertido en 100 m.
	 */
	private static function quantize( int $metros ): int {
		return max( self::STEP_MIN_M, (int) round( $metros / self::STEP_MIN_M ) * self::STEP_MIN_M );
	}
}
