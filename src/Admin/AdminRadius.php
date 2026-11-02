<?php

namespace SimpleForm\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SimpleForm\Core\Radius;

/**
 * Pantalla *SimpleForm → Radio de búsqueda*.
 *
 * El administrador fija el mínimo, el máximo y el paso del slider del mapa.
 * Guardar acá mueve a la vez el slider del formulario, el validador del
 * navegador y la validación del servidor: el rango vive en un solo lugar
 * (`Core\Radius`).
 *
 * Los valores se piden en metros porque es lo que se muestra en el slider; el
 * km es lo que viaja al servidor y se guarda en el pedido.
 */
class AdminRadius {

	const MENU_SLUG   = 'simple-form-radius';
	const ACTION      = 'sf_save_radius';
	const NONCE_ACTION = 'sf_save_radius';
	const NONCE_FIELD = 'sf_radius_nonce';
	const FIELD       = 'radius';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 12 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			AdminMenu::SLUG,
			__( 'Radio de búsqueda', 'simple-form' ),
			__( 'Radio de búsqueda', 'simple-form' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$guardado = Radius::all();
		$error    = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$valores  = $guardado;

		// Un guardado rechazado tiene que devolver el formulario con lo que el
		// usuario escribió. Si vuelve con los valores viejos, el siguiente
		// intento guarda los viejos y parece que la pantalla no hace nada.
		if ( '' !== $error ) {
			$intento = get_transient( $this->transient_key() );

			if ( is_array( $intento ) && isset( $intento['valores'] ) && is_array( $intento['valores'] ) ) {
				$valores = array(
					'min_m'  => (int) ( $intento['valores']['min_m'] ?? $guardado['min_m'] ),
					'max_m'  => (int) ( $intento['valores']['max_m'] ?? $guardado['max_m'] ),
					'step_m' => (int) ( $intento['valores']['step_m'] ?? $guardado['step_m'] ),
				);
			}

			delete_transient( $this->transient_key() );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Radio de búsqueda', 'simple-form' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['updated'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Rango del radio guardado. El formulario ya lo usa.', 'simple-form' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><strong><?php esc_html_e( 'No se guardó:', 'simple-form' ); ?></strong> <?php echo esc_html( $this->error_message( $error, $valores ) ); ?></p>
				</div>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: 1: mínimo actual, 2: máximo actual, 3: paso */
					esc_html__( 'El cliente elige el radio del mapa con un slider que va desde el mínimo hasta el máximo, de a un paso. Hoy el rango es %1$s a %2$s, de %3$s en %3$s.', 'simple-form' ),
					esc_html( Radius::format( $guardado['min_m'] / 1000 ) ),
					esc_html( Radius::format( $guardado['max_m'] / 1000 ) ),
					esc_html( (string) $guardado['step_m'] . ' m' )
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="sf-radius-min"><?php esc_html_e( 'Radio mínimo', 'simple-form' ); ?></label>
							</th>
							<td>
								<input type="number" id="sf-radius-min" name="<?php echo esc_attr( self::FIELD ); ?>[min_m]" value="<?php echo esc_attr( (string) $valores['min_m'] ); ?>" min="<?php echo esc_attr( (string) Radius::FLOOR_M ); ?>" max="<?php echo esc_attr( (string) Radius::CEIL_M ); ?>" step="<?php echo esc_attr( (string) Radius::STEP_MIN_M ); ?>" class="small-text" />
								<span class="description"><?php esc_html_e( 'Metros. Es lo más cerca que puede buscar el cliente.', 'simple-form' ); ?></span>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="sf-radius-max"><?php esc_html_e( 'Radio máximo', 'simple-form' ); ?></label>
							</th>
							<td>
								<input type="number" id="sf-radius-max" name="<?php echo esc_attr( self::FIELD ); ?>[max_m]" value="<?php echo esc_attr( (string) $valores['max_m'] ); ?>" min="<?php echo esc_attr( (string) ( Radius::FLOOR_M + Radius::STEP_MIN_M ) ); ?>" max="<?php echo esc_attr( (string) Radius::CEIL_M ); ?>" step="<?php echo esc_attr( (string) Radius::STEP_MIN_M ); ?>" class="small-text" />
								<span class="description">
									<?php
									printf(
										/* translators: %s: techo en km */
										esc_html__( 'Metros. Hasta %s. Más radio es más caro para el cliente: el precio suma el valor por km de cada servicio.', 'simple-form' ),
										esc_html( Radius::format( Radius::CEIL_M / 1000 ) )
									);
									?>
								</span>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="sf-radius-step"><?php esc_html_e( 'Paso del slider', 'simple-form' ); ?></label>
							</th>
							<td>
								<input type="number" id="sf-radius-step" name="<?php echo esc_attr( self::FIELD ); ?>[step_m]" value="<?php echo esc_attr( (string) $valores['step_m'] ); ?>" min="<?php echo esc_attr( (string) Radius::STEP_MIN_M ); ?>" step="<?php echo esc_attr( (string) Radius::STEP_MIN_M ); ?>" class="small-text" />
								<span class="description"><?php esc_html_e( 'Metros. Cuánto salta el slider en cada movimiento; no puede ser mayor que el rango.', 'simple-form' ); ?></span>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="description">
					<?php esc_html_e( 'Los valores se ajustan a múltiplos de 10 metros (el pedido guarda el radio en km con dos decimales).', 'simple-form' ); ?>
				</p>

				<?php submit_button( __( 'Guardar rango', 'simple-form' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Guarda el rango. Capacidad, nonce, saneado y validación, en ese orden.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No autorizado.', 'simple-form' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$raw = array();

		if ( isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ) {
			$raw = wp_unslash( $_POST[ self::FIELD ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se sanea campo por campo abajo.
		}

		$min  = $this->number( $raw['min_m'] ?? null );
		$max  = $this->number( $raw['max_m'] ?? null );
		$step = $this->number( $raw['step_m'] ?? null );

		$error = '';

		if ( $max < Radius::FLOOR_M + Radius::STEP_MIN_M || $max > Radius::CEIL_M ) {
			$error = 'max';
		} elseif ( $min < Radius::FLOOR_M || $min >= $max ) {
			$error = 'min';
		} elseif ( $step < Radius::STEP_MIN_M || $step > ( $max - $min ) ) {
			$error = 'step';
		}

		if ( '' !== $error ) {
			// Se guarda el intento para que la pantalla vuelva con estos valores:
			// si vuelve con los guardados, el próximo guardado manda los viejos
			// y el rango no cambia nunca.
			set_transient(
				$this->transient_key(),
				array(
					'valores' => array(
						'min_m'  => $min,
						'max_m'  => $max,
						'step_m' => $step,
					),
				),
				5 * MINUTE_IN_SECONDS
			);

			wp_safe_redirect( $this->url( array( 'error' => $error ) ) );
			exit;
		}

		update_option( Radius::OPTION, Radius::normalize( array( 'min_m' => $min, 'max_m' => $max, 'step_m' => $step ) ) );

		wp_safe_redirect( $this->url( array( 'updated' => '1' ) ) );
		exit;
	}

	/**
	 * URL de la pantalla con avisos.
	 *
	 * @param array $args
	 * @return string
	 */
	private function url( array $args ): string {
		return add_query_arg(
			array_merge( array( 'page' => self::MENU_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Clave del intento rechazado, por usuario.
	 *
	 * @return string
	 */
	private function transient_key(): string {
		return 'sf_radius_intento_' . get_current_user_id();
	}

	/**
	 * @param mixed $value
	 * @return int
	 */
	private function number( $value ): int {
		return is_numeric( $value ) ? (int) round( (float) $value ) : 0;
	}

	/**
	 * Mensaje del error con los números del intento, para que se entienda qué
	 * corregir sin adivinar.
	 *
	 * @param string $code
	 * @param array  $valores
	 * @return string
	 */
	private function error_message( string $code, array $valores ): string {
		$min  = (int) ( $valores['min_m'] ?? 0 );
		$max  = (int) ( $valores['max_m'] ?? 0 );
		$step = (int) ( $valores['step_m'] ?? 0 );

		switch ( $code ) {
			case 'max':
				return sprintf(
					/* translators: 1: mínimo admitido, 2: máximo admitido, 3: valor escrito */
					__( 'el radio máximo tiene que estar entre %1$d y %2$d metros, y escribiste %3$d.', 'simple-form' ),
					Radius::FLOOR_M + Radius::STEP_MIN_M,
					Radius::CEIL_M,
					$max
				);
			case 'min':
				return sprintf(
					/* translators: 1: piso en metros, 2: máximo escrito, 3: mínimo escrito */
					__( 'el radio mínimo no puede bajar de %1$d metros ni ser igual o mayor que el máximo (%2$d m), y escribiste %3$d m.', 'simple-form' ),
					Radius::FLOOR_M,
					$max,
					$min
				);
			case 'step':
				return sprintf(
					/* translators: 1: rango disponible en metros, 2: paso escrito */
					__( 'el paso tiene que ser de 10 metros o más y no puede superar el rango disponible (%1$d m), y escribiste %2$d m.', 'simple-form' ),
					max( 0, $max - $min ),
					$step
				);
			default:
				return __( 'revisa los valores.', 'simple-form' );
		}
	}
}
