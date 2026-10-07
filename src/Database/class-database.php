<?php
/**
 * Acceso a la base de datos
 *
 * @package SimpleForm\Core
 */

namespace SimpleForm\Core;

if ( ! defined('ABSPATH')){
    exit;
}

final class Database {

    //Que funciones debe tener database?
    //Diferentes tecnologias que necesite
    //Que variables debe tener database?
    //Que valores les asignaremos una propiedad


    //Primera variable (TABLE)
    //El nombre de la tabla

    private const TABLE = 'nombre';

    //Funcion table_name()
    //Devuelve el nombre de la tabla con prefijo WP
    //Une wpdb prefix con TABLE

    //Segunda variable
    //wpdb siendo una variable de wordpress

    public static function table_name(): string{
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    //Funcion create_table();
    //Crea las tablas a nuestra base de datos.
    //Usa dbDelta()
    //Se inicia desde Activate

    public static function create_table(): void {


        global $wpdb;

        require_once ABSPATH . 'wp-admin/include/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			draft_id VARCHAR(64) NOT NULL,
			full_name VARCHAR(191) NULL,
			email VARCHAR(191) NULL,
			phone VARCHAR(32) NULL,
			description TEXT NULL,
			service_type VARCHAR(64) NULL,
			lat DECIMAL(10,7) NULL,
			lng DECIMAL(10,7) NULL,
			radius SMALLINT UNSIGNED NULL,
			estimated_price DECIMAL(10,2) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY draft_id (draft_id)
		) {$charset};";
 
		dbDelta( $sql );
    }

    //


}
