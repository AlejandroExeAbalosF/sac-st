<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Actions;

use App\Modules\Aranceles\Enums\FeeChargeMedium;
use App\Modules\Aranceles\Enums\FeeChargeStatus;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeChargeItem;
use App\Modules\Aranceles\Models\FeeChargeReceipt;
use App\Modules\Aranceles\Support\FeeChargeFunds;
use App\Modules\Aranceles\Support\FeeLineCalculator;
use App\Modules\Ledger\Actions\RegisterCashFundReceipt;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSource;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Support\TalonarioSequence;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * El cobro de aranceles en efectivo: crear, recibir, aplicar y emitir el recibo
 * van en una sola transacción (regla A40).
 *
 * Un cobro de un día anterior se carga con el número del recibo de talonario que
 * se entregó ese día, y usa el precio de ese día (A32, A42).
 *
 * @phpstan-import-type Linea from FeeLineCalculator
 * @phpstan-import-type Renglon from FeeLineCalculator
 */
final class CollectFeeInCash
{
    public function __construct(
        private readonly FeeLineCalculator $calcular,
        private readonly RegisterCashFundReceipt $recibir,
        private readonly FeeChargeFunds $fondos,
        private readonly IssueFeeReceipt $emitir,
        private readonly TalonarioSequence $talonarios,
        private readonly RecordAuditEvent $auditar,
        private readonly StoreAttachment $adjuntos,
    ) {}

    /**
     * @param  list<Linea>  $lineas
     *
     * @throws ValidationException
     */
    public function preview(
        string $payerName,
        ?string $payerCuit,
        array $lineas,
        ?CarbonImmutable $receivedOn,
        ?string $talonarioNumber,
        ?int $signedById,
        bool $puedeCambiarImporte,
    ): FeeChargeReceipt {
        $fecha = $receivedOn ?? BusinessDate::today();
        $talonario = trim((string) $talonarioNumber) ?: null;

        $this->assertCollectable($fecha, $talonario);
        ['items' => $items, 'total' => $total] = $this->calcular->build($lineas, $fecha, $puedeCambiarImporte);

        $cobro = new FeeCharge(['payer_name' => $payerName, 'payer_cuit' => $payerCuit, 'medium' => FeeChargeMedium::Cash]);

        return $this->emitir->preview($cobro, self::papel($payerName, $payerCuit, $items), $total, $fecha, $talonario, $signedById);
    }

    /**
     * @param  list<Linea>  $lineas
     *
     * @throws ValidationException
     */
    public function handle(
        string $payerName,
        ?string $payerCuit,
        array $lineas,
        string $idempotencyKey,
        ?int $actorId,
        ?CarbonImmutable $receivedOn,
        ?string $talonarioNumber,
        ?int $signedById,
        bool $puedeCambiarImporte,
        ?UploadedFile $talonarioPhoto = null,
    ): FeeCharge {
        $fecha = $receivedOn ?? BusinessDate::today();
        $talonario = trim((string) $talonarioNumber) ?: null;

        if ($talonarioPhoto !== null && $talonario === null) {
            throw ValidationException::withMessages([
                'talonarioNumber' => 'Indicá el número del talonario al que pertenece la foto.',
            ]);
        }

        $adjunto = null;

        try {
            return DB::transaction(function () use ($payerName, $payerCuit, $lineas, $idempotencyKey, $actorId, $fecha, $talonario, $signedById, $puedeCambiarImporte, $talonarioPhoto, &$adjunto): FeeCharge {
                $yaCobrado = FeeCharge::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($yaCobrado !== null) {
                    return $yaCobrado;
                }

                $this->assertCollectable($fecha, $talonario);
                ['items' => $items, 'total' => $total] = $this->calcular->build($lineas, $fecha, $puedeCambiarImporte);

                $cobro = $this->crear($payerName, $payerCuit, $items, $fecha, $fecha, $idempotencyKey, $actorId);

                $recepcion = $this->recibir->handle(
                    amount: $total,
                    idempotencyKey: $idempotencyKey.':recepcion',
                    cashBoxId: $this->fondos->cashBoxId(),
                    receivedDate: $fecha,
                    medium: PaymentMedium::Cash,
                    actorId: $actorId,
                    notes: 'Arancel de '.$payerName,
                    currency: Currency::Ars,
                );

                $aplicacion = $this->fondos->apply($cobro, $recepcion, $total, $fecha, $actorId);

                $comprobante = $this->emitir->handle(
                    $cobro,
                    self::papel($payerName, $payerCuit, $items),
                    $total,
                    $fecha,
                    [$recepcion->financial_event_id, $aplicacion->id],
                    $talonario,
                    $signedById,
                    $actorId,
                );

                if ($talonarioPhoto !== null) {
                    $adjunto = $this->adjuntos->handle(
                        file: $talonarioPhoto,
                        subject: AttachmentSubject::Receipt,
                        subjectId: $comprobante->receipt_id,
                        documentType: 'talonario_photo',
                        userId: $actorId,
                        title: 'Foto del talonario N.º '.$talonario,
                        source: AttachmentSource::Scanned,
                    );
                }

                $this->auditar->handle('arancel.cobrado', $cobro, metadata: [
                    'medio' => $cobro->medium->label(),
                    'amount' => $total,
                    'recibo' => $comprobante->receipt()->value('formatted_number'),
                ], actorId: $actorId);

                return $cobro;
            });
        } catch (Throwable $e) {
            // La transacción volvió atrás y se llevó la fila: el archivo quedaría huérfano.
            if ($adjunto !== null) {
                $this->adjuntos->deleteFile(['disk' => $adjunto->storage_disk, 'key' => $adjunto->object_key]);
            }

            if (! $e instanceof UniqueConstraintViolationException) {
                throw $e;
            }

            // El mismo clic dos veces a la vez: el que llegó tarde devuelve el que ganó.
            $yaCobrado = FeeCharge::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($yaCobrado !== null) {
                return $yaCobrado;
            }

            throw $this->talonarios->translate($e, ReceiptType::Income, $this->fondos->cashBoxId(), $talonario) ?? $e;
        }
    }

    /**
     * Crea el cobro con sus renglones: pagado, o pendiente si no tiene fecha de
     * pago. Lo usan también la corrección y el pago por el banco.
     *
     * @param  list<Renglon>  $items
     */
    public function crear(string $payerName, ?string $payerCuit, array $items, CarbonImmutable $fechaDeTarifa, ?CarbonImmutable $pagadoEl, string $idempotencyKey, ?int $actorId, FeeChargeMedium $medio = FeeChargeMedium::Cash): FeeCharge
    {
        $cobro = FeeCharge::query()->create([
            'payer_name' => $payerName,
            'payer_cuit' => $payerCuit,
            'medium' => $medio,
            'status' => $pagadoEl === null ? FeeChargeStatus::Pending : FeeChargeStatus::Paid,
            'requested_on' => BusinessDate::today(),
            'pricing_date' => $fechaDeTarifa,
            'paid_on' => $pagadoEl,
            'idempotency_key' => $idempotencyKey,
            'created_by' => $actorId,
        ]);

        foreach ($items as $renglon) {
            FeeChargeItem::query()->create(['fee_charge_id' => $cobro->id, ...$renglon]);
        }

        return $cobro;
    }

    /**
     * Lo que va a decir el papel.
     *
     * @param  list<Renglon>  $items
     * @return array{payerName: string, payerCuit: string|null, lines: list<array{code: int, name: string, unitLabel: string|null, quantity: int|null, unitPrice: string|null, rate: string|null, baseAmount: string|null, amount: string}>, bankAccountNumber: string|null}
     */
    public static function papel(string $payerName, ?string $payerCuit, array $items): array
    {
        return [
            'payerName' => $payerName,
            'payerCuit' => $payerCuit,
            'lines' => array_map(fn (array $r): array => [
                'code' => $r['code_snapshot'],
                'name' => $r['name_snapshot'],
                'unitLabel' => $r['unit_label_snapshot'],
                'quantity' => $r['quantity'],
                'unitPrice' => $r['unit_price'],
                'rate' => $r['rate'],
                'baseAmount' => $r['base_amount'],
                'amount' => $r['original_amount'],
            ], $items),
            'bankAccountNumber' => null,
        ];
    }

    /** @throws ValidationException */
    private function assertCollectable(CarbonImmutable $fecha, ?string $talonario): void
    {
        $caja = $this->fondos->cashBoxId();
        $hoy = BusinessDate::today();

        if (CashBookOpening::for($caja, Currency::Ars) === null) {
            throw ValidationException::withMessages(['items' => 'La caja de Aranceles todavía no se abrió: no puede cobrar.']);
        }

        if ($fecha->greaterThan($hoy)) {
            throw ValidationException::withMessages(['receivedOn' => 'Un cobro no puede tener fecha futura.']);
        }

        if ($fecha->lessThan($hoy) && $talonario === null) {
            throw ValidationException::withMessages([
                'talonarioNumber' => 'Un cobro de un día anterior se carga con el número del recibo de talonario que se entregó ese día.',
            ]);
        }

        // Una hoja del talonario se usa una vez, aunque el recibo se haya anulado.
        $this->talonarios->assertFree(ReceiptType::Income, $caja, $talonario);
    }
}
