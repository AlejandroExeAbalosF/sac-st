<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Models\User;
use App\Support\TemporaryPassword;
use Illuminate\Support\Facades\DB;

/**
 * Alta de un usuario del sistema.
 *
 * No hay registro público: todo usuario nace acá, dado de alta por un
 * administrador. La contraseña la genera el sistema y se le muestra **una
 * sola vez** a quien hace el alta, que se la entrega al titular; la marca
 * `must_change_password` hace que ese conocimiento compartido dure hasta el
 * primer ingreso y ni un minuto más.
 *
 * Se eligió esto antes que el enlace por correo porque el alta no puede
 * depender de que el SMTP del organismo esté disponible: un usuario que no
 * puede entrar el día que lo dan de alta vuelve a trabajar en papel.
 */
final class CreateUser
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    /**
     * @param  array{first_name: string, last_name: string, username: string, document_number: string, email: string, position?: string|null}  $attributes
     * @return array{user: User, password: string} La clave en claro no se guarda ni se vuelve a mostrar.
     */
    public function handle(array $attributes, string $role): array
    {
        $password = TemporaryPassword::generate();

        $user = DB::transaction(function () use ($attributes, $role, $password): User {
            $user = User::query()->create([
                ...$attributes,
                'password' => $password,
                'is_active' => true,
                'must_change_password' => true,
            ]);

            $user->assignRole($role);

            return $user;
        });

        $this->auditar->handle(
            action: 'usuario.creado',
            subject: $user,
            after: [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'username' => $user->username,
                'document_number' => $user->document_number,
                'email' => $user->email,
                'position' => $user->position,
                'role' => $role,
            ],
        );

        return ['user' => $user, 'password' => $password];
    }
}
