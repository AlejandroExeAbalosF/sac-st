<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Ledger\Actions\RegisterCashFundReceipt;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Enums\PresentationPrecision;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineBalance;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Receipt;
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
        private readonly RegisterCashFundReceipt $recibir,
        private readonly ApplyFinePayment $aplicar,
        private readonly RecordAuditEvent $auditar,
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
            if (str_contains($e->getMessage(), 'receipts_talonario_number_unique') && $talonario !== null) {
                throw ValidationException::withMessages(['talonarioNumber' => $this->talonarioUsado($talonario)]);
            }

            throw $e;
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
            $caja = (int) CashBox::query()->where('code', CashBox::MULTAS)->valueOrFail('id');

            if (CashBookOpening::for($caja, Currency::from($multa->currency)) === null) {
                throw ValidationException::withMessages([
                    'amount' => $multa->currency === Currency::Ars->value
                        ? 'La caja de Multas todavía no se abrió: no puede cobrar.'
                        : 'La caja de Multas no opera en '.$multa->currency.'.',
                ]);
            }

            $saldo = (string) FineBalance::query()->whereKey($multa->id)->value('debt');

            /*
             * Una hoja del talonario se usa una vez, aunque el recibo se haya
             * anulado. La base lo impide; esto lo dice en el campo.
             */
            if ($talonario !== null && $this->talonarioTomado($caja, $talonario)) {
                throw ValidationException::withMessages(['talonarioNumber' => $this->talonarioUsado($talonario)]);
            }

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

            $recepcion = $this->recibir->handle(
                amount: $importe,
                idempotencyKey: $idempotencyKey.':recepcion',
                cashBoxId: $caja,
                receivedDate: $fecha,
                medium: $medio === FinePaymentMedium::Cheque ? PaymentMedium::Cheque : PaymentMedium::Cash,
                depositorId: $multa->person_id,
                actorId: $actorId,
                notes: 'Multa '.$multa->caption,
                cheque: $cheque,
                currency: Currency::from($multa->currency),
            );

            $comprobante = $this->aplicar->handle($pago, $recepcion, $fecha, $talonario, $signedById, $actorId);

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

    private function talonarioTomado(int $caja, string $talonario): bool
    {
        return Receipt::query()
            ->where('cash_box_id', $caja)
            ->where('receipt_type', ReceiptType::Income)
            ->where('talonario_number', $talonario)
            ->exists();
    }

    private function talonarioUsado(string $talonario): string
    {
        $recibo = Receipt::query()
            ->where('cash_box_id', CashBox::query()->where('code', CashBox::MULTAS)->value('id'))
            ->where('receipt_type', ReceiptType::Income)
            ->where('talonario_number', $talonario)
            ->value('formatted_number');

        return "La hoja {$talonario} del talonario de Multas ya se usó"
            .($recibo === null ? '.' : " en el recibo {$recibo}.")
            .' Revisá el número: cada hoja se carga una sola vez, aunque se haya anulado.';
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
