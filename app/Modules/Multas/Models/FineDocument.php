<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FineActType;
use App\Modules\Multas\Enums\FineDocumentKind;
use App\Modules\Multas\Enums\FineProvenance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Una resolución, un acta u otro papel del expediente.
 *
 * Sin unicidad todavía (P03): dos documentos con el mismo número en
 * expedientes distintos conviven, y la pantalla avisa la coincidencia.
 *
 * @property int $id
 * @property FineDocumentKind $kind
 * @property FineActType|null $act_type
 * @property int|null $number
 * @property int|null $year
 * @property string $original_text
 * @property Carbon|null $issued_on
 * @property DatePrecision $issued_precision
 * @property FineProvenance $provenance
 * @property int|null $created_by
 */
final class FineDocument extends Model
{
    protected $table = 'fine_documents';

    protected $fillable = [
        'kind',
        'act_type',
        'number',
        'year',
        'original_text',
        'issued_on',
        'issued_precision',
        'provenance',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => FineDocumentKind::class,
            'act_type' => FineActType::class,
            'number' => 'integer',
            'year' => 'integer',
            'issued_on' => 'date',
            'issued_precision' => DatePrecision::class,
            'provenance' => FineProvenance::class,
        ];
    }

    /**
     * Cómo se nombra en las pantallas: «Resolución 649/2026», «Acta HYS 1025288/2025».
     *
     * Si el número no está normalizado se muestra el texto del papel.
     */
    public function label(): string
    {
        if ($this->number === null || $this->kind === FineDocumentKind::Other) {
            return $this->original_text;
        }

        $numero = $this->number.($this->year === null ? '' : '/'.$this->year);

        return $this->kind === FineDocumentKind::Resolution
            ? "Resolución {$numero}"
            : 'Acta '.($this->act_type === null ? '' : $this->act_type->value.' ').$numero;
    }

    /**
     * @return BelongsToMany<FineCase, $this>
     */
    public function cases(): BelongsToMany
    {
        return $this->belongsToMany(FineCase::class, 'fine_case_documents');
    }
}
