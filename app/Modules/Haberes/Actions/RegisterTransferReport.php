<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\DisbursementMethod;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Haberes\Support\TransferStage;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSource;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * El organismo informó que transfirió — §2.3.1 del DER.
 *
 * **Es el primer papel del egreso bancario, y no prueba nada por sí
 * solo.** El SAF avisa que ejecutó la transferencia; que el dinero haya
 * salido de verdad lo dice el extracto, no el aviso. Por eso esto deja el
 * egreso esperando y el invariante 12 lo dice sin rodeos: *«un informe sin
 * débito no genera egreso»*.
 *
 * **Acá nace el egreso** cuando todavía no existía. Antes de este momento
 * no hay nada que registrar: la Orden es un pedido, no un pago. Si el
 * débito se reconoció primero —§12.4, que también ocurre— el egreso ya
 * está y esto solo le agrega el informe.
 *
 * **El papel del aviso, si lo hay.** Opcionalmente se adjunta la foto o el
 * PDF del informe, como adjunto del egreso (`transfer_report`). Se guarda en
 * la misma transacción que el informe; si algo falla, el archivo se borra y
 * no queda un adjunto huérfano. Un informe que se vuelve a cargar suma su
 * archivo: los anteriores quedan, porque un adjunto no se borra, y la
 * tarjeta muestra el último.
 */
final class RegisterTransferReport
{
    public function __construct(
        private readonly TransferStage $etapa,
        private readonly RecordAuditEvent $auditar,
        private readonly StoreAttachment $adjuntos,
    ) {}

    /**
     * @param  string|null  $reference  El número de operación o referencia
     *                                  con que el organismo identificó la
     *                                  transferencia.
     *
     * @throws ValidationException
     */
    public function handle(
        BeneficiaryInstallment $installment,
        ?CarbonInterface $reportedAt = null,
        ?string $reference = null,
        ?int $actorId = null,
        ?string $notes = null,
        ?UploadedFile $document = null,
    ): Disbursement {
        $adjunto = null;

        try {
            return $this->registrar($installment, $reportedAt, $reference, $actorId, $notes, $document, $adjunto);
        } catch (Throwable $e) {
            // El archivo ya se escribió en el disco; sin su fila, se borra.
            if ($adjunto !== null) {
                $this->adjuntos->deleteFile(['disk' => $adjunto->storage_disk, 'key' => $adjunto->object_key]);
            }

            throw $e;
        }
    }

    /** @throws ValidationException */
    private function registrar(
        BeneficiaryInstallment $installment,
        ?CarbonInterface $reportedAt,
        ?string $reference,
        ?int $actorId,
        ?string $notes,
        ?UploadedFile $document,
        ?Attachment &$adjunto,
    ): Disbursement {
        return DB::transaction(function () use (
            $installment, $reportedAt, $reference, $actorId, $notes, $document, &$adjunto
        ): Disbursement {
            $orden = $this->ordenVigente($installment);
            $egreso = $this->egresoEnCurso($installment, $orden, $actorId);

            if ($egreso->status === DisbursementStatus::Confirmed) {
                throw ValidationException::withMessages([
                    'installmentId' => 'El egreso ya está confirmado: el informe no se vuelve a cargar.',
                ]);
            }

            $antes = [
                'report_received_at' => $egreso->report_received_at?->toDateTimeString(),
                'transfer_reference' => $egreso->transfer_reference,
            ];

            $egreso->forceFill([
                'report_received_at' => $reportedAt ?? now(),
                'transfer_reference' => $reference,
                'notes' => $notes ?? $egreso->notes,
            ])->save();

            /* El estado sale de lo que hay registrado, nunca a mano. */
            $egreso->forceFill(['status' => $this->etapa->for($egreso)])->save();

            if ($document !== null) {
                $egreso->loadMissing('paymentOrder');

                $adjunto = $this->adjuntos->handle(
                    file: $document,
                    subject: AttachmentSubject::Disbursement,
                    subjectId: $egreso->id,
                    documentType: Disbursement::REPORT_DOCUMENT_TYPE,
                    userId: $actorId,
                    title: 'Informe de la transferencia — Orden '.($egreso->paymentOrder->formatted_number ?? $orden->formatted_number),
                    source: AttachmentSource::Scanned,
                );
            }

            $this->auditar->handle('egreso.informado', $egreso, before: $antes, after: [
                'report_received_at' => $egreso->report_received_at?->toDateTimeString(),
                'transfer_reference' => $egreso->transfer_reference,
                'status' => $egreso->status->value,
                'attachment_id' => $adjunto?->id,
            ], actorId: $actorId);

            return $egreso;
        });
    }

    /**
     * El egreso en curso de esta cuota, creándolo si es el primer acto.
     *
     * @throws ValidationException
     */
    private function egresoEnCurso(
        BeneficiaryInstallment $installment,
        PaymentOrder $orden,
        ?int $actorId,
    ): Disbursement {
        $existente = Disbursement::query()
            ->live()
            ->where('beneficiary_installment_id', $installment->id)
            ->lockForUpdate()
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        return Disbursement::query()->create([
            'beneficiary_installment_id' => $installment->id,
            'payment_order_id' => $orden->id,
            'method' => DisbursementMethod::BankTransfer,
            /*
             * El importe de la Orden, que es lo que se le pidió al
             * organismo. Con excedente bancario no coincide con lo que
             * entró, y tiene que no coincidir: el §2.4 deja el excedente
             * fuera del recibo, de la Orden y del egreso.
             */
            'amount' => $orden->amount,
            /*
             * El CBU al que se pidió transferir, congelado. Si
             * el organismo terminó usando otro, es una observación que
             * vuelve con el expediente y no algo que se corrija en
             * silencio acá.
             */
            'beneficiary_cbu_snapshot' => $orden->beneficiary_cbu_snapshot,
            'status' => DisbursementStatus::Pending,
            'created_by' => $actorId,
        ]);
    }

    /**
     * La Orden que autoriza este pago.
     *
     * Sin ella no hay nada que informar: el organismo no mueve dinero de
     * un tercero sin el papel que se lo pide, y la base tampoco admite un
     * egreso por transferencia sin Orden.
     *
     * @throws ValidationException
     */
    private function ordenVigente(BeneficiaryInstallment $installment): PaymentOrder
    {
        $orden = PaymentOrder::query()
            ->active()
            ->where('beneficiary_installment_id', $installment->id)
            ->first();

        if ($orden === null) {
            throw ValidationException::withMessages([
                'installmentId' => 'La cuota no tiene una Orden de Pago vigente. '
                    .'El organismo no transfiere sin el papel que se lo pide.',
            ]);
        }

        return $orden;
    }
}
