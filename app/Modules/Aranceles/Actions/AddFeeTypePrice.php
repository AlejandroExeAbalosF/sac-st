<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Actions;

use App\Modules\Aranceles\Enums\FeeCalculationMode;
use App\Modules\Aranceles\Models\FeeType;
use App\Modules\Aranceles\Models\FeeTypePrice;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Agrega lo que vale un tipo de arancel desde una fecha (regla A12).
 *
 * Puede ser futura, para un aumento ya dispuesto, o pasada, para reconstruir un
 * valor anterior. Un cobro ya hecho no cambia: guarda su propia copia.
 */
final class AddFeeTypePrice
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    /** @throws ValidationException */
    public function handle(
        FeeType $tipo,
        CarbonInterface $validFrom,
        string $value,
        ?string $legalReference,
        ?int $actorId,
    ): FeeTypePrice {
        $esUnitario = $tipo->calculation_mode === FeeCalculationMode::Unit;
        $valor = $esUnitario ? Decimal::scale($value) : Decimal::scale($value, 4);

        if (Decimal::isNegative($valor) || Decimal::equals($valor, '0', 4)) {
            throw ValidationException::withMessages([
                'value' => $esUnitario ? 'El precio tiene que ser mayor que cero.' : 'La tasa tiene que ser mayor que cero.',
            ]);
        }

        try {
            return DB::transaction(function () use ($tipo, $validFrom, $valor, $esUnitario, $legalReference, $actorId): FeeTypePrice {
                FeeType::query()->whereKey($tipo->id)->lockForUpdate()->firstOrFail();

                $precio = FeeTypePrice::query()->create([
                    'fee_type_id' => $tipo->id,
                    'valid_from' => $validFrom->toDateString(),
                    'unit_price' => $esUnitario ? $valor : null,
                    'rate' => $esUnitario ? null : $valor,
                    'legal_reference' => $legalReference,
                    'created_by' => $actorId,
                ]);

                $this->auditar->handle('arancel.precio-agregado', $tipo, metadata: array_filter([
                    'desde' => $validFrom->format('d/m/Y'),
                    'valor' => $valor,
                    'referencia' => $legalReference,
                ]), actorId: $actorId);

                return $precio;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'validFrom' => 'Ya hay un precio con esa fecha. Para corregirlo, anulalo y cargá el correcto.',
            ]);
        }
    }
}
