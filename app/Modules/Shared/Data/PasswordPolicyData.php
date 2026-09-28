<?php

declare(strict_types=1);

namespace App\Modules\Shared\Data;

use Illuminate\Validation\Rules\Password;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * La política de contraseñas, tal como la aplica el servidor.
 *
 * Existe para que la pantalla muestre los requisitos mientras se escribe
 * **sin tener su propia copia de ellos**. Se arma leyendo
 * `Password::defaults()` —la misma regla que valida el `FormRequest`—, así
 * que el día que se cambie la política en `AppServiceProvider` el checklist
 * cambia solo. Lo que el front evalúa es una ayuda; lo que decide sigue
 * siendo el servidor.
 */
#[TypeScript]
final class PasswordPolicyData extends Data
{
    public function __construct(
        public int $minLength,
        public ?int $maxLength,
        public bool $mixedCase,
        public bool $letters,
        public bool $numbers,
        public bool $symbols,
        /**
         * Si se consulta contra bases de filtraciones conocidas. Solo el
         * servidor puede hacerlo, así que la pantalla lo anuncia como algo
         * que se verifica al guardar.
         */
        public bool $uncompromised,
    ) {}

    public static function fromDefaults(): self
    {
        $rules = Password::defaults()->appliedRules();

        return new self(
            minLength: (int) $rules['min'],
            maxLength: $rules['max'] === null ? null : (int) $rules['max'],
            mixedCase: (bool) $rules['mixedCase'],
            letters: (bool) $rules['letters'],
            numbers: (bool) $rules['numbers'],
            symbols: (bool) $rules['symbols'],
            uncompromised: (bool) $rules['uncompromised'],
        );
    }
}
