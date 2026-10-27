<?php
/**
 * Correos transaccionales: quién recibe qué, y cuándo.
 *
 * El Mailer no decide estado ni monto: recibe un pedido ya movido y solo
 * traduce a un correo. Se prueba con el doble de wp_mail que captura los envíos.
 *
 * @package SimpleForm\Tests
 */

namespace SimpleForm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleForm\Mail\Mailer;
use SimpleForm\Payments\OrderStatus;
use SimpleForm\Tests\FakeWordPress;

final class MailerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		FakeWordPress::reset();
		FakeWordPress::set_option( 'admin_email', 'admin@telobuscamos.local' );
	}

	/**
	 * Pedido con todos los datos que consume el correo.
	 */
	private function pedido( string $status ): array {
		return array(
			'id'            => 42,
			'status'        => $status,
			'full_name'     => 'Ana Pérez',
			'email'         => 'ana@ejemplo.cl',
			'phone'         => '912345678',
			'description'   => 'Cerca del metro',
			'service_label' => 'Mudanza básica',
			'radius_km'     => 0.5,
			'amount'        => 15000,
			'paid_at'       => '2026-10-08 12:00:00',
			'created_at'    => '2026-10-08 12:00:00',
			'lat'           => -33.4489,
			'lng'           => -70.6693,
		);
	}

	public function test_un_aprobado_envia_al_comprador_y_al_admin(): void {
		Mailer::notify( $this->pedido( OrderStatus::APPROVED ) );

		$mails = FakeWordPress::mails();

		$this->assertCount( 2, $mails );
		$this->assertSame( 'ana@ejemplo.cl', $mails[0]['to'] );
		$this->assertSame( 'admin@telobuscamos.local', $mails[1]['to'] );
	}

	public function test_un_rechazo_envia_solo_al_comprador(): void {
		Mailer::notify( $this->pedido( OrderStatus::REJECTED ) );

		$mails = FakeWordPress::mails();

		$this->assertCount( 1, $mails );
		$this->assertSame( 'ana@ejemplo.cl', $mails[0]['to'] );
	}

	public function test_un_pedido_pendiente_no_envia_nada(): void {
		Mailer::notify( $this->pedido( OrderStatus::PENDING ) );

		$this->assertSame( array(), FakeWordPress::mails() );
	}

	public function test_los_correos_se_pueden_apagar_con_el_filtro(): void {
		FakeWordPress::set_filter(
			Mailer::FILTER_ENABLED,
			function () {
				return false;
			}
		);

		Mailer::notify( $this->pedido( OrderStatus::APPROVED ) );

		$this->assertSame( array(), FakeWordPress::mails() );
	}

	public function test_sin_correo_de_comprador_el_aprobado_avisa_solo_al_admin(): void {
		$order         = $this->pedido( OrderStatus::APPROVED );
		$order['email'] = '';

		Mailer::notify( $order );

		$mails = FakeWordPress::mails();

		$this->assertCount( 1, $mails );
		$this->assertSame( 'admin@telobuscamos.local', $mails[0]['to'] );
	}

	public function test_sin_correo_de_admin_no_se_le_avisa(): void {
		FakeWordPress::set_option( 'admin_email', '' );

		Mailer::notify( $this->pedido( OrderStatus::APPROVED ) );

		$mails = FakeWordPress::mails();

		$this->assertCount( 1, $mails );
		$this->assertSame( 'ana@ejemplo.cl', $mails[0]['to'] );
	}

	public function test_el_correo_al_admin_responde_al_comprador(): void {
		Mailer::notify( $this->pedido( OrderStatus::APPROVED ) );

		$mails   = FakeWordPress::mails();
		$headers = $mails[1]['headers'];

		$this->assertContains( 'Reply-To: ana@ejemplo.cl', $headers );
	}

	public function test_el_monto_va_entero_y_con_formato_clp(): void {
		Mailer::notify( $this->pedido( OrderStatus::APPROVED ) );

		$mails = FakeWordPress::mails();

		$this->assertStringContainsString( '$15.000 CLP', $mails[0]['message'] );
		// Entero, nunca un float: el monto con punto decimal no puede aparecer.
		$this->assertStringNotContainsString( '15000.0', $mails[0]['message'] );
	}

	public function test_el_radio_se_formatea_en_metros_hasta_un_kilometro(): void {
		Mailer::notify( $this->pedido( OrderStatus::APPROVED ) );

		$mails = FakeWordPress::mails();

		$this->assertStringContainsString( 'Radio: 500 m', $mails[0]['message'] );
	}
}
