<?php

namespace SimpleForm\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menú general del plugin en wp-admin.
 *
 * Antes cada sección era un menú de primer nivel. Ahora hay uno solo,
 * *SimpleForm*, y las secciones cuelgan de él. Los slugs de cada pantalla no
 * cambian (`admin.php?page=simple-form-services` y compañía siguen
 * funcionando), solo dejan de ser menús sueltos.
 *
 * Se registra con prioridad 9 para que el menú padre exista antes de que cada
 * sección agregue su submenú.
 */
class AdminMenu {

	const SLUG = 'simple-form';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 9 );
	}

	public function register_menu(): void {
		add_menu_page(
			'SimpleForm',
			'SimpleForm',
			'manage_options',
			self::SLUG,
			array( $this, 'render_page' ),
			'dashicons-location-alt',
			56
		);
	}

	/**
	 * Portada del menú: qué hay en cada sección y el acceso directo.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$secciones = array(
			array(
				'slug'  => 'simple-form-services',
				'titulo' => __( 'Servicios de pago', 'simple-form' ),
				'texto' => __( 'Los planes que el cliente elige en el Paso 2. El precio base y el valor por km salen de acá.', 'simple-form' ),
			),
			array(
				'slug'  => 'simple-form-payments',
				'titulo' => __( 'Medios de pago', 'simple-form' ),
				'texto' => __( 'Qué medios se ofrecen en el Paso 3 y con qué credenciales se cobran.', 'simple-form' ),
			),
			array(
				'slug'  => 'simple-form-radius',
				'titulo' => __( 'Radio de búsqueda', 'simple-form' ),
				'texto' => __( 'El mínimo y el máximo del radio que el cliente puede elegir en el mapa.', 'simple-form' ),
			),
			array(
				'slug'  => 'simple-form-catalogs',
				'titulo' => __( 'Catálogos del objeto', 'simple-form' ),
				'texto' => __( 'Las listas de tipo, tamaño y material que se ofrecen en el Paso 1.', 'simple-form' ),
			),
			array(
				'slug'  => 'simple-form-orders',
				'titulo' => __( 'Pedidos', 'simple-form' ),
				'texto' => __( 'Todo lo que entró, con el estado del pago y los datos del objeto.', 'simple-form' ),
			),
		);
		?>
		<div class="wrap">
			<h1>SimpleForm</h1>
			<p><?php esc_html_e( 'Configuración del formulario de búsqueda. Cada sección tiene su propia pantalla.', 'simple-form' ); ?></p>

			<table class="widefat striped" style="max-width:860px">
				<tbody>
					<?php foreach ( $secciones as $seccion ) : ?>
						<tr>
							<td style="width:220px">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $seccion['slug'] ) ); ?>"><strong><?php echo esc_html( $seccion['titulo'] ); ?></strong></a>
							</td>
							<td><?php echo esc_html( $seccion['texto'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
