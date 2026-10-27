<?php
/**
 * Pantalla de pedidos: listado y detalle, solo lectura.
 *
 * No edita montos ni estados: el estado lo mueve únicamente el webhook a
 * través de PaymentProcessor. Acá solo se consulta lo que quedó en la base.
 */

namespace SimpleForm\Admin;

use SimpleForm\Database\Database;
use SimpleForm\Database\OrderRepository;
use SimpleForm\Database\PaymentEventRepository;
use SimpleForm\Payments\Gateways;
use SimpleForm\Payments\OrderStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminOrders {

	const MENU_SLUG = 'simple-form-orders';
	const PER_PAGE  = 20;

	/**
	 * Registra el menú.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Añade "Pedidos" al menú de administración.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Pedidos', 'simple-form' ),
			__( 'Pedidos', 'simple-form' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-list-view',
			56
		);
	}

	/**
	 * Decide entre listado y detalle.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No autorizado.', 'simple-form' ) );
		}

		$order_id = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0;

		echo '<div class="wrap">';

		if ( $order_id > 0 ) {
			$this->render_detail( $order_id );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	/**
	 * Listado paginado de pedidos.
	 */
	private function render_list(): void {
		global $wpdb;

		$table  = Database::table_name( Database::TABLE_ORDERS );
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$page   = min( $page, $pages );
		$offset = ( $page - 1 ) * self::PER_PAGE;

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", self::PER_PAGE, $offset ),
			ARRAY_A
		);

		printf(
			'<h1>%s <span class="title-count">(%s)</span></h1>',
			esc_html__( 'Pedidos', 'simple-form' ),
			esc_html( number_format_i18n( $total ) )
		);

		if ( empty( $rows ) ) {
			printf( '<p>%s</p>', esc_html__( 'Todavía no hay pedidos.', 'simple-form' ) );
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		foreach ( array( 'Pedido', 'Fecha', 'Cliente', 'Servicio', 'Radio', 'Monto', 'Estado', 'Pago' ) as $heading ) {
			printf( '<th scope="col">%s</th>', esc_html( $heading ) );
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$url = add_query_arg(
				array(
					'page'  => self::MENU_SLUG,
					'order' => (int) $row['id'],
				),
				admin_url( 'admin.php' )
			);

			echo '<tr>';
			printf(
				'<td><a href="%s"><strong>#%s</strong></a></td>',
				esc_url( $url ),
				esc_html( (string) $row['id'] )
			);
			printf( '<td>%s</td>', esc_html( self::format_date( $row['created_at'] ) ) );
			printf(
				'<td>%s<br /><span class="description">%s</span></td>',
				esc_html( (string) $row['full_name'] ),
				esc_html( (string) $row['email'] )
			);
			printf( '<td>%s</td>', esc_html( (string) $row['service_label'] ) );
			printf( '<td>%s</td>', esc_html( self::format_radius( (float) $row['radius_km'] ) ) );
			printf( '<td>%s</td>', esc_html( self::format_money( (int) $row['amount'] ) ) );
			printf( '<td>%s</td>', self::status_html( (string) $row['status'] ) );
			printf(
				'<td>%s</td>',
				esc_html( '' !== (string) $row['external_payment_id'] ? (string) $row['external_payment_id'] : '—' )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( $pages > 1 ) {
			$links = paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%', admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
					'format'    => '',
					'current'   => $page,
					'total'     => $pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				)
			);

			if ( $links ) {
				printf( '<div class="tablenav"><div class="tablenav-pages">%s</div></div>', wp_kses_post( $links ) );
			}
		}
	}

	/**
	 * Detalle de un pedido y sus eventos de pago.
	 *
	 * @param int $order_id
	 */
	private function render_detail( int $order_id ): void {
		$order = OrderRepository::find( $order_id );

		$back = add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) );

		printf(
			'<p><a href="%s">&larr; %s</a></p>',
			esc_url( $back ),
			esc_html__( 'Volver a pedidos', 'simple-form' )
		);

		if ( null === $order ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Ese pedido no existe.', 'simple-form' )
			);
			return;
		}

		printf(
			'<h1>%s #%s %s</h1>',
			esc_html__( 'Pedido', 'simple-form' ),
			esc_html( (string) $order['id'] ),
			self::status_html( (string) $order['status'] )
		);

		echo '<h2>' . esc_html__( 'Cliente', 'simple-form' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row( __( 'Cuenta', 'simple-form' ), self::account_label( (int) $order['user_id'] ) );
		$this->row( __( 'Nombre', 'simple-form' ), (string) $order['full_name'] );
		$this->row( __( 'Correo', 'simple-form' ), (string) $order['email'] );
		$this->row( __( 'Teléfono', 'simple-form' ), (string) $order['phone'] );
		$this->row( __( 'Descripción', 'simple-form' ), (string) $order['description'] );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Servicio y ubicación', 'simple-form' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row( __( 'Servicio', 'simple-form' ), (string) $order['service_label'] );
		$this->row( __( 'Precio base', 'simple-form' ), self::format_money( (int) $order['service_base_price'] ) );
		$this->row( __( 'Precio por km', 'simple-form' ), self::format_money( (int) $order['service_price_per_km'] ) );
		$this->row( __( 'Radio', 'simple-form' ), self::format_radius( (float) $order['radius_km'] ) );
		$this->row(
			__( 'Ubicación', 'simple-form' ),
			null !== $order['lat'] && null !== $order['lng']
				? sprintf( '%s, %s', (string) $order['lat'], (string) $order['lng'] )
				: ''
		);
		$this->row( __( 'Monto', 'simple-form' ), self::format_money( (int) $order['amount'] ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Pago', 'simple-form' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row(
			__( 'Medio', 'simple-form' ),
			'' !== (string) $order['payment_method'] ? Gateways::label( (string) $order['payment_method'] ) : ''
		);
		$this->row( __( 'Estado', 'simple-form' ), OrderStatus::label( (string) $order['status'] ) );
		$this->row( __( 'ID de pago', 'simple-form' ), (string) $order['external_payment_id'] );
		$this->row( __( 'Pagado', 'simple-form' ), self::format_date( $order['paid_at'] ) );
		$this->row( __( 'Creado', 'simple-form' ), self::format_date( $order['created_at'] ) );
		$this->row( __( 'Actualizado', 'simple-form' ), self::format_date( $order['updated_at'] ) );
		echo '</tbody></table>';

		$events = PaymentEventRepository::find_by_order( (int) $order['id'] );

		echo '<h2>' . esc_html__( 'Eventos de pago', 'simple-form' ) . '</h2>';

		if ( empty( $events ) ) {
			printf( '<p>%s</p>', esc_html__( 'Sin eventos registrados.', 'simple-form' ) );
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		foreach ( array( 'Fecha', 'Pasarela', 'Tipo', 'Estado externo', 'Clave de idempotencia' ) as $heading ) {
			printf( '<th scope="col">%s</th>', esc_html( $heading ) );
		}
		echo '</tr></thead><tbody>';

		foreach ( $events as $event ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( self::format_date( $event['created_at'] ) ) );
			printf( '<td>%s</td>', esc_html( (string) $event['gateway'] ) );
			printf( '<td>%s</td>', esc_html( (string) $event['type'] ) );
			printf( '<td>%s</td>', esc_html( (string) $event['raw_status'] ) );
			printf( '<td><code>%s</code></td>', esc_html( (string) $event['external_event_id'] ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Fila de una tabla de detalle. Escapa siempre el valor.
	 *
	 * @param string      $label
	 * @param string|null $value
	 */
	/**
	 * Cuenta dueña del pedido, para no confundirla con el snapshot de contacto.
	 *
	 * El pedido guarda a quién se le cobró (nombre, correo, teléfono) y, aparte,
	 * el user_id de quien lo hizo con sesión. Un invitado queda en 0.
	 *
	 * @param int $user_id Cuenta del pedido.
	 * @return string
	 */
	private static function account_label( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return __( 'Invitado (sin cuenta)', 'simple-form' );
		}

		$user = get_userdata( $user_id );

		if ( false === $user ) {
			/* translators: %d: id de la cuenta. */
			return sprintf( __( 'Cuenta #%d (ya no existe)', 'simple-form' ), $user_id );
		}

		/* translators: 1: id de la cuenta, 2: correo. */
		return sprintf( __( 'Cuenta #%1$d — %2$s', 'simple-form' ), $user_id, (string) $user->user_email );
	}

	private function row( string $label, ?string $value ): void {
		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html( $label ),
			esc_html( '' !== (string) $value ? (string) $value : '—' )
		);
	}

	/**
	 * Etiqueta del estado. "Aprobado" va en negrita; el resto, normal.
	 *
	 * @param string $status
	 * @return string HTML ya escapado.
	 */
	private static function status_html( string $status ): string {
		$label = OrderStatus::is_valid( $status ) ? OrderStatus::label( $status ) : $status;

		if ( OrderStatus::APPROVED === $status ) {
			return '<strong>' . esc_html( $label ) . '</strong>';
		}

		return esc_html( $label );
	}

	/**
	 * Monto en CLP entero.
	 *
	 * @param int $amount
	 * @return string
	 */
	private static function format_money( int $amount ): string {
		return '$' . number_format_i18n( $amount, 0 );
	}

	/**
	 * Radio legible: metros hasta 1 km, después km. Igual que en el front.
	 *
	 * @param float $km
	 * @return string
	 */
	private static function format_radius( float $km ): string {
		$metros = (int) round( $km * 1000 );

		if ( $metros <= 0 ) {
			return '—';
		}

		if ( $metros < 1000 ) {
			return $metros . ' m';
		}

		return (int) round( $metros / 1000 ) . ' km';
	}

	/**
	 * Fecha guardada en UTC, mostrada en la zona del sitio.
	 *
	 * @param string|null $utc
	 * @return string
	 */
	private static function format_date( ?string $utc ): string {
		if ( empty( $utc ) ) {
			return '';
		}

		return (string) get_date_from_gmt( $utc, 'Y-m-d H:i' );
	}
}
