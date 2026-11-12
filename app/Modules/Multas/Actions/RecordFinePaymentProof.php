<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El comprobante de un pago por banco: su fecha de pago y su archivo.
 *
 * Los dos son opcionales y llegan juntos, cuando aparece el papel. La fecha
 * es la que dice el comprobante; es informativa —no mueve el saldo ni decide
 * el término, que sale de la presentación—, así que se completa o se corrige
 * mientras el pago está informado, sin el trámite de «Corregir». Verificado,
 * queda fija con el pago. La base lo impone en `fine_payments_guard`, y cada
 * cambio queda auditado sobre la multa.
 *
 * El archivo entra por `AttachFineFile`, en el mismo acto: si algo falla,
 * no queda ni la fecha ni el archivo.
 */
final class RecordFinePaymentProof
{
    public function __construct(
        private readonly AttachFineFile $adjuntar,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  bool  $conFecha  Si el pedido trae la fecha: vacía, la borra.
     */
    public function handle(
        FinePayment $pago,
        bool $conFecha,
        ?CarbonImmutable $pagadoEl,
        ?UploadedFile $archivo,
        ?string $title,
        ?int $actorId,
    ): FinePayment {
        if ($pago->appeal_kind !== null || $pago->medium->isCounter()) {
            throw ValidationException::withMessages([
                'declaredPaidOn' => 'La fecha del comprobante es de un pago por banco informado.',
            ]);
        }

        if ($pagadoEl !== null && $pagadoEl->greaterThan(BusinessDate::today())) {
            throw ValidationException::withMessages(['declaredPaidOn' => 'El pago no puede tener fecha futura.']);
        }

        return DB::transaction(function () use ($pago, $conFecha, $pagadoEl, $archivo, $title, $actorId): FinePayment {
            $pago = FinePayment::query()->lockForUpdate()->findOrFail($pago->id);
            $antes = $pago->declared_paid_on?->toDateString();
            $despues = $pagadoEl?->toDateString();
            $cambiaFecha = $conFecha && $antes !== $despues;

            if (! $cambiaFecha && $archivo === null) {
                throw ValidationException::withMessages([
                    'file' => 'Cargá la fecha de pago o adjuntá el comprobante.',
                ]);
            }

            if ($cambiaFecha) {
                if ($pago->status !== FinePaymentStatus::Reported) {
                    throw ValidationException::withMessages([
                        'declaredPaidOn' => 'La fecha de pago se cambia mientras el pago está informado: verificado, queda con su recibo.',
                    ]);
                }

                $pago->forceFill(['declared_paid_on' => $despues])->save();

                // Los pagos se auditan sobre su multa: es donde se leen.
                $this->auditar->handle(
                    'multa.fecha-de-pago-cargada',
                    Fine::query()->findOrFail($pago->fine_id),
                    before: ['declared_paid_on' => $antes],
                    after: ['declared_paid_on' => $despues],
                    metadata: ['pago' => $pago->id, 'antes' => $antes, 'despues' => $despues],
                    actorId: $actorId,
                );
            }

            if ($archivo !== null) {
                $this->adjuntar->handle($pago, $archivo, $title, $actorId);
            }

            return $pago;
        });
    }
}
