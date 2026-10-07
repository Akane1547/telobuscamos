<?php
/**
 * Pantalla de administración para las categorías/servicios.
 *
 * @package SimpleForm\Admin
 */

namespace SimpleForm\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminServices {

	const OPTION_KEY = 'simple_form_services';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_sf_save_services', array( $this, 'handle_save' ) );
	}

	public function register_menu(): void {
		add_menu_page(
			'Servicios de pago',
			'Servicios de pago',
			'manage_options',
			'simple-form-services',
			array( $this, 'render_page' ),
			'dashicons-money-alt'
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$services = get_option( self::OPTION_KEY, array() );
		?>
		<div class="wrap">
			<h1>Servicios de pago</h1>
			<?php if ( isset( $_GET['updated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['updated'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Servicios guardados.', 'simple-form' ); ?></p>
				</div>
			<?php endif; ?>
			<p>Estas categorías alimentan el <code>select</code> del Paso 2 del formulario.</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sf_save_services', 'sf_services_nonce' ); ?>
				<input type="hidden" name="action" value="sf_save_services" />

				<table class="widefat" id="sf-services-table">
					<thead>
						<tr>
							<th>ID (slug)</th>
							<th>Etiqueta</th>
							<th>Precio base</th>
							<th>Precio por km</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $services ) ) : ?>
							<tr>
								<td><input type="text" name="services[0][id]" /></td>
								<td><input type="text" name="services[0][label]" /></td>
								<td><input type="number" step="0.01" name="services[0][base_price]" /></td>
								<td><input type="number" step="0.01" name="services[0][price_per_km]" /></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $services as $i => $service ) : ?>
								<tr>
									<td><input type="text" name="services[<?php echo esc_attr( $i ); ?>][id]" value="<?php echo esc_attr( $service['id'] ?? '' ); ?>" /></td>
									<td><input type="text" name="services[<?php echo esc_attr( $i ); ?>][label]" value="<?php echo esc_attr( $service['label'] ?? '' ); ?>" /></td>
									<td><input type="number" step="0.01" name="services[<?php echo esc_attr( $i ); ?>][base_price]" value="<?php echo esc_attr( $service['base_price'] ?? 0 ); ?>" /></td>
									<td><input type="number" step="0.01" name="services[<?php echo esc_attr( $i ); ?>][price_per_km]" value="<?php echo esc_attr( $service['price_per_km'] ?? 0 ); ?>" /></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<?php submit_button( 'Guardar servicios' ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.' );
		}

		check_admin_referer( 'sf_save_services', 'sf_services_nonce' );

		$raw      = isset( $_POST['services'] ) && is_array( $_POST['services'] ) ? wp_unslash( $_POST['services'] ) : array();
		$services = array();

		foreach ( $raw as $row ) {
			$id = sanitize_title( $row['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}

			// Se guarda exactamente como lista/array de objetos (como le gusta a JS)
			$services[] = array(
				'id'           => $id,
				'label'        => sanitize_text_field( $row['label'] ?? '' ),
				'base_price'   => (float) ( $row['base_price'] ?? 0 ),
				'price_per_km' => (float) ( $row['price_per_km'] ?? 0 ),
			);
		}

		update_option( self::OPTION_KEY, $services );

wp_safe_redirect( admin_url( 'admin.php?page=simple-form-services&updated=1' ) );		exit;
	}
}