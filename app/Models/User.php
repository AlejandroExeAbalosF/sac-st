<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasGeneratedColumns;
use App\Modules\Shared\Models\UserLoginEvent;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Usuario del sistema.
 *
 * Se autentica por `username`, no por email. El email y el DNI son
 * obligatorios en el alta (ver la migración que los impone y por qué
 * diverge del DER §9.1).
 *
 * `User` se queda en `App\Models` —y no dentro de un módulo— porque el
 * framework y varios paquetes lo asumen ahí.
 *
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property-read string $name «Apellido, Nombre». La calcula PostgreSQL.
 * @property string $username
 * @property string $document_number
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_active
 * @property bool $must_change_password
 * @property Carbon|null $last_login_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
// `name` no está: la calcula la base a partir del apellido y el nombre.
#[Fillable(['first_name', 'last_name', 'username', 'document_number', 'position', 'email', 'password', 'is_active', 'must_change_password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasGeneratedColumns, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Historial de accesos del usuario, del más reciente al más antiguo.
     *
     * @return HasMany<UserLoginEvent, $this>
     */
    public function loginEvents(): HasMany
    {
        return $this->hasMany(UserLoginEvent::class)->latest('created_at');
    }

    /**
     * Un usuario inactivo conserva todo su historial pero no puede entrar.
     * Nunca se borra: sus acciones pasadas tienen que seguir siendo
     * atribuibles.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * @return list<string>
     */
    protected function generatedColumns(): array
    {
        return ['name'];
    }

    /**
     * @return list<string>
     */
    protected function generatedFrom(): array
    {
        return ['first_name', 'last_name'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }
}
