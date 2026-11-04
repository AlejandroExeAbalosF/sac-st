<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Actions;

use App\Modules\Aranceles\Enums\FeeCalculationMode;
use App\Modules\Aranceles\Models\FeeChargeItem;
use App\Modules\Aranceles\Models\FeeType;
use App\Modules\Aranceles\Support\FeeTypeUniqueness;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrige un tipo de arancel o lo desactiva. El modo de cálculo no se toca: lo
 * fija su primer precio.
 */
final class UpdateFeeType
{
    private const array AUDITABLE = ['code', 'decree_letter', 'name', 'unit_label', 'is_active'];

    public function __construct(private readonly RecordAuditEvent $auditar) {}

    /**
     * @param  array{code: int, decreeLetter: ?string, name: string, unitLabel: ?string, isActive: bool}  $datos
     *
     * @throws ValidationException
     */
    public function handle(FeeType $tipo, array $datos, ?int $actorId): FeeType
    {
        try {
            return DB::transaction(function () use ($tipo, $datos, $actorId): FeeType {
                $tipo = FeeType::query()->whereKey($tipo->id)->lockForUpdate()->firstOrFail();
                $antes = $tipo->only(self::AUDITABLE);

                if ($datos['code'] !== $tipo->code && FeeChargeItem::query()->where('fee_type_id', $tipo->id)->exists()) {
                    throw ValidationException::withMessages(['code' => 'Este arancel ya se usó en un cobro: su código no cambia.']);
                }

                $tipo->fill([
                    'code' => $datos['code'],
                    'decree_letter' => $datos['decreeLetter'],
                    'name' => $datos['name'],
                    'unit_label' => $tipo->calculation_mode === FeeCalculationMode::Unit ? $datos['unitLabel'] : null,
                    'is_active' => $datos['isActive'],
                ])->save();

                [$anterior, $nuevo] = RecordAuditEvent::diff($antes, $tipo->only(self::AUDITABLE));

                if ($nuevo !== []) {
                    $this->auditar->handle('arancel.tipo-corregido', $tipo, before: $anterior, after: $nuevo, actorId: $actorId);
                }

                return $tipo;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(FeeTypeUniqueness::messagesFor($e));
        }
    }
}
