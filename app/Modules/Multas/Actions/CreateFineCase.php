<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\FineProvenance;
use App\Modules\Multas\Models\FineCase;
use App\Modules\Multas\Support\FineCaseNumber;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Validation\ValidationException;

/**
 * Da de alta un expediente de Multas a mano.
 *
 * El texto se guarda tal como se escribió y, al lado, número, año y sufijo
 * normalizados. Si la identidad ya existe, la base lo rechaza
 * (`fine_cases_identity_unique`): quien lo llama decide si reutilizar el
 * existente, que es lo que hace el alta de una multa.
 */
final class CreateFineCase
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    public function handle(string $originalText, ?string $notes, ?int $actorId): FineCase
    {
        $numero = FineCaseNumber::parse($originalText);

        if ($numero === null) {
            throw ValidationException::withMessages([
                'caseNumber' => 'El número de expediente se escribe como en el papel: 169607/24-0 o 120276/25.',
            ]);
        }

        $expediente = FineCase::query()->create([
            'original_text' => trim($originalText),
            'number' => $numero->number,
            'year' => $numero->year,
            'suffix' => $numero->suffix,
            'provenance' => FineProvenance::Manual,
            'notes' => $notes,
            'created_by' => $actorId,
        ]);

        $this->auditar->handle(
            'expediente-multa.registrado',
            $expediente,
            after: ['original_text' => $expediente->original_text],
            actorId: $actorId,
        );

        return $expediente;
    }

    /**
     * El expediente con esa identidad exacta, si ya está cargado.
     *
     * Exacta quiere decir con el mismo sufijo: `120276/25` no encuentra a
     * `120276/25-0` (P03). La pantalla avisa esas coincidencias aparte.
     */
    public static function existing(FineCaseNumber $numero): ?FineCase
    {
        return FineCase::query()
            ->where('number', $numero->number)
            ->where('year', $numero->year)
            ->when(
                $numero->suffix === null,
                fn ($query) => $query->whereNull('suffix'),
                fn ($query) => $query->where('suffix', $numero->suffix),
            )
            ->first();
    }
}
