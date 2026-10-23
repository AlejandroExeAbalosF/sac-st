<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Los expedientes de Multas filtrados, para la vista por expediente.
 *
 * Usa los mismos filtros que el listado de multas y los lee igual: un
 * expediente aparece si alguna de sus multas coincide con la búsqueda o se
 * registró en el año pedido. La deuda, en cambio, es del expediente entero:
 * «sin deuda» quiere decir que ninguna de sus multas debe, no que alguna no
 * deba.
 *
 * Lo último que se registró va primero, como en el listado de multas: el
 * expediente se ordena por su multa más reciente, y los que solo tienen
 * históricos sin fecha van al final.
 *
 * @phpstan-import-type Filters from FineListQuery
 */
final class FineCaseListQuery
{
    /**
     * @param  Filters  $filtros
     * @return Builder<FineCase>
     */
    public function query(array $filtros): Builder
    {
        $coincidentes = Fine::query()
            ->select('fines.fine_case_id')
            ->buscar($filtros['q'])
            ->when($filtros['anio'] !== null, fn (Builder $query) => $query->whereYear('fines.registered_on', (int) $filtros['anio']));

        $resumen = DB::table('fines')
            ->join('fine_balances', 'fine_balances.fine_id', '=', 'fines.id')
            ->whereIn('fines.fine_case_id', $coincidentes)
            ->groupBy('fines.fine_case_id')
            ->select('fines.fine_case_id')
            ->selectRaw('COUNT(*) FILTER (WHERE fine_balances.debt > 0) AS fines_with_debt')
            ->selectRaw("MAX(COALESCE(fines.registered_on, '-infinity'::date)) AS last_registered_on");

        return FineCase::query()
            ->select('fine_cases.*')
            ->joinSub($resumen, 'resumen', 'resumen.fine_case_id', '=', 'fine_cases.id')
            ->when($filtros['estado'] === 'con-deuda', fn (Builder $query) => $query->where('resumen.fines_with_debt', '>', 0))
            ->when($filtros['estado'] === 'sin-deuda', fn (Builder $query) => $query->where('resumen.fines_with_debt', 0))
            ->with(['fines.person:id,name', 'fines.balance'])
            ->orderByDesc('resumen.last_registered_on')
            ->orderByDesc('fine_cases.id');
    }
}
