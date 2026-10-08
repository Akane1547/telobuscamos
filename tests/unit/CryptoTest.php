<?php
/**
 * Cifrado de credenciales en reposo.
 *
 * @package SimpleForm\Tests
 */

namespace SimpleForm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleForm\Security\Crypto;
use SimpleForm\Tests\FakeWordPress;

final class CryptoTest extends TestCase {

	private const SECRET = 'TEST-1234567890-abcdef';

	protected function setUp(): void {
		parent::setUp();
		FakeWordPress::reset();
	}

	public function test_sodium_esta_disponible(): void {
		$this->assertTrue( Crypto::available() );
	}

	public function test_ida_y_vuelta(): void {
		$cifrado = Crypto::encrypt( self::SECRET );

		$this->assertNotSame( '', $cifrado );
		$this->assertSame( self::SECRET, Crypto::decrypt( $cifrado ) );
	}

	public function test_el_cifrado_no_contiene_el_texto_claro(): void {
		$this->assertStringNotContainsString( 'TEST-1234', Crypto::encrypt( self::SECRET ) );
	}

	public function test_valores_largos_con_acentos(): void {
		$largo = str_repeat( 'áéíóúñ-', 500 );

		$this->assertSame( $largo, Crypto::decrypt( Crypto::encrypt( $largo ) ) );
	}

	public function test_dos_cifrados_del_mismo_valor_son_distintos(): void {
		$this->assertNotSame( Crypto::encrypt( self::SECRET ), Crypto::encrypt( self::SECRET ) );
		$this->assertSame( self::SECRET, Crypto::decrypt( Crypto::encrypt( self::SECRET ) ) );
	}

	public function test_la_cadena_vacia_no_se_cifra(): void {
		$this->assertSame( '', Crypto::encrypt( '' ) );
		$this->assertSame( '', Crypto::decrypt( '' ) );
	}

	public function test_criptograma_alterado_no_descifra(): void {
		$raw = base64_decode( Crypto::encrypt( self::SECRET ), true );
		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 0xFF );

		$this->assertSame( '', Crypto::decrypt( base64_encode( $raw ) ) );
	}

	public function test_base64_invalido_no_descifra(): void {
		$this->assertSame( '', Crypto::decrypt( '!!!esto no es base64!!!' ) );
	}

	public function test_base64_valido_pero_corto_no_descifra(): void {
		$this->assertSame( '', Crypto::decrypt( base64_encode( 'corto' ) ) );
	}

	public function test_texto_que_nunca_paso_por_encrypt_no_descifra(): void {
		$this->assertSame( '', Crypto::decrypt( 'un-token-en-claro' ) );
	}

	public function test_con_otro_salt_no_se_puede_descifrar(): void {
		$cifrado = Crypto::encrypt( self::SECRET );

		FakeWordPress::set_salt( 'otro-salt-completamente-distinto' );

		$this->assertSame( '', Crypto::decrypt( $cifrado ) );
		$this->assertSame( self::SECRET, Crypto::decrypt( Crypto::encrypt( self::SECRET ) ) );

		FakeWordPress::set_salt( 'salt-de-prueba-0123456789' );
		$this->assertSame( self::SECRET, Crypto::decrypt( $cifrado ) );
	}

	public function test_sin_salt_no_hay_cifrado(): void {
		$cifrado = Crypto::encrypt( self::SECRET );

		FakeWordPress::set_salt( '' );

		$this->assertSame( '', Crypto::encrypt( self::SECRET ) );
		$this->assertSame( '', Crypto::decrypt( $cifrado ) );
	}
}
