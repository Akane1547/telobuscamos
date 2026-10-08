<?php
/**
 * Pantalla de administración de los medios de pago.
 *
 * Aquí se elige qué pasarelas ofrece el Paso 3 del formulario y se cargan sus
 * credenciales. Esta pantalla no conoce ninguna pasarela en concreto: el
 * registro (Gateways) dice cuáles hay y qué campos pide cada una, así que añadir
 * una es una entrada en el registro y nada más.
 *
 * Las credenciales nunca se pintan: si hay un valor guardado el campo sale vacío
 * con un marcador, y en blanco significa "no lo cambio". Si el valor lo fija una
 * constante de wp-config.php, el campo sale bloqueado y se dice de dónde sale.
 *
 * @package SimpleForm\Admin
 */

namespace SimpleForm\Admin;

use SimpleForm\Payments\Gateways;
use SimpleForm\Payments\PaymentConfig;
use SimpleForm\Security\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminPayments {

	const MENU_SLUG = 'simple-form-payments';

	const ACTION_METHODS     = 'sf_save_payment_methods';
	const ACTION_CREDENTIALS = 'sf_save_payment_credentials';

	const NONCE_METHODS     = 'sf_payment_methods_nonce';
	const NONCE_CREDENTIALS = 'sf_payment_credentials_nonce';

	public function __construct() {
		// Prioridad 11: el menú padre lo registra AdminServices en la 10.
		add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
		add_action( 'admin_post_' . self::ACTION_METHODS, array( $this, 'handle_save_methods' ) );
		add_action( 'admin_post_' . self::ACTION_CREDENTIALS, array( $this, 'handle_save_credentials' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			'simple-form-services',
			__( 'Medios de pago', 'simple-form' ),
			__( 'Medios de pago', 'simple-form' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$ids     = Gateways::ids();
		$enabled = PaymentConfig::enabled_ids();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Medios de pago', 'simple-form' ); ?></h1>

			<?php $this->render_notices(); ?>

			<p><?php esc_html_e( 'Estos son los medios que ofrece el Paso 3 del formulario. Un medio que no esté activo no se ofrece al cliente ni se acepta desde el servidor.', 'simple-form' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION_METHODS, self::NONCE_METHODS ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_METHODS ); ?>" />

				<table class="widefat striped" style="max-width: 760px;">
					<thead>
						<tr>
							<th style="width: 80px;"><?php esc_html_e( 'Activo', 'simple-form' ); ?></th>
							<th><?php esc_html_e( 'Medio', 'simple-form' ); ?></th>
							<th><?php esc_html_e( 'Credenciales', 'simple-form' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $ids ) ) : ?>
							<tr>
								<td colspan="3"><?php esc_html_e( 'No hay ninguna pasarela registrada.', 'simple-form' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $ids as $id ) : ?>
								<tr>
									<td>
										<input type="checkbox" name="methods[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, $enabled, true ) ); ?> />
									</td>
									<td><?php echo esc_html( Gateways::label( $id ) ); ?></td>
									<td><?php echo esc_html( $this->sources_summary( $id ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<?php submit_button( __( 'Guardar medios', 'simple-form' ) ); ?>
			</form>

			<?php $this->render_credentials_form( $ids ); ?>

			<?php $this->render_checks(); ?>
		</div>
		<?php
	}

	/**
	 * Aviso de guardado, errores y los dos avisos de configuración.
	 */
	private function render_notices(): void {
		// Solo es un aviso de pantalla tras una redirección nuestra; no decide nada.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$updated = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';

		$mensajes = array(
			'methods'     => array( 'success', __( 'Medios guardados.', 'simple-form' ) ),
			'credentials' => array( 'success', __( 'Credenciales guardadas.', 'simple-form' ) ),
		);

		if ( isset( $mensajes[ $updated ] ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $mensajes[ $updated ][0] ),
				esc_html( $mensajes[ $updated ][1] )
			);
		}

		$errores = array(
			'https'   => __( 'No se guardó: en modo producción la dirección del sitio tiene que ser HTTPS.', 'simple-form' ),
			'crypto'  => __( 'No se guardó: este PHP no puede cifrar (falta libsodium) o wp-config.php no tiene salts.', 'simple-form' ),
			'unknown' => __( 'No se guardó: esa pasarela no está registrada.', 'simple-form' ),
		);

		if ( isset( $errores[ $error ] ) ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $errores[ $error ] )
			);
		}

		$ids     = Gateways::ids();
		$enabled = PaymentConfig::enabled_ids();

		if ( empty( $ids ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'No hay ninguna pasarela registrada: el formulario no puede cobrar.', 'simple-form' )
			);
			return;
		}

		if ( empty( $enabled ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'No hay ningún medio activo: el Paso 3 no ofrecerá ninguno y el formulario no se podrá completar.', 'simple-form' )
			);
		}

		$en_sandbox = array();

		foreach ( $enabled as $id ) {
			if ( PaymentConfig::MODE_SANDBOX === PaymentConfig::mode( $id ) ) {
				$en_sandbox[] = Gateways::label( $id );
			}
		}

		if ( ! empty( $en_sandbox ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Modo de pruebas.', 'simple-form' ),
				esc_html(
					sprintf(
						/* translators: %s: lista de pasarelas en modo de pruebas. */
						__( 'Estos medios están en sandbox y no cobrarán dinero real: %s.', 'simple-form' ),
						implode( ', ', $en_sandbox )
					)
				)
			);
		}
	}

	/**
	 * Formulario de credenciales, una sección por pasarela registrada. Los campos
	 * salen del registro: esta pantalla no sabe qué pide Mercado Pago.
	 *
	 * @param array $ids
	 */
	private function render_credentials_form( array $ids ): void {
		if ( empty( $ids ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Credenciales', 'simple-form' ); ?></h2>
		<p><?php esc_html_e( 'Los valores no se muestran nunca. Un campo en blanco conserva el que ya estaba guardado.', 'simple-form' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ACTION_CREDENTIALS, self::NONCE_CREDENTIALS ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CREDENTIALS ); ?>" />

			<?php foreach ( $ids as $id ) : ?>
				<h3><?php echo esc_html( Gateways::label( $id ) ); ?></h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Modo', 'simple-form' ); ?></th>
						<td><?php $this->render_mode_select( $id ); ?></td>
					</tr>
					<?php foreach ( Gateways::credential_fields( $id ) as $field ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $this->field_label( $field ) ); ?></th>
							<td><?php $this->render_credential_field( $id, $field ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endforeach; ?>

			<?php submit_button( __( 'Guardar credenciales', 'simple-form' ) ); ?>
		</form>
		<?php
	}

	/**
	 * @param string $id
	 */
	private function render_mode_select( string $id ): void {
		$name   = 'credentials[' . $id . '][mode]';
		$mode   = PaymentConfig::mode( $id );
		$source = PaymentConfig::source( $id, 'mode' );

		if ( 'constant' === $source ) {
			printf( '<input type="text" class="regular-text" disabled="disabled" value="%s" />', esc_attr( $this->mode_label( $mode ) ) );
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: nombre de la constante de wp-config.php. */
						__( 'Lo fija %s en wp-config.php.', 'simple-form' ),
						PaymentConfig::constant_name( $id, 'mode' )
					)
				)
			);
			return;
		}

		printf( '<select name="%s">', esc_attr( $name ) );

		foreach ( array( PaymentConfig::MODE_SANDBOX, PaymentConfig::MODE_PRODUCTION ) as $opcion ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $opcion ),
				selected( $mode, $opcion, false ),
				esc_html( $this->mode_label( $opcion ) )
			);
		}

		echo '</select>';
	}

	/**
	 * Campo de una credencial. Nunca lleva el valor dentro.
	 *
	 * @param string $id
	 * @param string $field
	 */
	private function render_credential_field( string $id, string $field ): void {
		$source = PaymentConfig::source( $id, $field );
		$name   = 'credentials[' . $id . '][' . $field . ']';

		if ( 'constant' === $source ) {
			printf(
				'<input type="text" class="regular-text" disabled="disabled" value="" placeholder="%s" />',
				esc_attr__( 'definida en wp-config.php', 'simple-form' )
			);
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: nombre de la constante de wp-config.php. */
						__( 'Se lee de %s.', 'simple-form' ),
						PaymentConfig::constant_name( $id, $field )
					)
				)
			);
			return;
		}

		$placeholder = ( 'stored' === $source )
			? __( '•••••••• — escribe solo si quieres cambiarla', 'simple-form' )
			: __( 'sin configurar', 'simple-form' );

		printf(
			'<input type="text" class="regular-text" name="%s" value="" autocomplete="off" placeholder="%s" />',
			esc_attr( $name ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Comprobaciones que §16 pide antes de dar la integración por buena.
	 */
	private function render_checks(): void {
		$https  = 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
		$sodium = Crypto::available();
		?>
		<h2><?php esc_html_e( 'Comprobaciones', 'simple-form' ); ?></h2>
		<table class="widefat striped" style="max-width: 760px;">
			<tbody>
				<tr>
					<th style="width: 320px;"><?php esc_html_e( 'HTTPS en la dirección del sitio', 'simple-form' ); ?></th>
					<td>
						<?php if ( $https ) : ?>
							<?php esc_html_e( 'Sí. Mercado Pago exige HTTPS para cobrar de verdad.', 'simple-form' ); ?>
						<?php else : ?>
							<strong><?php esc_html_e( 'No.', 'simple-form' ); ?></strong>
							<?php esc_html_e( 'Con HTTP solo se puede probar: no se guardará el modo producción y Mercado Pago rechaza los retornos a un dominio local.', 'simple-form' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Cifrado disponible (libsodium)', 'simple-form' ); ?></th>
					<td>
						<?php if ( $sodium ) : ?>
							<?php esc_html_e( 'Sí. Las credenciales se guardan cifradas.', 'simple-form' ); ?>
						<?php else : ?>
							<strong><?php esc_html_e( 'No.', 'simple-form' ); ?></strong>
							<?php esc_html_e( 'Sin libsodium no se pueden guardar credenciales desde aquí: defínelas como constantes en wp-config.php.', 'simple-form' ); ?>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param string $field
	 * @return string Etiqueta traducida, o la clave si el campo no se conoce.
	 */
	private function field_label( string $field ): string {
		$labels = array(
			'access_token'   => __( 'Access Token', 'simple-form' ),
			'webhook_secret' => __( 'Clave secreta del webhook', 'simple-form' ),
		);

		return $labels[ $field ] ?? $field;
	}

	/**
	 * @param string $mode
	 * @return string
	 */
	private function mode_label( string $mode ): string {
		return ( PaymentConfig::MODE_PRODUCTION === $mode )
			? __( 'Producción (cobra de verdad)', 'simple-form' )
			: __( 'Pruebas (sandbox, no cobra)', 'simple-form' );
	}

	/**
	 * Resumen de dónde sale cada credencial, sin enseñar ningún valor.
	 *
	 * @param string $id
	 * @return string
	 */
	private function sources_summary( string $id ): string {
		$campos    = array_merge( array( 'mode' ), Gateways::credential_fields( $id ) );
		$constant  = 0;
		$stored    = 0;
		$missing   = 0;

		foreach ( $campos as $field ) {
			switch ( PaymentConfig::source( $id, $field ) ) {
				case 'constant':
					$constant++;
					break;
				case 'stored':
					$stored++;
					break;
				default:
					$missing++;
			}
		}

		if ( 0 === $missing && 0 === $stored ) {
			return __( 'Todo desde wp-config.php.', 'simple-form' );
		}

		return sprintf(
			/* translators: 1: campos guardados, 2: campos desde wp-config.php, 3: campos sin configurar. */
			__( '%1$d guardadas, %2$d desde wp-config.php, %3$d sin configurar.', 'simple-form' ),
			$stored,
			$constant,
			$missing
		);
	}

	/**
	 * Guarda la lista de medios activos.
	 */
	public function handle_save_methods(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No autorizado.', 'simple-form' ) );
		}

		check_admin_referer( self::ACTION_METHODS, self::NONCE_METHODS );

		$raw = isset( $_POST['methods'] ) && is_array( $_POST['methods'] ) ? wp_unslash( $_POST['methods'] ) : array();

		// Sin marcar ninguno se guarda una lista vacía, que es una elección
		// legítima: el aviso de "sin medios activos" queda a la vista.
		PaymentConfig::save_enabled_ids( $raw );

		$this->redirect( 'methods' );
	}

	/**
	 * Guarda el modo y las credenciales de cada pasarela.
	 */
	public function handle_save_credentials(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No autorizado.', 'simple-form' ) );
		}

		check_admin_referer( self::ACTION_CREDENTIALS, self::NONCE_CREDENTIALS );

		$raw = isset( $_POST['credentials'] ) && is_array( $_POST['credentials'] ) ? wp_unslash( $_POST['credentials'] ) : array();

		foreach ( Gateways::ids() as $id ) {
			$entrada = isset( $raw[ $id ] ) && is_array( $raw[ $id ] ) ? $raw[ $id ] : array();
			$modo    = isset( $entrada['mode'] ) ? sanitize_key( $entrada['mode'] ) : PaymentConfig::MODE_SANDBOX;

			$campos = array();

			foreach ( Gateways::credential_fields( $id ) as $field ) {
				if ( isset( $entrada[ $field ] ) && is_string( $entrada[ $field ] ) && '' !== trim( $entrada[ $field ] ) ) {
					$campos[ $field ] = sanitize_text_field( $entrada[ $field ] );
				}
			}

			if ( ! PaymentConfig::save( $id, $modo, $campos ) ) {
				// Producción sin HTTPS, o nada que cifrar: no se guardó nada.
				$this->redirect( '', 'https' );
			}
		}

		$this->redirect( 'credentials' );
	}

	/**
	 * Redirección a URL fija, nunca al referer.
	 *
	 * @param string $updated
	 * @param string $error
	 */
	private function redirect( string $updated = '', string $error = '' ): void {
		$args = array();

		if ( '' !== $updated ) {
			$args['updated'] = $updated;
		}

		if ( '' !== $error ) {
			$args['error'] = $error;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}
}
