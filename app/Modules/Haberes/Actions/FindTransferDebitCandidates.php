<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Support\TransferFees;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Haberes\Support\TransferDebitCandidate;
use App\Modules\Haberes\Support\TransferDebitMatch;
use App\Modules\Haberes\Support\TransferDebitPair;
use App\Support\Money\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Busca en el extracto el débito con que el organismo pagó.
 *
 * Es el tercer buscador del sistema y el más fácil de los tres: acá se
 * sabe **de qué cuenta** salió, **cuánto** exactamente y **hacia quién**.
 * Con los tickets del expediente hay que averiguar de quién viene un
 * crédito anónimo; acá el candidato o coincide o es otro pago.
 *
 * **El pago puede ser dos renglones.** Una transferencia MacrOnline E-set
 * trae su comisión aparte, con la misma referencia, y la comisión la
 * absorbe el beneficiario: la Orden se cancela con los dos. Qué cuenta
 * como pago lo decide `TransferDebitPair`, la misma regla que aplican la
 * vinculación y la validación.
 *
 * **No vincula: propone.** Confirmar es de una persona, porque un débito
 * mal atribuido daría por pagada una cuota con la transferencia de otra —y
 * dejaría a un trabajador esperando un dinero que el sistema cree
 * entregado—.
 */
final class FindTransferDebitCandidates
{
    public function __construct(private readonly TransferDebitPair $par) {}

    /**
     * Los débitos que podrían ser el pago de esta cuota.
     *
     * Arranca de la cuota y no del egreso porque el egreso puede todavía
     * no existir: el §12.4 contempla que el débito se reconozca antes de
     * que el organismo informe, y ése es justamente el momento en que
     * alguien pide esta lista.
     *
     * Devuelve los que coinciden y los que quedan **para revisión**: el
     * importe cierra, pero el extracto no alcanza para afirmarlo solo. La
     * pantalla muestra a los segundos sin dejarlos elegir.
     *
     * @return list<TransferDebitCandidate>
     */
    public function handle(BeneficiaryInstallment $installment): array
    {
        $installment->loadMissing('haber.beneficiary');

        $orden = PaymentOrder::query()
            ->active()
            ->where('beneficiary_installment_id', $installment->id)
            ->first();

        if ($orden === null || $orden->organism_bank_account_id === null) {
            return [];
        }

        $disbursement = Disbursement::query()
            ->live()
            ->where('beneficiary_installment_id', $installment->id)
            ->first();

        $importe = $disbursement->amount ?? $orden->amount;

        $desde = $orden->order_date->copy()->subDays(TransferDebitPair::DAYS_BEFORE_ORDER);

        /*
         * Una primera criba en la base, solo para no traer el extracto
         * entero: el débito que iguala el importe, o el que lo iguala con
         * una comisión de su operación. Quién coincide de verdad lo dice
         * `TransferDebitPair`, igual que al vincular y al validar.
         */
        $movimientos = BankTransaction::query()
            ->where('bank_account_id', $orden->organism_bank_account_id)
            ->where('direction', TransactionDirection::Debit->value)
            ->where('reconciliation_status', '!=', ReconciliationStatus::Ignored->value)
            ->whereDate('transaction_date', '>=', $desde->toDateString())
            ->where(fn (Builder $q) => $q
                ->whereNull('causal_code')
                ->orWhereNotIn('causal_code', TransferFees::CAUSALS))
            ->where(fn (Builder $q) => $q
                ->whereRaw('ABS(amount) = ?::numeric', [$importe])
                ->orWhereExists(fn (QueryBuilder $comision) => $comision
                    ->selectRaw('1')
                    ->from('bank_transactions as f')
                    ->whereIn('bank_transactions.causal_code', array_keys(TransferFees::PAIRS))
                    ->whereColumn('f.bank_account_id', 'bank_transactions.bank_account_id')
                    ->whereColumn('f.transaction_date', 'bank_transactions.transaction_date')
                    ->whereColumn('f.operation_id', 'bank_transactions.operation_id')
                    ->where('f.direction', TransactionDirection::Debit->value)
                    ->whereIn('f.causal_code', TransferFees::CAUSALS)
                    ->whereRaw('ABS(bank_transactions.amount) + ABS(f.amount) = ?::numeric', [$importe])))
            ->orderBy('transaction_date')
            ->get();

        $candidatos = $movimientos
            ->map(fn (BankTransaction $m): TransferDebitMatch => $this->par->resolve($m, $orden, $importe, $disbursement?->id))
            ->filter(fn (TransferDebitMatch $par): bool => $par->problem === null)
            ->map(fn (TransferDebitMatch $par): TransferDebitCandidate => $this->describe($installment, $orden, $disbursement, $par))
            ->sortBy(fn (TransferDebitCandidate $c): int => $c->rank())
            ->values()
            ->all();

        /** @var list<TransferDebitCandidate> $candidatos */
        return $candidatos;
    }

    private function describe(
        BeneficiaryInstallment $installment,
        PaymentOrder $orden,
        ?Disbursement $disbursement,
        TransferDebitMatch $par,
    ): TransferDebitCandidate {
        $movimiento = $par->transfer;

        /*
         * La referencia de la que se mide la cercanía es la del informe
         * del organismo; si todavía no informó, la de la Orden. Un débito
         * observado antes del informe (§12.4) igual tiene que poder
         * ordenarse.
         */
        $referencia = $disbursement->report_received_at ?? $orden->order_date;

        $fecha = $movimiento->transaction_date;
        $dias = $fecha === null ? 0 : (int) $referencia->copy()->startOfDay()->diffInDays($fecha, false);

        $porReferencia = $disbursement?->transfer_reference !== null
            && $movimiento->operation_id !== null
            && $this->soloDigitos($disbursement->transfer_reference) === $this->soloDigitos($movimiento->operation_id);

        $beneficiario = $installment->haber->beneficiary->name;
        $porBeneficiario = $this->mencionaAlBeneficiario($movimiento, $beneficiario);

        $signals = ['importe' => $par->fee === null
            ? 'exacto'
            : 'exacto con la comisión de $'.Decimal::format($par->feeAmount() ?? '0')];

        $signals['fecha'] = match (true) {
            $dias === 0 => 'el mismo día',
            $dias === 1 => 'el día siguiente',
            $dias > 1 => "{$dias} días después",
            default => abs($dias).' días antes',
        };

        if ($porReferencia) {
            $signals['operación'] = 'coincide con la referencia informada';
        }

        if ($porBeneficiario) {
            $signals['destinatario'] = 'menciona al beneficiario';
        }

        return new TransferDebitCandidate(
            transaction: $movimiento,
            fee: $par->fee,
            reviewReason: $par->reviewReason,
            dayGap: $dias,
            referenceMatches: $porReferencia,
            beneficiaryMatches: $porBeneficiario,
            signals: $signals,
        );
    }

    /**
     * Si el extracto nombra al beneficiario.
     *
     * Es una señal débil y por eso no filtra: los bancos truncan, abrevian
     * y a veces solo ponen «TRANSFERENCIA». Cuando el apellido aparece, es
     * lo que le da confianza a quien mira; cuando no, no significa nada.
     */
    private function mencionaAlBeneficiario(BankTransaction $movimiento, string $beneficiario): bool
    {
        $texto = mb_strtoupper(
            ($movimiento->counterparty_name ?? '').' '.($movimiento->description ?? '')
        );

        if (trim($texto) === '') {
            return false;
        }

        /* El apellido, que es la primera palabra en «Vera, Elena Beatriz». */
        $apellido = mb_strtoupper(trim(explode(',', $beneficiario)[0]));

        return $apellido !== '' && mb_strlen($apellido) >= 4 && str_contains($texto, $apellido);
    }

    private function soloDigitos(string $valor): string
    {
        return preg_replace('/\D+/', '', $valor) ?? '';
    }
}
