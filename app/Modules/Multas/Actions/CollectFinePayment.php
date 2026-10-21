<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Enums\PresentationPrecision;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineBalance;
use App\Modules\Multas\Models\FinePayment;
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
 * El cobro de una multa en la caja: efectivo o cheque (M02).
 *
 * Recibir, aplicar y emitir el recibo van en una sola transacción: lo que se
 * cobra en el mostrador reduce la deuda en el acto. El cheque se recibe por
 * el importe entero del instrumento y queda en custodia; si supera la deuda,
 * lo que sobra es excedente (M05, M06).
 *
 * El «recibí de» es la carátula de la multa (M09). Un cobro de un día
 * anterior se carga desde el recibo de talonario que se entregó ese día: su
 * número es el respaldo de la fecha de presentación.
 */
final class CollectFinePayment
{
    public function __construct(
        private readonly ReceiveFinePaymentAtCounter $recibir,
        private readonly RecordAuditEvent $auditar,
        private readonly TalonarioSequence $talonarios,
    ) {}

    /**
     * @param  array{number: string, bank: string|null, issueDate: string|null}|null  $cheque
     *
     * @throws ValidationException
     */
    public function handle(
        Fine $multa,
        FinePaymentMedium $medio,
        string $amount,
        string $idempotencyKey,
        ?int $actorId,
        ?CarbonImmutable $receivedOn = null,
        ?array $cheque = null,
        ?string $judicialReference = null,
        ?string $talonarioNumber = null,
        ?int $signedById = null,
    ): FinePayment {
        $importe = Decimal::scale($amount);
        $hoy = BusinessDate::today();
        $fecha = $receivedOn ?? $hoy;
        $talonario = trim((string) $talonarioNumber) ?: null;
        $judicial = trim((string) $judicialReference) ?: null;

        $this->assertCollectable($medio, $importe, $fecha, $hoy, $talonario, $judicial, $cheque);

        try {
            return $this->collect($multa, $medio, $importe, $idempotencyKey, $actorId, $fecha, $hoy, $cheque, $judicial, $talonario, $signedById);
        } catch (UniqueConstraintViolationException $e) {
            // El mismo clic dos veces a la vez: el que llegó tarde devuelve el que ganó.
            $yaCobrado = FinePayment::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($yaCobrado !== null) {
                return $yaCobrado;
            }

            // Dos cobros a la vez con el mismo papel: la base frenó al segundo.
            throw $this->talonarios->translate($e, ReceiptType::Income, $this->cajaDeMultas(), $talonario) ?? $e;
        }
    }

    /**
     * @param  array{number: string, bank: string|null, issueDate: string|null}|null  $cheque
     *
     * @throws ValidationException
     * @throws UniqueConstraintViolationException dos pedidos simultáneos con la misma clave o el mismo talonario
     */
    private function collect(
        Fine $multa,
        FinePaymentMedium $medio,
        string $importe,
        string $idempotencyKey,
        ?int $actorId,
        CarbonImmutable $fecha,
        CarbonImmutable $hoy,
        ?array $cheque,
        ?string $judicial,
        ?string $talonario,
        ?int $signedById,
    ): FinePayment {
        return DB::transaction(function () use (
            $multa, $medio, $importe, $idempotencyKey, $actorId, $fecha, $hoy, $cheque, $judicial, $talonario, $signedById
        ): FinePayment {
            $yaCobrado = FinePayment::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($yaCobrado !== null) {
                return $yaCobrado;
            }

            $multa = Fine::query()->whereKey($multa->id)->lockForUpdate()->firstOrFail();
            $saldo = (string) FineBalance::query()->whereKey($multa->id)->value('debt');

            if (Decimal::equals($saldo, '0')) {
                throw ValidationException::withMessages(['amount' => 'La multa no tiene saldo pendiente: no hay nada que cobrar.']);
            }

            /*
             * El cobro de hoy se presenta en el momento; el de un día anterior,
             * ese día, con el talonario como respaldo.
             */
            $ahora = CarbonImmutable::now();
            $delDia = $fecha->isSameDay($hoy);

            $pago = FinePayment::query()->create([
                'fine_id' => $multa->id,
                'medium' => $medio,
                'judicial_origin' => $judicial !== null,
                'judicial_reference' => $judicial,
                'status' => FinePaymentStatus::Applied,
                'amount' => $importe,
                'presented_at' => $delDia ? $ahora : BusinessDate::startOfDay($fecha->toDateString()),
                'presented_precision' => $delDia ? PresentationPrecision::Instant : PresentationPrecision::Day,
                'presentation_evidence' => $delDia ? null : 'Recibo de talonario N.º '.$talonario,
                'reported_at' => $ahora,
                'payer_person_id' => $multa->person_id,
                'payer_name' => $multa->caption,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actorId,
            ]);

            $comprobante = $this->recibir->handle($pago, $fecha, $cheque, $talonario, $signedById, $actorId);

            $this->auditar->handle(
                'multa.pago-cobrado',
                $multa,
                after: ['debt' => (string) FineBalance::query()->whereKey($multa->id)->value('debt')],
                metadata: array_filter([
                    'medio' => $medio->label(),
                    'amount' => $importe,
                    'recibo' => $comprobante->receipt()->value('formatted_number'),
                    'judicial' => $judicial,
                ]),
                actorId: $actorId,
            );

            return $pago;
        });
    }

    private function cajaDeMultas(): int
    {
        return (int) CashBox::query()->where('code', CashBox::MULTAS)->value('id');
    }

    /**
     * @param  array{number: string, bank: string|null, issueDate: string|null}|null  $cheque
     *
     * @throws ValidationException
     */
    private function assertCollectable(
        FinePaymentMedium $medio,
        string $importe,
        CarbonImmutable $fecha,
        CarbonImmutable $hoy,
        ?string $talonario,
        ?string $judicial,
        ?array $cheque,
    ): void {
        if (! $medio->isCounter()) {
            throw ValidationException::withMessages([
                'medium' => 'En la caja se cobra en efectivo o con cheque. Lo que llega por el banco se informa y se verifica contra el crédito.',
            ]);
        }

        if (Decimal::isNegative($importe) || Decimal::equals($importe, '0')) {
            throw ValidationException::withMessages(['amount' => 'El importe cobrado tiene que ser mayor que cero.']);
        }

        if ($fecha->greaterThan($hoy)) {
            throw ValidationException::withMessages(['receivedOn' => 'Un cobro no puede tener fecha futura.']);
        }

        if ($fecha->lessThan($hoy) && $talonario === null) {
            throw ValidationException::withMessages([
                'talonarioNumber' => 'Un cobro de un día anterior se carga con el número del recibo de talonario que se entregó ese día.',
            ]);
        }

        if ($judicial !== null && $medio !== FinePaymentMedium::Cheque) {
            throw ValidationException::withMessages([
                'judicialReference' => 'Una orden de pago judicial llega como cheque o como transferencia, no en efectivo.',
            ]);
        }

        if ($medio === FinePaymentMedium::Cheque && trim($cheque['number'] ?? '') === '') {
            throw ValidationException::withMessages(['cheque.number' => 'Un cheque sin número no se puede inventariar ni buscar después.']);
        }
    }
}
