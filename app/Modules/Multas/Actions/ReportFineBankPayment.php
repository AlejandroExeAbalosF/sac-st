<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Enums\FineReasonCode;
use App\Modules\Multas\Enums\PresentationPrecision;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineBalance;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Un pago por el banco que alguien presentó: transferencia, depósito
 * directo o cheque electrónico (M02).
 *
 * No toca la deuda ni lleva recibo: queda **informado** hasta que se
 * verifique contra un crédito del extracto. Lo que sí queda es cuándo se
 * presentó el comprobante, que es lo que decide el término (M08): hoy, en el
 * momento de cargarlo, o un día anterior con su respaldo —el sello de Mesa
 * de Entradas, una nota—.
 *
 * Un informado que se cargó mal se corrige antes de verificarlo: el anterior
 * se descarta con su motivo y el nuevo apunta a él.
 */
final class ReportFineBankPayment
{
    public function __construct(
        private readonly DiscardFinePayment $descartar,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        Fine $multa,
        FinePaymentMedium $medio,
        string $amount,
        string $idempotencyKey,
        ?int $actorId,
        ?CarbonImmutable $presentedOn = null,
        ?string $presentationEvidence = null,
        ?CarbonImmutable $declaredPaidOn = null,
        ?string $judicialReference = null,
        ?FinePayment $replaces = null,
        ?string $correctionReason = null,
    ): FinePayment {
        $importe = Decimal::scale($amount);
        $hoy = BusinessDate::today();
        $presentado = $presentedOn ?? $hoy;
        $respaldo = trim((string) $presentationEvidence) ?: null;
        $judicial = trim((string) $judicialReference) ?: null;

        $this->assertReportable($medio, $importe, $presentado, $hoy, $respaldo, $declaredPaidOn, $judicial);

        if ($replaces !== null && ($presentedOn !== null || $respaldo !== null)) {
            throw ValidationException::withMessages(['presentedOn' => 'Una corrección conserva la presentación del pago que reemplaza: no se carga otra.']);
        }

        return DB::transaction(function () use (
            $multa, $medio, $importe, $idempotencyKey, $actorId, $presentado, $hoy, $respaldo, $declaredPaidOn, $judicial,
            $replaces, $correctionReason
        ): FinePayment {
            $yaInformado = FinePayment::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($yaInformado !== null) {
                return $yaInformado;
            }

            $multa = Fine::query()->whereKey($multa->id)->lockForUpdate()->firstOrFail();

            if ($replaces !== null) {
                if ($replaces->fine_id !== $multa->id || $replaces->appeal_kind !== null) {
                    throw ValidationException::withMessages(['replacesPaymentId' => 'Se corrige un pago informado de esta misma multa.']);
                }

                if ($replaces->status !== FinePaymentStatus::Reported) {
                    throw ValidationException::withMessages(['replacesPaymentId' => 'Ese pago ya no está informado: lo que ya se aplicó tiene recibo y no se corrige así.']);
                }

                $this->descartar->handle($replaces, new Reason(FineReasonCode::ReplacedByCorrection, $correctionReason), $actorId);
            }

            // Una corrección no pide saldo: no mueve plata, completa los datos de un
            // depósito que ya existe. Si sobra, el excedente se reconoce al verificarlo.
            if ($replaces === null && Decimal::equals((string) FineBalance::query()->whereKey($multa->id)->value('debt'), '0')) {
                throw ValidationException::withMessages(['amount' => 'La multa no tiene saldo pendiente: no hay pago que informar.']);
            }

            $ahora = CarbonImmutable::now();
            $delDia = $presentado->isSameDay($hoy);

            $presentacion = $replaces !== null
                ? $this->presentacionHeredada($replaces)
                : [
                    'presented_at' => $delDia ? $ahora : BusinessDate::startOfDay($presentado->toDateString()),
                    'presented_precision' => $delDia ? PresentationPrecision::Instant : PresentationPrecision::Day,
                    'presentation_evidence' => $delDia ? null : $respaldo,
                ];

            $pago = FinePayment::query()->create([
                'fine_id' => $multa->id,
                'medium' => $medio,
                'judicial_origin' => $judicial !== null,
                'judicial_reference' => $judicial,
                'status' => FinePaymentStatus::Reported,
                'amount' => $importe,
                ...$presentacion,
                'reported_at' => $ahora,
                'declared_paid_on' => $declaredPaidOn?->toDateString(),
                'payer_person_id' => $multa->person_id,
                'payer_name' => $multa->caption,
                'replaces_fine_payment_id' => $replaces?->id,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actorId,
            ]);

            $this->auditar->handle('multa.pago-informado', $multa, metadata: array_filter([
                'medio' => $medio->label(),
                'amount' => $importe,
                'judicial' => $judicial,
                'corrige' => $replaces === null ? null : 'Reemplaza el informado N.º '.$replaces->id,
            ]), actorId: $actorId);

            return $pago;
        });
    }

    /**
     * La presentación del corregido es la del informado que reemplaza: se
     * corrigen los datos de la carga, no el día en que se presentó el
     * comprobante, que es lo que decide el término.
     *
     * @return array{presented_at: mixed, presented_precision: mixed, presentation_evidence: string}
     */
    private function presentacionHeredada(FinePayment $anterior): array
    {
        return [
            'presented_at' => $anterior->presented_at,
            'presented_precision' => $anterior->presented_precision,
            'presentation_evidence' => $anterior->presentation_evidence
                ?? 'Presentado con el pago informado que este corrige.',
        ];
    }

    /**
     * @throws ValidationException
     */
    private function assertReportable(
        FinePaymentMedium $medio,
        string $importe,
        CarbonImmutable $presentado,
        CarbonImmutable $hoy,
        ?string $respaldo,
        ?CarbonImmutable $pagado,
        ?string $judicial,
    ): void {
        if ($medio->isCounter()) {
            throw ValidationException::withMessages([
                'medium' => 'El efectivo y el cheque se cobran en la caja: no se informan.',
            ]);
        }

        if (Decimal::isNegative($importe) || Decimal::equals($importe, '0')) {
            throw ValidationException::withMessages(['amount' => 'El importe tiene que ser mayor que cero.']);
        }

        if ($presentado->greaterThan($hoy)) {
            throw ValidationException::withMessages(['presentedOn' => 'La presentación no puede tener fecha futura.']);
        }

        if ($presentado->lessThan($hoy) && $respaldo === null) {
            throw ValidationException::withMessages([
                'presentationEvidence' => 'Una presentación de un día anterior lleva su respaldo: el sello de Mesa de Entradas, una nota.',
            ]);
        }

        if ($pagado !== null && $pagado->greaterThan($hoy)) {
            throw ValidationException::withMessages(['declaredPaidOn' => 'El pago no puede tener fecha futura.']);
        }

        if ($judicial !== null && $medio !== FinePaymentMedium::BankTransfer) {
            throw ValidationException::withMessages([
                'judicialReference' => 'Una orden de pago judicial llega como transferencia o como cheque en la caja.',
            ]);
        }
    }
}
