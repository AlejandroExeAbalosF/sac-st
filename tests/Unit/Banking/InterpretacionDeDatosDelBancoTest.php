<?php

declare(strict_types=1);

namespace Tests\Unit\Banking;

use App\Modules\Banking\Support\Counterparty;
use App\Support\Money\Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Las dos piezas de las que depende todo lo demás: leer un importe y
 * reconocer al empleador dentro del concepto.
 *
 * Van sin base de datos porque no la necesitan, y con los valores exactos
 * que traen los archivos del área.
 */
class InterpretacionDeDatosDelBancoTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function importes(): array
    {
        return [
            'formato argentino del CSV' => ['473.191,20', '473191.20'],
            'millones con coma decimal' => ['1.253.491,05', '1253491.05'],
            'decimal con punto, del Excel' => ['473191.2', '473191.20'],
            'entero sin decimales' => ['121', '121.00'],
            'negativo del Excel' => ['-2355296.41', '-2355296.41'],
            'saldo grande' => ['17.692.768,55', '17692768.55'],
            'coma sin miles' => ['891962,00', '891962.00'],
            // Sin este caso, `1.253.491` se leería como mil doscientos
            // cincuenta y tres con cuarenta y nueve.
            'miles sin decimales' => ['1.253.491', '1253491.00'],
            'un solo grupo de miles' => ['1.253', '1253.00'],
            'con símbolo y espacios' => ['$ 800.000,00', '800000.00'],
        ];
    }

    #[DataProvider('importes')]
    public function test_interpreta_los_importes_del_extracto(string $crudo, string $esperado): void
    {
        $this->assertSame($esperado, Decimal::parse($crudo));
    }

    public function test_lo_que_no_es_un_importe_no_devuelve_cero(): void
    {
        // Devolver `0.00` acá sería inventar un movimiento sin plata. El
        // parser tiene que poder rechazar la fila.
        $this->assertNull(Decimal::parse(''));
        $this->assertNull(Decimal::parse(null));
        $this->assertNull(Decimal::parse('sin importe'));
        $this->assertNull(Decimal::parse('N/D'));
        $this->assertNull(Decimal::parse('1.2e5'));
        $this->assertNull(Decimal::parse('-'));
    }

    /**
     * Los separadores mal agrupados se leen igual.
     *
     * `12,34,56.7` no es un número bien escrito, pero el separador decimal
     * es el último y los dígitos están todos. Rechazarlo sería descartar un
     * movimiento real por un problema tipográfico del banco. Lo que no se
     * tolera —y es lo que de verdad importa— es que una cadena sin dígitos
     * se convierta en cero y entre a la contabilidad como un movimiento
     * sin plata.
     */
    public function test_tolera_los_miles_mal_agrupados(): void
    {
        $this->assertSame('123456.70', Decimal::parse('12,34,56.7'));
    }

    public function test_las_operaciones_rechazan_lo_que_no_es_numero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Decimal::add('1.00', 'dos');
    }

    public function test_suma_y_resta_sin_perder_centavos(): void
    {
        $this->assertSame('18473068.40', Decimal::add('18473189.40', '-121.00'));
        $this->assertSame('22656189.76', Decimal::sub('19875115.76', '-2781074.00'));
        $this->assertTrue(Decimal::equals('17692768.55', '17692768.550'));
    }

    /**
     * @return array<string, array{0: string, 1: string|null, 2: string|null}>
     */
    public static function conceptos(): array
    {
        return [
            'transferencia con apellido' => [
                'TRANSF SANDOVAL 20444444445 VAR VARIOS',
                '20444444445',
                'SANDOVAL',
            ],
            'circuito cerrado con nombre truncado' => [
                'CCERR J V L B AGR 30333333339 CIRC.CERRADO',
                '30333333339',
                'J V L B AGR',
            ],
            'transferencia con token de operación' => [
                'TRANSF:3D5W612EJ6GK5EQG9GXYVR-30715030817',
                '30715030817',
                null,
            ],
            'credin con token' => [
                'CREDIN:ORD6LEN87LXOLKJ42M1Y30-30710668929',
                '30710668929',
                null,
            ],
            'ingreso con razón social' => [
                'ING TRANSF:HIPERMAYORISTA ATLAS S-30715030817',
                '30715030817',
                'HIPERMAYORISTA ATLAS S',
            ],
            // Un depósito por ventanilla: el número que trae no es un CUIT
            // y no hay a quién nombrar.
            'depósito sin identificar' => [
                '995979830 - Numero de Operacion',
                null,
                null,
            ],
            'comisión bancaria' => [
                'Comision Trf. MacrOL E-set',
                null,
                null,
            ],
        ];
    }

    #[DataProvider('conceptos')]
    public function test_encuentra_al_empleador_dentro_del_concepto(
        string $concepto,
        ?string $cuit,
        ?string $nombre,
    ): void {
        $contraparte = Counterparty::fromDescription($concepto);

        $this->assertSame($cuit, $contraparte->identifier);
        $this->assertSame($nombre, $contraparte->name);
    }

    /**
     * El dígito verificador es lo que separa un CUIT de un número de
     * once cifras cualquiera.
     */
    public function test_valida_el_digito_verificador_del_cuit(): void
    {
        $this->assertTrue(Counterparty::isValidCuit('30222222229'));
        $this->assertTrue(Counterparty::isValidCuit('20444444445'));

        $this->assertFalse(Counterparty::isValidCuit('30712160737'));
        $this->assertFalse(Counterparty::isValidCuit('99999999999'));
        $this->assertFalse(Counterparty::isValidCuit('123456789'));
    }
}
