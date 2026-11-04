<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Actions;

use App\Modules\Aranceles\Enums\FeeCalculationMode;
use App\Modules\Aranceles\Models\FeeType;
use App\Modules\Aranceles\Support\FeeTypeUniqueness;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Da de alta un tipo de arancel con su primer precio: un tipo sin precio no se
 * podría cobrar, y la base lo rechaza.
 */
final class CreateFeeType
{
    public function __construct(
        private readonly AddFeeTypePrice $agregarPrecio,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  array{code: int, decreeLetter: ?string, name: string, calculationMode: FeeCalculationMode, unitLabel: ?string}  $datos
     *
     * @throws ValidationException
     */
    public function handle(
        array $datos,
        CarbonInterface $validFrom,
        string $value,
        ?string $legalReference,
        ?int $actorId,
    ): FeeType {
        try {
            return DB::transaction(function () use ($datos, $validFrom, $value, $legalReference, $actorId): FeeType {
                $tipo = FeeType::query()->create([
                    'code' => $datos['code'],
                    'decree_letter' => $datos['decreeLetter'],
                    'name' => $datos['name'],
                    'calculation_mode' => $datos['calculationMode'],
                    'unit_label' => $datos['calculationMode'] === FeeCalculationMode::Unit ? $datos['unitLabel'] : null,
                    'is_active' => true,
                ]);

                $this->auditar->handle('arancel.tipo-registrado', $tipo, after: [
                    'code' => $tipo->code,
                    'name' => $tipo->name,
                    'calculation_mode' => $tipo->calculation_mode->value,
                ], actorId: $actorId);

                $this->agregarPrecio->handle($tipo, $validFrom, $value, $legalReference, $actorId);

                return $tipo;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(FeeTypeUniqueness::messagesFor($e));
        }
    }
}
