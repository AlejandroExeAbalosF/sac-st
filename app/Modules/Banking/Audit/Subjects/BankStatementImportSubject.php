<?php

declare(strict_types=1);

namespace App\Modules\Banking\Audit\Subjects;

use App\Models\User;
use App\Modules\Banking\Enums\ImportStatus;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;
use App\Support\EnumLabels;

final class BankStatementImportSubject extends BaseAuditSubjectResolver
{
    public function subjectType(): string
    {
        return 'BankStatementImport';
    }

    public function label(): string
    {
        return 'Extracto bancario';
    }

    public function fields(): array
    {
        return [
            'original_filename' => 'Archivo',
            'rows_total' => 'Filas',
            'rows_new' => 'Movimientos nuevos',
            'rows_duplicate' => 'Movimientos repetidos',
            'status' => 'Estado',
            'failure_reason' => 'Motivo del rechazo',
        ];
    }

    public function valueLabels(): array
    {
        return ['status' => EnumLabels::of(ImportStatus::class)];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $puedeVer = $this->can($viewer, 'banco.extractos.ver');

        $descripciones = [];

        foreach (BankStatementImport::query()->whereIn('id', $ids)->get(['id', 'original_filename']) as $extracto) {
            $descripciones[$extracto->id] = new AuditSubjectDescription(
                $extracto->original_filename,
                // Un extracto revertido ya no tiene movimientos, pero la
                // importación sigue existiendo y su pantalla cuenta qué pasó.
                $puedeVer ? route('banco.extractos.show', $extracto->id) : null,
            );
        }

        return $descripciones;
    }
}
