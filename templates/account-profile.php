<?php
/**
 * Página /perfil.
 *
 * Solo lectura: muestra los datos con los que se creó la cuenta. La edición no
 * está implementada a propósito.
 *
 * @package SimpleForm\Account
 */

use SimpleForm\Account\Customer;
use SimpleForm\Account\Phone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sf_user_id = get_current_user_id();

// Auth::handle() redirige a /iniciar-sesion antes de llegar acá; el guard se
// repite porque esta plantilla no debe pintar datos de nadie sin sesión.
if ( $sf_user_id <= 0 ) {
	wp_safe_redirect( \SimpleForm\Account\Pages::login_url() );
	exit;
}

$sf_name    = Customer::name( $sf_user_id );
$sf_email   = Customer::email( $sf_user_id );
$sf_phone   = Phone::format( Customer::phone( $sf_user_id ) );
$sf_created = Customer::created_at( $sf_user_id );

get_header();
?>

<main class="sf-account sf-account--profile">
	<div class="sf-container">
		<div class="sf-profile">
			<p class="sf-profile__kicker">Cuenta</p>
			<h1 class="sf-profile__title">Mi perfil</h1>

			<dl class="sf-profile__list">
				<div class="sf-profile__row">
					<dt>Nombre</dt>
					<dd><?php echo esc_html( '' !== $sf_name ? $sf_name : '—' ); ?></dd>
				</div>
				<div class="sf-profile__row">
					<dt>Correo electrónico</dt>
					<dd><?php echo esc_html( $sf_email ); ?></dd>
				</div>
				<div class="sf-profile__row">
					<dt>Número de teléfono</dt>
					<dd><?php echo esc_html( '' !== $sf_phone ? $sf_phone : '—' ); ?></dd>
				</div>
				<div class="sf-profile__row">
					<dt>Cuenta creada</dt>
					<dd><?php echo esc_html( '' !== $sf_created ? mysql2date( 'd/m/Y', $sf_created ) : '—' ); ?></dd>
				</div>
			</dl>

			<a class="sf-profile__logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Cerrar sesión</a>
		</div>
	</div>
</main>

<?php
get_footer();
