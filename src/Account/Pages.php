<?php
/**
 * Rutas y presentación de la cuenta del cliente.
 *
 * Las dos páginas (/iniciar-sesion y /perfil) las sigue creando y editando
 * WordPress: este archivo solo decide qué plantilla se pinta en ellas, qué
 * estilos se encolan y cómo se ve el enlace del menú según la sesión. El tema
 * no se toca.
 *
 * @package SimpleForm\Account
 */

namespace SimpleForm\Account;

use SimpleForm\Core\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pages
 */
final class Pages {

	/**
	 * Slug de la página de inicio de sesión y registro.
	 */
	const LOGIN_SLUG = 'iniciar-sesion';

	/**
	 * Slug de la página de perfil.
	 */
	const PROFILE_SLUG = 'perfil';

	/**
	 * Encola estilos y filtros del front.
	 */
	public function __construct() {
		add_filter( 'template_include', array( $this, 'template' ) );
		add_filter( 'wp_setup_nav_menu_item', array( $this, 'nav_item' ) );
		add_filter( 'show_admin_bar', array( $this, 'admin_bar' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * La barra de WordPress no es parte del sitio del cliente.
	 *
	 * Un suscriptor la ve por omisión y le tapa la cabecera con un menú que no
	 * le sirve. Solo se mantiene para quien administra.
	 *
	 * @param bool $show Lo que decidió WordPress.
	 * @return bool
	 */
	public function admin_bar( $show ): bool {
		return current_user_can( 'manage_options' ) ? (bool) $show : false;
	}

	/**
	 * URL de la página de inicio de sesión.
	 *
	 * @return string
	 */
	public static function login_url(): string {
		return self::page_url( self::LOGIN_SLUG );
	}

	/**
	 * URL de la página de perfil.
	 *
	 * @return string
	 */
	public static function profile_url(): string {
		return self::page_url( self::PROFILE_SLUG );
	}

	/**
	 * Está el visitante en la página de inicio de sesión.
	 *
	 * @return bool
	 */
	public static function is_login_page(): bool {
		return is_page( self::LOGIN_SLUG );
	}

	/**
	 * Está el visitante en la página de perfil.
	 *
	 * @return bool
	 */
	public static function is_profile_page(): bool {
		return is_page( self::PROFILE_SLUG );
	}

	/**
	 * Sustituye la plantilla del tema por la de la cuenta.
	 *
	 * Las plantillas usan get_header()/get_footer(), así que la cabecera, el
	 * pie y la tipografía siguen siendo los del sitio.
	 *
	 * @param string $template Plantilla que resolvió WordPress.
	 * @return string
	 */
	public function template( $template ): string {
		if ( self::is_login_page() ) {
			return SIMPLE_FORM_PATH . 'templates/account-login.php';
		}

		if ( self::is_profile_page() ) {
			return SIMPLE_FORM_PATH . 'templates/account-profile.php';
		}

		return (string) $template;
	}

	/**
	 * Carga la hoja de la cuenta solo en sus dos páginas.
	 *
	 * No se toca el CSS del sitio: las clases van con prefijo sf-account.
	 */
	public function assets(): void {
		if ( ! self::is_login_page() && ! self::is_profile_page() ) {
			return;
		}

		wp_enqueue_style(
			'simple-form-account',
			SIMPLE_FORM_URL . 'assets/css/account.css',
			array(),
			Assets::asset_version( 'assets/css/account.css', SIMPLE_FORM_VERSION )
		);
	}

	/**
	 * Cambia "Iniciar sesión" por "Perfil" cuando hay sesión.
	 *
	 * Se decide en el servidor con is_user_logged_in(): el navegador no sabe ni
	 * opina sobre el estado de la sesión.
	 *
	 * @param object $item Ítem de menú que está preparando WordPress.
	 * @return object
	 */
	public function nav_item( $item ) {
		if ( is_admin() || ! is_object( $item ) || ! is_user_logged_in() ) {
			return $item;
		}

		if ( ! self::is_login_link( $item ) ) {
			return $item;
		}

		$item->title = __( 'Perfil', 'simple-form' );
		$item->url   = self::profile_url();

		$profile_page = get_page_by_path( self::PROFILE_SLUG );

		if ( null !== $profile_page ) {
			$item->object    = 'page';
			$item->object_id = (int) $profile_page->ID;
			$item->type      = 'post_type';
		}

		return $item;
	}

	/**
	 * El ítem es el que lleva a la página de inicio de sesión.
	 *
	 * Se reconoce por la página enlazada y, si el ítem es un enlace propio, por
	 * la ruta.
	 *
	 * @param object $item Ítem de menú.
	 * @return bool
	 */
	private static function is_login_link( $item ): bool {
		$login_page = get_page_by_path( self::LOGIN_SLUG );

		if ( null !== $login_page && isset( $item->object_id ) && (int) $item->object_id === (int) $login_page->ID ) {
			return true;
		}

		$url = isset( $item->url ) ? (string) $item->url : '';

		return '' !== $url && false !== strpos( $url, '/' . self::LOGIN_SLUG );
	}

	/**
	 * URL de una página del sitio, con respaldo si todavía no existe.
	 *
	 * @param string $slug Slug de la página.
	 * @return string
	 */
	private static function page_url( string $slug ): string {
		$page = get_page_by_path( $slug );

		if ( null !== $page ) {
			return (string) get_permalink( $page );
		}

		return home_url( '/' . $slug . '/' );
	}
}
