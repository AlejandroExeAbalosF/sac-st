<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\LegacyDocumentKind;
use App\Modules\Haberes\Enums\LegacySettlementMode;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Haberes\Models\LegacyDocument;
use App\Modules\Haberes\Models\LegacySettlement;
use App\Modules\Haberes\Support\InstallmentMovements;
use App\Modules\Haberes\Support\LegacyCutoff;
use App\Modules\Haberes\Support\LegacyDisbursementReceipts;
use App\Modules\Haberes\Support\LegacyPaper;
use App\Modules\Haberes\Support\LegacyPaperCheck;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSource;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Da por pagada una cuota que se pagó fuera del circuito.
 *
 * Es lo que permite cargar un expediente histórico entero: sus cuotas ya
 * pagadas quedan registradas con los papeles que lo prueban, y el
 * sistema no las vuelve a ofrecer para cobrar ni para pagar.
 *
 * **No mueve dinero.** En `before_opening` la plata entró y salió antes de
 * la apertura; en `legacy_disbursement` el egreso ya está en el libro,
 * como un pago de «Pagos anteriores», y esto solo lo ata a su cuota.
 *
 * ─── Lo que exige ───────────────────────────────────────────────────────
 *
 * - Expediente vigente, haber activo y cuota pendiente **sin movimientos**:
 *   una cuota que ya se movió adentro no se declara pagada afuera.
 * - El recibo de ingreso de papel, por el importe de la cuota —se pagan
 *   enteras—. Si la cuota ya tiene uno vigente, se reutiliza.
 * - Fechas anteriores a la apertura de la caja.
 * - En `legacy_disbursement`, un recibo del mismo beneficiario, en la
 *   moneda del haber y con disponible suficiente.
 *
 * Todo lo que acá se explica, la base lo vuelve a imponer.
 */
final class RecordLegacySettlement
{
    public function __construct(
        private readonly InstallmentMovements $movimientos,
        private readonly LegacyCutoff $corte,
        private readonly LegacyDisbursementReceipts $recibosAnteriores,
        private readonly LegacyPaperCheck $papeles,
        private readonly StoreAttachment $adjuntos,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  bool  $confirmDuplicates  El operador vio el aviso de un
     *                                   número repetido con otra fecha —o
     *                                   de una foto ya cargada— y confirmó
     *                                   que es otro papel.
     *
     * @throws ValidationException
     */
    public function handle(
        BeneficiaryInstallment $installment,
        LegacySettlementMode $mode,
        ?LegacyPaper $income,
        ?LegacyPaper $order = null,
        ?LegacyPaper $expense = null,
        ?CarbonInterface $paidOn = null,
        ?PaymentMedium $paymentMedium = null,
        ?int $receiptId = null,
        ?string $notes = null,
        ?int $actorId = null,
        bool $confirmDuplicates = false,
    ): LegacySettlement {
        /** @var list<Attachment> $guardados */
        $guardados = [];

        try {
            return DB::transaction(function () use (
                $installment, $mode, $income, $order, $expense, $paidOn, $paymentMedium,
                $receiptId, $notes, $actorId, $confirmDuplicates, &$guardados,
            ): LegacySettlement {
                [$haber, $cuota] = $this->lockChain($installment);

                $this->assertSettleable($haber, $cuota);

                $ingresoVigente = $this->movimientos->currentLegacyDocument($cuota->id, LegacyDocumentKind::IncomeReceipt);
                $papeles = $this->papersToStore($ingresoVigente, $income, $order, $expense, $mode);

                $apertura = $this->corte->date();

                if ($apertura === null) {
                    throw ValidationException::withMessages([
                        'installment' => 'La caja de Haberes todavía no tiene apertura: sin ella no se puede saber si un papel es anterior al sistema.',
                    ]);
                }

                $this->papeles->assertBefore($papeles, $apertura);
                $this->assertIncomeMatches($ingresoVigente, $income, $cuota);
                $this->papeles->assertNotLoaded($papeles, $confirmDuplicates);

                $datosDelPago = $this->paymentFor($mode, $haber, $cuota, $paidOn, $paymentMedium, $receiptId, $apertura, $ingresoVigente, $income);

                $registro = LegacySettlement::query()->create([
                    'haber_id' => $haber->id,
                    'beneficiary_installment_id' => $cuota->id,
                    'mode' => $mode,
                    'amount' => $cuota->importeEsperado(),
                    ...$datosDelPago,
                    'notes' => $notes,
                    'recorded_by' => $actorId,
                    'recorded_at' => now(),
                ]);

                foreach ($papeles as $papel) {
                    $documento = LegacyDocument::query()->create([
                        'haber_id' => $haber->id,
                        'beneficiary_installment_id' => $cuota->id,
                        'legacy_settlement_id' => $registro->id,
                        'kind' => $papel->kind,
                        'number' => $papel->number,
                        'issued_on' => $papel->issuedOn,
                        'amount' => $papel->amount,
                        'recorded_by' => $actorId,
                        'recorded_at' => now(),
                    ]);

                    if ($papel->photo !== null) {
                        $guardados[] = $this->adjuntos->handle(
                            file: $papel->photo,
                            subject: AttachmentSubject::LegacyDocument,
                            subjectId: $documento->id,
                            documentType: 'legacy_'.$papel->kind->value,
                            userId: $actorId,
                            title: $papel->kind->label().' n.º '.$papel->number,
                            source: AttachmentSource::Scanned,
                        );
                    }
                }

                $this->papeles->assertPhotosNotLoaded($guardados, $papeles, $confirmDuplicates);

                $cuota->forceFill(['workflow_status' => InstallmentWorkflowStatus::LegacySettled])->save();

                $this->auditar->handle('cuota.pagada-fuera-del-circuito', $cuota, after: [
                    'mode' => $mode->value,
                    'amount' => $registro->amount,
                    'paid_on' => $registro->paid_on?->toDateString(),
                    'payment_medium' => $registro->payment_medium?->value,
                    'legacy_disbursement_receipt_id' => $registro->legacy_disbursement_receipt_id,
                ], metadata: [
                    'papeles' => implode(', ', array_map(
                        fn (LegacyPaper $papel): string => $papel->kind->label().' '.$papel->number,
                        $papeles,
                    )),
                ], actorId: $actorId);

                return $registro;
            });
        } catch (Throwable $exception) {
            foreach ($guardados as $adjunto) {
                $this->adjuntos->deleteFile([
                    'disk' => $adjunto->storage_disk,
                    'key' => $adjunto->object_key,
                ]);
            }

            throw $exception;
        }
    }

    /**
     * Expediente, haber y cuota, de afuera hacia adentro: el mismo orden
     * que toman la asignación y las bajas, para no abrir un abrazo mortal.
     *
     * @return array{0: Haber, 1: BeneficiaryInstallment}
     */
    private function lockChain(BeneficiaryInstallment $installment): array
    {
        $expedienteId = (int) Haber::query()->whereKey($installment->haber_id)->valueOrFail('expediente_id');

        $expediente = Expediente::query()->lockForUpdate()->findOrFail($expedienteId);
        $haber = Haber::query()->where('expediente_id', $expediente->id)->lockForUpdate()->findOrFail($installment->haber_id);
        $cuota = BeneficiaryInstallment::query()->where('haber_id', $haber->id)->lockForUpdate()->findOrFail($installment->id);

        if ($expediente->status === ExpedienteStatus::Cancelled) {
            throw ValidationException::withMessages([
                'installment' => 'El expediente está anulado.',
            ]);
        }

        $haber->setRelation('expediente', $expediente);

        return [$haber, $cuota];
    }

    /** @throws ValidationException */
    private function assertSettleable(Haber $haber, BeneficiaryInstallment $cuota): void
    {
        if ($haber->workflow_status !== HaberWorkflowStatus::Active) {
            throw ValidationException::withMessages([
                'installment' => 'El haber no está activo: no se le pueden registrar pagos.',
            ]);
        }

        if ($cuota->workflow_status !== InstallmentWorkflowStatus::Active) {
            throw ValidationException::withMessages([
                'installment' => 'Solo una cuota pendiente se puede dar por pagada fuera del circuito.',
            ]);
        }

        $motivo = $this->movimientos->obstacleFor($cuota->id);

        if ($motivo !== null) {
            throw ValidationException::withMessages([
                'installment' => $motivo.' No se puede dar por pagada fuera del circuito.',
            ]);
        }
    }

    /**
     * Qué papeles nuevos se guardan.
     *
     * El recibo de ingreso se pide solo si la cuota no tiene uno vigente:
     * si se cargó antes, al apartar fondos, es el mismo papel y cargarlo de
     * nuevo chocaría con la unicidad. Un pago desde «Pagos anteriores» no
     * lleva recibo de egreso de papel: su egreso es el recibo del sistema.
     *
     * @return list<LegacyPaper>
     *
     * @throws ValidationException
     */
    private function papersToStore(
        ?LegacyDocument $ingresoVigente,
        ?LegacyPaper $income,
        ?LegacyPaper $order,
        ?LegacyPaper $expense,
        LegacySettlementMode $mode,
    ): array {
        if ($ingresoVigente === null && $income === null) {
            throw ValidationException::withMessages([
                'incomeNumber' => 'Falta el recibo de ingreso: es lo que prueba que el empleador pagó.',
            ]);
        }

        if ($ingresoVigente !== null && $income !== null) {
            throw ValidationException::withMessages([
                'incomeNumber' => sprintf(
                    'La cuota ya tiene cargado el recibo de ingreso n.º %s del %s.',
                    $ingresoVigente->number,
                    $ingresoVigente->issued_on->format('d/m/Y'),
                ),
            ]);
        }

        if ($mode === LegacySettlementMode::LegacyDisbursement && $expense !== null) {
            throw ValidationException::withMessages([
                'expenseNumber' => 'Un pago hecho desde Pagos anteriores ya tiene su recibo de egreso del sistema.',
            ]);
        }

        return array_values(array_filter([$income, $order, $expense]));
    }

    /**
     * Las cuotas se pagan enteras: el recibo de ingreso es por la cuota.
     *
     * @throws ValidationException
     */
    private function assertIncomeMatches(
        ?LegacyDocument $ingresoVigente,
        ?LegacyPaper $income,
        BeneficiaryInstallment $cuota,
    ): void {
        $importe = $income->amount ?? $ingresoVigente?->amount;

        if ($importe !== null && ! Decimal::equals($importe, $cuota->importeEsperado())) {
            throw ValidationException::withMessages([
                'incomeAmount' => sprintf(
                    'El recibo de ingreso es de %s y la cuota es de %s: las cuotas se pagan enteras.',
                    Decimal::format($importe),
                    Decimal::format($cuota->importeEsperado()),
                ),
            ]);
        }
    }

    /**
     * La fecha y el medio del pago, o el recibo que los trae.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function paymentFor(
        LegacySettlementMode $mode,
        Haber $haber,
        BeneficiaryInstallment $cuota,
        ?CarbonInterface $paidOn,
        ?PaymentMedium $paymentMedium,
        ?int $receiptId,
        CarbonInterface $apertura,
        ?LegacyDocument $ingresoVigente,
        ?LegacyPaper $income,
    ): array {
        if ($mode === LegacySettlementMode::BeforeOpening) {
            if ($paidOn === null || $paymentMedium === null) {
                throw ValidationException::withMessages(array_filter([
                    'paidOn' => $paidOn === null ? 'Falta la fecha en que se le pagó al beneficiario.' : null,
                    'paymentMedium' => $paymentMedium === null ? 'Falta cómo se le pagó.' : null,
                ]));
            }

            if ($paidOn->greaterThanOrEqualTo($apertura)) {
                throw ValidationException::withMessages([
                    'paidOn' => sprintf('El pago tiene que ser anterior a la apertura de la caja (%s).', $apertura->format('d/m/Y')),
                ]);
            }

            $cobro = $income->issuedOn ?? $ingresoVigente?->issued_on;

            if ($cobro !== null && $paidOn->lessThan($cobro)) {
                throw ValidationException::withMessages([
                    'paidOn' => 'Se le pagó al beneficiario antes de que el empleador depositara: revisá las fechas.',
                ]);
            }

            return [
                'paid_on' => $paidOn,
                'payment_medium' => $paymentMedium,
            ];
        }

        if ($receiptId === null) {
            throw ValidationException::withMessages([
                'receiptId' => 'Elegí el recibo de Pagos anteriores con que se pagó la cuota.',
            ]);
        }

        $candidato = $this->recibosAnteriores->lockedCandidate($haber, $receiptId);

        if ($candidato === null) {
            throw ValidationException::withMessages([
                'receiptId' => 'Ese recibo no es un pago de Pagos anteriores vigente de este beneficiario en la moneda del haber.',
            ]);
        }

        if (Decimal::isNegative(Decimal::sub($candidato['available'], $cuota->importeEsperado()))) {
            throw ValidationException::withMessages([
                'receiptId' => sprintf(
                    'Al recibo %s le quedan %s sin vincular y la cuota es de %s.',
                    $candidato['receipt']->formatted_number,
                    Decimal::format($candidato['available']),
                    Decimal::format($cuota->importeEsperado()),
                ),
            ]);
        }

        return ['legacy_disbursement_receipt_id' => $candidato['receipt']->id];
    }
}
