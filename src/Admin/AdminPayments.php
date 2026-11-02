<?php
/**
 * Pantalla de administración de los medios de pago.
 *
 * Aquí se eligen los medios que ofrece el Paso 3 del formulario y se cargan
 * sus credenciales. Esta pantalla no conoce ninguna pasarela en concreto: el
 * registro (Gateways) dice cuáles hay y qué campos pide cada una, así que añadir
 * una es una entrada en el registro y nada más.
 *
 * Cada medio tiene su juego de credenciales **por modo** (pruebas y comercial):
 * cambiar de modo y guardar no borra las claves del otro, así se puede alternar
 * sin volver a escribirlas.
 *
 * Las credenciales nunca se pintan: si hay un valor guardado el campo sale vacío
 * con un marcador, y en blanco significa "no lo cambio". Si el valor lo fija una
 * constante de wp-config.php, el campo sale bloqueado y se dice de dónde sale.
 *
 * @package SimpleForm\Admin
 */

namespace SimpleForm\Admin;

use SimpleForm\Core\Assets;
use SimpleForm\Payments\Gateways;
use SimpleForm\Payments\PaymentConfig;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminPayments {

	const MENU_SLUG = 'simple-form-payments';

	const ACTION_SAVE = 'sf_save_payments';
	const NONCE_SAVE  = 'sf_payments_nonce';

	const HANDLE_ADMIN_JS = 'simple-form-admin-payments';

	/**
	 * Hook de la pantalla, tal como lo devuelve add_submenu_page().
	 *
	 * @var string
	 */
	private string $screen_id = '';

	public function __construct() {
		// Prioridad 11: el menú padre (AdminMenu) se registra en la 9 y
		// Servicios de pago en la 10, así que este queda justo debajo.
		add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function register_menu(): void {
		$this->screen_id = (string) add_submenu_page(
			AdminMenu::SLUG,
			__( 'Medios de pago', 'simple-form' ),
			__( 'Medios de pago', 'simple-form' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Encola el JS de la pantalla, solo en esta pantalla.
	 *
	 * @param string $hook_suffix Identificador de la pantalla actual.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( $this->screen_id !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE_ADMIN_JS,
			SIMPLE_FORM_URL . 'assets/js/admin-payments.js',
			array(),
			Assets::asset_version( 'assets/js/admin-payments.js', SIMPLE_FORM_VERSION ),
			true
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$enabled   = PaymentConfig::enabled_ids();
		$available = array_values( array_diff( Gateways::ids(), $enabled ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Medios de pago', 'simple-form' ); ?></h1>

			<?php $this->render_notices(); ?>

			<p><?php esc_html_e( 'Estos son los medios que ofrece el Paso 3 del formulario. Cada uno guarda sus credenciales de pruebas y de producción por separado: cambiar de modo no borra las del otro.', 'simple-form' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION_SAVE, self::NONCE_SAVE ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />

				<p class="sf-payments__add">
					<label for="sf-add-payment"><strong><?php esc_html_e( 'Añadir medio de pago', 'simple-form' ); ?></strong></label>
					<select id="sf-add-payment">
						<?php foreach ( $available as $id ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( Gateways::label( $id ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button" data-add-payment <?php disabled( empty( $available ) ); ?>>
						<?php esc_html_e( 'Añadir', 'simple-form' ); ?>
					</button>
					<span class="description" data-add-empty <?php echo empty( $available ) ? '' : 'hidden'; ?>>
						<?php esc_html_e( 'No hay más medios disponibles en este plugin.', 'simple-form' ); ?>
					</span>
				</p>

				<div id="sf-payment-rows">
					<?php foreach ( $enabled as $id ) : ?>
						<?php $this->render_method( $id, false ); ?>
					<?php endforeach; ?>

					<?php if ( empty( $enabled ) ) : ?>
						<p class="sf-payments__empty" data-payments-empty>
							<?php esc_html_e( 'Todavía no hay ningún medio cargado. Usa "Añadir medio de pago" para elegir uno.', 'simple-form' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<?php submit_button( __( 'Guardar medios de pago', 'simple-form' ) ); ?>
			</form>

			<?php foreach ( $available as $id ) : ?>
				<template id="sf-payment-tpl-<?php echo esc_attr( $id ); ?>">
					<?php $this->render_method( $id, true ); ?>
				</template>
			<?php endforeach; ?>

			<?php $this->render_checks(); ?>
		</div>
		<?php
	}

	/**
	 * Avisos de guardado, errores y los avisos de configuración.
	 */
	private function render_notices(): void {
		// Solo son avisos de pantalla tras una redirección nuestra; no deciden nada.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$updated = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : '';
		$warning = isset( $_GET['warning'] ) ? sanitize_key( wp_unslash( $_GET['warning'] ) ) : '';
		$error   = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable

		if ( 'payments' === $updated ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Medios y credenciales guardados.', 'simple-form' )
			);
		}

		$avisos = array(
			'https' => __( 'Las claves de producción quedaron guardadas, pero el modo comercial no se activa hasta que el sitio esté en HTTPS: Mercado Pago rechaza los retornos y las notificaciones por HTTP.', 'simple-form' ),
		);

		if ( '' !== $warning && isset( $avisos[ $warning ] ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html( $avisos[ $warning ] )
			);
		}

		$errores = array(
			'crypto'  => __( 'No se guardó: este PHP no puede cifrar (falta libsodium) o wp-config.php no tiene salts.', 'simple-form' ),
			'unknown' => __( 'No se guardó: esa pasarela no está registrada.', 'simple-form' ),
		);

		if ( '' !== $error && isset( $errores[ $error ] ) ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $errores[ $error ] )
			);
		}

		if ( empty( Gateways::ids() ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'No hay ninguna pasarela registrada: el formulario no puede cobrar.', 'simple-form' )
			);

			return;
		}

		if ( empty( PaymentConfig::enabled_ids() ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'No hay ningún medio cargado: el Paso 3 no ofrecerá ninguno y el formulario no se podrá completar.', 'simple-form' )
			);
		}

		$en_sandbox = array();

		foreach ( PaymentConfig::enabled_ids() as $id ) {
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
	 * Una fila de medio: modo, credenciales y estado de cada juego de claves.
	 *
	 * La misma función sirve para las filas visibles y para la plantilla de un
	 * medio que todavía no se agregó (`<template>`: su contenido no se envía).
	 *
	 * @param string $id          ID de la pasarela.
	 * @param bool   $is_template Si es la plantilla de "Añadir".
	 */
	private function render_method( string $id, bool $is_template ): void {
		$prefix = $is_template ? 'tpl-' : '';
		?>
		<div class="sf-payment" data-payment="<?php echo esc_attr( $id ); ?>">
			<div class="sf-payment__head">
				<h2>
					<?php echo esc_html( Gateways::label( $id ) ); ?>
					<span class="sf-payment__badge <?php echo esc_attr( 'sf-payment__badge--' . PaymentConfig::mode( $id ) ); ?>">
						<?php echo esc_html( $this->mode_short_label( PaymentConfig::mode( $id ) ) ); ?>
					</span>
				</h2>
				<button type="button" class="button-link sf-payment__remove" data-remove-payment>
					<?php esc_html_e( 'Quitar', 'simple-form' ); ?>
				</button>
			</div>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="sf-mode-<?php echo esc_attr( $prefix . $id ); ?>"><?php esc_html_e( 'Modo', 'simple-form' ); ?></label>
					</th>
					<td>
						<?php $this->render_mode_select( $id, $is_template ); ?>
					</td>
				</tr>

				<?php foreach ( Gateways::credential_fields( $id ) as $field ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $this->field_label( $field ) ); ?></th>
						<td><?php $this->render_credential_field( $id, $field ); ?></td>
					</tr>
				<?php endforeach; ?>

				<tr>
					<th scope="row"><?php esc_html_e( 'Claves guardadas', 'simple-form' ); ?></th>
					<td>
						<?php $this->render_keys_state( $id ); ?>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Selector de modo. Si lo fija wp-config.php, sale bloqueado y se explica.
	 *
	 * @param string $id
	 * @param bool   $is_template
	 */
	private function render_mode_select( string $id, bool $is_template ): void {
		$mode = PaymentConfig::desired_mode( $id );

		if ( PaymentConfig::mode_is_locked( $id ) ) {
			printf(
				'<input type="text" class="regular-text" disabled="disabled" value="%s" />',
				esc_attr( $this->mode_label( $mode ) )
			);
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

		printf(
			'<select id="sf-mode-%s" name="payments[%s][mode]">',
			esc_attr( ( $is_template ? 'tpl-' : '' ) . $id ),
			esc_attr( $id )
		);

		foreach ( PaymentConfig::modes() as $opcion ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $opcion ),
				selected( $mode, $opcion, false ),
				esc_html( $this->mode_label( $opcion ) )
			);
		}

		echo '</select>';

		if ( PaymentConfig::MODE_PRODUCTION === $mode && ! PaymentConfig::site_is_https() ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Elegiste el modo comercial, pero el sitio no está en HTTPS: las claves se guardan y el cobro real no se activa hasta que lo esté.', 'simple-form' )
			);
		}
	}

	/**
	 * Campo de una credencial. Nunca lleva el valor dentro.
	 *
	 * @param string $id
	 * @param string $field
	 */
	private function render_credential_field( string $id, string $field ): void {
		$source = PaymentConfig::source( $id, $field );
		$name   = 'payments[' . $id . '][' . $field . ']';

		if ( 'constant' === $source ) {
			printf(
				'<input type="password" class="regular-text" disabled="disabled" value="" placeholder="%s" />',
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

		// type=password: la credencial no se lee mientras se escribe ni queda
		// expuesta al autocompletado o a una extensión del navegador.
		printf(
			'<input type="password" class="regular-text" name="%s" value="" autocomplete="new-password" spellcheck="false" placeholder="%s" />',
			esc_attr( $name ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Estado de los dos juegos de claves, sin enseñar ningún valor.
	 *
	 * @param string $id
	 */
	private function render_keys_state( string $id ): void {
		$partes = array();

		foreach ( PaymentConfig::modes() as $mode ) {
			$cuenta = PaymentConfig::keys_count( $id, $mode );

			$partes[] = sprintf(
				'<strong>%1$s:</strong> %2$s',
				esc_html( $this->mode_short_label( $mode ) ),
				esc_html(
					sprintf(
						/* translators: 1: claves guardadas, 2: total de claves. */
						__( '%1$d de %2$d', 'simple-form' ),
						$cuenta['ok'],
						$cuenta['total']
					)
				)
			);
		}

		printf(
			'<p class="description" data-keys-state>%s</p>',
			implode( ' &nbsp;·&nbsp; ', $partes ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cada parte ya viene escapada.
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Cada modo guarda sus propias claves: cambiar de modo y guardar no borra las del otro.', 'simple-form' )
		);
	}

	/**
	 * Comprobaciones que §16 pide antes de dar la integración por buena.
	 */
	private function render_checks(): void {
		$https  = PaymentConfig::site_is_https();
		$sodium = \SimpleForm\Security\Crypto::available();
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
							<?php esc_html_e( 'Con HTTP solo se puede probar: el modo comercial no se activa y Mercado Pago rechaza los retornos a un dominio local.', 'simple-form' ); ?>
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
			? __( 'Comercial (cobra de verdad)', 'simple-form' )
			: __( 'Pruebas (sandbox, no cobra)', 'simple-form' );
	}

	/**
	 * @param string $mode
	 * @return string
	 */
	private function mode_short_label( string $mode ): string {
		return ( PaymentConfig::MODE_PRODUCTION === $mode )
			? __( 'Comercial', 'simple-form' )
			: __( 'Pruebas', 'simple-form' );
	}

	/**
	 * Guarda los medios que vinieron en el formulario y sus credenciales.
	 *
	 * La lista de medios activos es exactamente lo que llega en el POST: quitar
	 * una fila da de baja el medio (sus claves quedan guardadas por si se vuelve
	 * a agregar).
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No autorizado.', 'simple-form' ) );
		}

		check_admin_referer( self::ACTION_SAVE, self::NONCE_SAVE );

		$raw = isset( $_POST['payments'] ) && is_array( $_POST['payments'] ) ? wp_unslash( $_POST['payments'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se sanea campo por campo abajo.

		$ids     = array();
		$error   = '';
		$warning = '';

		foreach ( $raw as $id => $entrada ) {
			if ( ! is_string( $id ) ) {
				continue;
			}

			$id = sanitize_key( $id );

			if ( '' === $id || ! Gateways::has( $id ) || ! is_array( $entrada ) ) {
				continue;
			}

			if ( in_array( $id, $ids, true ) ) {
				continue;
			}

			$mode = isset( $entrada['mode'] ) ? sanitize_key( $entrada['mode'] ) : PaymentConfig::MODE_SANDBOX;

			$campos = array();

			foreach ( Gateways::credential_fields( $id ) as $field ) {
				if ( ! isset( $entrada[ $field ] ) || ! is_string( $entrada[ $field ] ) ) {
					continue;
				}

				// Una credencial no es texto de pantalla: se recorta, se le quitan
				// los caracteres de control y se descarta si trae espacios adentro
				// (un token con un espacio es un pegote a medias). sanitize_text_field
				// está pensado para texto visible y puede alterar un token válido.
				$valor = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $entrada[ $field ] ) );

				if ( '' === $valor || false !== strpos( $valor, ' ' ) ) {
					continue;
				}

				$campos[ $field ] = $valor;
			}

			$resultado = PaymentConfig::save( $id, $mode, $campos );

			if ( 'unknown' === $resultado || 'crypto' === $resultado ) {
				// Un medio que no se pudo guardar no se da de alta.
				if ( '' === $error ) {
					$error = $resultado;
				}

				continue;
			}

			if ( 'https' === $resultado ) {
				$warning = 'https';
			}

			$ids[] = $id;
		}

		PaymentConfig::save_enabled_ids( $ids );

		$this->redirect( '' === $error ? 'payments' : '', $error, $warning );
	}

	/**
	 * Redirección a URL fija, nunca al referer.
	 *
	 * @param string $updated
	 * @param string $error
	 * @param string $warning
	 */
	private function redirect( string $updated = '', string $error = '', string $warning = '' ): void {
		$args = array();

		if ( '' !== $updated ) {
			$args['updated'] = $updated;
		}

		if ( '' !== $error ) {
			$args['error'] = $error;
		}

		if ( '' !== $warning ) {
			$args['warning'] = $warning;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}
}
