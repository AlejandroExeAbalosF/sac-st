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
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
 */
final class RegisterFine
{
    public function __construct(
        private readonly CreateFineCase $crearExpediente,
        private readonly AttachFineDocument $registrarDocumento,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  Lo validado por `StoreFineRequest`.
     */
    public function handle(array $datos, ?int $actorId): Fine
    {
        $numeroDeExpediente = (string) $datos['caseNumber'];
        $numero = FineCaseNumber::parse($numeroDeExpediente);

        if ($numero === null) {
            throw ValidationException::withMessages([
                'caseNumber' => 'El número de expediente se escribe como en el papel: 169607/24-0 o 120276/25.',
            ]);
        }

        $importe = Decimal::scale((string) $datos['amount']);
        $caratula = trim((string) $datos['caption']);
        $registro = (string) $datos['registeredOn'];

        return DB::transaction(function () use ($datos, $numero, $numeroDeExpediente, $importe, $caratula, $registro, $actorId): Fine {
            $expediente = CreateFineCase::existing($numero)
                ?? $this->crearExpediente->handle($numeroDeExpediente, isset($datos['caseNotes']) ? (string) $datos['caseNotes'] : null, $actorId);

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

            return $multa;
        });
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
