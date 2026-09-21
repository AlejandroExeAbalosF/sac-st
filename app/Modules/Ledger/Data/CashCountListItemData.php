<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Data;

use App\Modules\Ledger\Models\CashCount;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un arqueo, tal como lo ve la pantalla.
 *
 * `balanced` y `fullyCounted` viajan como dos banderas separadas a
 * propósito. Un arqueo puede cuadrar sin haberse contado entero —es lo que
 * hizo el área los veinte días de junio— y la pantalla tiene que poder
 * decir las dos cosas: «cuadra» en verde y «no se contó todo» al lado. Una
 * sola bandera obligaría a elegir cuál de las dos se oculta.
 */
#[TypeScript]
final class CashCountListItemData extends Data
{
    /**
     * @param  list<array{denomination: numeric-string, quantity: int, subtotal: numeric-string}>  $lines
     */
    public function __construct(
        public int $id,
        public string $countedOn,
        public int $sequence,
        public string $currency,
        /** @var numeric-string */
        public string $expectedAmount,
        /** @var numeric-string */
        public string $countedAmount,
        /** @var numeric-string */
        public string $uncountedAmount,
        public ?string $uncountedReason,
        /** @var numeric-string */
        public string $differenceAmount,
        public string $status,
        public string $statusLabel,
        public ?string $explanation,
        public bool $balanced,
        public bool $fullyCounted,
        public bool $editable,
        public ?int $performedById,
        public ?string $performedBy,
        public ?string $reviewedBy,
        /** Lo revisó quien lo contó: la pantalla lo dice, no lo esconde. */
        public bool $selfReviewed,
        public bool $adjusted,
        public array $lines,
    ) {}

    public static function fromModel(CashCount $count): self
    {
        return new self(
            id: (int) $count->id,
            countedOn: $count->counted_on->toDateString(),
            sequence: $count->sequence,
            currency: $count->currency->value,
            expectedAmount: $count->expected_amount,
            countedAmount: $count->counted_amount,
            uncountedAmount: $count->uncounted_amount,
            uncountedReason: $count->uncounted_reason,
            differenceAmount: $count->difference_amount,
            status: $count->status->value,
            statusLabel: $count->status->label(),
            explanation: $count->explanation,
            balanced: $count->isBalanced(),
            fullyCounted: $count->wasFullyCounted(),
            editable: $count->status->isEditable(),
            performedById: $count->performed_by,
            performedBy: $count->relationLoaded('performedBy') ? $count->performedBy?->name : null,
            reviewedBy: $count->relationLoaded('reviewedBy') ? $count->reviewedBy?->name : null,
            selfReviewed: $count->wasSelfReviewed(),
            adjusted: $count->adjustment_event_id !== null,
            lines: self::lines($count),
        );
    }

    /**
     * El desglose por denominación, si vino cargado.
     *
     * Se arma con un bucle y no con `map`: una clausura que devuelve
     * `array` borra la forma del arreglo, y con ella la garantía de que
     * cada fila trae exactamente los tres campos que la pantalla espera.
     *
     * @return list<array{denomination: numeric-string, quantity: int, subtotal: numeric-string}>
     */
    private static function lines(CashCount $count): array
    {
        if (! $count->relationLoaded('lines')) {
            return [];
        }

        $desglose = [];

        foreach ($count->lines as $linea) {
            $desglose[] = [
                'denomination' => $linea->denomination,
                'quantity' => (int) $linea->quantity,
                'subtotal' => $linea->subtotal,
            ];
        }

        return $desglose;
    }
}
