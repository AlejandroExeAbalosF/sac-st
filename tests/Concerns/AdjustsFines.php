<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Modules\Multas\Models\Fine;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Un ajuste se pide como importe nuevo, no como diferencia.
 *
 * Los tests piensan en «reducir 400.000»: esto lo traduce a lo que manda el
 * formulario —el importe al que queda y el vigente que vio— leyendo el
 * vigente de la base.
 */
trait AdjustsFines
{
    /**
     * @return array{newAmount: string, expectedAmount: string}
     */
    private function importeAjustado(Fine $multa, string $tipo, string $importe): array
    {
        $vigente = (string) DB::table('fine_balances')->where('fine_id', $multa->id)->value('obligation');

        return [
            'newAmount' => $tipo === 'increase' ? Decimal::add($vigente, $importe) : Decimal::sub($vigente, $importe),
            'expectedAmount' => $vigente,
        ];
    }
}
