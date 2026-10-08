<?php
/**
 * Verificación de la firma de las notificaciones de Mercado Pago.
 *
 * Es la parte más delicada del webhook: si esto está mal, o cualquiera puede
 * marcar un pedido como pagado, o Mercado Pago nunca logra notificarnos. Por eso
 * se prueba sin HTTP, con el header armado exactamente como lo manda Mercado
 * Pago según su documentación.
 *
 * @package SimpleForm\Tests
 */

namespace SimpleForm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleForm\Rest\WebhookController;
use SimpleForm\Tests\FakeWordPress;

final class WebhookControllerTest extends TestCase {

	private const SECRETO = 'a1b2c3d4e5f60718293a4b5c6d7e8f90112233445566778899aabbccddeeff00';

	protected function setUp(): void {
		parent::setUp();
		FakeWordPress::reset();
	}

	/**
	 * Arma el header tal como lo manda Mercado Pago, calculando el HMAC con la
	 * fórmula documentada: id:[data.id];request-id:[x-request-id];ts:[ts];
	 *
	 * @param string $id      Valor de data.id.
	 * @param string $ts      Timestamp en milisegundos.
	 * @param string $secreto Clave con la que firmar.
	 * @return string Valor del header x-signature.
	 */
	private function firma( string $id, string $ts, string $secreto = self::SECRETO ): string {
		$mensaje = sprintf( 'id:%s;request-id:%s;ts:%s;', strtolower( $id ), 'req-123', $ts );

		return sprintf( 'ts=%s,v1=%s', $ts, hash_hmac( 'sha256', $mensaje, $secreto ) );
	}

	/**
	 * @param string $firma Valor del header.
	 * @return array
	 */
	private function server( string $firma, string $request_id = 'req-123' ): array {
		return array(
			'HTTP_X_SIGNATURE'  => $firma,
			'HTTP_X_REQUEST_ID' => $request_id,
		);
	}

	public function test_acepta_una_firma_valida(): void {
		$ts = '1704908010';

		$this->assertTrue(
			WebhookController::signature_matches( $this->server( $this->firma( '123456', $ts ) ), '123456', self::SECRETO )
		);
	}

	public function test_rechaza_una_firma_hecha_con_otro_secreto(): void {
		$firma = $this->firma( '123456', '1704908010', str_repeat( 'f', 64 ) );

		$this->assertFalse(
			WebhookController::signature_matches( $this->server( $firma ), '123456', self::SECRETO )
		);
	}

	/**
	 * La firma ata el id: si alguien cambia el id del pago, deja de coincidir.
	 */
	public function test_rechaza_si_cambia_el_id(): void {
		$firma = $this->firma( '123456', '1704908010' );

		$this->assertFalse(
			WebhookController::signature_matches( $this->server( $firma ), '999999', self::SECRETO )
		);
	}

	public function test_rechaza_si_cambia_el_request_id(): void {
		$firma = $this->firma( '123456', '1704908010' );

		$this->assertFalse(
			WebhookController::signature_matches( $this->server( $firma, 'otro-request' ), '123456', self::SECRETO )
		);
	}

	/**
	 * La documentación pide el id en minúsculas en el mensaje firmado.
	 */
	public function test_el_id_se_compara_en_minusculas(): void {
		$firma = $this->firma( 'ABC123XYZ', '1704908010' );

		$this->assertTrue(
			WebhookController::signature_matches( $this->server( $firma ), 'ABC123XYZ', self::SECRETO )
		);
	}

	public function test_el_orden_de_los_parametros_del_header_no_importa(): void {
		$ts      = '1704908010';
		$mensaje = sprintf( 'id:%s;request-id:%s;ts:%s;', '123456', 'req-123', $ts );
		$hmac    = hash_hmac( 'sha256', $mensaje, self::SECRETO );

		$this->assertTrue(
			WebhookController::signature_matches( $this->server( 'v1=' . $hmac . ',ts=' . $ts ), '123456', self::SECRETO )
		);
	}

	public function test_acepta_el_hmac_en_mayusculas(): void {
		$ts      = '1704908010';
		$mensaje = sprintf( 'id:%s;request-id:%s;ts:%s;', '123456', 'req-123', $ts );
		$hmac    = strtoupper( hash_hmac( 'sha256', $mensaje, self::SECRETO ) );

		$this->assertTrue(
			WebhookController::signature_matches( $this->server( 'ts=' . $ts . ',v1=' . $hmac ), '123456', self::SECRETO )
		);
	}

	public function test_sin_header_de_firma_no_pasa(): void {
		$this->assertFalse(
			WebhookController::signature_matches( array( 'HTTP_X_REQUEST_ID' => 'req-123' ), '123456', self::SECRETO )
		);
	}

	public function test_sin_request_id_no_pasa(): void {
		$this->assertFalse(
			WebhookController::signature_matches( array( 'HTTP_X_SIGNATURE' => $this->firma( '123456', '1704908010' ) ), '123456', self::SECRETO )
		);
	}

	public function test_un_header_malformado_no_pasa(): void {
		$this->assertFalse(
			WebhookController::signature_matches( $this->server( 'v1=solov1' ), '123456', self::SECRETO )
		);
	}

	public function test_sin_secreto_configurado_no_pasa_nada(): void {
		$this->assertFalse(
			WebhookController::signature_matches( $this->server( $this->firma( '123456', '1704908010' ) ), '123456', '' )
		);
	}

	public function test_sin_id_de_pago_no_pasa(): void {
		$this->assertFalse(
			WebhookController::signature_matches( $this->server( $this->firma( '123456', '1704908010' ) ), '', self::SECRETO )
		);
	}
}
