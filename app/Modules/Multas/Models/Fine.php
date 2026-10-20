<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use App\Models\User;
use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FineProvenance;
use App\Modules\Multas\Support\FineCaseNumber;
use App\Modules\Multas\Support\FineDocumentNumber;
use App\Modules\Shared\Models\Person;
use App\Support\Database\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * La multa.
 *
 * No guarda su importe: la obligación es la suma de `fine_debt_entries`, y
 * el saldo sale de `fine_balances`. La persona es opcional (M01): la
 * carátula alcanza para identificar al sancionado.
 *
 * @property int $id
 * @property int $fine_case_id
 * @property int|null $person_id
 * @property string $caption_original
 * @property string $caption
 * @property string $currency
 * @property Carbon|null $registered_on
 * @property DatePrecision $registered_precision
 * @property FineProvenance $provenance
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read FineCase $fineCase
 * @property-read Person|null $person
 * @property-read FineBalance|null $balance
 * @property-read User|null $creator
 */
final class Fine extends Model
{
    protected $table = 'fines';

    protected $fillable = [
        'fine_case_id',
        'person_id',
        'caption_original',
        'caption',
        'currency',
        'registered_on',
        'registered_precision',
        'provenance',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registered_on' => 'date',
            'registered_precision' => DatePrecision::class,
            'provenance' => FineProvenance::class,
        ];
    }

    /**
     * Busca por lo que trae el papel que presenta quien viene a pagar
     * (RF-01): número de expediente, de resolución o de acta, o la carátula.
     *
     * El número se compara normalizado —«169607/24» encuentra «169607/2024-0»—
     * y también como texto, para los importados que no se pudieron leer.
     *
     * @param  Builder<Fine>  $query
     * @return Builder<Fine>
     */
    public function scopeBuscar(Builder $query, string $termino): Builder
    {
        $termino = trim($termino);

        if ($termino === '') {
            return $query;
        }

        $texto = Like::contains(Str::lower(Str::ascii($termino)));
        $expediente = FineCaseNumber::parse($termino);
        $documento = FineDocumentNumber::parse($termino);

        // Solo dígitos: puede ser el número del expediente sin el año.
        $soloNumero = ctype_digit($termino) && strlen($termino) <= 9 ? (int) $termino : null;

        return $query->where(function (Builder $multa) use ($texto, $expediente, $documento, $soloNumero): void {
            $multa
                ->whereRaw('lower(f_unaccent(fines.caption)) LIKE ?', [$texto])
                ->orWhereRaw('lower(f_unaccent(fines.caption_original)) LIKE ?', [$texto])
                ->orWhereHas('fineCase', function (Builder $caso) use ($texto, $expediente, $soloNumero): void {
                    $caso->whereRaw('lower(fine_cases.original_text) LIKE ?', [$texto]);

                    if ($soloNumero !== null) {
                        $caso->orWhere('fine_cases.number', $soloNumero);
                    }

                    if ($expediente !== null) {
                        $caso->orWhere(fn (Builder $exacto) => $exacto
                            ->where('fine_cases.number', $expediente->number)
                            ->where('fine_cases.year', $expediente->year));
                    }
                })
                ->orWhereHas('documents', function (Builder $papel) use ($texto, $documento): void {
                    $papel->whereRaw('lower(fine_documents.original_text) LIKE ?', [$texto]);

                    if ($documento !== null) {
                        $papel->orWhere(function (Builder $numero) use ($documento): void {
                            $numero->where('fine_documents.number', $documento->number);

                            if ($documento->year !== null) {
                                $numero->where('fine_documents.year', $documento->year);
                            }
                        });
                    }
                });
        });
    }

    /**
     * @return BelongsTo<FineCase, $this>
     */
    public function fineCase(): BelongsTo
    {
        return $this->belongsTo(FineCase::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * Los documentos con el papel que cumplen. Un mismo documento aparece
     * dos veces si cumple dos papeles.
     *
     * @return BelongsToMany<FineDocument, $this>
     */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(FineDocument::class, 'fine_document_links')
            ->withPivot('role');
    }

    /**
     * @return HasMany<FineDebtEntry, $this>
     */
    public function debtEntries(): HasMany
    {
        return $this->hasMany(FineDebtEntry::class);
    }

    /**
     * @return HasMany<FineHistoricalMovement, $this>
     */
    public function historicalMovements(): HasMany
    {
        return $this->hasMany(FineHistoricalMovement::class);
    }

    /**
     * @return HasMany<FineIdentifierRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(FineIdentifierRevision::class);
    }

    /**
     * @return HasOne<FineBalance, $this>
     */
    public function balance(): HasOne
    {
        return $this->hasOne(FineBalance::class, 'fine_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
