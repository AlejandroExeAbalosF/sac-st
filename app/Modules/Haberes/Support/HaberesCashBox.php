<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Shared\Models\CashBox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo que las pantallas de Haberes pueden mirar.
 *
 * Recepciones y recibos son tablas compartidas: desde que Multas cobra,
 * también guardan lo de otra caja. Haberes muestra y opera solo lo suyo, y lo
 * que no tiene caja, que es lo que existía antes de que hubiera más de una.
 */
final class HaberesCashBox
{
    private ?int $id = null;

    public function id(): int
    {
        return $this->id ??= (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
    }

    public function owns(?int $cashBoxId): bool
    {
        return $cashBoxId === null || $cashBoxId === $this->id();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scope(Builder $query, string $column = 'cash_box_id'): Builder
    {
        return $query->where(fn (Builder $caja) => $caja->whereNull($column)->orWhere($column, $this->id()));
    }
}
