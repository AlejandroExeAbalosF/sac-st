<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\ProfileValidationRules;
use App\Modules\Shared\Actions\CreateUser;
use App\Modules\Shared\Enums\SystemRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Alta de un administrador desde la consola del servidor.
 *
 * Existe porque una instalación nueva no tiene a nadie que pueda dar altas
 * desde la pantalla de usuarios, y en producción el seeder no crea ningún
 * usuario. Sirve también de rescate si todos los administradores quedan
 * bloqueados.
 *
 * La contraseña no entra por ningún lado —ni por opción, ni por variable de
 * entorno, ni por un seeder—: la genera `CreateUser`, igual que en un alta
 * desde la pantalla, se muestra una sola vez y el primer ingreso obliga a
 * cambiarla. Así no queda escrita en el historial del shell, en un archivo
 * del servidor ni, siendo el repo público, en ningún lugar que se pueda leer.
 */
final class CreateAdministrator extends Command
{
    use ProfileValidationRules;

    protected $signature = 'usuarios:crear-administrador
        {--nombre= : Nombre de pila}
        {--apellido= : Apellido}
        {--usuario= : Nombre de usuario (minúsculas, sin espacios)}
        {--dni= : DNI, solo dígitos}
        {--email= : Correo electrónico}
        {--rol= : administrador o super-admin}';

    protected $description = 'Da de alta un administrador con una contraseña temporal que se muestra una sola vez';

    public function handle(CreateUser $createUser): int
    {
        $datos = $this->normalizar([
            'firstName' => $this->valor('nombre', 'Nombre'),
            'lastName' => $this->valor('apellido', 'Apellido'),
            'username' => $this->valor('usuario', 'Nombre de usuario'),
            'documentNumber' => $this->valor('dni', 'DNI'),
            'email' => $this->valor('email', 'Correo electrónico'),
            'role' => $this->rol(),
        ]);

        $validator = Validator::make($datos, [
            ...$this->profileRules(),
            'role' => ['required', Rule::in(self::rolesPermitidos())],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        ['user' => $user, 'password' => $password] = $createUser->handle([
            'first_name' => $datos['firstName'],
            'last_name' => $datos['lastName'],
            'username' => $datos['username'],
            'document_number' => $datos['documentNumber'],
            'email' => $datos['email'],
        ], $datos['role']);

        $this->components->info("Se creó {$user->username} con el rol {$datos['role']}.");
        $this->components->twoColumnDetail('Contraseña temporal', $password);
        $this->components->warn('No se vuelve a mostrar. En el primer ingreso el sistema pide cambiarla.');

        return self::SUCCESS;
    }

    /**
     * Solo los roles que pueden dar altas desde la pantalla: para un usuario
     * común ya está el módulo de usuarios.
     *
     * @return list<string>
     */
    private static function rolesPermitidos(): array
    {
        return [SystemRole::Administrador->value, SystemRole::SuperAdmin->value];
    }

    private function valor(string $opcion, string $etiqueta): string
    {
        $valor = $this->option($opcion);

        if (is_string($valor)) {
            return $valor;
        }

        // Sin terminal no hay a quién preguntarle: vacío, y la validación lo
        // rechaza como obligatorio con el nombre del campo.
        if (! $this->input->isInteractive()) {
            return '';
        }

        return text(label: $etiqueta, required: true);
    }

    private function rol(): string
    {
        $valor = $this->option('rol');

        if (is_string($valor)) {
            return $valor;
        }

        if (! $this->input->isInteractive()) {
            return SystemRole::Administrador->value;
        }

        return (string) select(
            label: 'Rol',
            options: self::rolesPermitidos(),
            default: SystemRole::Administrador->value,
        );
    }

    /**
     * La misma limpieza que el alta desde la pantalla (StoreUserRequest):
     * el CHECK de la tabla exige el usuario en minúscula, y un espacio de
     * más no tiene que ser motivo de rechazo.
     *
     * @param  array<string, string>  $datos
     * @return array<string, string>
     */
    private function normalizar(array $datos): array
    {
        return [
            ...$datos,
            'firstName' => (string) preg_replace('/\s+/u', ' ', trim($datos['firstName'])),
            'lastName' => (string) preg_replace('/\s+/u', ' ', trim($datos['lastName'])),
            'username' => mb_strtolower(trim($datos['username'])),
            'documentNumber' => trim($datos['documentNumber']),
            'email' => mb_strtolower(trim($datos['email'])),
        ];
    }
}
