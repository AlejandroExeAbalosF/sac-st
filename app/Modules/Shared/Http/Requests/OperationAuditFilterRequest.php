<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Requests;

use App\Models\User;
use App\Modules\Shared\Audit\AuditCatalog;
use App\Modules\Shared\Audit\AuditSubjectResolver;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Los filtros de la auditoría de operaciones.
 *
 * Los usa la pantalla y el Excel: lo que se descarga es exactamente lo que
 * se estaba mirando. Un filtro inválido vuelve a la pantalla sin filtros y
 * con el error al lado del campo; no se ignora en silencio, porque una
 * auditoría que muestra otra cosa que la pedida sin avisar es peor que
 * ninguna.
 */
final class OperationAuditFilterRequest extends FormRequest
{
    /** El valor de `usuario` para los eventos que no provocó una persona. */
    public const SYSTEM_USER = 'sistema';

    protected $redirectRoute = 'configuracion.auditoria.index';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $catalogo = app(AuditCatalog::class);

        return [
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'usuario' => ['nullable', 'string', $this->usuarioValido(...)],
            'accion' => ['nullable', 'string', Rule::in($catalogo->codes())],
            'entidad' => ['nullable', 'string', Rule::in(array_map(
                fn (AuditSubjectResolver $sujeto): string => $sujeto->subjectType(),
                $catalogo->subjects(),
            ))],
            'criticas' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hasta.after_or_equal' => 'La fecha «hasta» no puede ser anterior a «desde».',
            'accion.in' => 'Esa acción no está en el catálogo de auditoría.',
            'entidad.in' => 'Ese tipo de entidad no está en el catálogo de auditoría.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'desde' => 'desde',
            'hasta' => 'hasta',
            'usuario' => 'usuario',
            'accion' => 'acción',
            'entidad' => 'entidad',
            'criticas' => 'solo críticas',
        ];
    }

    /**
     * Los filtros ya validados, con la forma que usa la consulta.
     *
     * @return array{desde: string|null, hasta: string|null, usuario: int|'sistema'|null, accion: string|null, entidad: string|null, criticas: bool}
     */
    public function filters(): array
    {
        $usuario = $this->validated('usuario');

        return [
            'desde' => $this->texto('desde'),
            'hasta' => $this->texto('hasta'),
            'usuario' => match (true) {
                $usuario === null || $usuario === '' => null,
                $usuario === self::SYSTEM_USER => self::SYSTEM_USER,
                default => (int) $usuario,
            },
            'accion' => $this->texto('accion'),
            'entidad' => $this->texto('entidad'),
            'criticas' => $this->boolean('criticas'),
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['desde', 'hasta', 'usuario', 'accion', 'entidad'] as $campo) {
            if (is_string($this->query($campo))) {
                $this->merge([$campo => trim((string) $this->query($campo))]);
            }
        }
    }

    private function texto(string $campo): ?string
    {
        $valor = $this->validated($campo);

        return is_string($valor) && $valor !== '' ? $valor : null;
    }

    /**
     * Un usuario que existe, o el sistema.
     *
     * Se valida que exista y no solo que sea un número: un id inventado
     * devolvería una lista vacía que se lee como «no hizo nada», y esa es
     * una conclusión que la pantalla no puede sacar por un error de tipeo.
     */
    private function usuarioValido(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === self::SYSTEM_USER) {
            return;
        }

        if (! is_string($value) || ctype_digit($value) === false || ! User::query()->whereKey((int) $value)->exists()) {
            $fail('Ese usuario no existe.');
        }
    }
}
