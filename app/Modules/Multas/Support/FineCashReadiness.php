<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Models\User;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Shared\Models\CashBox;

/**
 * Si la caja de Multas todavía no se abrió, dicho a quien lo necesita.
 *
 * Sin apertura no se cobra en el mostrador, ni se deposita, ni se verifica
 * un pago contra el extracto. Las pantallas ocultan esos botones, y sin
 * este aviso el operador veía la multa como si no hubiera nada que cobrar:
 * en un entorno recién instalado «Cobrar en caja» simplemente no estaba.
 *
 * La cuenta bancaria no se pregunta aparte: la apertura de Multas la exige
 * y una cuenta se da de baja sin borrarse, así que con apertura hay cuenta.
 *
 * Solo explica: los Actions y la base siguen rechazando por su cuenta.
 */
final class FineCashReadiness
{
    /**
     * Si falta la apertura y a esta persona le afecta; nulo si nada la frena.
     *
     * @param  bool  $affected  si cobra, deposita o verifica: lo que exige la apertura
     * @return array{canFix: bool}|null
     */
    public function pending(?User $usuario, Currency $moneda, bool $affected): ?array
    {
        if ($usuario === null || ! $affected) {
            return null;
        }

        $caja = CashBox::query()->where('code', CashBox::MULTAS)->value('id');

        if ($caja !== null && CashBookOpening::for((int) $caja, $moneda) !== null) {
            return null;
        }

        return ['canFix' => $usuario->can('multas.caja.abrir')];
    }
}
