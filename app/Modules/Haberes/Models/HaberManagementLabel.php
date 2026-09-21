<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Etiqueta de gestión de la cuota: T.CONOC., P.P. HOMOL., PAGAR.
 *
 * `blocks_payment` es lo que le da consecuencia: cambiar la etiqueta es la
 * acción que bloquea o libera el pago, sin dos campos que sincronizar. El
 * usuario administra etiquetas; el sistema deriva la consecuencia.
 *
 * No es un enum porque pueden aparecer descripciones nuevas y todavía
 * falta confirmar el significado desarrollado de las siglas.
 *
 * @property int $id
 * @property string $code
 * @property string|null $description
 * @property bool $blocks_payment
 * @property bool $is_active
 */
final class HaberManagementLabel extends Model
{
    protected $table = 'haber_management_labels';

    protected $fillable = [
        'code',
        'description',
        'blocks_payment',
        'sort_order',
        'is_active',
    ];

    /**
     * @param  Builder<HaberManagementLabel>  $query
     * @return Builder<HaberManagementLabel>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blocks_payment' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
