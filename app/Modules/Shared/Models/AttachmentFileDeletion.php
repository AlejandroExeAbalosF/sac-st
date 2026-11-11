<?php

declare(strict_types=1);

namespace App\Modules\Shared\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * El borrado pendiente del archivo de un adjunto eliminado.
 *
 * Se anota en la misma transacción que elimina la fila del adjunto: si
 * después el disco falla o el proceso se corta, el archivo no queda
 * huérfano sin que nadie lo sepa. La base solo deja registrar intentos y,
 * una vez, el borrado logrado.
 *
 * @property int $id
 * @property int $attachment_id
 * @property string $disk
 * @property string $object_key
 * @property int $requested_by
 * @property CarbonInterface $requested_at
 * @property CarbonInterface|null $deleted_at
 * @property int $attempts
 * @property string|null $last_error
 */
final class AttachmentFileDeletion extends Model
{
    public $timestamps = false;

    protected $fillable = ['attachment_id', 'disk', 'object_key', 'requested_by'];

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('deleted_at');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'deleted_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
