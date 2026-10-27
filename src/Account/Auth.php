<?php
/**
 * Inicio de sesión, registro, confirmación de correo, recuperación de contraseña
 * y edición del perfil del cliente.
 *
 * Se apoya en WordPress de punta a punta: wp_signon() para entrar, wp_insert_user()
 * para crear la cuenta, wp_set_auth_cookie() para dejarla abierta, wp_set_password()
 * al confirmar el correo y retrieve_password() para la contraseña olvidada. No hay
 * sesión, cookie ni tabla propia.
 *
 * El registro no abre sesión: deja la cuenta pendiente y manda un enlace al
 * correo. Solo al abrirlo —y elegir la contraseña— la cuenta queda activa, así
 * que nadie puede apropiarse de un correo ajeno escribiéndolo en el formulario.
 *
 * Todo se atiende en template_redirect, antes de que se imprima nada, porque el
 * camino correcto termina en una redirección.
 *
 * @package SimpleForm\Account
 */

namespace SimpleForm\Account;

use SimpleForm\Mail\Mailer;
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
	 * Acción del nonce del formulario de perfil (es otra operación).
	 */
	const NONCE_PROFILE = 'simple_form_profile';

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
	 * Recuperaciones de contraseña por minuto y por cliente.
	 */
	const LIMIT_RECOVER = 5;

	/**
	 * Intentos de confirmación de correo por minuto y por cliente.
	 */
	const LIMIT_CONFIRM = 10;

	/**
	 * Campo del token de confirmación.
	 */
	const FIELD_TOKEN = 'sf_token';

	/**
	 * Campo de la contraseña repetida al confirmar.
	 */
	const FIELD_PASSWORD_2 = 'sf_password_2';

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
	 * Aviso neutro (no es un error): "revisa tu correo", "te enviamos las
	 * instrucciones". Es el mismo texto en los dos caminos del registro, para
	 * que la respuesta no diga si el correo ya existía.
	 *
	 * @var string
	 */
	private static string $notice = '';

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

		// Una cuenta pendiente no entra ni por wp-login.php: el enlace del
		// correo es el único camino para activarla.
		add_filter( 'authenticate', array( $this, 'block_pending' ), 30, 3 );
	}

	/**
	 * Rechaza el login de una cuenta que todavía no confirmó el correo.
	 *
	 * @param mixed  $user     Usuario autenticado, error o null.
	 * @param string $username Login escrito.
	 * @param string $password Contraseña escrita (no se usa).
	 * @return mixed
	 */
	public function block_pending( $user, $username, $password ) {
		if ( $user instanceof \WP_User && Confirmation::is_pending( (int) $user->ID ) ) {
			return new \WP_Error(
				'simple_form_pending',
				__( 'Confirma tu correo para activar la cuenta: revisa el enlace que te enviamos.', 'simple-form' )
			);
		}

		return $user;
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
	 * Aviso neutro del último envío, o ''.
	 *
	 * @return string
	 */
	public static function notice(): string {
		return self::$notice;
	}

	/**
	 * Token que llegó por la URL de confirmación (para el formulario).
	 *
	 * @return string
	 */
	public static function token(): string {
		return isset( $_GET['sf_confirm'] ) ? sanitize_text_field( wp_unslash( $_GET['sf_confirm'] ) ) : '';
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
		if ( in_array( self::$view, array( 'register', 'recover', 'confirm' ), true ) ) {
			return self::$view;
		}

		// Con un token en la URL, la página es el formulario de confirmación.
		if ( '' !== self::token() ) {
			return 'confirm';
		}

		$requested = isset( $_GET['form'] ) ? sanitize_key( wp_unslash( $_GET['form'] ) ) : '';

		if ( 'registro' === $requested ) {
			return 'register';
		}

		if ( 'recuperar' === $requested ) {
			return 'recover';
		}

		return 'login';
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
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '';

		if ( is_user_logged_in() ) {
			if ( Pages::is_login_page() ) {
				wp_safe_redirect( Pages::profile_url() );
				exit;
			}

			// El perfil es editable: el envío se atiende acá, con la sesión ya
			// resuelta por WordPress.
			if ( 'POST' === $method && Pages::is_profile_page() ) {
				$this->update_profile();
			}

			return;
		}

		if ( Pages::is_profile_page() ) {
			wp_safe_redirect( Pages::login_url() );
			exit;
		}

		if ( ! Pages::is_login_page() ) {
			return;
		}

		// Enlace del correo: activa la cuenta y deja el formulario de contraseña.
		if ( 'GET' === $method && '' !== self::token() ) {
			$this->confirm();

			return;
		}

		if ( 'POST' !== $method ) {
			return;
		}

		$action = isset( $_POST[ self::ACTION_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::ACTION_FIELD ] ) ) : '';

		if ( 'login' === $action ) {
			$this->login();

			return;
		}

		if ( 'register' === $action ) {
			$this->register();

			return;
		}

		if ( 'recover' === $action ) {
			$this->recover();

			return;
		}

		if ( 'confirm' === $action ) {
			$this->confirm_password();
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
		// traduce antes de llamar a wp_signon(). Ojo: get_user_by() devuelve
		// false cuando no hay usuario, no null; comparar contra null dejaba
		// pasar un $user->user_login sobre false (warning en el HTML, que
		// además delata si el correo existe).
		$user = get_user_by( 'email', $email );

		// Cuenta creada pero sin confirmar: el enlace del correo todavía no se
		// abrió. No se dice nada del resto de las cuentas.
		if ( $user instanceof \WP_User && Confirmation::is_pending( (int) $user->ID ) ) {
			self::$error = __( 'Confirma tu correo para activar la cuenta: revisa el enlace que te enviamos.', 'simple-form' );
			return;
		}

		$signed = wp_signon(
			array(
				'user_login'    => $user instanceof \WP_User ? $user->user_login : $email,
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

		if ( '' === $phone ) {
			self::$error = __( 'El teléfono debe tener 9 dígitos y empezar con 9 (celular chileno).', 'simple-form' );
			return;
		}

		if ( strlen( $password ) < self::MIN_PASSWORD ) {
			/* translators: %d: largo mínimo de la contraseña. */
			self::$error = sprintf( __( 'La contraseña debe tener al menos %d caracteres.', 'simple-form' ), self::MIN_PASSWORD );
			return;
		}

		// El navegador recibe la misma respuesta exista o no la cuenta: el aviso
		// de "ya tienes cuenta" va al correo del dueño, no al formulario.
		$existing = get_user_by( 'email', $email );

		if ( $existing instanceof \WP_User ) {
			$this->notify_existing( $existing );

			self::$notice = self::register_notice();
			self::$view   = 'login';

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

		$token = Confirmation::issue( (int) $user_id );

		Mailer::account_confirmation( $email, Confirmation::url( $token ), $name );

		// Sin sesión: la cuenta queda pendiente hasta que se abra el enlace y se
		// elija la contraseña.
		self::$notice = self::register_notice();
		self::$view   = 'login';
	}

	/**
	 * Avisa al dueño de una cuenta que ya existe.
	 *
	 * Si la cuenta está pendiente, se le reenvía el enlace de confirmación (quizá
	 * se perdió el correo); si ya está activa, se le dice que entre con su
	 * contraseña. En los dos casos el navegador no cambia de respuesta.
	 *
	 * @param \WP_User $user Cuenta con ese correo.
	 */
	private function notify_existing( \WP_User $user ): void {
		if ( Confirmation::is_pending( (int) $user->ID ) ) {
			$token = Confirmation::issue( (int) $user->ID );

			Mailer::account_confirmation( (string) $user->user_email, Confirmation::url( $token ), Customer::name( (int) $user->ID ) );

			return;
		}

		Mailer::account_exists( (string) $user->user_email );
	}

	/**
	 * Texto único del registro, exista o no la cuenta.
	 *
	 * @return string
	 */
	private static function register_notice(): string {
		return __( 'Si los datos están bien, te enviamos un correo con un enlace para activar la cuenta. Revisa tu bandeja (y el correo no deseado).', 'simple-form' );
	}

	/**
	 * Abre el formulario de confirmación desde el enlace del correo.
	 *
	 * No activa nada todavía: primero hay que elegir la contraseña.
	 */
	private function confirm(): void {
		self::$view = 'confirm';

		if ( 0 === Confirmation::verify( self::token() ) ) {
			self::$view  = 'login';
			self::$error = __( 'Ese enlace no sirve: venció o ya se usó. Puedes registrarte otra vez o recuperar tu contraseña.', 'simple-form' );
		}
	}

	/**
	 * Activa la cuenta con la contraseña que eligió el cliente.
	 */
	private function confirm_password(): void {
		self::$view = 'confirm';

		$token = isset( $_POST[ self::FIELD_TOKEN ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_TOKEN ] ) ) : '';

		if ( ! self::check_nonce() ) {
			return;
		}

		if ( ! RateLimit::check( 'account_confirm', self::LIMIT_CONFIRM ) ) {
			self::$error = __( 'Demasiados intentos. Espera un minuto y vuelve a probar.', 'simple-form' );
			return;
		}

		$user_id = Confirmation::verify( $token );

		if ( 0 === $user_id ) {
			self::$view  = 'login';
			self::$error = __( 'Ese enlace no sirve: venció o ya se usó. Puedes registrarte otra vez o recuperar tu contraseña.', 'simple-form' );
			return;
		}

		$password  = isset( $_POST[ self::FIELD_PASSWORD ] ) ? (string) wp_unslash( $_POST[ self::FIELD_PASSWORD ] ) : '';
		$password2 = isset( $_POST[ self::FIELD_PASSWORD_2 ] ) ? (string) wp_unslash( $_POST[ self::FIELD_PASSWORD_2 ] ) : '';

		if ( strlen( $password ) < self::MIN_PASSWORD ) {
			/* translators: %d: largo mínimo de la contraseña. */
			self::$error = sprintf( __( 'La contraseña debe tener al menos %d caracteres.', 'simple-form' ), self::MIN_PASSWORD );
			return;
		}

		if ( $password !== $password2 ) {
			self::$error = __( 'Las dos contraseñas no coinciden.', 'simple-form' );
			return;
		}

		// La contraseña que se escribió al registrarse no se usa nunca: la que
		// vale es esta, elegida por quien abrió el enlace del correo. Así una
		// cuenta creada con un correo ajeno no queda con la clave de quien la
		// creó.
		wp_set_password( $password, $user_id );

		Confirmation::activate( $user_id );

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );

		wp_safe_redirect( Pages::profile_url() );
		exit;
	}

	/**
	 * Pide el enlace de recuperación de contraseña.
	 *
	 * El aviso es el mismo exista o no la cuenta: el correo lo manda WordPress
	 * (`retrieve_password()`) solo cuando la cuenta existe y está confirmada.
	 */
	private function recover(): void {
		self::$view = 'recover';

		$email = isset( $_POST[ self::FIELD_EMAIL ] ) ? sanitize_email( wp_unslash( $_POST[ self::FIELD_EMAIL ] ) ) : '';

		self::$values['email'] = $email;

		if ( ! self::check_nonce() ) {
			return;
		}

		if ( ! RateLimit::check( 'account_recover', self::LIMIT_RECOVER ) ) {
			self::$error = __( 'Demasiados intentos. Espera un minuto y vuelve a probar.', 'simple-form' );
			return;
		}

		if ( '' !== $email && is_email( $email ) ) {
			$user = get_user_by( 'email', $email );

			if ( $user instanceof \WP_User && ! Confirmation::is_pending( (int) $user->ID ) ) {
				retrieve_password( $user->user_login );
			}
		}

		self::$notice = __( 'Si ese correo tiene una cuenta, te enviamos las instrucciones para cambiar la contraseña. Revisa tu bandeja (y el correo no deseado).', 'simple-form' );
		self::$view   = 'login';
	}

	/**
	 * Guarda el nombre y el teléfono del usuario autenticado.
	 *
	 * La identidad sale de get_current_user_id(): el formulario no puede elegir
	 * a qué cuenta le escribe. El correo no se edita acá: es el identificador de
	 * la cuenta y cambiarlo pide confirmar la dirección nueva.
	 */
	private function update_profile(): void {
		$action = isset( $_POST[ self::ACTION_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::ACTION_FIELD ] ) ) : '';

		if ( 'profile' !== $action ) {
			return;
		}

		$user_id = get_current_user_id();

		$nonce = isset( $_POST[ self::FIELD_NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_NONCE ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_PROFILE ) ) {
			self::$error = __( 'La página expiró. Recárgala y vuelve a intentarlo.', 'simple-form' );
			return;
		}

		$name  = isset( $_POST[ self::FIELD_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_NAME ] ) ) : '';
		$phone = isset( $_POST[ self::FIELD_PHONE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_PHONE ] ) ) : '';

		if ( '' === $name ) {
			self::$error = __( 'Escribe tu nombre.', 'simple-form' );
			return;
		}

		if ( '' === Phone::normalize( $phone ) ) {
			self::$error = __( 'El teléfono debe tener 9 dígitos y empezar con 9 (celular chileno).', 'simple-form' );
			return;
		}

		if ( ! Customer::save( $user_id, $name, $phone ) ) {
			self::$error = __( 'No pudimos guardar los cambios. Vuelve a intentarlo en un momento.', 'simple-form' );
			return;
		}

		self::$notice = __( 'Guardamos tus datos.', 'simple-form' );
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
