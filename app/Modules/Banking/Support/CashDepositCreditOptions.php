<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Actions\FindCashDepositCandidates;
use App\Modules\Banking\Actions\ListCreditsForTransfer;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Support\Money\Decimal;

/**
 * Los créditos del extracto que podrían acreditar un traslado, como los
 * muestra el diálogo de acreditación.
 *
 * Propone, no vincula: confirmar es de una persona, porque un crédito mal
 * atribuido cierra una ventana de tránsito que sigue abierta. Lo usan las
 * pantallas de cada caja —Haberes, Multas—, que deciden quién puede verlo.
 */
final class CashDepositCreditOptions
{
    public function __construct(
        private readonly FindCashDepositCandidates $buscar,
        private readonly ListCreditsForTransfer $listar,
    ) {}

    /**
     * Con `$todos`, el listado ancho en vez del filtro duro: «no hay
     * candidatos» casi nunca significa que el crédito no exista, y sin esta
     * salida la pantalla queda sin nada que ofrecer justo cuando más hace
     * falta.
     *
     * @return array{candidates: list<array<string, mixed>>, todos: bool, periodImported: bool}
     */
    public function for(CashToBankTransfer $transfer, bool $todos): array
    {
        $candidatos = $todos
            ? array_map(
                fn (BankTransaction $m): array => $this->describirCredito($m, $transfer),
                $this->listar->handle($transfer),
            )
            : array_map(
                fn (CashDepositCandidate $c): array => [
                    'id' => $c->transaction->id,
                    'date' => $c->transaction->transaction_date?->format('Y-m-d'),
                    'amount' => $c->transaction->amount,
                    'description' => $c->transaction->description,
                    'operationId' => $c->transaction->operation_id,
                    'signals' => $c->signals,
                ],
                $this->buscar->handle($transfer),
            );

        return [
            'candidates' => $candidatos,
            'todos' => $todos,
            'periodImported' => $this->listar->periodIsImported($transfer),
        ];
    }

    /**
     * Un crédito del listado ancho, con su distancia a la vista.
     *
     * En qué difiere es lo único que el operador necesita para decidir, y
     * decirlo es lo que distingue esta lista de un volcado de la tabla.
     *
     * @return array<string, mixed>
     */
    private function describirCredito(BankTransaction $movimiento, CashToBankTransfer $transfer): array
    {
        $diferencia = Decimal::sub(
            Decimal::abs($movimiento->amount),
            $transfer->amount,
        );

        $dias = $movimiento->transaction_date === null
            ? null
            : (int) $transfer->deposit_date->diffInDays($movimiento->transaction_date, false);

        $signals = [
            'importe' => Decimal::equals($diferencia, '0')
                ? 'exacto'
                : 'difiere en $ '.Decimal::format(Decimal::abs($diferencia)),
        ];

        if ($dias !== null) {
            $signals['fecha'] = match (true) {
                $dias === 0 => 'el mismo día del depósito',
                $dias === 1 => 'el día siguiente',
                $dias > 1 => "{$dias} días después",
                $dias === -1 => 'un día antes del depósito',
                default => abs($dias).' días antes del depósito',
            };
        }

        return [
            'id' => $movimiento->id,
            'date' => $movimiento->transaction_date?->format('Y-m-d'),
            'amount' => $movimiento->amount,
            'description' => $movimiento->description,
            'operationId' => $movimiento->operation_id,
            'signals' => $signals,
        ];
    }
}
