<?php
/**
 * Pantalla de administración de los catálogos del objeto.
 *
 * Tres listas —tipo, tamaño y material— que alimentan los selects del Paso 1.
 * El administrador agrega, cambia o borra entradas; el formulario las pide por
 * AJAX y el servidor solo acepta ids que existan en la lista.
 *
 * @package SimpleForm\Admin
 */

namespace SimpleForm\Admin;

use SimpleForm\Core\Assets;
use SimpleForm\Core\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AdminCatalogs
 */
class AdminCatalogs {

	/**
	 * Hook de la pantalla, tal como lo devuelve add_menu_page().
	 *
	 * @var string
	 */
	private $screen_id = '';

	/**
	 * Marcador de la fila modelo que el JS clona.
	 */
	const MODEL_MARKER = '__NEXT__';

	/**
	 * Hook del JavaScript de la pantalla.
	 */
	const HANDLE_ADMIN_JS = 'simple-form-admin-catalogs';

	/**
	 * Engancha menú, guardado y assets.
	 */
	public function __construct() {
		// Prioridad 13: dentro de SimpleForm va después de Radio de búsqueda.
		add_action( 'admin_menu', array( $this, 'register_menu' ), 13 );
		add_action( 'admin_post_sf_save_catalogs', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Menú "Catálogos del objeto".
	 */
	public function register_menu(): void {
		$this->screen_id = (string) add_submenu_page(
			AdminMenu::SLUG,
			__( 'Catálogos del objeto', 'simple-form' ),
			__( 'Catálogos del objeto', 'simple-form' ),
			'manage_options',
			'simple-form-catalogs',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Encola el JS solo en esta pantalla.
	 *
	 * @param string $hook_suffix Pantalla actual.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( $this->screen_id !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE_ADMIN_JS,
			SIMPLE_FORM_URL . 'assets/js/admin-catalogs.js',
			array(),
			Assets::asset_version( 'assets/js/admin-catalogs.js', SIMPLE_FORM_VERSION ),
			true
		);
	}

	/**
	 * Pinta la pantalla con las tres listas.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$catalogs = Catalog::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Catálogos del objeto', 'simple-form' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['updated'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Catálogos guardados.', 'simple-form' ); ?></p>
				</div>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: %d: tope de entradas por lista. */
					esc_html__( 'Estas listas alimentan el Paso 1 del formulario. Si una queda vacía, ese campo no se le pide al cliente. Máximo %d entradas por lista.', 'simple-form' ),
					(int) Catalog::MAX_ITEMS
				);
				?>
			</p>
			<p class="description"><?php esc_html_e( 'El ID es el valor que viaja y se guarda en el pedido: se escribe una sola vez y conviene no cambiarlo después. La etiqueta es lo que ve el cliente.', 'simple-form' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sf_save_catalogs', 'sf_catalogs_nonce' ); ?>
				<input type="hidden" name="action" value="sf_save_catalogs" />

				<?php foreach ( Catalog::KINDS as $kind ) : ?>
					<h2><?php echo esc_html( (string) Catalog::KIND_LABELS[ $kind ] ); ?></h2>
					<?php $this->render_table( $kind, $catalogs[ $kind ] ?? array() ); ?>
				<?php endforeach; ?>

				<?php submit_button( __( 'Guardar catálogos', 'simple-form' ) ); ?>
			</form>

			<?php // Filas modelo que el JS clona, fuera del formulario para que nunca se envíen. ?>
			<?php foreach ( Catalog::KINDS as $kind ) : ?>
				<table hidden>
					<tbody>
						<?php $this->render_row( $kind, 0, array(), true ); ?>
					</tbody>
				</table>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Tabla de una lista.
	 *
	 * @param string $kind  types|sizes|materials.
	 * @param array  $items Entradas guardadas.
	 */
	private function render_table( string $kind, array $items ): void {
		$slug = 'sf-catalogs-' . $kind;
		?>
		<table class="widefat" id="<?php echo esc_attr( $slug ); ?>" data-catalog-list="<?php echo esc_attr( $kind ); ?>" data-next-index="<?php echo esc_attr( (string) count( $items ) ); ?>">
			<thead>
				<tr>
					<th><?php esc_html_e( 'ID (slug)', 'simple-form' ); ?></th>
					<th><?php esc_html_e( 'Etiqueta', 'simple-form' ); ?></th>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Acciones', 'simple-form' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $items ) ) : ?>
					<?php $this->render_row( $kind, 0, array() ); ?>
				<?php else : ?>
					<?php foreach ( $items as $index => $item ) : ?>
						<?php $this->render_row( $kind, (int) $index, is_array( $item ) ? $item : array() ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<p>
			<button type="button" class="button" data-add-catalog="<?php echo esc_attr( $kind ); ?>">
				<?php esc_html_e( 'Añadir entrada', 'simple-form' ); ?>
			</button>
		</p>
		<?php
	}

	/**
	 * Fila de una lista.
	 *
	 * @param string $kind     types|sizes|materials.
	 * @param int    $index    Índice del nombre del campo.
	 * @param array  $item     Datos guardados.
	 * @param bool   $is_model True para la fila que clona el JS.
	 */
	private function render_row( string $kind, int $index, array $item, bool $is_model = false ): void {
		$campo = 'catalogs[' . $kind . '][' . ( $is_model ? self::MODEL_MARKER : (string) $index ) . ']';
		?>
		<tr<?php echo $is_model ? ' data-catalog-row-model="' . esc_attr( self::MODEL_MARKER ) . '" hidden' : ' data-catalog-row="1"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- atributo con una constante de la clase. ?>>
			<td>
				<input type="text" class="regular-text" name="<?php echo esc_attr( $campo ); ?>[id]" value="<?php echo esc_attr( (string) ( $item['id'] ?? '' ) ); ?>" />
			</td>
			<td>
				<input type="text" class="regular-text" name="<?php echo esc_attr( $campo ); ?>[label]" value="<?php echo esc_attr( (string) ( $item['label'] ?? '' ) ); ?>" />
			</td>
			<td>
				<button type="button" class="button-link" data-remove-catalog><?php esc_html_e( 'Eliminar', 'simple-form' ); ?></button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Guarda las tres listas.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No autorizado.', 'simple-form' ) );
		}

		check_admin_referer( 'sf_save_catalogs', 'sf_catalogs_nonce' );

		$raw = isset( $_POST['catalogs'] ) && is_array( $_POST['catalogs'] ) ? wp_unslash( $_POST['catalogs'] ) : array();

		$limpio = array();

		foreach ( Catalog::KINDS as $kind ) {
			$lista = isset( $raw[ $kind ] ) && is_array( $raw[ $kind ] ) ? $raw[ $kind ] : array();

			// Catalog::normalize() descarta filas sin id, repetidas, vacías y
			// las que pasan del tope; el id va con sanitize_title().
			$limpio[ $kind ] = Catalog::normalize( $lista );
		}

		update_option( Catalog::OPTION, $limpio );

		wp_safe_redirect( admin_url( 'admin.php?page=simple-form-catalogs&updated=1' ) );
		exit;
	}
}
