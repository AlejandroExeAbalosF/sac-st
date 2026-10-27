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
 * @property int $fine_number El orden dentro del expediente; lo pone la base al insertar.
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
     * La ficha de la multa: `/multas/expedientes/12/169607-2024-0/multa/2`.
     *
     * Cuelga de su expediente y va por su número dentro de él, no por el id
     * global ni por la carátula, que puede ser el nombre de una persona. Las
     * acciones sobre la multa siguen por el id: no se leen ni se comparten.
     *
     * @return array{fineCase: FineCase, fine: self}
     */
    public function showParameters(): array
    {
        return ['fineCase' => $this->fineCase, 'fine' => $this];
    }

    /**
     * Lo mismo cuando solo se tiene el id: un ajuste, un pago o un
     * antecedente saben de qué multa son, pero no la traen cargada.
     *
     * @return array{fineCase: FineCase, fine: self}
     */
    public static function showParametersFor(int $id): array
    {
        return self::query()->with('fineCase')->findOrFail($id)->showParameters();
    }

    /**
     * El número lo pone la base al insertar (`fines_assign_number`), y
     * Eloquent no relee lo que asigna un trigger: sin esto, la multa recién
     * creada no sabría su número y no podría armar su dirección.
     */
    protected static function booted(): void
    {
        self::created(function (Fine $multa): void {
            if (! array_key_exists('fine_number', $multa->getAttributes())) {
                $multa->setAttribute('fine_number', (int) self::query()->whereKey($multa->id)->value('fine_number'));
                $multa->syncOriginalAttribute('fine_number');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fine_number' => 'integer',
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
     * dos veces si cumple dos papeles. Solo los vínculos vigentes: uno
     * anulado ya no dice nada de la multa.
     *
     * @return BelongsToMany<FineDocument, $this>
     */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(FineDocument::class, 'fine_document_links')
            ->withPivot('role')
            ->wherePivotNull('revoked_at');
    }

    /**
     * Los documentos que se desvincularon, con el papel, el motivo y quién.
     *
     * @return BelongsToMany<FineDocument, $this>
     */
    public function revokedDocuments(): BelongsToMany
    {
        return $this->belongsToMany(FineDocument::class, 'fine_document_links')
            ->withPivot('role', 'revoked_at', 'revoked_by', 'revocation_reason_code', 'revocation_reason')
            ->wherePivotNotNull('revoked_at');
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
     * @return HasMany<FinePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(FinePayment::class)->orderBy('id');
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
