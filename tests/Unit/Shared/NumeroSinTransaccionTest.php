<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Modules\Shared\Actions\TakeNextDocumentNumber;
use RuntimeException;
use Tests\TestCase;

/**
 * Pedir un número fuera de una transacción no se permite.
 *
 * Vive en `Unit` y no en `Feature` por un motivo concreto:
 * `RefreshDatabase` envuelve cada test de `Feature` en una transacción, de
 * modo que allí **siempre** hay una abierta y esta comprobación nunca
 * podría fallar. Acá no hay ninguna, que es la situación de producción.
 *
 * Lo que protege: el correlativo y el comprobante que lo usa tienen que
 * confirmarse juntos. Si el número se toma fuera de una transacción y la
 * emisión falla después, queda un número consumido sin documento — y un
 * hueco en la numeración de comprobantes es exactamente lo que una
 * auditoría pregunta.
 */
class NumeroSinTransaccionTest extends TestCase
{
    public function test_pedir_un_numero_exige_una_transaccion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exige una transacción');

        (new TakeNextDocumentNumber)->handle('0010');
    }
}
