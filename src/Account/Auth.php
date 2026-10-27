<?php
/**
 * Inicio de sesión y registro del cliente.
 *
 * Se apoya en WordPress de punta a punta: wp_signon() para entrar, wp_insert_user()
 * para crear la cuenta, wp_set_auth_cookie() para dejarla abierta y wp_logout_url()
 * para cerrarla. No hay sesión, cookie ni tabla propia.
 *
 * El formulario se envía por POST a la misma página y se atiende en
 * template_redirect, antes de que se imprima nada, porque el camino correcto
 * termina en una redirección.
 *
 * @package SimpleForm\Account
 */

namespace SimpleForm\Account;

use SimpleForm\Security\RateLimit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Auth
 */
final class Auth {

	/**
	 * Acción del nonce de los dos formularios.
	 */
	const NONCE_ACTION = 'simple_form_account';

	/**
	 * Nombre del campo que distingue los dos formularios.
	 *
	 * Todos los campos van con prefijo sf_ a propósito: `name` es una query var
	 * pública y WordPress la toma del $_POST en parse_request(), así que un
	 * campo llamado "name" convierte el envío en una búsqueda por slug y la
	 * página responde 404 antes de que nadie lea el formulario.
	 */
	const ACTION_FIELD = 'sf_account_action';

	/**
	 * Campo del nonce.
	 */
	const FIELD_NONCE = '_sf_nonce';

	/**
	 * Campos del cliente.
	 */
	const FIELD_NAME     = 'sf_name';
	const FIELD_EMAIL    = 'sf_email';
	const FIELD_PHONE    = 'sf_phone';
	const FIELD_PASSWORD = 'sf_password';

	/**
	 * Intentos de inicio de sesión por minuto y por cliente.
	 */
	const LIMIT_LOGIN = 10;

	/**
	 * Registros por minuto y por cliente.
	 */
	const LIMIT_REGISTER = 5;

	/**
	 * Largo mínimo de contraseña que se le exige al cliente.
	 */
	const MIN_PASSWORD = 8;

	/**
	 * Mensaje de error del último envío, para volver a pintar el formulario.
	 *
	 * @var string
	 */
	private static string $error = '';

	/**
	 * Formulario que hay que volver a mostrar tras un error: login o register.
	 *
	 * @var string
	 */
	private static string $view = 'login';

	/**
	 * Lo que escribió el cliente, para no borrarle los campos al fallar.
	 *
	 * @var array<string,string>
	 */
	private static array $values = array();

	/**
	 * Engancha el manejo del POST.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'handle' ) );
	}

	/**
	 * Error del último envío, o ''.
	 *
	 * @return string
	 */
	public static function error(): string {
		return self::$error;
	}

	/**
	 * Formulario que corresponde pintar en esta carga.
	 *
	 * El registro vive en la misma página y se pide con ?form=registro, así que
	 * funciona sin JavaScript.
	 *
	 * @return string login|register
	 */
	public static function view(): string {
		if ( 'register' === self::$view ) {
			return 'register';
		}

		$requested = isset( $_GET['form'] ) ? sanitize_key( wp_unslash( $_GET['form'] ) ) : '';

		return 'registro' === $requested ? 'register' : 'login';
	}

	/**
	 * Valor previo de un campo del formulario.
	 *
	 * @param string $key name|email|phone.
	 * @return string
	 */
	public static function value( string $key ): string {
		return isset( self::$values[ $key ] ) ? self::$values[ $key ] : '';
	}

	/**
	 * Atiende el envío y resuelve la sesión del visitante.
	 *
	 * Corre en template_redirect: hay redirecciones y todavía no hay salida.
	 */
	public function handle(): void {
		if ( is_user_logged_in() ) {
			if ( Pages::is_login_page() ) {
				wp_safe_redirect( Pages::profile_url() );
				exit;
			}

			return;
		}

		if ( Pages::is_profile_page() ) {
			wp_safe_redirect( Pages::login_url() );
			exit;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '';

		if ( 'POST' !== $method || ! Pages::is_login_page() ) {
			return;
		}

		$action = isset( $_POST[ self::ACTION_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::ACTION_FIELD ] ) ) : '';

		if ( 'login' === $action ) {
			$this->login();
			return;
		}

		if ( 'register' === $action ) {
			$this->register();
		}
	}

	/**
	 * Inicia sesión con correo y contraseña.
	 */
	private function login(): void {
		self::$view = 'login';

		$email    = isset( $_POST[ self::FIELD_EMAIL ] ) ? sanitize_email( wp_unslash( $_POST[ self::FIELD_EMAIL ] ) ) : '';
		$password = isset( $_POST[ self::FIELD_PASSWORD ] ) ? (string) wp_unslash( $_POST[ self::FIELD_PASSWORD ] ) : '';

		self::$values['email'] = $email;

		if ( ! self::check_nonce() ) {
			return;
		}

		if ( ! RateLimit::check( 'account_login', self::LIMIT_LOGIN ) ) {
			self::$error = __( 'Demasiados intentos. Espera un minuto y vuelve a probar.', 'simple-form' );
			return;
		}

		if ( ! is_email( $email ) || '' === $password ) {
			self::$error = self::generic_login_error();
			return;
		}

		// WordPress autentica con el login del usuario, no con el correo: se
		// traduce antes de llamar a wp_signon().
		$user = get_user_by( 'email', $email );

		$signed = wp_signon(
			array(
				'user_login'    => null === $user ? $email : $user->user_login,
				'user_password' => $password,
				'remember'      => true,
			),
			is_ssl()
		);

		if ( is_wp_error( $signed ) ) {
			// El motivo real (contraseña, correo inexistente, cuenta bloqueada)
			// no sale al navegador: sirve para enumerar cuentas.
			self::$error = self::generic_login_error();
			return;
		}

		wp_safe_redirect( Pages::profile_url() );
		exit;
	}

	/**
	 * Crea la cuenta del cliente con sus datos.
	 */
	private function register(): void {
		self::$view = 'register';

		$name      = isset( $_POST[ self::FIELD_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_NAME ] ) ) : '';
		$email     = isset( $_POST[ self::FIELD_EMAIL ] ) ? sanitize_email( wp_unslash( $_POST[ self::FIELD_EMAIL ] ) ) : '';
		$raw_phone = isset( $_POST[ self::FIELD_PHONE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_PHONE ] ) ) : '';
		$password  = isset( $_POST[ self::FIELD_PASSWORD ] ) ? (string) wp_unslash( $_POST[ self::FIELD_PASSWORD ] ) : '';

		self::$values = array(
			'name'  => $name,
			'email' => $email,
			'phone' => $raw_phone,
		);

		if ( ! self::check_nonce() ) {
			return;
		}

		if ( ! RateLimit::check( 'account_register', self::LIMIT_REGISTER ) ) {
			self::$error = __( 'Demasiados intentos. Espera un minuto y vuelve a probar.', 'simple-form' );
			return;
		}

		$phone = Phone::normalize( $raw_phone );

		if ( '' === $name ) {
			self::$error = __( 'Escribe tu nombre.', 'simple-form' );
			return;
		}

		if ( ! is_email( $email ) ) {
			self::$error = __( 'Escribe un correo electrónico válido.', 'simple-form' );
			return;
		}

		if ( email_exists( $email ) ) {
			self::$error = __( 'Ya existe una cuenta con este correo. Inicia sesión.', 'simple-form' );
			return;
		}

		if ( '' === $phone ) {
			self::$error = __( 'El teléfono debe tener 9 dígitos y empezar con 9 (celular chileno).', 'simple-form' );
			return;
		}

		if ( strlen( $password ) < self::MIN_PASSWORD ) {
			/* translators: %d: largo mínimo de la contraseña. */
			self::$error = sprintf( __( 'La contraseña debe tener al menos %d caracteres.', 'simple-form' ), self::MIN_PASSWORD );
			return;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => self::unique_login( $email ),
				'user_email'   => $email,
				'user_pass'    => $password,
				'first_name'   => $name,
				'nickname'     => $name,
				'display_name' => $name,
				'role'         => get_option( 'default_role', 'subscriber' ),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			self::$error = __( 'No pudimos crear la cuenta. Vuelve a intentarlo en un momento.', 'simple-form' );
			return;
		}

		// El teléfono es la única pieza que WordPress no tiene como campo nativo.
		update_user_meta( $user_id, Customer::META_PHONE, $phone );

		// Queda con la sesión abierta: el pedido ya puede usar sus datos.
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );

		wp_safe_redirect( Pages::profile_url() );
		exit;
	}

	/**
	 * Verifica el nonce del formulario y deja el error puesto si no vale.
	 *
	 * @return bool
	 */
	private static function check_nonce(): bool {
		$nonce = isset( $_POST[ self::FIELD_NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_NONCE ] ) ) : '';

		if ( wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return true;
		}

		self::$error = __( 'La página expiró. Recárgala y vuelve a intentarlo.', 'simple-form' );

		return false;
	}

	/**
	 * Mensaje único de credenciales incorrectas.
	 *
	 * @return string
	 */
	private static function generic_login_error(): string {
		return __( 'Correo o contraseña incorrectos.', 'simple-form' );
	}

	/**
	 * Login único derivado del correo.
	 *
	 * El cliente no lo elige ni lo ve: entra siempre con su correo.
	 *
	 * @param string $email Correo validado.
	 * @return string
	 */
	private static function unique_login( string $email ): string {
		$at   = strpos( $email, '@' );
		$base = sanitize_user( false === $at ? $email : substr( $email, 0, $at ), true );

		if ( '' === $base ) {
			$base = 'cliente';
		}

		$candidate = $base;
		$suffix    = 1;

		while ( username_exists( $candidate ) ) {
			++$suffix;
			$candidate = $base . $suffix;
		}

		return $candidate;
	}
}
