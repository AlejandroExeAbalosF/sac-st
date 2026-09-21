<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money\AmountInWords;
use PHPUnit\Framework\TestCase;

/**
 * El importe en letras del comprobante.
 *
 * **Estos son los mismos casos que `resources/js/lib/amount-words.test.ts`,
 * a propósito.** Las dos implementaciones existen porque se usan en
 * momentos distintos —el front avisa mientras se tipea, este imprime el
 * recibo—, y un par de gemelos que se separan es peor que no tenerlos:
 * el aviso en pantalla diría una cosa y el papel otra.
 *
 * Si acá se agrega un caso, va también al de TypeScript.
 */
class AmountInWordsTest extends TestCase
{
    public function test_lee_los_tramos_que_tienen_forma_propia(): void
    {
        $this->assertSame('cero', AmountInWords::for('0'));
        $this->assertSame('uno', AmountInWords::for('1'));
        $this->assertSame('quince', AmountInWords::for('15'));
        $this->assertSame('veintiuno', AmountInWords::for('21'));
        $this->assertSame('treinta y uno', AmountInWords::for('31'));
        $this->assertSame('cien', AmountInWords::for('100'));
        $this->assertSame('ciento uno', AmountInWords::for('101'));
        $this->assertSame('quinientos', AmountInWords::for('500'));
        $this->assertSame('novecientos noventa y nueve', AmountInWords::for('999'));
    }

    public function test_apocopa_uno_delante_de_mil_y_de_millones(): void
    {
        $this->assertSame('mil', AmountInWords::for('1000'));
        $this->assertSame('veintiún mil', AmountInWords::for('21000'));
        $this->assertSame('un millón', AmountInWords::for('1000000'));
        $this->assertSame('veintiún millones', AmountInWords::for('21000000'));
    }

    /** La razón de que el recibo lleve el importe en letras. */
    public function test_distingue_a_simple_vista_un_cero_de_mas(): void
    {
        $this->assertSame('diez millones', AmountInWords::for('10000000'));
        $this->assertSame('cien millones', AmountInWords::for('100000000'));
        $this->assertSame('un millón', AmountInWords::for('1000000'));
    }

    public function test_lee_los_importes_del_relevamiento(): void
    {
        $this->assertSame(
            'un millón doscientos cuatro mil quinientos',
            AmountInWords::for('1204500.00'),
        );
        $this->assertSame(
            'cinco mil setecientos noventa con seis centavos',
            AmountInWords::for('5790.06'),
        );
        $this->assertSame(
            'seiscientos dos mil doscientos cincuenta',
            AmountInWords::for('602250.00'),
        );
    }

    /** El importe llega como lo tipea el operador, al estilo argentino. */
    public function test_acepta_el_importe_como_lo_tipea_el_operador(): void
    {
        $this->assertSame(
            'un millón doscientos cuatro mil quinientos con cincuenta centavos',
            AmountInWords::for('1.204.500,50'),
        );
        $this->assertSame('mil con cincuenta centavos', AmountInWords::for('1000.50'));
    }

    public function test_dice_el_signo(): void
    {
        $this->assertSame('menos seis millones', AmountInWords::for('-6000000.00'));
    }

    public function test_calla_cuando_no_hay_nada_que_leer(): void
    {
        $this->assertSame('', AmountInWords::for(''));
        $this->assertSame('', AmountInWords::for(null));
        // Trece dígitos: más allá el número deja de ser legible en voz alta.
        $this->assertSame('', AmountInWords::for('9999999999999'));
    }
}
