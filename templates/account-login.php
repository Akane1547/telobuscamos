<?php
/**
 * Página /iniciar-sesion.
 *
 * Cuatro vistas en la misma página, sin JavaScript: iniciar sesión
 * (?form=login), crear cuenta (?form=registro), recuperar contraseña
 * (?form=recuperar) y confirmar la cuenta (?sf_confirm=token, el enlace que
 * llega por correo).
 *
 * Todo lo resuelve SimpleForm\Account\Auth::handle() en el servidor; acá solo se
 * pinta lo que esa clase dejó en sus propiedades estáticas.
 *
 * @package SimpleForm\Account
 */

use SimpleForm\Account\Auth;
use SimpleForm\Account\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sf_view   = Auth::view();
$sf_error  = Auth::error();
$sf_notice = Auth::notice();

get_header();
?>

<main class="sf-account sf-account--login">
	<div class="sf-account__veil" aria-hidden="true"></div>

	<div class="sf-account__inner">
		<div class="sf-account__card">
			<div class="sf-account__brand">
				<?php if ( has_custom_logo() ) : ?>
					<?php the_custom_logo(); ?>
				<?php else : ?>
					<span class="sf-account__brand-name"><?php bloginfo( 'name' ); ?></span>
				<?php endif; ?>
			</div>

			<?php if ( '' !== $sf_error ) : ?>
				<p class="sf-account__alert" role="alert"><?php echo esc_html( $sf_error ); ?></p>
			<?php endif; ?>

			<?php if ( '' !== $sf_notice ) : ?>
				<p class="sf-account__notice" role="status"><?php echo esc_html( $sf_notice ); ?></p>
			<?php endif; ?>

			<?php if ( 'register' === $sf_view ) : ?>
				<h1 class="sf-account__title">Crear cuenta</h1>
				<p class="sf-account__lead">Con tu cuenta completamos tus datos en cada pedido.</p>

				<form class="sf-account__form" method="post" action="<?php echo esc_url( Pages::login_url() ); ?>">
					<?php wp_nonce_field( Auth::NONCE_ACTION, Auth::FIELD_NONCE ); ?>
					<input type="hidden" name="<?php echo esc_attr( Auth::ACTION_FIELD ); ?>" value="register" />

					<div class="sf-account__field">
						<label for="sf-register-name">Nombre completo</label>
						<input type="text" id="sf-register-name" name="<?php echo esc_attr( Auth::FIELD_NAME ); ?>" value="<?php echo esc_attr( Auth::value( 'name' ) ); ?>" autocomplete="name" required />
					</div>

					<div class="sf-account__field">
						<label for="sf-register-email">Correo electrónico</label>
						<input type="email" id="sf-register-email" name="<?php echo esc_attr( Auth::FIELD_EMAIL ); ?>" value="<?php echo esc_attr( Auth::value( 'email' ) ); ?>" autocomplete="email" inputmode="email" required />
					</div>

					<div class="sf-account__field">
						<label for="sf-register-phone">Número de teléfono</label>
						<div class="sf-account__group">
							<span class="sf-account__prefix">+56</span>
							<input type="tel" id="sf-register-phone" name="<?php echo esc_attr( Auth::FIELD_PHONE ); ?>" value="<?php echo esc_attr( Auth::value( 'phone' ) ); ?>" placeholder="9 1234 5678" autocomplete="tel" inputmode="tel" required />
						</div>
					</div>

					<div class="sf-account__field">
						<label for="sf-register-password">Contraseña</label>
						<input type="password" id="sf-register-password" name="<?php echo esc_attr( Auth::FIELD_PASSWORD ); ?>" autocomplete="new-password" minlength="<?php echo esc_attr( (string) Auth::MIN_PASSWORD ); ?>" required />
						<p class="sf-account__hint">Al menos <?php echo esc_html( (string) Auth::MIN_PASSWORD ); ?> caracteres. Te vamos a pedir elegirla otra vez al confirmar el correo.</p>
					</div>

					<button class="sf-account__submit" type="submit">Crear mi cuenta</button>
				</form>

				<p class="sf-account__switch">
					¿Ya tienes cuenta?
					<a href="<?php echo esc_url( Pages::login_url() ); ?>">Iniciar sesión</a>
				</p>
			<?php elseif ( 'recover' === $sf_view ) : ?>
				<h1 class="sf-account__title">Recuperar contraseña</h1>
				<p class="sf-account__lead">Escribe el correo de tu cuenta y te enviamos cómo cambiarla.</p>

				<form class="sf-account__form" method="post" action="<?php echo esc_url( Pages::login_url() ); ?>">
					<?php wp_nonce_field( Auth::NONCE_ACTION, Auth::FIELD_NONCE ); ?>
					<input type="hidden" name="<?php echo esc_attr( Auth::ACTION_FIELD ); ?>" value="recover" />

					<div class="sf-account__field">
						<label for="sf-recover-email">Correo electrónico</label>
						<input type="email" id="sf-recover-email" name="<?php echo esc_attr( Auth::FIELD_EMAIL ); ?>" value="<?php echo esc_attr( Auth::value( 'email' ) ); ?>" autocomplete="email" inputmode="email" required />
					</div>

					<button class="sf-account__submit" type="submit">Enviarme el enlace</button>
				</form>

				<p class="sf-account__switch">
					<a href="<?php echo esc_url( Pages::login_url() ); ?>">Volver a iniciar sesión</a>
				</p>
			<?php elseif ( 'confirm' === $sf_view ) : ?>
				<h1 class="sf-account__title">Activa tu cuenta</h1>
				<p class="sf-account__lead">Elige la contraseña con la que vas a entrar. La cuenta queda activa al terminar.</p>

				<form class="sf-account__form" method="post" action="<?php echo esc_url( Pages::login_url() ); ?>">
					<?php wp_nonce_field( Auth::NONCE_ACTION, Auth::FIELD_NONCE ); ?>
					<input type="hidden" name="<?php echo esc_attr( Auth::ACTION_FIELD ); ?>" value="confirm" />
					<input type="hidden" name="<?php echo esc_attr( Auth::FIELD_TOKEN ); ?>" value="<?php echo esc_attr( Auth::token() ); ?>" />

					<div class="sf-account__field">
						<label for="sf-confirm-password">Contraseña</label>
						<input type="password" id="sf-confirm-password" name="<?php echo esc_attr( Auth::FIELD_PASSWORD ); ?>" autocomplete="new-password" minlength="<?php echo esc_attr( (string) Auth::MIN_PASSWORD ); ?>" required />
						<p class="sf-account__hint">Al menos <?php echo esc_html( (string) Auth::MIN_PASSWORD ); ?> caracteres.</p>
					</div>

					<div class="sf-account__field">
						<label for="sf-confirm-password-2">Repite la contraseña</label>
						<input type="password" id="sf-confirm-password-2" name="<?php echo esc_attr( Auth::FIELD_PASSWORD_2 ); ?>" autocomplete="new-password" minlength="<?php echo esc_attr( (string) Auth::MIN_PASSWORD ); ?>" required />
					</div>

					<button class="sf-account__submit" type="submit">Activar mi cuenta</button>
				</form>
			<?php else : ?>
				<h1 class="sf-account__title">Iniciar sesión</h1>
				<p class="sf-account__lead">Entra para completar tus datos automáticamente.</p>

				<form class="sf-account__form" method="post" action="<?php echo esc_url( Pages::login_url() ); ?>">
					<?php wp_nonce_field( Auth::NONCE_ACTION, Auth::FIELD_NONCE ); ?>
					<input type="hidden" name="<?php echo esc_attr( Auth::ACTION_FIELD ); ?>" value="login" />

					<div class="sf-account__field">
						<label for="sf-login-email">Correo electrónico</label>
						<input type="email" id="sf-login-email" name="<?php echo esc_attr( Auth::FIELD_EMAIL ); ?>" value="<?php echo esc_attr( Auth::value( 'email' ) ); ?>" autocomplete="email" inputmode="email" required />
					</div>

					<div class="sf-account__field">
						<label for="sf-login-password">Contraseña</label>
						<input type="password" id="sf-login-password" name="<?php echo esc_attr( Auth::FIELD_PASSWORD ); ?>" autocomplete="current-password" required />
					</div>

					<button class="sf-account__submit" type="submit">Ingresar</button>
				</form>

				<p class="sf-account__switch">
					<a href="<?php echo esc_url( Pages::login_url() . '?form=recuperar' ); ?>">¿Olvidaste tu contraseña?</a>
				</p>

				<p class="sf-account__switch">
					¿No tienes cuenta?
					<a href="<?php echo esc_url( Pages::login_url() . '?form=registro' ); ?>">Registrarse</a>
				</p>
			<?php endif; ?>
		</div>
	</div>
</main>

<?php
get_footer();
