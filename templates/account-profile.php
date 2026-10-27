<?php
/**
 * Página /perfil.
 *
 * Muestra los datos de la cuenta y permite editar nombre y teléfono. El correo
 * es el identificador de la cuenta y no se edita acá; la fecha de creación
 * tampoco. El POST lo atiende SimpleForm\Account\Auth::update_profile(), que
 * toma la identidad de get_current_user_id().
 *
 * @package SimpleForm\Account
 */

use SimpleForm\Account\Auth;
use SimpleForm\Account\Customer;
use SimpleForm\Account\Pages;
use SimpleForm\Account\Phone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sf_user_id = get_current_user_id();

// Auth::handle() redirige a /iniciar-sesion antes de llegar acá; el guard se
// repite porque esta plantilla no debe pintar datos de nadie sin sesión.
if ( $sf_user_id <= 0 ) {
	wp_safe_redirect( Pages::login_url() );
	exit;
}

$sf_name    = Customer::name( $sf_user_id );
$sf_email   = Customer::email( $sf_user_id );
$sf_phone   = Phone::local( Customer::phone( $sf_user_id ) );
$sf_created = Customer::created_at( $sf_user_id );
$sf_error   = Auth::error();
$sf_notice  = Auth::notice();

get_header();
?>

<main class="sf-account sf-account--profile">
	<div class="sf-container">
		<div class="sf-profile">
			<p class="sf-profile__kicker">Cuenta</p>
			<h1 class="sf-profile__title">Mi perfil</h1>

			<?php if ( '' !== $sf_error ) : ?>
				<p class="sf-account__alert" role="alert"><?php echo esc_html( $sf_error ); ?></p>
			<?php endif; ?>

			<?php if ( '' !== $sf_notice ) : ?>
				<p class="sf-account__notice" role="status"><?php echo esc_html( $sf_notice ); ?></p>
			<?php endif; ?>

			<form class="sf-account__form sf-profile__form" method="post" action="<?php echo esc_url( Pages::profile_url() ); ?>">
				<?php wp_nonce_field( Auth::NONCE_PROFILE, Auth::FIELD_NONCE ); ?>
				<input type="hidden" name="<?php echo esc_attr( Auth::ACTION_FIELD ); ?>" value="profile" />

				<div class="sf-account__field">
					<label for="sf-profile-name">Nombre</label>
					<input type="text" id="sf-profile-name" name="<?php echo esc_attr( Auth::FIELD_NAME ); ?>" value="<?php echo esc_attr( $sf_name ); ?>" autocomplete="name" required />
				</div>

				<div class="sf-account__field">
					<label for="sf-profile-phone">Número de teléfono</label>
					<div class="sf-account__group">
						<span class="sf-account__prefix">+56</span>
						<input type="tel" id="sf-profile-phone" name="<?php echo esc_attr( Auth::FIELD_PHONE ); ?>" value="<?php echo esc_attr( $sf_phone ); ?>" placeholder="9 1234 5678" autocomplete="tel" inputmode="tel" required />
					</div>
				</div>

				<button class="sf-account__submit sf-profile__save" type="submit">Guardar cambios</button>
			</form>

			<dl class="sf-profile__list">
				<div class="sf-profile__row">
					<dt>Correo electrónico</dt>
					<dd><?php echo esc_html( $sf_email ); ?></dd>
				</div>
				<div class="sf-profile__row">
					<dt>Cuenta creada</dt>
					<dd><?php echo esc_html( '' !== $sf_created ? mysql2date( 'd/m/Y', $sf_created ) : '—' ); ?></dd>
				</div>
			</dl>

			<p class="sf-account__hint">El correo es el nombre de tu cuenta: si necesitas cambiarlo, escríbenos.</p>

			<a class="sf-profile__logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Cerrar sesión</a>
		</div>
	</div>
</main>

<?php
get_footer();
