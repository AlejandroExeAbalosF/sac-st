<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\AppealKind;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Enums\PresentationPrecision;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineBalance;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Support\AppealAmount;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\CashBox;
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
 * La base es el importe vigente de la multa en este momento, y queda
 * congelada: una resolución posterior no invalida el cálculo. Solo en
 * efectivo, que se cobra en el acto, o por depósito directo, que queda
 * informado hasta verificarlo contra el extracto.
 *
 * El sancionado trae un recibo externo que dice el importe. Si no coincide
 * con el 20 % calculado, el intento queda **en revisión**: no se cobra ni se
 * emite recibo hasta que el administrador lo resuelva. No hay excepción
 * discrecional: lo que se aplica es siempre el 20 % exigido.
 *
 * También queda en revisión un recurso sobre una multa que ya tiene otro sin
 * descartar (P05, provisorio hasta que el área defina los recursos repetidos).
 *
 * El depósito directo puede haberse presentado antes de cargarlo: como en
 * cualquier pago por banco, la presentación —con su respaldo— decide el
 * término (M08). El efectivo se presenta y se cobra en el mostrador, ahora.
 */
final class RegisterFineAppeal
{
    public function __construct(
        private readonly ReceiveFinePaymentAtCounter $recibir,
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
        string $externalAmount,
        string $idempotencyKey,
        ?int $actorId,
        ?string $talonarioNumber = null,
        ?int $signedById = null,
        ?CarbonImmutable $declaredPaidOn = null,
        ?CarbonImmutable $presentedOn = null,
        ?string $presentationEvidence = null,
    ): FinePayment {
        $referencia = trim($externalReference);
        $externo = Decimal::scale($externalAmount);
        $talonario = trim((string) $talonarioNumber) ?: null;
        $hoy = BusinessDate::today();
        $presentado = $presentedOn ?? $hoy;
        $respaldo = trim((string) $presentationEvidence) ?: null;

        $this->assertRegistrable($medio, $referencia, $externo, $declaredPaidOn, $presentado, $hoy, $respaldo);

        try {
            return $this->register(
                $multa, $tipo, $medio, $referencia, $externo, $idempotencyKey, $actorId, $talonario, $signedById, $declaredPaidOn,
                $presentado->isSameDay($hoy) ? null : $presentado, $respaldo,
            );
        } catch (UniqueConstraintViolationException $e) {
            $yaRegistrado = FinePayment::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($yaRegistrado !== null) {
                return $yaRegistrado;
            }

            throw $this->talonarios->translate(
                $e, ReceiptType::Income, (int) CashBox::query()->where('code', CashBox::MULTAS)->value('id'), $talonario,
            ) ?? $e;
        }
    }

    /**
     * @throws ValidationException
     * @throws UniqueConstraintViolationException dos pedidos simultáneos con la misma clave o el mismo talonario
     */
    private function register(
        Fine $multa,
        AppealKind $tipo,
        FinePaymentMedium $medio,
        string $referencia,
        string $externo,
        string $idempotencyKey,
        ?int $actorId,
        ?string $talonario,
        ?int $signedById,
        ?CarbonImmutable $declaredPaidOn,
        ?CarbonImmutable $presentadoAntes,
        ?string $respaldo,
    ): FinePayment {
        return DB::transaction(function () use (
            $multa, $tipo, $medio, $referencia, $externo, $idempotencyKey, $actorId, $talonario, $signedById, $declaredPaidOn,
            $presentadoAntes, $respaldo
        ): FinePayment {
            $yaRegistrado = FinePayment::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($yaRegistrado !== null) {
                return $yaRegistrado;
            }

            $multa = Fine::query()->whereKey($multa->id)->lockForUpdate()->firstOrFail();
            $saldo = FineBalance::query()->whereKey($multa->id)->firstOrFail();

            if (Decimal::equals($saldo->debt, '0')) {
                throw ValidationException::withMessages(['externalAmount' => 'La multa no tiene saldo pendiente: no hay recurso que pagar.']);
            }

            $base = $saldo->obligation;
            $exigido = AppealAmount::required($base);

            if (Decimal::equals($exigido, '0')) {
                throw ValidationException::withMessages([
                    'externalAmount' => 'El 20 % de la multa vigente no llega a un peso: no hay importe que exigir.',
                ]);
            }

            $coincide = Decimal::equals($externo, $exigido);
            // Otro recurso de la multa que no se descartó: este va a revisión (P05).
            $repetido = FinePayment::query()
                ->where('fine_id', $multa->id)
                ->whereNotNull('appeal_kind')
                ->where('status', '<>', FinePaymentStatus::Discarded)
                ->exists();
            $ahora = CarbonImmutable::now();

            $pago = FinePayment::query()->create([
                'fine_id' => $multa->id,
                'medium' => $medio,
                'status' => match (true) {
                    ! $coincide || $repetido => FinePaymentStatus::UnderReview,
                    $medio === FinePaymentMedium::Cash => FinePaymentStatus::Applied,
                    default => FinePaymentStatus::Reported,
                },
                'amount' => $exigido,
                'presented_at' => $presentadoAntes === null ? $ahora : BusinessDate::startOfDay($presentadoAntes->toDateString()),
                'presented_precision' => $presentadoAntes === null ? PresentationPrecision::Instant : PresentationPrecision::Day,
                'presentation_evidence' => $presentadoAntes === null ? null : $respaldo,
                'reported_at' => $ahora,
                'declared_paid_on' => $declaredPaidOn?->toDateString(),
                'payer_person_id' => $multa->person_id,
                'payer_name' => $multa->caption,
                'appeal_kind' => $tipo,
                'appeal_base_amount' => $base,
                'appeal_required_amount' => $exigido,
                'external_receipt_reference' => $referencia,
                'external_receipt_amount' => $externo,
                'appeal_repeated' => $repetido,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actorId,
            ]);

            $comprobante = $pago->status === FinePaymentStatus::Applied
                ? $this->recibir->handle($pago, BusinessDate::today(), null, $talonario, $signedById, $actorId)
                : null;

            $this->auditar->handle('multa.recurso-registrado', $multa, metadata: array_filter([
                'tipo' => $tipo->label(),
                'medio' => $medio->label(),
                'base' => $base,
                'exigido' => $exigido,
                'externo' => $referencia,
                'importe_externo' => $externo,
                'repetido' => $repetido ? 'Sí: la multa ya tiene otro recurso' : null,
                'recibo' => $comprobante?->receipt()->value('formatted_number'),
            ]), actorId: $actorId);

            return $pago;
        });
    }

    /**
     * @throws ValidationException
     */
    private function assertRegistrable(
        FinePaymentMedium $medio,
        string $referencia,
        string $externo,
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

        if (Decimal::isNegative($externo) || Decimal::equals($externo, '0')) {
            throw ValidationException::withMessages(['externalAmount' => 'Anotá el importe que dice el recibo externo.']);
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
