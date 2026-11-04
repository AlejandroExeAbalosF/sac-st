<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Actions;

use App\Modules\Aranceles\Models\FeeChargeItem;
use App\Modules\Aranceles\Models\FeeType;
use App\Modules\Aranceles\Models\FeeTypePrice;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anula un precio mal cargado: queda a la vista con su motivo y deja de regir.
 *
 * Es la salida para un error de tipeo: los precios no se editan, y la fecha ya
 * ocupada no admite otro. Uno ya usado en un cobro no se anula.
 */
final class VoidFeeTypePrice
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    /** @throws ValidationException */
    public function handle(FeeTypePrice $precio, string $reason, ?int $actorId): FeeTypePrice
    {
        return DB::transaction(function () use ($precio, $reason, $actorId): FeeTypePrice {
            $tipo = FeeType::query()->whereKey($precio->fee_type_id)->lockForUpdate()->firstOrFail();
            $precio = FeeTypePrice::query()->whereKey($precio->id)->lockForUpdate()->firstOrFail();

            // Un segundo envío del mismo formulario.
            if ($precio->voided_at !== null) {
                return $precio;
            }

            if (FeeChargeItem::query()->where('fee_type_price_id', $precio->id)->exists()) {
                throw ValidationException::withMessages([
                    'reason' => 'Ese precio ya se usó en un cobro: no se anula. Cargá el correcto desde otra fecha.',
                ]);
            }

            $otros = FeeTypePrice::query()
                ->where('fee_type_id', $tipo->id)
                ->whereKeyNot($precio->id)
                ->whereNull('voided_at')
                ->exists();

            if (! $otros) {
                throw ValidationException::withMessages([
                    'reason' => 'Es el único precio de este tipo de arancel: primero cargá el correcto.',
                ]);
            }

            $precio->forceFill([
                'voided_at' => now(),
                'voided_by' => $actorId,
                'void_reason' => $reason,
            ])->save();

            $this->auditar->handle('arancel.precio-anulado', $tipo, metadata: [
                'desde' => $precio->valid_from->format('d/m/Y'),
                'valor' => $precio->unit_price ?? $precio->rate,
                'reason' => $reason,
            ], actorId: $actorId);

            return $precio;
        });
    }
}
