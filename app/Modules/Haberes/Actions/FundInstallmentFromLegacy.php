<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\AllocationKind;
use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Haberes\Models\LegacyDocument;
use App\Modules\Haberes\Support\IncomeEvidence;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Haberes\Support\LegacyCutoff;
use App\Modules\Haberes\Support\LegacyPaper;
use App\Modules\Haberes\Support\LegacyPaperCheck;
use App\Modules\Ledger\Actions\SetAsideLegacyFunds;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSource;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Financia una cuota con plata apartada del sistema anterior.
 *
 * Es el caso del expediente histórico que todavía tiene plata en custodia:
 * el efectivo en el cajón, un cheque en la cartera o un depósito directo en
 * la cuenta, que la apertura declaró como «del sistema anterior» sin saber
 * de quién era. Apartarlo lo dice, y desde ahí la cuota sigue el circuito
 * de siempre: entrega por mostrador, traslado, u Orden y transferencia.
 *
 * ─── Lo que exige ───────────────────────────────────────────────────────
 *
 * - Expediente vigente, haber activo y cuota pendiente **sin financiación**
 *   y sin recibo de ingreso del sistema: no se mezcla dinero del sistema
 *   anterior con dinero actual.
 * - El **importe completo** de la cuota, de una o más fuentes del mismo
 *   medio —dos cheques, por ejemplo—: las cuotas se pagan enteras.
 * - El **recibo de ingreso de papel** por ese importe, anterior a la
 *   apertura. Si la cuota ya tiene uno —se apartó antes y se liberó— se
 *   reutiliza: es el mismo papel.
 *
 * Cada fuente es un asiento `legacy_funds_allocated` con su asignación. La
 * base vuelve a imponer todo: la forma del asiento, que la recepción del
 * sistema anterior no se reasigne, que no haya mezcla y que `LEGACY_FUNDS`
 * no quede negativo.
 */
final class FundInstallmentFromLegacy
{
    public function __construct(
        private readonly SetAsideLegacyFunds $apartar,
        private readonly InstallmentFunding $financiacion,
        private readonly LegacyCutoff $corte,
        private readonly LegacyPaperCheck $papeles,
        private readonly StoreAttachment $adjuntos,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  list<array{amount: numeric-string, chequeReceiptId?: int|null, newCheque?: array{number: string, bank: string|null, issueDate: string|null}|null}>  $sources  De dónde
     *                                                                                                                                                                       sale: una por
     *                                                                                                                                                                       cheque, o una
     *                                                                                                                                                                       sola para el
     *                                                                                                                                                                       efectivo o el
     *                                                                                                                                                                       depósito.
     * @return list<FundingAllocation>
     *
     * @throws ValidationException
     */
    public function handle(
        BeneficiaryInstallment $installment,
        PaymentMedium $medium,
        array $sources,
        ?LegacyPaper $income,
        string $idempotencyKey,
        ?int $bankAccountId = null,
        ?int $actorId = null,
        bool $confirmDuplicates = false,
    ): array {
        /** @var list<Attachment> $guardados */
        $guardados = [];

        try {
            return DB::transaction(function () use (
                $installment, $medium, $sources, $income, $idempotencyKey,
                $bankAccountId, $actorId, $confirmDuplicates, &$guardados,
            ): array {
                $yaApartado = FundingAllocation::query()
                    ->whereHas('allocationEvent', fn ($q) => $q->where('idempotency_key', 'like', $idempotencyKey.':%'))
                    ->get();

                if ($yaApartado->isNotEmpty()) {
                    return array_values($yaApartado->all());
                }

                [$expediente, $haber, $cuota] = $this->lockChain($installment);

                $this->assertFundable($expediente, $haber, $cuota);

                $moneda = Currency::from($haber->currency);
                $caja = (int) CashBox::query()->active()->where('code', CashBox::HABERES)->valueOrFail('id');
                $cheques = $this->lockedCheques($medium, $sources);

                $this->assertSources($medium, $sources, $cuota, $bankAccountId, $cheques);

                $papelVigente = IncomeEvidence::of($cuota)?->paper;
                $papeles = $this->paperToStore($papelVigente, $income, $cuota, $confirmDuplicates);

                $asignaciones = [];

                foreach ($sources as $indice => $fuente) {
                    $cheque = isset($fuente['chequeReceiptId']) ? $cheques[$fuente['chequeReceiptId']] : null;

                    $apartado = $this->apartar->handle(
                        cashBoxId: $caja,
                        currency: $moneda,
                        medium: $medium,
                        amount: $fuente['amount'],
                        haberId: $haber->id,
                        installmentId: $cuota->id,
                        idempotencyKey: $idempotencyKey.':'.$indice,
                        cheque: $cheque,
                        bankAccountId: $bankAccountId,
                        newCheque: $fuente['newCheque'] ?? null,
                        description: sprintf(
                            'Reservado para Expte. %s · %s · cuota %d',
                            $expediente->display_number,
                            $haber->beneficiary->name,
                            $cuota->installment_number,
                        ),
                        actorId: $actorId,
                    );

                    $asignaciones[] = FundingAllocation::query()->create([
                        'allocation_event_id' => $apartado['event']->id,
                        'fund_receipt_id' => $apartado['receipt']->id,
                        'haber_id' => $haber->id,
                        'beneficiary_installment_id' => $cuota->id,
                        'allocation_kind' => AllocationKind::Allocation,
                        'amount' => Decimal::scale($fuente['amount']),
                        'allocated_by' => $actorId,
                        'allocated_at' => now(),
                        'notes' => 'Reservado del sistema anterior',
                    ]);
                }

                foreach ($papeles as $papel) {
                    $documento = LegacyDocument::query()->create([
                        'haber_id' => $haber->id,
                        'beneficiary_installment_id' => $cuota->id,
                        'kind' => $papel->kind,
                        'number' => $papel->number,
                        'issued_on' => $papel->issuedOn,
                        'amount' => $papel->amount,
                        'medium' => $medium,
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

                $this->auditar->handle('cuota.financiada-sistema-anterior', $cuota, after: [
                    'amount' => $cuota->importeEsperado(),
                    'payment_medium' => $medium->value,
                ], metadata: [
                    'fuentes' => count($sources),
                    'recibo' => $papeles === [] ? $papelVigente?->number : $papeles[0]->number,
                ], actorId: $actorId);

                return $asignaciones;
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
     * Expediente, haber y cuota, de afuera hacia adentro, como la asignación.
     *
     * @return array{0: Expediente, 1: Haber, 2: BeneficiaryInstallment}
     */
    private function lockChain(BeneficiaryInstallment $installment): array
    {
        $expedienteId = (int) Haber::query()->whereKey($installment->haber_id)->valueOrFail('expediente_id');

        $expediente = Expediente::query()->lockForUpdate()->findOrFail($expedienteId);
        $haber = Haber::query()->where('expediente_id', $expediente->id)->lockForUpdate()->findOrFail($installment->haber_id);
        $cuota = BeneficiaryInstallment::query()->where('haber_id', $haber->id)->lockForUpdate()->findOrFail($installment->id);

        return [$expediente, $haber, $cuota];
    }

    /** @throws ValidationException */
    private function assertFundable(Expediente $expediente, Haber $haber, BeneficiaryInstallment $cuota): void
    {
        $motivo = match (true) {
            $expediente->status === ExpedienteStatus::Cancelled => 'El expediente está anulado.',
            $haber->workflow_status !== HaberWorkflowStatus::Active => 'El haber no está activo.',
            $cuota->workflow_status !== InstallmentWorkflowStatus::Active => 'Solo se reserva plata para una cuota pendiente.',
            ! Decimal::equals($this->financiacion->allocated($cuota), '0') => 'La cuota ya tiene dinero asignado: no se mezcla con el del sistema anterior.',
            IncomeEvidence::of($cuota)?->isPaper() === false => 'La cuota ya tiene un recibo de ingreso del sistema.',
            default => null,
        };

        if ($motivo !== null) {
            throw ValidationException::withMessages(['installment' => $motivo]);
        }
    }

    /**
     * Los cheques de la cartera, bloqueados en orden de id.
     *
     * El orden fijo es lo que evita el abrazo mortal entre dos apartados
     * que usan los mismos dos cheques.
     *
     * @param  list<array{amount: numeric-string, chequeReceiptId?: int|null, newCheque?: array{number: string, bank: string|null, issueDate: string|null}|null}>  $sources
     * @return array<int, FundReceipt>
     */
    private function lockedCheques(PaymentMedium $medium, array $sources): array
    {
        if ($medium !== PaymentMedium::Cheque) {
            return [];
        }

        $ids = array_values(array_unique(array_map(
            fn (array $fuente): int => (int) $fuente['chequeReceiptId'],
            array_filter($sources, fn (array $fuente): bool => isset($fuente['chequeReceiptId'])),
        )));
        sort($ids);

        return FundReceipt::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * @param  list<array{amount: numeric-string, chequeReceiptId?: int|null, newCheque?: array{number: string, bank: string|null, issueDate: string|null}|null}>  $sources
     * @param  array<int, FundReceipt>  $cheques
     *
     * @throws ValidationException
     */
    private function assertSources(
        PaymentMedium $medium,
        array $sources,
        BeneficiaryInstallment $cuota,
        ?int $bankAccountId,
        array $cheques,
    ): void {
        if ($sources === []) {
            throw ValidationException::withMessages(['sources' => 'Falta de dónde sale la plata.']);
        }

        if ($medium !== PaymentMedium::Cheque && count($sources) > 1) {
            throw ValidationException::withMessages(['sources' => 'El efectivo y el depósito directo salen de una sola fuente.']);
        }

        if ($medium === PaymentMedium::Bank && $bankAccountId === null) {
            throw ValidationException::withMessages(['bankAccountId' => 'Un depósito directo tiene que decir en qué cuenta está.']);
        }

        $total = '0.00';

        foreach ($sources as $fuente) {
            $total = Decimal::add($total, $fuente['amount']);

            /*
             * Un cheque identificado recién ahora no tiene saldo libre que
             * mirar: nace por este importe. Lo controla Ledger, contra lo
             * que la apertura declaró sin detallar.
             */
            if ($medium !== PaymentMedium::Cheque || isset($fuente['newCheque'])) {
                continue;
            }

            $id = (int) ($fuente['chequeReceiptId'] ?? 0);
            $cheque = $cheques[$id] ?? null;

            if ($cheque === null) {
                throw ValidationException::withMessages(['sources' => 'Uno de los cheques no está en la cartera.']);
            }

            /*
             * Entero y libre. El cheque es un papel que se entrega o se
             * deposita completo: repartido entre cuotas, entregar una
             * marcaría entregado el papel entero y el libro conservaría el
             * resto en custodia sin ningún cheque detrás. La base lo
             * impone igual (`legacy_cheque_stays_whole`).
             */
            $libre = $this->financiacion->unallocated($cheque);

            if (! Decimal::equals($libre, $cheque->amount) || ! Decimal::equals($fuente['amount'], $cheque->amount)) {
                throw ValidationException::withMessages([
                    'sources' => sprintf(
                        'El cheque %s es de %s y se reserva entero: no se reparte entre cuotas.',
                        $cheque->cheque_number,
                        Decimal::format($cheque->amount),
                    ),
                ]);
            }
        }

        if (! Decimal::equals($total, $cuota->importeEsperado())) {
            throw ValidationException::withMessages([
                'sources' => sprintf(
                    'Se reservan %s y la cuota es de %s: las cuotas se pagan enteras.',
                    Decimal::format($total),
                    Decimal::format($cuota->importeEsperado()),
                ),
            ]);
        }
    }

    /**
     * El recibo de ingreso de papel que hay que guardar, si hay que guardar uno.
     *
     * @return list<LegacyPaper>
     *
     * @throws ValidationException
     */
    private function paperToStore(?LegacyDocument $vigente, ?LegacyPaper $income, BeneficiaryInstallment $cuota, bool $confirmDuplicates): array
    {
        if ($vigente !== null) {
            if ($income !== null) {
                throw ValidationException::withMessages([
                    'incomeNumber' => sprintf(
                        'La cuota ya tiene cargado el recibo de ingreso n.º %s del %s: se usa ese.',
                        $vigente->number,
                        $vigente->issued_on->format('d/m/Y'),
                    ),
                ]);
            }

            if (! Decimal::equals($vigente->amount, $cuota->importeEsperado())) {
                throw ValidationException::withMessages([
                    'incomeNumber' => sprintf(
                        'El recibo de ingreso cargado es de %s y la cuota hoy es de %s: no respalda el importe completo.',
                        Decimal::format($vigente->amount),
                        Decimal::format($cuota->importeEsperado()),
                    ),
                ]);
            }

            return [];
        }

        if ($income === null) {
            throw ValidationException::withMessages([
                'incomeNumber' => 'Falta el recibo de ingreso de papel: es lo que respalda esta plata.',
            ]);
        }

        if (! Decimal::equals($income->amount, $cuota->importeEsperado())) {
            throw ValidationException::withMessages([
                'incomeAmount' => sprintf(
                    'El recibo de ingreso es de %s y la cuota es de %s: las cuotas se pagan enteras.',
                    Decimal::format($income->amount),
                    Decimal::format($cuota->importeEsperado()),
                ),
            ]);
        }

        $apertura = $this->corte->date();

        if ($apertura === null) {
            throw ValidationException::withMessages([
                'installment' => 'La caja de Haberes todavía no tiene apertura: sin ella no hay plata del sistema anterior que reservar.',
            ]);
        }

        $this->papeles->assertBefore([$income], $apertura);
        $this->papeles->assertNotLoaded([$income], $confirmDuplicates);

        return [$income];
    }
}
