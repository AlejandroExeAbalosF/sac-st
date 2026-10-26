<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Models;

use App\Models\User;
use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Shared\Models\Person;
use App\Support\Database\Like;
use App\Support\Routing\ReadableRouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Expediente de SiCE con los haberes que reconoce.
 *
 * No registra dinero: registra el derecho. Lo recibido y lo pagado son
 * conceptos distintos y viven en el módulo Ledger.
 *
 * @property int $id
 * @property string|null $source_system
 * @property string|null $external_id
 * @property string|null $canonical_number
 * @property string $display_number
 * @property string|null $external_reference
 * @property string|null $subject
 * @property int|null $employer_id
 * @property string|null $employer_representative
 * @property string|null $notes
 * @property string|null $declared_total_amount
 * @property ExpedienteStatus $status
 * @property Carbon|null $received_date
 * @property Carbon|null $created_at
 * @property-read Person|null $employer El expediente puede no tener empleador cargado.
 * @property-read User|null $creator Quién lo cargó, si ese usuario todavía existe.
 */
final class Expediente extends Model
{
    use ReadableRouteKey;

    protected $table = 'expedientes';

    protected $fillable = [
        'source_system',
        'external_id',
        'canonical_number',
        'display_number',
        'external_reference',
        'year',
        'received_date',
        'employer_id',
        'employer_role',
        'employer_representative',
        'subject',
        'notes',
        'declared_total_amount',
        'extra_data',
        'status',
        'created_by',
    ];

    /**
     * @return BelongsTo<Person, $this>
     */
    public function employer(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'employer_id');
    }

    /**
     * @return HasMany<Haber, $this>
     */
    public function haberes(): HasMany
    {
        return $this->hasMany(Haber::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Todo lo que la lista necesita, en una sola consulta.
     *
     * Sin esto la pantalla hace una consulta por expediente para los
     * haberes y otra por haber para el beneficiario: con cincuenta filas
     * son ciento cincuenta viajes a la base para una tabla.
     *
     * @param  Builder<Expediente>  $query
     * @return Builder<Expediente>
     */
    public function scopeParaListado(Builder $query): Builder
    {
        return $query->with([
            'employer:id,name',
            'haberes' => fn ($haberes) => $haberes->orderBy('id'),
            'haberes.beneficiary:id,name,document',
            'haberes.creator:id,name',
            'haberes.installments' => fn ($cuotas) => $cuotas->orderBy('installment_number'),
            'haberes.installments.managementLabel:id,code,blocks_payment',
        ]);
    }

    /**
     * Busca por número, carátula, empleador, beneficiario o documento.
     *
     * @param  Builder<Expediente>  $query
     * @return Builder<Expediente>
     */
    public function scopeBuscar(Builder $query, ?string $termino): Builder
    {
        $termino = trim((string) $termino);

        if ($termino === '') {
            return $query;
        }

        $texto = self::normalizar($termino);
        $digitos = preg_replace('/\D+/', '', $termino) ?? '';

        return $query->where(function (Builder $expediente) use ($texto, $digitos): void {
            $expediente
                ->where('search_text', 'like', Like::contains($texto))
                ->orWhereHas('employer', fn (Builder $persona) => $persona->where('search_name', 'like', Like::contains($texto)))
                ->orWhereHas('haberes.beneficiary', function (Builder $persona) use ($texto, $digitos): void {
                    $persona->where('search_name', 'like', Like::contains($texto));

                    if ($digitos !== '') {
                        $persona->orWhere('document', 'like', Like::contains($digitos));
                    }
                });
        });
    }

    /**
     * Normaliza el término de búsqueda igual que la columna generada
     * `search_text`, que del lado de PostgreSQL usa `lower(f_unaccent(…))`.
     * Para el castellano las dos coinciden: á→a, ñ→n.
     */
    public static function normalizar(string $texto): string
    {
        return Str::lower(Str::ascii($texto));
    }

    /**
     * El número en la dirección: el completo de SiCE con guiones,
     * `0030064-125957-2026-0`. No la forma corta: `125957/2026` no dice la
     * instancia y no es única. `null` si el expediente no tiene número
     * canónico; entonces va solo con el id. Ver `ReadableRouteKey`.
     */
    public function routeNumber(): ?string
    {
        return $this->canonical_number === null ? null : str_replace('/', '-', $this->canonical_number);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ExpedienteStatus::class,
            'received_date' => 'date',
            'declared_total_amount' => 'decimal:2',
            'extra_data' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
