<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\AppealKind;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Enums\FineReasonCode;
use App\Modules\Multas\Enums\PresentationPrecision;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineBalance;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Support\AppealAmount;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Support\Reason;
use App\Modules\Shared\Support\TalonarioSequence;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El pago del 20 % para recurrir la multa, jerárquico o en apelación (M07).
 *
 * **Se cobra lo que dice el recibo externo** (U, 21/10/2026): la liquidación
 * del 20 % la hace otra oficina y llega con su propio documento. El sistema
 * guarda al lado el 20 % que calcula sobre el importe vigente de la multa
 * —sin centavos, con la base congelada— como referencia; si difieren, el
 * cobro sigue y la diferencia queda a la vista.
 *
 * **Una multa admite un solo recurso.** Un segundo se rechaza; un intento
 * descartado no cuenta.
 *
 * Solo en efectivo, que se cobra en el acto, o por depósito directo, que
 * queda informado hasta verificarlo contra el extracto. El depósito puede
 * haberse presentado antes de cargarlo: como en cualquier pago por banco, la
 * presentación —con su respaldo— decide el término (M08).
 *
 * Un depósito informado que se cargó mal se corrige: el anterior se descarta
 * con su motivo y el nuevo apunta a él.
 */
final class RegisterFineAppeal
{
    public function __construct(
        private readonly ReceiveFinePaymentAtCounter $recibir,
        private readonly DiscardFinePayment $descartar,
        private readonly TalonarioSequence $talonarios,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        Fine $multa,
        AppealKind $tipo,
        FinePaymentMedium $medio,
        string $externalReference,
        string $amount,
        string $idempotencyKey,
        ?int $actorId,
        ?string $talonarioNumber = null,
        ?int $signedById = null,
        ?CarbonImmutable $declaredPaidOn = null,
        ?CarbonImmutable $presentedOn = null,
        ?string $presentationEvidence = null,
        ?FinePayment $replaces = null,
        ?string $correctionReason = null,
    ): FinePayment {
        $referencia = trim($externalReference);
        $importe = Decimal::scale($amount);
        $talonario = trim((string) $talonarioNumber) ?: null;
        $hoy = BusinessDate::today();
        $presentado = $presentedOn ?? $hoy;
        $respaldo = trim((string) $presentationEvidence) ?: null;

        $this->assertRegistrable($medio, $referencia, $importe, $declaredPaidOn, $presentado, $hoy, $respaldo);

        if ($replaces !== null) {
            $this->assertCorrectable($medio, $presentedOn, $respaldo);
        }

        try {
            return DB::transaction(function () use (
                $multa, $tipo, $medio, $referencia, $importe, $idempotencyKey, $actorId, $talonario, $signedById,
                $declaredPaidOn, $presentado, $hoy, $respaldo, $replaces, $correctionReason
            ): FinePayment {
                $yaRegistrado = FinePayment::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($yaRegistrado !== null) {
                    return $yaRegistrado;
                }

                $multa = Fine::query()->whereKey($multa->id)->lockForUpdate()->firstOrFail();

                if ($replaces !== null) {
                    $this->reemplazar($multa, $replaces, $correctionReason, $actorId);
                }

                $saldo = FineBalance::query()->whereKey($multa->id)->firstOrFail();

                // Una corrección no pide saldo: completa los datos de un depósito que ya existe.
                if ($replaces === null && Decimal::equals($saldo->debt, '0')) {
                    throw ValidationException::withMessages(['amount' => 'La multa no tiene saldo pendiente: no hay recurso que pagar.']);
                }

                $anterior = FinePayment::query()
                    ->where('fine_id', $multa->id)
                    ->whereNotNull('appeal_kind')
                    ->where('status', '<>', FinePaymentStatus::Discarded)
                    ->first();

                if ($anterior !== null) {
                    throw ValidationException::withMessages([
                        'kind' => 'La multa ya tiene un recurso registrado: una multa admite uno solo. Si aquel se cargó mal, corregilo o descartalo.',
                    ]);
                }

                $base = $saldo->obligation;
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
                    'status' => $medio === FinePaymentMedium::Cash ? FinePaymentStatus::Applied : FinePaymentStatus::Reported,
                    'amount' => $importe,
                    ...$presentacion,
                    'reported_at' => $ahora,
                    'declared_paid_on' => $declaredPaidOn?->toDateString(),
                    'payer_person_id' => $multa->person_id,
                    'payer_name' => $multa->caption,
                    'appeal_kind' => $tipo,
                    'appeal_base_amount' => $base,
                    'appeal_required_amount' => AppealAmount::required($base),
                    'external_receipt_reference' => $referencia,
                    'replaces_fine_payment_id' => $replaces?->id,
                    'idempotency_key' => $idempotencyKey,
                    'created_by' => $actorId,
                ]);

                $comprobante = $pago->status === FinePaymentStatus::Applied
                    ? $this->recibir->handle($pago, $hoy, null, $talonario, $signedById, $actorId)
                    : null;

                $this->auditar->handle('multa.recurso-registrado', $multa, metadata: array_filter([
                    'tipo' => $tipo->label(),
                    'medio' => $medio->label(),
                    'base' => $base,
                    'exigido' => $pago->appeal_required_amount,
                    'externo' => $referencia,
                    'importe' => $importe,
                    'recibo' => $comprobante?->receipt()->value('formatted_number'),
                    'corrige' => $replaces === null ? null : 'Reemplaza el informado N.º '.$replaces->id,
                ]), actorId: $actorId);

                return $pago;
            });
        } catch (UniqueConstraintViolationException $e) {
            $yaRegistrado = FinePayment::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($yaRegistrado !== null) {
                return $yaRegistrado;
            }

            // Dos recursos de la misma multa a la vez: la base frenó al segundo.
            if (str_contains($e->getMessage(), 'fine_payments_one_appeal_per_fine')) {
                throw ValidationException::withMessages(['kind' => 'La multa ya tiene un recurso registrado: una multa admite uno solo.']);
            }

            throw $this->talonarios->translate(
                $e, ReceiptType::Income, (int) CashBox::query()->where('code', CashBox::MULTAS)->value('id'), $talonario,
            ) ?? $e;
        }
    }

    /**
     * Una corrección: el recurso informado que se cargó mal se descarta con su
     * motivo, en la misma transacción que registra el corregido.
     *
     * @throws ValidationException
     */
    private function reemplazar(Fine $multa, FinePayment $anterior, ?string $motivo, ?int $actorId): void
    {
        if ($anterior->fine_id !== $multa->id || $anterior->appeal_kind === null) {
            throw ValidationException::withMessages(['replacesPaymentId' => 'Se corrige un recurso de esta misma multa.']);
        }

        if ($anterior->status !== FinePaymentStatus::Reported) {
            throw ValidationException::withMessages(['replacesPaymentId' => 'Ese pago ya no está informado: lo que ya se aplicó tiene recibo y no se corrige así.']);
        }

        $this->descartar->handle($anterior, new Reason(FineReasonCode::ReplacedByCorrection, $motivo), $actorId);
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
     * Lo que se corrige es un depósito informado: el corregido también es un
     * depósito —el efectivo se cobra en la caja, no se corrige hacia ahí— y
     * conserva la presentación del original.
     *
     * @throws ValidationException
     */
    private function assertCorrectable(FinePaymentMedium $medio, ?CarbonImmutable $presentedOn, ?string $respaldo): void
    {
        if ($medio !== FinePaymentMedium::DirectDeposit) {
            throw ValidationException::withMessages([
                'medium' => 'Se corrige un depósito informado: el corregido también es un depósito. Si se pagó en efectivo, descartá el informado y cobralo en la caja.',
            ]);
        }

        if ($presentedOn !== null || $respaldo !== null) {
            throw ValidationException::withMessages(['presentedOn' => 'Una corrección conserva la presentación del pago que reemplaza: no se carga otra.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertRegistrable(
        FinePaymentMedium $medio,
        string $referencia,
        string $importe,
        ?CarbonImmutable $pagado,
        CarbonImmutable $presentado,
        CarbonImmutable $hoy,
        ?string $respaldo,
    ): void {
        if (! in_array($medio, [FinePaymentMedium::Cash, FinePaymentMedium::DirectDeposit], true)) {
            throw ValidationException::withMessages([
                'medium' => 'El 20 % del recurso se paga en efectivo o por depósito directo.',
            ]);
        }

        if ($referencia === '') {
            throw ValidationException::withMessages(['externalReference' => 'Anotá el número del recibo externo que trae el sancionado.']);
        }

        if (Decimal::isNegative($importe) || Decimal::equals($importe, '0')) {
            throw ValidationException::withMessages(['amount' => 'Anotá el importe que dice el recibo externo.']);
        }

        if ($pagado !== null && ($medio !== FinePaymentMedium::DirectDeposit || $pagado->greaterThan($hoy))) {
            throw ValidationException::withMessages(['declaredPaidOn' => 'La fecha del depósito va solo en un depósito directo, y no puede ser futura.']);
        }

        if ($presentado->greaterThan($hoy)) {
            throw ValidationException::withMessages(['presentedOn' => 'La presentación no puede tener fecha futura.']);
        }

        if ($presentado->lessThan($hoy) && $medio !== FinePaymentMedium::DirectDeposit) {
            throw ValidationException::withMessages([
                'presentedOn' => 'El recurso en efectivo se presenta y se cobra en el mostrador, en el momento.',
            ]);
        }

        if ($presentado->lessThan($hoy) && $respaldo === null) {
            throw ValidationException::withMessages([
                'presentationEvidence' => 'Una presentación de un día anterior lleva su respaldo: el sello de Mesa de Entradas, una nota.',
            ]);
        }
    }
}
