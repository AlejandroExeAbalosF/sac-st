<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Models;

use App\Models\User;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\PaymentTerms;
use App\Modules\Shared\Models\Person;
use App\Modules\Shared\Models\PersonBankAccount;
use App\Support\Database\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Derecho total reconocido a un beneficiario dentro de un expediente.
 *
 * @property int $id
 * @property int $expediente_id
 * @property int $haber_number Ordinal dentro del expediente, único ahí.
 * @property int $beneficiary_id
 * @property string $assigned_amount
 * @property int|null $expected_installment_count
 * @property string|null $concept
 * @property HaberWorkflowStatus $workflow_status
 * @property string|null $block_reason
 * @property PaymentTerms $payment_terms
 * @property Carbon|null $legal_date
 * @property string|null $resolution_reference
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property-read Person $beneficiary Nunca falta: lo exige la FK compuesta contra person_roles.
 * @property-read User|null $creator Quién lo cargó, si ese usuario todavía existe.
 * @property-read int $installments_count Solo con `paraListado`.
 * @property-read int $paid_installments_count Solo con `paraListado`.
 */
final class Haber extends Model
{
    protected $table = 'haberes';

    protected $fillable = [
        'expediente_id',
        'haber_number',
        'beneficiary_id',
        'beneficiary_role',
        'default_bank_account_id',
        'assigned_amount',
        'expected_installment_count',
        'payment_terms',
        'concept',
        'legal_date',
        'resolution_reference',
        'workflow_status',
        'block_reason',
        'notes',
        'created_by',
    ];

    /**
     * @return BelongsTo<Expediente, $this>
     */
    public function expediente(): BelongsTo
    {
        return $this->belongsTo(Expediente::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'beneficiary_id');
    }

    /** @return BelongsTo<PersonBankAccount, $this> */
    public function defaultBankAccount(): BelongsTo
    {
        return $this->belongsTo(PersonBankAccount::class, 'default_bank_account_id');
    }

    /**
     * @return HasMany<BeneficiaryInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(BeneficiaryInstallment::class, 'haber_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Todo lo que el listado plano de haberes necesita, en una consulta.
     *
     * Las cuotas se traen **y** se cuentan. Se cuentan porque la fila
     * cerrada dice «dos de tres pagadas» sobre las esperadas, que pueden
     * ser más que las cargadas; se traen porque la fila se despliega para
     * mostrarlas. Vienen con su etiqueta de gestión, que es lo que hace
     * visible la cuota trabada, y ordenadas por número, que es como las
     * nombra el papel.
     *
     * @param  Builder<Haber>  $query
     * @return Builder<Haber>
     */
    public function scopeParaListado(Builder $query): Builder
    {
        return $query
            ->with([
                'beneficiary:id,name,document',
                'expediente:id,display_number,received_date,employer_id',
                'expediente.employer:id,name',
                'installments' => fn ($cuotas) => $cuotas->orderBy('installment_number'),
                'installments.managementLabel:id,code,blocks_payment',
            ])
            ->withCount([
                'installments',
                'installments as paid_installments_count' => fn (Builder $cuotas) => $cuotas
                    ->where('workflow_status', InstallmentWorkflowStatus::Paid),
            ]);
    }

    /**
     * Busca por beneficiario, documento, expediente o empleador.
     *
     * Es la misma pregunta que responde `Expediente::scopeBuscar`, hecha
     * desde el otro lado: quien escribe un DNI en el listado de haberes
     * espera la fila de esa persona, no el expediente que la contiene.
     *
     * @param  Builder<Haber>  $query
     * @return Builder<Haber>
     */
    public function scopeBuscar(Builder $query, ?string $termino): Builder
    {
        $termino = trim((string) $termino);

        if ($termino === '') {
            return $query;
        }

        $texto = Expediente::normalizar($termino);
        $digitos = preg_replace('/\D+/', '', $termino) ?? '';

        return $query->where(function (Builder $haber) use ($texto, $digitos): void {
            $haber
                ->whereHas('beneficiary', function (Builder $persona) use ($texto, $digitos): void {
                    $persona->where('search_name', 'like', Like::contains($texto));

                    if ($digitos !== '') {
                        $persona->orWhere('document', 'like', Like::contains($digitos));
                    }
                })
                ->orWhereHas('expediente', function (Builder $expediente) use ($texto): void {
                    $expediente
                        ->where('search_text', 'like', Like::contains($texto))
                        ->orWhereHas('employer', fn (Builder $persona) => $persona->where('search_name', 'like', Like::contains($texto)));
                });
        });
    }

    /**
     * El derecho reconocido.
     *
     * El cast `decimal:2` garantiza la forma, pero el analizador solo ve
     * `string`. Este accesor es el punto donde esa garantía se declara una
     * vez, en vez de anotarla en cada suma.
     *
     * @return numeric-string
     */
    public function importeAsignado(): string
    {
        /** @var numeric-string $importe */
        $importe = $this->assigned_amount;

        return $importe;
    }

    /**
     * Suma de las cuotas que siguen en pie.
     *
     * Se calcula, no se guarda: un contador almacenado es una copia que se
     * desincroniza el día que alguien anula una cuota por otra vía.
     *
     * @return numeric-string
     */
    public function sumaDeCuotas(): string
    {
        $total = '0.00';

        foreach ($this->installments as $cuota) {
            if ($cuota->workflow_status->value === 'cancelled') {
                continue;
            }

            $total = bcadd($total, $cuota->importeEsperado(), 2);
        }

        /** @var numeric-string $total */
        return $total;
    }

    /**
     * El ordinal se pone solo.
     *
     * Va acá y no en el Action porque es una propiedad del haber, no de
     * una vía de carga: el alta, los sembrados y cualquier importación que
     * venga después tienen que salir numeradas sin acordarse de hacerlo.
     *
     * `max + 1` no puede chocar con un número viejo: acá nada se borra, así
     * que el haber anulado sigue ocupando el suyo. Lo que esto no resuelve
     * es que dos cargas simultáneas lean el mismo máximo; de eso se ocupa
     * el candado de `AddHaber`, y por debajo el índice único.
     */
    protected static function booted(): void
    {
        self::creating(function (Haber $haber): void {
            $haber->haber_number ??= (int) static::query()
                ->where('expediente_id', $haber->expediente_id)
                ->max('haber_number') + 1;
        });
    }

    /**
     * Lo que el haber muestra en la barra de direcciones.
     *
     * Su ordinal dentro del expediente, no su id. El id es un contador
     * global —dos haberes del mismo expediente podían ser el 3 y el 148—
     * y no le decía nada a nadie; el ordinal se lee como el papel.
     *
     * Solo es único **dentro** del expediente, así que todas las rutas del
     * haber cuelgan de él. No es una decisión de diseño de URL: es lo que
     * impide que `route('haberes.haber.cancel', $haber)` arme una
     * dirección que apunte a otro haber.
     */
    public function getRouteKey(): string
    {
        return (string) $this->haber_number;
    }

    /**
     * Resuelve la dirección de vuelta al haber.
     *
     * El expediente viene antes en la URL, así que a esta altura ya está
     * resuelto y es un modelo: el número se busca adentro de él.
     */
    public function resolveRouteBinding($value, $field = null): ?static
    {
        $expediente = request()->route()?->parameter('expediente');

        if (! $expediente instanceof Expediente) {
            return null;
        }

        return $this->newQuery()
            ->where('expediente_id', $expediente->getKey())
            ->where('haber_number', $value)
            ->first();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'workflow_status' => HaberWorkflowStatus::class,
            'payment_terms' => PaymentTerms::class,
            'assigned_amount' => 'decimal:2',
            'legal_date' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
