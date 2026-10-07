<?php
/**
 * Shortcode del formulario de pago.
 *
 * @package SimpleForm\Core
 */

namespace SimpleForm\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Shortcode
 *
 * Registra [simple_form_form] y devuelve el HTML del formulario.
 */
class Shortcode {

	private Assets $assets;

	private static bool $rendered = false;

	public function __construct( Assets $assets ) {
		$this->assets = $assets;
		add_shortcode( 'simple_form_form', array( $this, 'render' ) );
	}

	/**
	 * Renderiza el formulario.
	 *
	 * @param array|string $atts Atributos del shortcode.
	 * @return string HTML del formulario.
	 */
	public function render( $atts ): string {

		// Solo se admite un formulario por página (IDs fijos en el HTML y JS).
		if ( self::$rendered ) {
			return current_user_can( 'manage_options' )
				? '<p class="pn-form__notice">' . esc_html__( 'Simple Form: solo se admite un formulario por página.', 'simple-form' ) . '</p>'
				: '';
		}
		self::$rendered = true;

		// Encola CSS/JS y genera el nonce solo cuando el shortcode se ejecuta.
		$this->assets->enqueue();

		$atts = shortcode_atts(
			array(
				'title'        => __( 'Realiza tu pago', 'simple-form' ),
				'button_label' => __( 'Pagar ahora', 'simple-form' ),
			),
			$atts,
			'simple_form_form'
		);

		// Obtener servicios guardados desde el admin
		$services = get_option( \SimpleForm\Admin\AdminServices::OPTION_KEY, array() );

		ob_start();
		?>
		<div class="pn-form-wrapper">
			<ol class="pn-stepper" aria-label="<?php echo esc_attr__( 'Progreso del pedido', 'simple-form' ); ?>">
				<li class="pn-stepper__item is-active" data-step="1">
					<span class="pn-stepper__circle">1</span>
					<span class="pn-stepper__label"><?php esc_html_e( 'Tus datos', 'simple-form' ); ?></span>
				</li>
				<li class="pn-stepper__item" data-step="2">
					<span class="pn-stepper__circle">2</span>
					<span class="pn-stepper__label"><?php esc_html_e( 'Servicio y ubicación', 'simple-form' ); ?></span>
				</li>
				<li class="pn-stepper__item" data-step="3">
					<span class="pn-stepper__circle">3</span>
					<span class="pn-stepper__label"><?php esc_html_e( 'Medio de pago', 'simple-form' ); ?></span>
				</li>
				<li class="pn-stepper__item" data-step="4">
					<span class="pn-stepper__circle">4</span>
					<span class="pn-stepper__label"><?php esc_html_e( 'Confirmación', 'simple-form' ); ?></span>
				</li>
			</ol>

			<div class="pn-form-layout">
				<form id="pn-payment-form" class="pn-form" novalidate>
					<h3 class="pn-form__title"><?php echo esc_html( $atts['title'] ); ?></h3>

					<!-- PASO 1: Tus datos -->
					<section class="pn-step" data-step-panel="1">
						<div class="pn-form__row">
							<label for="pn-full-name" class="pn-form__label"><?php esc_html_e( 'Nombre completo', 'simple-form' ); ?></label>
							<input type="text" id="pn-full-name" name="name" class="pn-form__input" placeholder="<?php echo esc_attr__( 'Ej: Juan Pérez', 'simple-form' ); ?>" required />
							<span class="pn-form__error" data-error-for="name"></span>
						</div>

						<div class="pn-form__row">
							<label for="pn-email" class="pn-form__label"><?php esc_html_e( 'Correo electrónico', 'simple-form' ); ?></label>
							<input type="email" id="pn-email" name="email" class="pn-form__input" placeholder="tu@correo.com" required />
							<span class="pn-form__error" data-error-for="email"></span>
						</div>

						<div class="pn-form__row">
							<label for="pn-phone" class="pn-form__label"><?php esc_html_e( 'Teléfono', 'simple-form' ); ?></label>
							<div class="pn-form__input-group">
								<span class="pn-form__prefix">+56</span>
								<input type="tel" id="pn-phone" name="phone" class="pn-form__input" placeholder="9 1234 5678" required />
							</div>
							<span class="pn-form__error" data-error-for="phone"></span>
						</div>

						<div class="pn-form__row">
							<label for="pn-description" class="pn-form__label">
								<?php esc_html_e( 'Descripción', 'simple-form' ); ?> <span class="pn-form__optional"><?php esc_html_e( '(opcional)', 'simple-form' ); ?></span>
							</label>
							<textarea id="pn-description" name="description" class="pn-form__input pn-form__textarea" placeholder="<?php echo esc_attr__( 'Motivo del pago...', 'simple-form' ); ?>" rows="3"></textarea>
						</div>
					</section>

					<!-- PASO 2: Servicio y ubicación -->
					<section class="pn-step" data-step-panel="2" hidden>
						<div class="pn-form__row">
							<label for="pn-service-type" class="pn-form__label"><?php esc_html_e( 'Tipo de servicio', 'simple-form' ); ?></label>
							<select id="pn-service-type" name="service_id" class="pn-form__input pn-form__select" required>
								<option value="" disabled selected><?php esc_html_e( 'Selecciona un plan', 'simple-form' ); ?></option>
								<?php if ( ! empty( $services ) && is_array( $services ) ) : ?>
									<?php foreach ( $services as $service ) : ?>
										<option value="<?php echo esc_attr( $service['id'] ?? '' ); ?>">
											<?php echo esc_html( $service['label'] ?? $service['name'] ?? __( 'Servicio', 'simple-form' ) ); ?>
										</option>
									<?php endforeach; ?>
								<?php endif; ?>
							</select>
							<span class="pn-form__error" data-error-for="service_id"></span>
							<?php if ( empty( $services ) ) : ?>
								<p class="pn-form__notice"><?php esc_html_e( 'No hay servicios configurados aún. Ve a Servicios de pago en el admin.', 'simple-form' ); ?></p>
							<?php endif; ?>
						</div>

						<div class="pn-form__row">
							<label class="pn-form__label"><?php esc_html_e( 'Ubicación y radio de búsqueda', 'simple-form' ); ?></label>

							<div id="map-plugin" class="map-plugin-contenedor"></div>

							<div class="map-plugin__radio-control">
								<label for="pn-map-radius" class="map-plugin__radio-label">
									<?php esc_html_e( 'Radio:', 'simple-form' ); ?> <span class="map-plugin__radio-value">1</span> km
								</label>
								<input type="range" id="pn-map-radius" class="map-plugin__radio-slider" min="1" max="20" step="1" value="1" />
							</div>

							<div class="map-plugin__price">
								<span class="map-plugin__price-label"><?php esc_html_e( 'Precio estimado', 'simple-form' ); ?></span>
								<span class="map-plugin__price-value" id="pn-price-value">$0</span>
							</div>

							<span class="pn-form__error" data-error-for="map_location"></span>

							<span class="pn-form__error" data-error-for="radius_km"></span>

							<input type="hidden" id="pn-lat" name="lat" value="" />
							<input type="hidden" id="pn-lng" name="lng" value="" />
							<input type="hidden" id="pn-radius" name="radius_km" value="1" />
							<input type="hidden" id="pn-price" name="estimated_price" value="0" />
						</div>
					</section>

					<!-- PASO 3: Medio de pago -->
					<section class="pn-step" data-step-panel="3" hidden>
						<div class="pn-form__row">
							<label class="pn-form__label"><?php esc_html_e( 'Selecciona un medio de pago', 'simple-form' ); ?></label>
							<div class="pn-form__radio-group">
								<label class="pn-form__radio">
									<input type="radio" name="payment_method" value="mercado_pago" required />
									Mercado Pago
								</label>
							</div>
							<span class="pn-form__error" data-error-for="payment_method"></span>
						</div>
					</section>

					<!-- PASO 4: Confirmación -->
					<section class="pn-step" data-step-panel="4" hidden>
						<div class="pn-receipt">
							<div class="pn-receipt__header">
								<span class="pn-receipt__badge pn-receipt__badge--pending"><?php esc_html_e( 'Pago pendiente', 'simple-form' ); ?></span>
								<span class="pn-receipt__date" id="pn-receipt-date">—</span>
							</div>

							<h4 class="pn-receipt__subtitle"><?php esc_html_e( 'Datos del cliente', 'simple-form' ); ?></h4>
							<dl class="pn-receipt__list">
								<div class="pn-receipt__row"><dt><?php esc_html_e( 'Nombre', 'simple-form' ); ?></dt><dd id="pn-receipt-name">—</dd></div>
								<div class="pn-receipt__row"><dt><?php esc_html_e( 'Correo', 'simple-form' ); ?></dt><dd id="pn-receipt-email">—</dd></div>
								<div class="pn-receipt__row"><dt><?php esc_html_e( 'Teléfono', 'simple-form' ); ?></dt><dd id="pn-receipt-phone">—</dd></div>
								<div class="pn-receipt__row"><dt><?php esc_html_e( 'Descripción', 'simple-form' ); ?></dt><dd id="pn-receipt-description">—</dd></div>
							</dl>

							<h4 class="pn-receipt__subtitle"><?php esc_html_e( 'Detalle del servicio', 'simple-form' ); ?></h4>
							<dl class="pn-receipt__list">
								<div class="pn-receipt__row"><dt><?php esc_html_e( 'Servicio', 'simple-form' ); ?></dt><dd id="pn-receipt-service">—</dd></div>
								<div class="pn-receipt__row"><dt><?php esc_html_e( 'Radio', 'simple-form' ); ?></dt><dd id="pn-receipt-radius">—</dd></div>
								<div class="pn-receipt__row pn-receipt__row--total"><dt><?php esc_html_e( 'Total pagado', 'simple-form' ); ?></dt><dd id="pn-receipt-price">$0</dd></div>
							</dl>
						</div>
					</section>

					<div class="pn-form__nav">
						<button type="button" class="pn-btn pn-btn--ghost" data-action="prev" hidden><?php esc_html_e( 'Atrás', 'simple-form' ); ?></button>
						<button type="button" class="pn-btn pn-btn--primary" data-action="next"><?php esc_html_e( 'Siguiente', 'simple-form' ); ?></button>
						<button type="submit" class="pn-btn pn-btn--dark" data-action="submit" hidden>
							<span class="pn-form__submit-text"><?php echo esc_html( $atts['button_label'] ); ?></span>
							<span class="pn-form__spinner" aria-hidden="true"></span>
						</button>
					</div>

					<div class="pn-form__feedback" role="status" aria-live="polite"></div>
				</form>

				<aside class="pn-summary">
					<h4 class="pn-summary__title"><?php esc_html_e( 'Resumen', 'simple-form' ); ?></h4>
					<dl class="pn-summary__list">
						<div class="pn-summary__row"><dt><?php esc_html_e( 'Servicio', 'simple-form' ); ?></dt><dd id="pn-summary-service">—</dd></div>
						<div class="pn-summary__row"><dt><?php esc_html_e( 'Radio', 'simple-form' ); ?></dt><dd id="pn-summary-radius">—</dd></div>
						<div class="pn-summary__row pn-summary__row--total"><dt><?php esc_html_e( 'Precio estimado', 'simple-form' ); ?></dt><dd id="pn-summary-price">$0</dd></div>
					</dl>
				</aside>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}