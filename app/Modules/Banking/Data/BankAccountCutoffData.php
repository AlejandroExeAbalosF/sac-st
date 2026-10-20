<?php

declare(strict_types=1);

namespace App\Modules\Banking\Data;

use App\Models\User;
use App\Modules\Banking\Models\BankAccountOpening;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * El corte de una cuenta compartida y si lo importado después cierra.
 */
#[TypeScript]
final class BankAccountCutoffData extends Data
{
    public function __construct(
        public int $bankAccountId,
        public string $cutoffOn,
        /** @var numeric-string */
        public string $statementBalance,
        public ?string $notes,
        public ?string $declaredByName,
        public string $declaredAt,
        public ?int $attachmentId,
        /**
         * El saldo al corte según un extracto que abarca ese día. Nulo si
         * ningún archivo importado lo demuestra todavía: la verificación
         * queda pendiente.
         *
         * @var numeric-string|null
         */
        public ?string $statementAtCutoff,
        /**
         * El saldo al corte más lo movido hasta el último saldo informado,
         * contra ese saldo, y cuántos movimientos posteriores todavía no se
         * pueden contrastar. Nulo si después del corte no hay saldo informado.
         *
         * @var array{expected: numeric-string, latest: numeric-string, latestDate: string, difference: numeric-string, unverifiedCount: int}|null
         */
        public ?array $reconciliation,
    ) {}

    /**
     * Espera cargado `declarer`.
     *
     * @param  numeric-string|null  $delExtracto
     * @param  array{expected: numeric-string, latest: numeric-string, latestDate: string, difference: numeric-string, unverifiedCount: int}|null  $conciliacion
     */
    public static function fromModel(BankAccountOpening $corte, ?int $adjunto, ?string $delExtracto, ?array $conciliacion): self
    {
        $autor = $corte->declarer;

        return new self(
            bankAccountId: $corte->bank_account_id,
            cutoffOn: $corte->cutoff_on->toDateString(),
            statementBalance: $corte->statement_balance,
            notes: $corte->notes,
            declaredByName: $autor instanceof User ? $autor->name : null,
            declaredAt: $corte->created_at->toIso8601String(),
            attachmentId: $adjunto,
            statementAtCutoff: $delExtracto,
            reconciliation: $conciliacion,
        );
    }
}
