<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\LegacyDocumentKind;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Haberes\Models\LegacyDocument;
use App\Modules\Haberes\Models\LegacySettlement;
use App\Modules\Haberes\Support\InstallmentMovements;
use App\Modules\Haberes\Support\LegacyCutoff;
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
 * **No mueve dinero.** La plata entró y salió antes de la apertura: nunca
 * estuvo en el saldo con que la caja abrió los libros.
 *
 * ─── Lo que exige ───────────────────────────────────────────────────────
 *
 * - Expediente vigente, haber activo y cuota pendiente **sin movimientos**:
 *   una cuota que ya se movió adentro no se declara pagada afuera.
 * - El recibo de ingreso de papel, por el importe de la cuota —se pagan
 *   enteras—. Si la cuota ya tiene uno vigente, se reutiliza.
 * - La fecha y el medio del pago, y fechas anteriores a la apertura de la
 *   caja.
 *
 * Todo lo que acá se explica, la base lo vuelve a imponer.
 */
final class RecordLegacySettlement
{
    public function __construct(
        private readonly InstallmentMovements $movimientos,
        private readonly LegacyCutoff $corte,
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
        ?LegacyPaper $income,
        CarbonInterface $paidOn,
        PaymentMedium $paymentMedium,
        ?LegacyPaper $order = null,
        ?LegacyPaper $expense = null,
        ?string $notes = null,
        ?int $actorId = null,
        bool $confirmDuplicates = false,
    ): LegacySettlement {
        /** @var list<Attachment> $guardados */
        $guardados = [];

        try {
            return DB::transaction(function () use (
                $installment, $income, $order, $expense, $paidOn, $paymentMedium,
                $notes, $actorId, $confirmDuplicates, &$guardados,
            ): LegacySettlement {
                [$haber, $cuota] = $this->lockChain($installment);

                $this->assertSettleable($haber, $cuota);

                $ingresoVigente = $this->movimientos->currentLegacyDocument($cuota->id, LegacyDocumentKind::IncomeReceipt);
                $papeles = $this->papersToStore($ingresoVigente, $income, $order, $expense);

                $apertura = $this->corte->date($cuota->currency());

                if ($apertura === null) {
                    throw ValidationException::withMessages([
                        'installment' => sprintf(
                            'La caja de Haberes todavía no tiene apertura en %s: sin ella no se puede saber si un papel es anterior al sistema.',
                            mb_strtolower($cuota->currency()->label()),
                        ),
                    ]);
                }

                $this->papeles->assertBefore($papeles, $apertura);
                $this->assertIncomeMatches($ingresoVigente, $income, $cuota);
                $this->papeles->assertNotLoaded($papeles, $confirmDuplicates);

                $this->assertPaidOn($paidOn, $apertura, $ingresoVigente, $income);

                $registro = LegacySettlement::query()->create([
                    'haber_id' => $haber->id,
                    'beneficiary_installment_id' => $cuota->id,
                    'amount' => $cuota->importeEsperado(),
                    'paid_on' => $paidOn,
                    'payment_medium' => $paymentMedium,
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
                    'amount' => $registro->amount,
                    'paid_on' => $registro->paid_on->toDateString(),
                    'payment_medium' => $registro->payment_medium->value,
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
     * si se cargó antes, al reservar fondos, es el mismo papel y cargarlo de
     * nuevo chocaría con la unicidad.
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
     * El pago es anterior a la apertura, y posterior a que el empleador pagó.
     *
     * @throws ValidationException
     */
    private function assertPaidOn(
        CarbonInterface $paidOn,
        CarbonInterface $apertura,
        ?LegacyDocument $ingresoVigente,
        ?LegacyPaper $income,
    ): void {
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
    }
}
