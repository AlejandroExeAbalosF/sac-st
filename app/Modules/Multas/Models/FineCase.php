<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use App\Models\User;
use App\Modules\Multas\Enums\FineProvenance;
use App\Modules\Multas\Support\FineCaseNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * El expediente de Multas.
 *
 * No es el expediente de Haberes: ese registra haberes en consignación y
 * vive en otro módulo. Este identifica el trámite que originó una o varias
 * multas, con los documentos que le pertenecen.
 *
 * El número se guarda como vino (`original_text`, inmutable) y normalizado
 * (`number`, `year`, `suffix`), que solo cambia por una revisión.
 *
 * @property int $id
 * @property string $original_text
 * @property int|null $number
 * @property int|null $year
 * @property string|null $suffix
 * @property FineProvenance $provenance
 * @property string|null $notes
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read User|null $creator
 */
final class FineCase extends Model
{
    protected $table = 'fine_cases';

    protected $fillable = [
        'original_text',
        'number',
        'year',
        'suffix',
        'provenance',
        'notes',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'year' => 'integer',
            'provenance' => FineProvenance::class,
        ];
    }

    /** «169607/2024-0», o el texto original si el número no está normalizado. */
    public function label(): string
    {
        return FineCaseNumber::format($this->number, $this->year, $this->suffix) ?? $this->original_text;
    }

    /**
     * @return HasMany<Fine, $this>
     */
    public function fines(): HasMany
    {
        return $this->hasMany(Fine::class);
    }

    /**
     * @return BelongsToMany<FineDocument, $this>
     */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(FineDocument::class, 'fine_case_documents');
    }

    /**
     * @return HasMany<FineIdentifierRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(FineIdentifierRevision::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
