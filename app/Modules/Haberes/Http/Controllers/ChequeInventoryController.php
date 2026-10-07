<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Haberes\Support\ChequeInventory;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Shared\Models\CashBox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La cartera de cheques de la caja de Haberes, para el modal de la caja.
 *
 * Responde JSON: se abre encima de la caja del día, sin dejarla.
 */
final class ChequeInventoryController extends Controller
{
    public function __invoke(Request $request, ChequeInventory $cartera): JsonResponse
    {
        $datos = $request->validate([
            'currency' => ['nullable', Rule::enum(Currency::class)],
        ]);

        $caja = (int) CashBox::query()->active()->where('code', CashBox::HABERES)->valueOrFail('id');

        return response()->json($cartera->for(
            $caja,
            Currency::from((string) ($datos['currency'] ?? Currency::Ars->value)),
        ));
    }
}
