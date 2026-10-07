<?php
/**
 * Pantalla de administración de los planes/servicios.
 *
 * Los planes se crean y editan aquí: son los que alimentan el select del
 * Paso 2 y de los que sale el precio (base + precio por km). El servidor
 * guarda una lista de planes; el orden de la pantalla es el orden del
 * select.
 *
 * @package SimpleForm\Admin
 */

namespace SimpleForm\Admin;

use SimpleForm\Core\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminServices {

	const OPTION_KEY = 'simple_form_services';

	const HANDLE_ADMIN_JS = 'simple-form-admin-services';
	const SCREEN_ID       = 'toplevel_page_simple-form-services';

	/**
	 * Marcador que llevan los nombres de la fila modelo, que el JS sustituye
	 * por el índice real al clonarla. No es un número para que jamás pueda
	 * coincidir con una fila enviada.
	 */
	const MODEL_MARKER = '__NEXT__';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_sf_save_services', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'Servicios de pago', 'simple-form' ),
			__( 'Servicios de pago', 'simple-form' ),
			'manage_options',
			'simple-form-services',
			array( $this, 'render_page' ),
			'dashicons-money-alt'
		);
	}

	/**
	 * Encola el JS de la pantalla, solo en esta pantalla.
	 *
	 * @param string $hook_suffix Identificador de la pantalla actual.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( self::SCREEN_ID !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE_ADMIN_JS,
			SIMPLE_FORM_URL . 'assets/js/admin-services.js',
			array(),
			Assets::asset_version( 'assets/js/admin-services.js', SIMPLE_FORM_VERSION ),
			true
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$services = get_option( self::OPTION_KEY, array() );
		$services = is_array( $services ) ? array_values( $services ) : array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Servicios de pago', 'simple-form' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['updated'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Servicios guardados.', 'simple-form' ); ?></p>
				</div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Estos planes alimentan el select del Paso 2 del formulario. Los precios son en pesos chilenos, sin decimales.', 'simple-form' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sf_save_services', 'sf_services_nonce' ); ?>
				<input type="hidden" name="action" value="sf_save_services" />

				<table class="widefat" id="sf-services-table" data-next-index="<?php echo esc_attr( (string) count( $services ) ); ?>">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID (slug)', 'simple-form' ); ?></th>
							<th><?php esc_html_e( 'Etiqueta', 'simple-form' ); ?></th>
							<th><?php esc_html_e( 'Precio base (CLP)', 'simple-form' ); ?></th>
							<th><?php esc_html_e( 'Precio por km (CLP)', 'simple-form' ); ?></th>
							<th><span class="screen-reader-text"><?php esc_html_e( 'Acciones', 'simple-form' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $services ) ) : ?>
							<?php $this->render_row( 0, array() ); ?>
						<?php else : ?>
							<?php foreach ( $services as $index => $service ) : ?>
								<?php $this->render_row( (int) $index, is_array( $service ) ? $service : array() ); ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<p>
					<button type="button" class="button" id="sf-add-service"><?php esc_html_e( 'Añadir plan', 'simple-form' ); ?></button>
				</p>

				<?php submit_button( __( 'Guardar servicios', 'simple-form' ) ); ?>

				<p class="description"><?php esc_html_e( 'Una fila sin ID no se guarda: así se descarta. El ID es el valor que viaja en el formulario, se escribe una sola vez y no conviene cambiarlo.', 'simple-form' ); ?></p>
			</form>

			<?php // Fila modelo que el JS clona. Va fuera del formulario para que sus campos nunca se envíen. ?>
			<table hidden>
				<tbody>
					<?php $this->render_row( 0, array(), true ); ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Pinta una fila de plan.
	 *
	 * @param int   $index     Índice que llevará el nombre de los campos. En la
	 *                         fila modelo va 0 y manda el marcador.
	 * @param array $service   Datos guardados del plan.
	 * @param bool  $is_model  True para la fila que el JS clona.
	 */
	private function render_row( int $index, array $service, bool $is_model = false ): void {
		$field = 'services[' . ( $is_model ? self::MODEL_MARKER : (string) $index ) . ']';
		?>
		<tr<?php echo $is_model ? ' data-service-row-model="' . esc_attr( self::MODEL_MARKER ) . '" hidden' : ' data-service-row="1"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- atributo construido con una constante de la clase. ?>>
			<td>
				<input type="text" class="regular-text" name="<?php echo esc_attr( $field ); ?>[id]" value="<?php echo esc_attr( (string) ( $service['id'] ?? '' ) ); ?>" />
			</td>
			<td>
				<input type="text" class="regular-text" name="<?php echo esc_attr( $field ); ?>[label]" value="<?php echo esc_attr( (string) ( $service['label'] ?? '' ) ); ?>" />
			</td>
			<td>
				<input type="number" min="0" step="1" class="small-text" name="<?php echo esc_attr( $field ); ?>[base_price]" value="<?php echo esc_attr( (string) (int) ( $service['base_price'] ?? 0 ) ); ?>" />
			</td>
			<td>
				<input type="number" min="0" step="1" class="small-text" name="<?php echo esc_attr( $field ); ?>[price_per_km]" value="<?php echo esc_attr( (string) (int) ( $service['price_per_km'] ?? 0 ) ); ?>" />
			</td>
			<td>
				<button type="button" class="button-link" data-remove-service><?php esc_html_e( 'Eliminar', 'simple-form' ); ?></button>
			</td>
		</tr>
		<?php
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No autorizado.', 'simple-form' ) );
		}

		check_admin_referer( 'sf_save_services', 'sf_services_nonce' );

		$raw      = isset( $_POST['services'] ) && is_array( $_POST['services'] ) ? wp_unslash( $_POST['services'] ) : array();
		$services = array();
		$seen     = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$id = sanitize_title( $row['id'] ?? '' );

			// Sin ID la fila se descarta. Un ID repetido dejaría dos planes
			// con la misma clave y get_service_by_id() solo encontraría uno:
			// se conserva el primero.
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;

			$services[] = array(
				'id'           => $id,
				'label'        => sanitize_text_field( $row['label'] ?? '' ),
				'base_price'   => absint( round( (float) ( $row['base_price'] ?? 0 ) ) ),
				'price_per_km' => absint( round( (float) ( $row['price_per_km'] ?? 0 ) ) ),
			);
		}

		update_option( self::OPTION_KEY, $services );

		wp_safe_redirect( admin_url( 'admin.php?page=simple-form-services&updated=1' ) );
		exit;
	}
}
