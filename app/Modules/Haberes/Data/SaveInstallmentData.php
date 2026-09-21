<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Enums\ExpectedMedium;
use App\Modules\Haberes\Http\Requests\SaveInstallmentRequest;
use Carbon\CarbonInterface;
use LogicException;

/** Datos ya validados y normalizados para crear o corregir una cuota. */
final readonly class SaveInstallmentData
{
    /** @param numeric-string $amount */
    public function __construct(
        public string $amount,
        public ?int $managementLabelId,
        public ?string $concept,
        public ?string $dueDate,
        public ?ExpectedMedium $expectedMedium,
        public ?string $notes,
        public ?CarbonInterface $version,
    ) {}

    public static function fromRequest(SaveInstallmentRequest $request): self
    {
        $amount = $request->string('amount')->toString();

        // La regla `numeric` ya lo comprobó. Mantener la guarda hace que la
        // garantía siga siendo cierta aunque este DTO se reutilice fuera del
        // ciclo habitual de validación HTTP.
        if (! is_numeric($amount)) {
            throw new LogicException('El importe validado debe ser numérico.');
        }

        return new self(
            amount: $amount,
            managementLabelId: $request->filled('managementLabelId')
                ? $request->integer('managementLabelId')
                : null,
            concept: $request->filled('concept') ? $request->string('concept')->toString() : null,
            dueDate: $request->filled('dueDate') ? $request->string('dueDate')->toString() : null,
            expectedMedium: $request->enum('expectedMedium', ExpectedMedium::class),
            notes: $request->filled('notes') ? $request->string('notes')->toString() : null,
            version: $request->date('version'),
        );
    }
}
