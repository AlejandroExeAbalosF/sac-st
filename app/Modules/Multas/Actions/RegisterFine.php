<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FineDebtEntryKind;
use App\Modules\Multas\Enums\FineDocumentKind;
use App\Modules\Multas\Enums\FineDocumentRole;
use App\Modules\Multas\Enums\FineProvenance;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineCase;
use App\Modules\Multas\Models\FineDebtEntry;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Multas\Support\FineCaseNumber;
use App\Modules\Multas\Support\NewDocument;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Models\Attachment;
use App\Support\Money\Decimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Da de alta una multa a mano, con todo lo que trae el papel.
 *
 * Toda multa se origina en una resolución y sus actas, de un expediente
 * (M01). El alta los resuelve en un solo acto: usa el expediente si ya está
 * cargado —con la misma identidad exacta— o lo crea; usa los documentos que
 * el expediente ya tiene o registra los nuevos; y deja la multa con su
 * importe original, que cita la resolución.
 *
 * La fecha del importe original es la de la resolución: es la que dispone
 * la deuda. La fecha de registro es otra cosa —el día en que se anotó en la
 * cuenta corriente— y define el período de origen (M08).
 *
 * Cada papel puede traer su archivo, opcional (M11): el del expediente va
 * al expediente; el de la resolución y el de cada acta nueva, a ese
 * documento. Se guardan en el mismo acto: si algo falla no queda una multa
 * a medias ni un archivo suelto en el disco.
 */
final class RegisterFine
{
    public function __construct(
        private readonly CreateFineCase $crearExpediente,
        private readonly AttachFineDocument $registrarDocumento,
        private readonly RecordAuditEvent $auditar,
        private readonly AttachFineFile $adjuntar,
        private readonly StoreAttachment $archivos,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  Lo validado por `StoreFineRequest`.
     */
    public function handle(array $datos, ?int $actorId): Fine
    {
        // Desde la ficha del expediente llega su id: el expediente ya está elegido.
        $casoId = isset($datos['fineCaseId']) ? (int) $datos['fineCaseId'] : null;
        $numeroDeExpediente = (string) ($datos['caseNumber'] ?? '');
        $numero = FineCaseNumber::parse($numeroDeExpediente);

        if ($casoId === null && $numero === null) {
            throw ValidationException::withMessages([
                'caseNumber' => 'El número de expediente se escribe como en el papel: 169607/24-0 o 120276/25.',
            ]);
        }

        $importe = Decimal::scale((string) $datos['amount']);
        $caratula = trim((string) $datos['caption']);
        $registro = (string) $datos['registeredOn'];

        /** @var list<Attachment> $guardados */
        $guardados = [];

        try {
            return $this->registrar($datos, $casoId, $numero, $numeroDeExpediente, $importe, $caratula, $registro, $actorId, $guardados);
        } catch (Throwable $exception) {
            // Las filas volvieron atrás: los archivos ya escritos quedarían huérfanos.
            foreach ($guardados as $adjunto) {
                $this->archivos->deleteFile(['disk' => $adjunto->storage_disk, 'key' => $adjunto->object_key]);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  list<Attachment>  $guardados  los archivos escritos, para borrarlos si el acto vuelve atrás
     */
    private function registrar(
        array $datos,
        ?int $casoId,
        ?FineCaseNumber $numero,
        string $numeroDeExpediente,
        string $importe,
        string $caratula,
        string $registro,
        ?int $actorId,
        array &$guardados,
    ): Fine {
        return DB::transaction(function () use ($datos, $casoId, $numero, $numeroDeExpediente, $importe, $caratula, $registro, $actorId, &$guardados): Fine {
            $expediente = match (true) {
                $casoId !== null => FineCase::query()->findOrFail($casoId),
                default => CreateFineCase::existing($numero)
                    ?? $this->crearExpediente->handle($numeroDeExpediente, isset($datos['caseNotes']) ? (string) $datos['caseNotes'] : null, $actorId),
            };

            $resolucion = $this->resolucion($expediente, $datos, $actorId);
            $actas = $this->actas($expediente, $datos, $actorId);

            $multa = Fine::query()->create([
                'fine_case_id' => $expediente->id,
                'person_id' => isset($datos['personId']) ? (int) $datos['personId'] : null,
                'caption_original' => $caratula,
                'caption' => $caratula,
                'registered_on' => $registro,
                'registered_precision' => DatePrecision::Day,
                'provenance' => FineProvenance::Manual,
                'created_by' => $actorId,
            ]);

            foreach ([$resolucion, ...$actas] as $documento) {
                $multa->documents()->attach($documento->id, [
                    'fine_case_id' => $expediente->id,
                    'role' => FineDocumentRole::Origin->value,
                    'created_by' => $actorId,
                ]);
            }

            FineDebtEntry::query()->create([
                'fine_id' => $multa->id,
                'kind' => FineDebtEntryKind::Constitution,
                'amount' => $importe,
                'effective_on' => $resolucion->issued_on,
                'effective_precision' => DatePrecision::Day,
                'fine_document_id' => $resolucion->id,
                'provenance' => FineProvenance::Manual,
                'created_by' => $actorId,
            ]);

            $this->auditar->handle(
                'multa.registrada',
                $multa,
                after: [
                    'caption' => $multa->caption,
                    'person_id' => $multa->person_id,
                    'registered_on' => $registro,
                    'amount' => $importe,
                ],
                metadata: [
                    'expediente' => $expediente->label(),
                    'resolucion' => $resolucion->label(),
                ],
                actorId: $actorId,
            );

            $this->adjuntarArchivos($expediente, $resolucion, $actas, $datos, $actorId, $guardados);

            return $multa;
        });
    }

    /**
     * El archivo de cada papel, al dueño que le corresponde.
     *
     * @param  array<string, mixed>  $datos
     * @param  list<FineDocument>  $actas  las elegidas del expediente primero, las nuevas después
     * @param  list<Attachment>  $guardados
     */
    private function adjuntarArchivos(
        FineCase $expediente,
        FineDocument $resolucion,
        array $actas,
        array $datos,
        ?int $actorId,
        array &$guardados,
    ): void {
        $nuevas = array_slice($actas, count((array) ($datos['actIds'] ?? [])));
        $pares = [[$expediente, $datos['caseFile'] ?? null], [$resolucion, $datos['resolutionFile'] ?? null]];

        foreach (array_values((array) ($datos['acts'] ?? [])) as $indice => $acta) {
            $pares[] = [$nuevas[$indice], ((array) $acta)['file'] ?? null];
        }

        foreach ($pares as [$duenio, $archivo]) {
            if ($archivo instanceof UploadedFile) {
                $guardados[] = $this->adjuntar->handle($duenio, $archivo, null, $actorId);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function resolucion(FineCase $expediente, array $datos, ?int $actorId): FineDocument
    {
        $id = $datos['resolutionId'] ?? null;

        $resolucion = $id !== null
            ? $this->delExpediente($expediente, (int) $id, 'resolutionId')
            : $this->registrarDocumento->handle(
                $expediente,
                NewDocument::fromArray((array) ($datos['resolution'] ?? []), FineDocumentKind::Resolution),
                $actorId,
                'resolution.text',
            );

        if ($resolucion->kind !== FineDocumentKind::Resolution) {
            throw ValidationException::withMessages(['resolutionId' => 'La multa se origina en una resolución, no en un acta.']);
        }

        /*
         * El importe original lleva la fecha de la resolución, y una carga
         * manual la sabe con día (`fine_debt_entries_manual_date_check`).
         */
        if ($resolucion->issued_precision !== DatePrecision::Day || $resolucion->issued_on === null) {
            throw ValidationException::withMessages([
                $id !== null ? 'resolutionId' : 'resolution.issuedOn' => 'Falta la fecha de la resolución: es la fecha del importe original.',
            ]);
        }

        return $resolucion;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return list<FineDocument>
     */
    private function actas(FineCase $expediente, array $datos, ?int $actorId): array
    {
        $actas = [];

        foreach ((array) ($datos['actIds'] ?? []) as $indice => $id) {
            $acta = $this->delExpediente($expediente, (int) $id, "actIds.{$indice}");

            if ($acta->kind !== FineDocumentKind::Act) {
                throw ValidationException::withMessages(["actIds.{$indice}" => 'Ese documento no es un acta.']);
            }

            $actas[] = $acta;
        }

        foreach ((array) ($datos['acts'] ?? []) as $indice => $nueva) {
            $actas[] = $this->registrarDocumento->handle(
                $expediente,
                NewDocument::fromArray((array) $nueva, FineDocumentKind::Act),
                $actorId,
                "acts.{$indice}.text",
            );
        }

        return $actas;
    }

    private function delExpediente(FineCase $expediente, int $id, string $field): FineDocument
    {
        $documento = $expediente->documents()->whereKey($id)->first();

        if (! $documento instanceof FineDocument) {
            throw ValidationException::withMessages([$field => 'Ese documento no pertenece al expediente.']);
        }

        return $documento;
    }
}
