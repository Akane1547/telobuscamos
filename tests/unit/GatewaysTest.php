<?php
/**
 * Registro de pasarelas: metadatos e invariantes.
 *
 * @package SimpleForm\Tests
 */

namespace SimpleForm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleForm\Payments\Gateways;

final class GatewaysTest extends TestCase {

	public function test_lista_las_pasarelas_registradas(): void {
		$this->assertSame( array( 'mercado_pago' ), Gateways::ids() );
	}

	public function test_reconoce_una_registrada(): void {
		$this->assertTrue( Gateways::has( 'mercado_pago' ) );
	}

	public function test_no_reconoce_una_desconocida(): void {
		$this->assertFalse( Gateways::has( 'paypal' ) );
		$this->assertFalse( Gateways::has( '' ) );
	}

	public function test_etiqueta_visible(): void {
		$this->assertSame( 'Mercado Pago', Gateways::label( 'mercado_pago' ) );
		$this->assertSame( '', Gateways::label( 'paypal' ) );
	}

	public function test_clase_de_la_pasarela(): void {
		$this->assertSame( 'SimpleForm\Payments\MercadoPagoGateway', Gateways::gateway_class( 'mercado_pago' ) );
		$this->assertSame( '', Gateways::gateway_class( 'paypal' ) );
	}

	public function test_campos_de_credenciales(): void {
		$this->assertSame( array( 'access_token', 'webhook_secret' ), Gateways::credential_fields( 'mercado_pago' ) );
		$this->assertSame( array(), Gateways::credential_fields( 'paypal' ) );
	}

	/**
	 * Añadir una pasarela con un id, una etiqueta o un campo mal escritos tiene
	 * que fallar aquí: es lo único que impide que un error de tipeo llegue a la
	 * pantalla del admin y al formulario.
	 */
	public function test_toda_entrada_del_registro_esta_completa_y_bien_formada(): void {
		$problemas = array();

		foreach ( Gateways::REGISTRY as $id => $entrada ) {
			if ( ! is_string( $id ) || '' === $id || $id !== strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $id ) ) ) {
				$problemas[] = "id inválido: {$id}";
			}

			if ( empty( $entrada['label'] ) || ! is_string( $entrada['label'] ) ) {
				$problemas[] = "{$id}: falta label";
			}

			if ( empty( $entrada['class'] ) || ! is_string( $entrada['class'] ) ) {
				$problemas[] = "{$id}: falta class";
			}

			if ( ! is_array( $entrada['credentials'] ) || array() === $entrada['credentials'] ) {
				$problemas[] = "{$id}: credentials vacío";
			}

			foreach ( (array) ( $entrada['credentials'] ?? array() ) as $campo ) {
				if ( ! is_string( $campo ) || $campo !== strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $campo ) ) ) {
					$problemas[] = "{$id}: campo de credencial inválido: " . var_export( $campo, true );
				}
			}
		}

		$this->assertSame( array(), $problemas );
	}
}
