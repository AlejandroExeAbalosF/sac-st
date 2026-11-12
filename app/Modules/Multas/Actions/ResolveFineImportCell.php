<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\FineImportCellStatus;
use App\Modules\Multas\Models\FineImportCell;
use App\Modules\Multas\Models\FineImportRow;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marca un pago de la planilla posterior a la apertura: se identificó con su
 * crédito o su cobro, o se descarta. Una vez, con una nota. No toca el saldo:
 * el pago real entra por el circuito del sistema.
 */
final class ResolveFineImportCell
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    /** @throws ValidationException */
    public function handle(FineImportCell $celda, FineImportCellStatus $estado, string $note, ?int $actorId): FineImportCell
    {
        $nota = trim($note);

        if ($estado === FineImportCellStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'Se marca como identificado o descartado.']);
        }

        if ($nota === '') {
            throw ValidationException::withMessages(['note' => $estado === FineImportCellStatus::Identified
                ? 'Anotá con qué crédito o cobro se identificó.'
                : 'Contá por qué se descarta.']);
        }

        return DB::transaction(function () use ($celda, $estado, $nota, $actorId): FineImportCell {
            $celda = FineImportCell::query()->whereKey($celda->id)->lockForUpdate()->firstOrFail();

            if ($celda->status !== FineImportCellStatus::Pending) {
                throw ValidationException::withMessages(['note' => 'Ese pago ya se marcó.']);
            }

            $celda->forceFill([
                'status' => $estado,
                'note' => mb_substr($nota, 0, 500),
                'resolved_by' => $actorId,
                'resolved_at' => now(),
            ])->save();

            $fila = FineImportRow::query()->with('batch')->findOrFail($celda->fine_import_row_id);

            $this->auditar->handle('multa.importacion-celda', $fila->batch, metadata: [
                'fila' => "{$fila->sheet}, fila {$fila->row_number}, columna {$celda->column}",
                'amount' => $celda->amount,
                'estado' => $estado->label(),
                'reason' => $nota,
            ], actorId: $actorId);

            return $celda;
        });
    }
}
