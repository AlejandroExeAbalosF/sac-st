<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Shared\Models\Receipt;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un pago de «Pagos anteriores» que todavía puede respaldar cuotas.
 *
 * El número que se muestra es el del talonario si lo hubo, igual que en
 * el historial de esa pantalla: es el que el beneficiario firmó.
 */
#[TypeScript]
final class LegacyReceiptOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $number,
        public string $date,
        public ?string $medium,
        /** La referencia a la planilla manual. */
        public ?string $reference,
        /** @var numeric-string */
        public string $amount,
        /** @var numeric-string */
        public string $available,
    ) {}

    /** @param  numeric-string  $available */
    public static function fromModel(Receipt $recibo, string $available): self
    {
        return new self(
            id: $recibo->id,
            number: $recibo->talonario_number ?? $recibo->formatted_number,
            date: $recibo->issue_date->format('Y-m-d'),
            medium: $recibo->medium_snapshot,
            reference: $recibo->expediente_number_snapshot,
            amount: $recibo->amount,
            available: $available,
        );
    }
}
