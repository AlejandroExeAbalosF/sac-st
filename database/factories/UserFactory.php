<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'username' => $this->uniqueUsername(),
            'document_number' => (string) fake()->unique()->numberBetween(10_000_000, 49_999_999),
            'email' => fake()->unique()->safeEmail(),
            // El cargo y las dos banderas se declaran aunque la tabla tenga
            // default: `Model::shouldBeStrict` hace que leer un atributo que
            // el modelo no trae explote, y `create()` no relee la fila, así
            // que el default de la base no llega al objeto.
            'position' => null,
            'is_active' => true,
            'must_change_password' => false,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Genera un username que respeta el CHECK de la tabla: minúsculas,
     * sin espacios ni acentos, al menos tres caracteres. El locale es_AR
     * de Faker produce nombres con tildes, así que no alcanza con
     * `userName()` a secas.
     */
    private function uniqueUsername(): string
    {
        $base = Str::lower(Str::ascii(fake()->firstName().'.'.fake()->lastName()));
        $base = preg_replace('/[^a-z0-9._-]/', '', $base) ?? 'usuario';

        return Str::limit($base, 45, '').'.'.fake()->unique()->numberBetween(1000, 999999);
    }

    /**
     * Un usuario dado de baja: conserva su historial y no puede ingresar.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Un usuario recién dado de alta, con la contraseña que le entregó el
     * administrador todavía sin cambiar.
     */
    public function mustChangePassword(): static
    {
        return $this->state(fn (array $attributes) => [
            'must_change_password' => true,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
