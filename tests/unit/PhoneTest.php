<?php
/**
 * Formato del teléfono: una sola regla para la cuenta y para el pedido.
 *
 * El teléfono se guarda como nueve dígitos y se muestra con código de país. Lo
 * que se comprueba acá es que la normalización no acepte nada que simple-form
 * rechazaría, porque si el registro guardara un formato que el formulario no
 * entiende, el cliente quedaría con un dato inutilizable.
 *
 * @package SimpleForm\Tests
 */

namespace SimpleForm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleForm\Account\Phone;

final class PhoneTest extends TestCase {

	public function test_normaliza_las_formas_que_escribe_el_cliente(): void {
		$casos = array(
			'987654321',
			'9 8765 4321',
			'987654321',
			'+56 9 8765 4321',
			'+56987654321',
			'56987654321',
			'  9-8765-4321 ',
		);

		foreach ( $casos as $entrada ) {
			$this->assertSame( '987654321', Phone::normalize( $entrada ), "entrada: {$entrada}" );
		}
	}

	public function test_rechaza_lo_que_no_es_celular_chileno(): void {
		$casos = array(
			'',
			'12345678',
			'123456789',
			'888888888',
			'98765432',
			'9876543210',
			'5698765432',
			'abc',
		);

		foreach ( $casos as $entrada ) {
			$this->assertSame( '', Phone::normalize( $entrada ), "entrada: {$entrada}" );
		}
	}

	public function test_presenta_los_dos_formatos(): void {
		$this->assertSame( '9 8765 4321', Phone::local( '987654321' ) );
		$this->assertSame( '+56 9 8765 4321', Phone::format( '987654321' ) );
	}

	public function test_format_no_inventa_nada_con_entrada_invalida(): void {
		$this->assertSame( '', Phone::local( '123' ) );
		$this->assertSame( '', Phone::format( '123' ) );
	}
}
