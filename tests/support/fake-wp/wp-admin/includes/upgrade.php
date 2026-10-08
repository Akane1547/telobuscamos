<?php
/**
 * Doble de wp-admin/includes/upgrade.php para las pruebas unitarias.
 *
 * Database::create_tables() hace require_once de este archivo a través de
 * ABSPATH, que en el arranque de la suite unitaria apunta acá. No es el dbDelta
 * real: solo guarda el SQL que le llega para que la prueba pueda mirarlo.
 *
 * @package SimpleForm\Tests
 */

if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $queries ) {
		$GLOBALS['sf_dbdelta_queries'][] = $queries;

		return array();
	}
}
