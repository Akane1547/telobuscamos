<?php
/**
 * Correos transaccionales del pedido.
 *
 * Único sitio que envía correos, y se dispara desde PaymentProcessor cuando una
 * transición de estado cambia de verdad un pedido. No decide nada: el estado ya
 * está persistido y validado por la máquina de estados cuando llega acá, así que
 * un reintento del webhook no vuelve a transicionar y no repite el correo.
 *
 * @package SimpleForm\Mail
 */

namespace SimpleForm\Mail;

use SimpleForm\Payments\OrderStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mailer {

	/**
	 * Permite apagar todos los correos (por ejemplo, en pruebas o en un
	 * entorno sin correo). Por defecto están encendidos.
	 */
	const FILTER_ENABLED = 'simple_form_email_enabled';

	/**
	 * Envía el correo (o correos) que corresponden al estado del pedido.
	 *
	 * Solo se llama con un pedido que acaba de cambiar de estado. Un aprobado
	 * avisa al comprador y al administrador; un rechazo, solo al comprador.
	 * El resto de los estados no notifica.
	 *
	 * @param array $order Pedido con el estado ya aplicado.
	 * @return void
	 */
	public static function notify( array $order ): void {
		if ( ! apply_filters( self::FILTER_ENABLED, true ) ) {
			return;
		}

		$status = (string) ( $order['status'] ?? '' );

		if ( OrderStatus::APPROVED === $status ) {
			self::to_buyer( $order, 'approved' );
			self::to_admin( $order );

			return;
		}

		if ( OrderStatus::REJECTED === $status ) {
			self::to_buyer( $order, 'rejected' );
		}
	}

	/**
	 * Correo de confirmación de cuenta.
	 *
	 * Lleva el enlace que activa la cuenta. Sin ese enlace nadie puede entrar:
	 * la cuenta queda pendiente hasta que se abra.
	 *
	 * @param string $email Correo de la cuenta.
	 * @param string $link  Enlace de confirmación.
	 * @param string $nombre Nombre escrito al registrarse (puede ir vacío).
	 * @return bool true si wp_mail aceptó el envío.
	 */
	public static function account_confirmation( string $email, string $link, string $nombre = '' ): bool {
		if ( ! apply_filters( self::FILTER_ENABLED, true ) ) {
			return false;
		}

		if ( '' === $email || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return false;
		}

		$lineas   = array();
		$lineas[] = '' !== $nombre ? sprintf( __( 'Hola %s:', 'simple-form' ), $nombre ) : __( 'Hola:', 'simple-form' );
		$lineas[] = '';
		$lineas[] = __( 'Para activar tu cuenta, abre este enlace:', 'simple-form' );
		$lineas[] = $link;
		$lineas[] = '';
		$lineas[] = __( 'El enlace vale 24 horas. Si no creaste esta cuenta, ignora este correo: sin abrir el enlace no queda activa.', 'simple-form' );

		return wp_mail(
			$email,
			__( 'Confirma tu cuenta', 'simple-form' ),
			implode( "\n", $lineas ),
			self::headers()
		);
	}

	/**
	 * Aviso a quien ya tiene cuenta, cuando alguien intenta registrarse con su
	 * correo.
	 *
	 * El navegador recibe siempre la misma respuesta, así que este correo es el
	 * único que se enterá de que hubo un intento: sirve para que la persona no
	 * quede con la duda y para que note si no fue ella.
	 *
	 * @param string $email Correo de la cuenta existente.
	 * @return bool true si wp_mail aceptó el envío.
	 */
	public static function account_exists( string $email ): bool {
		if ( ! apply_filters( self::FILTER_ENABLED, true ) ) {
			return false;
		}

		if ( '' === $email || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return false;
		}

		$lineas   = array();
		$lineas[] = __( 'Hola:', 'simple-form' );
		$lineas[] = '';
		$lineas[] = __( 'Alguien intentó crear una cuenta con este correo, y ya tienes una.', 'simple-form' );
		$lineas[] = __( 'Si fuiste tú, entra con tu contraseña de siempre:', 'simple-form' );
		$lineas[] = \SimpleForm\Account\Pages::login_url();
		$lineas[] = '';
		$lineas[] = __( 'Si olvidaste la contraseña, en esa página hay un enlace para recuperarla.', 'simple-form' );
		$lineas[] = __( 'Si no fuiste tú, no tienes que hacer nada: no se creó ninguna cuenta nueva.', 'simple-form' );

		return wp_mail(
			$email,
			__( 'Ya tienes cuenta en TeLoBuscamos', 'simple-form' ),
			implode( "\n", $lineas ),
			self::headers()
		);
	}

	/**
	 * Correo al comprador, según el desenlace.
	 *
	 * @param array  $order
	 * @param string $tipo approved|rejected.
	 * @return void
	 */
	private static function to_buyer( array $order, string $tipo ): void {
		$email = (string) ( $order['email'] ?? '' );

		if ( '' === $email || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return;
		}

		if ( 'approved' === $tipo ) {
			$subject = __( 'Tu pedido fue confirmado', 'simple-form' );
			$message = self::buyer_approved( $order );
		} else {
			$subject = __( 'Tu pago fue rechazado', 'simple-form' );
			$message = self::buyer_rejected( $order );
		}

		wp_mail( $email, $subject, $message, self::headers() );
	}

	/**
	 * Correo al administrador: hay un pedido confirmado que coordinar.
	 *
	 * Lleva los datos de contacto del comprador y la ubicación a propósito: es
	 * la información que necesita quien presta el servicio. Nunca lleva
	 * credenciales ni el detalle del pago.
	 *
	 * @param array $order
	 * @return void
	 */
	private static function to_admin( array $order ): void {
		$admin = self::admin_email();

		if ( '' === $admin || false === filter_var( $admin, FILTER_VALIDATE_EMAIL ) ) {
			return;
		}

		$headers = self::headers();

		$buyer = (string) ( $order['email'] ?? '' );

		if ( '' !== $buyer ) {
			$headers[] = 'Reply-To: ' . $buyer;
		}

		wp_mail( $admin, __( 'Nuevo pedido confirmado', 'simple-form' ), self::admin_body( $order ), $headers );
	}

	/**
	 * Cuerpo del correo de confirmación al comprador.
	 *
	 * @param array $order
	 * @return string
	 */
	private static function buyer_approved( array $order ): string {
		$nombre = (string) ( $order['full_name'] ?? '' );

		$lineas   = array();
		$lineas[] = '' !== $nombre ? sprintf( __( 'Hola %s:', 'simple-form' ), $nombre ) : __( 'Hola:', 'simple-form' );
		$lineas[] = '';
		$lineas[] = __( 'Tu pago fue confirmado. Acá va el resumen de tu pedido:', 'simple-form' );
		$lineas[] = '';
		$lineas   = array_merge( $lineas, self::resumen( $order ) );
		$lineas[] = '';
		$lineas[] = __( 'Gracias por tu compra.', 'simple-form' );

		return implode( "\n", $lineas );
	}

	/**
	 * Cuerpo del aviso de rechazo al comprador.
	 *
	 * @param array $order
	 * @return string
	 */
	private static function buyer_rejected( array $order ): string {
		$nombre = (string) ( $order['full_name'] ?? '' );

		$lineas   = array();
		$lineas[] = '' !== $nombre ? sprintf( __( 'Hola %s:', 'simple-form' ), $nombre ) : __( 'Hola:', 'simple-form' );
		$lineas[] = '';
		$lineas[] = __( 'Tu pago no se procesó y el pedido no quedó confirmado.', 'simple-form' );
		$lineas[] = __( 'Puedes intentarlo de nuevo desde el mismo formulario.', 'simple-form' );

		return implode( "\n", $lineas );
	}

	/**
	 * Cuerpo del aviso al administrador.
	 *
	 * @param array $order
	 * @return string
	 */
	private static function admin_body( array $order ): string {
		$lineas   = array();
		$lineas[] = __( 'Un pedido fue confirmado:', 'simple-form' );
		$lineas[] = '';
		$lineas[] = __( 'Pedido:', 'simple-form' ) . ' #' . (int) ( $order['id'] ?? 0 );
		$lineas   = array_merge( $lineas, self::resumen( $order ) );
		$lineas[] = '';
		$lineas[] = __( 'Cliente:', 'simple-form' );
		$lineas[] = '  ' . __( 'Nombre:', 'simple-form' ) . ' ' . (string) ( $order['full_name'] ?? '' );
		$lineas[] = '  ' . __( 'Correo:', 'simple-form' ) . ' ' . (string) ( $order['email'] ?? '' );
		$lineas[] = '  ' . __( 'Teléfono:', 'simple-form' ) . ' ' . (string) ( $order['phone'] ?? '' );

		$desc = (string) ( $order['description'] ?? '' );

		if ( '' !== $desc ) {
			$lineas[] = '  ' . __( 'Descripción:', 'simple-form' ) . ' ' . $desc;
		}

		$lat = (float) ( $order['lat'] ?? 0 );
		$lng = (float) ( $order['lng'] ?? 0 );

		if ( 0.0 !== $lat || 0.0 !== $lng ) {
			$lineas[] = '  ' . __( 'Ubicación:', 'simple-form' ) . ' ' . $lat . ', ' . $lng;
		}

		return implode( "\n", $lineas );
	}

	/**
	 * Líneas del resumen (servicio, radio, total y fecha), comunes a ambos
	 * destinatarios.
	 *
	 * @param array $order
	 * @return array
	 */
	private static function resumen( array $order ): array {
		$lineas = array();

		$servicio = (string) ( $order['service_label'] ?? '' );

		if ( '' !== $servicio ) {
			$lineas[] = __( 'Servicio:', 'simple-form' ) . ' ' . $servicio;
		}

		$radio = (float) ( $order['radius_km'] ?? 0 );

		if ( $radio > 0 ) {
			$lineas[] = __( 'Radio:', 'simple-form' ) . ' ' . self::format_radius( $radio );
		}

		$lineas[] = __( 'Total:', 'simple-form' ) . ' ' . self::money( (int) ( $order['amount'] ?? 0 ) );

		$fecha = self::fecha( $order );

		if ( '' !== $fecha ) {
			$lineas[] = __( 'Fecha:', 'simple-form' ) . ' ' . $fecha;
		}

		return $lineas;
	}

	/**
	 * Monto CLP entero, con separador de miles. Nunca un float.
	 *
	 * @param int $amount
	 * @return string
	 */
	private static function money( int $amount ): string {
		return '$' . number_format( $amount, 0, ',', '.' ) . ' CLP';
	}

	/**
	 * Radio legible: metros hasta 1 km, después km. Espejo del formateador del
	 * front (assets/js/config.js) y del de la pasarela, para que el correo diga
	 * lo mismo que el sitio.
	 *
	 * @param float $km
	 * @return string
	 */
	private static function format_radius( float $km ): string {
		$metros = (int) round( $km * 1000 );

		if ( $metros < 1000 ) {
			return $metros . ' m';
		}

		return (int) round( $metros / 1000 ) . ' km';
	}

	/**
	 * Fecha del pedido para el correo, en la hora local del sitio.
	 *
	 * @param array $order
	 * @return string Vacía si la fecha guardada no es interpretable.
	 */
	private static function fecha( array $order ): string {
		$utc = (string) ( $order['paid_at'] ?? $order['created_at'] ?? '' );

		if ( '' === $utc ) {
			return '';
		}

		$ts = strtotime( $utc . ' UTC' );

		if ( false === $ts ) {
			return '';
		}

		if ( function_exists( 'wp_date' ) ) {
			return wp_date( get_option( 'date_format', 'd/m/Y' ) . ' ' . get_option( 'time_format', 'H:i' ), $ts );
		}

		return gmdate( 'd/m/Y H:i', $ts );
	}

	/**
	 * Cabeceras comunes: From con el nombre del sitio y la dirección del admin.
	 * El nombre se limpia de saltos de línea para que nadie inyecte cabeceras.
	 *
	 * @return array
	 */
	private static function headers(): array {
		$name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';

		if ( '' === $name ) {
			$name = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		}

		$name = preg_replace( '/[\r\n]+/', ' ', $name );

		return array( 'From: ' . $name . ' <' . self::admin_email() . '>' );
	}

	/**
	 * @return string Dirección del administrador del sitio.
	 */
	private static function admin_email(): string {
		return (string) get_option( 'admin_email', '' );
	}
}
