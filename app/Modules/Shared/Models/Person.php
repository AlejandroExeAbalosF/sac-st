<?php

declare(strict_types=1);

namespace App\Modules\Shared\Models;

use App\Concerns\HasGeneratedColumns;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Persona u organización del maestro transversal.
 *
 * @property int $id
 * @property string $type
 * @property string|null $first_name Solo cuando es persona física.
 * @property string|null $last_name Solo cuando es persona física.
 * @property string|null $legal_name Solo cuando es organización.
 * @property int|null $owner_person_id El titular; solo cuando es organización.
 * @property-read string $name La calcula PostgreSQL sobre las tres de arriba.
 * @property-read string $search_name La calcula PostgreSQL: el nombre, normalizado.
 * @property string|null $document
 * @property string|null $tax_identifier El CUIL, cuando el papel lo trajo. Lo ata al DNI un CHECK.
 * @property string|null $address Lo pide el formulario de la Orden de Pago.
 * @property string|null $phone
 * @property bool $is_active
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Person extends Model
{
    use HasGeneratedColumns;

    /**
     * `name` y `search_name` no están: las calcula la base a partir de las
     * tres columnas de nombre, y asignarlas desde PHP sería un error de
     * escritura sobre una columna generada.
     *
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'first_name',
        'last_name',
        'legal_name',
        'owner_person_id',
        'document',
        'tax_identifier',
        'address',
        'phone',
        'is_active',
        'created_by',
    ];

    /**
     * @return HasMany<PersonRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(PersonRole::class);
    }

    /**
     * @return HasMany<PersonBankAccount, $this>
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(PersonBankAccount::class);
    }

    /**
     * Quien está al frente de la organización.
     *
     * Es una ficha del maestro y no un nombre copiado, porque puede ser
     * alguien que ya está cargado por otro motivo. Mientras no tenga un rol
     * en `person_roles` no aparece en los buscadores de empleador ni de
     * beneficiario.
     *
     * @return BelongsTo<Person, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(self::class, 'owner_person_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<Person>  $query
     * @return Builder<Person>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Person>  $query
     * @return Builder<Person>
     */
    public function scopeForRole(Builder $query, string $role): Builder
    {
        return $query->whereHas('roles', fn (Builder $roles) => $roles->where('role', $role));
    }

    public static function normalizeName(string $name): string
    {
        return Str::lower(Str::ascii($name));
    }

    /**
     * El nombre para mostrar, armado como lo arma la base.
     *
     * Es el espejo en PHP de la columna generada `people.name`. Existe
     * porque las validaciones que evitan duplicados tienen que consultar
     * `search_name` **antes** del insert, y en ese momento la fila todavía
     * no existe. Si la expresión de la migración cambia, esta también.
     */
    public static function composeName(string $type, ?string $firstName, ?string $lastName, ?string $legalName): string
    {
        if ($type === 'individual') {
            return trim((string) $lastName).', '.trim((string) $firstName);
        }

        return trim((string) $legalName);
    }

    /**
     * @return array{id:int,name:string,document:string|null,type:string}
     */
    public function toOption(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'document' => $this->document,
            'type' => $this->type,
        ];
    }

    /**
     * @return list<string>
     */
    protected function generatedColumns(): array
    {
        return ['name', 'search_name'];
    }

    /**
     * @return list<string>
     */
    protected function generatedFrom(): array
    {
        return ['type', 'first_name', 'last_name', 'legal_name'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
