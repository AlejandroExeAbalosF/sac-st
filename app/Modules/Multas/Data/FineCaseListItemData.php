<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineCase;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Una fila de la vista por expediente: el expediente con lo que suman sus
 * multas.
 */
#[TypeScript]
final class FineCaseListItemData extends Data
{
    public function __construct(
        public int $id,
        /** El número en la dirección; `null` si no está normalizado. */
        public ?string $routeNumber,
        public string $label,
        /**
         * Las carátulas de sus multas, sin repetir. Casi siempre es una: las
         * multas de un mismo expediente suelen ser de la misma empresa.
         *
         * @var list<string>
         */
        public array $captions,
        /** @var list<string> */
        public array $personNames,
        public int $finesCount,
        public int $finesWithDebt,
        public bool $hasExcess,
        /** @var list<FineCaseTotalData> */
        public array $totals,
    ) {}

    /**
     * Espera cargados `fines`, `fines.person` y `fines.balance`.
     */
    public static function fromModel(FineCase $expediente): self
    {
        // En el orden en que se registraron, para que la primera carátula sea la de la primera multa.
        $multas = $expediente->fines->sortBy('id');

        /** @var array<string, array{obligation: numeric-string, debt: numeric-string}> $porMoneda */
        $porMoneda = [];
        $conDeuda = 0;
        $conExcedente = false;

        foreach ($multas as $multa) {
            $saldo = FineBalanceData::fromModel($multa->balance);
            $total = $porMoneda[$multa->currency] ?? ['obligation' => '0.00', 'debt' => '0.00'];

            $porMoneda[$multa->currency] = [
                'obligation' => bcadd($total['obligation'], $saldo->obligation, 2),
                'debt' => bcadd($total['debt'], $saldo->debt, 2),
            ];

            $conDeuda += bccomp($saldo->debt, '0', 2) > 0 ? 1 : 0;
            $conExcedente = $conExcedente || bccomp($saldo->excess, '0', 2) !== 0;
        }

        $totales = [];

        foreach ($porMoneda as $moneda => $total) {
            $totales[] = new FineCaseTotalData(Currency::from($moneda), $total['obligation'], $total['debt']);
        }

        return new self(
            id: $expediente->id,
            routeNumber: $expediente->routeNumber(),
            label: $expediente->label(),
            captions: self::distintos($multas->map(fn (Fine $multa): string => $multa->caption)->all()),
            personNames: self::distintos($multas->map(fn (Fine $multa): ?string => $multa->person?->name)->all()),
            finesCount: $multas->count(),
            finesWithDebt: $conDeuda,
            hasExcess: $conExcedente,
            totals: $totales,
        );
    }

    /**
     * @param  array<array-key, string|null>  $valores
     * @return list<string>
     */
    private static function distintos(array $valores): array
    {
        return array_values(array_unique(array_filter($valores, fn (?string $valor): bool => $valor !== null && $valor !== '')));
    }
}
