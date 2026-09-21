<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Support\TicketCandidate;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un movimiento propuesto, con el porqué a la vista.
 *
 * Las señales viajan como texto y no como puntaje: quien confirma tiene
 * que poder entender por qué un candidato está primero, sobre todo cuando
 * el orden está equivocado.
 */
#[TypeScript]
final class TicketCandidateData extends Data
{
    public function __construct(
        public int $transactionId,
        public string $transactionDate,
        /** @var numeric-string */
        public string $amount,
        public ?string $operationId,
        public ?string $causalCode,
        public ?string $description,
        public ?string $counterpartyIdentifier,
        public ?string $counterpartyName,
        /** @var numeric-string|null */
        public ?string $balanceAfter,
        public int $dayGap,
        public bool $operationMatches,
        public bool $employerMatches,
        /** @var array<string, string> */
        public array $signals,
    ) {}

    public static function fromCandidate(TicketCandidate $candidato): self
    {
        $movimiento = $candidato->transaction;

        return new self(
            transactionId: $movimiento->id,
            transactionDate: $movimiento->transaction_date?->format('Y-m-d') ?? '',
            amount: $movimiento->amount,
            operationId: $movimiento->operation_id,
            causalCode: $movimiento->causal_code,
            description: $movimiento->description,
            counterpartyIdentifier: $movimiento->counterparty_identifier,
            counterpartyName: $movimiento->counterparty_name,
            balanceAfter: $movimiento->balance_after,
            dayGap: $candidato->dayGap,
            operationMatches: $candidato->operationMatches,
            employerMatches: $candidato->employerMatches,
            signals: $candidato->signals,
        );
    }
}
