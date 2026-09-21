<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Support\AllocatableAmount;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Haberes\Support\TransferDebitCandidate;
use App\Support\Money\Decimal;

/**
 * Busca en el extracto el débito con que el organismo pagó.
 *
 * Es el tercer buscador del sistema y el más fácil de los tres: acá se
 * sabe **de qué cuenta** salió, **cuánto** exactamente y **hacia quién**.
 * Con los tickets del expediente hay que averiguar de quién viene un
 * crédito anónimo; acá el candidato o coincide o es otro pago.
 *
 * **No vincula: propone.** Confirmar es de una persona, porque un débito
 * mal atribuido daría por pagada una cuota con la transferencia de otra —y
 * dejaría a un trabajador esperando un dinero que el sistema cree
 * entregado—.
 */
final class FindTransferDebitCandidates
{
    /**
     * Hacia atrás no se busca **nada**: el organismo no puede haber
     * transferido antes de que se le pidiera. La única tolerancia es de un
     * día, y solo por si la Orden se fechó con un día de diferencia
     * respecto de cuando salió.
     */
    private const DAYS_BEFORE_ORDER = 1;

    public function __construct(private readonly AllocatableAmount $disponible) {}

    /**
     * Los débitos que podrían ser el pago de esta cuota.
     *
     * Arranca de la cuota y no del egreso porque el egreso puede todavía
     * no existir: el §12.4 contempla que el débito se reconozca antes de
     * que el organismo informe, y ése es justamente el momento en que
     * alguien pide esta lista.
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

        $desde = $orden->order_date->copy()->subDays(self::DAYS_BEFORE_ORDER);

        /*
         * Importe exacto, sin tolerancia: las comisiones son movimientos
         * aparte en el extracto real, así que el débito de una
         * transferencia sale completo. Si difiere, es otro pago.
         */
        $movimientos = BankTransaction::query()
            ->where('bank_account_id', $orden->organism_bank_account_id)
            ->where('direction', TransactionDirection::Debit->value)
            ->where('reconciliation_status', '!=', ReconciliationStatus::Ignored->value)
            ->whereDate('transaction_date', '>=', $desde->toDateString())
            ->whereRaw('ABS(amount) = ?::numeric', [$importe])
            ->orderBy('transaction_date')
            ->get();

        $candidatos = $movimientos
            /*
             * Lo que ya está imputado no se ofrece: un débito consumido
             * por otro egreso no puede ser además éste, o sería la misma
             * plata saliendo dos veces.
             */
            ->filter(fn (BankTransaction $m): bool => ! Decimal::equals($this->disponible->for($m), '0'))
            ->map(fn (BankTransaction $m): TransferDebitCandidate => $this->describe($installment, $orden, $disbursement, $m))
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
        BankTransaction $movimiento,
    ): TransferDebitCandidate {
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

        $signals = ['importe' => 'exacto'];

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
