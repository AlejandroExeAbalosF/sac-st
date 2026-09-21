<?php

declare(strict_types=1);

namespace App\Modules\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contexto en el que una persona puede ser elegida.
 *
 * @property int $person_id
 * @property string $role
 * @property string $person_type
 */
final class PersonRole extends Model
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $fillable = ['role', 'person_type'];

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
