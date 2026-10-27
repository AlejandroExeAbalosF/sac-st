<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Modules\Multas\Enums\FineReasonAct;
use App\Modules\Multas\Enums\FineReasonCode;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Un motivo: el código de la lista y, si hace falta, el detalle.
 *
 * Se guarda en dos columnas —el código para contar, el detalle para
 * entender— y se muestra junto: «Error de carga: el importe tenía un cero de
 * más». Con «Otro» el detalle es obligatorio; la base lo impone en cada
 * tabla.
 */
final readonly class FineReason
{
    public ?string $detail;

    public function __construct(public FineReasonCode $code, ?string $detail = null)
    {
        $limpio = trim((string) $detail);
        $this->detail = $limpio === '' ? null : $limpio;

        if ($code === FineReasonCode::Other && $this->detail === null) {
            throw ValidationException::withMessages(['reason' => 'Con «Otro», contá el motivo en el detalle.']);
        }
    }

    /**
     * El motivo de un Form Request validado con `rules()`; nulo si no se eligió.
     *
     * @param  array<string, mixed>  $validado
     */
    public static function fromValidated(array $validado): ?self
    {
        $codigo = $validado['reasonCode'] ?? null;

        return $codigo === null || $codigo === ''
            ? null
            : new self(FineReasonCode::from((string) $codigo), isset($validado['reason']) ? (string) $validado['reason'] : null);
    }

    /**
     * Las reglas del motivo de un acto. Opcional solo donde el Action decide
     * si hace falta, como al completar o corregir un dato.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(FineReasonAct $acto, bool $required = true): array
    {
        return [
            'reasonCode' => [$required ? 'required' : 'nullable', Rule::enum(FineReasonCode::class)->only($acto->codes())],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:reasonCode,'.FineReasonCode::Other->value],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'reasonCode.required' => 'Elegí el motivo.',
            'reasonCode.enum' => 'Ese motivo no corresponde a este acto.',
            'reason.required_if' => 'Con «Otro», contá el motivo en el detalle.',
        ];
    }

    /** Cómo se lee en la ficha y en la auditoría. */
    public function describe(): string
    {
        return self::text($this->code, $this->detail);
    }

    /** Lo mismo, desde lo guardado: una fila puede no tener motivo. */
    public static function text(FineReasonCode|string|null $code, ?string $detail): ?string
    {
        return $code === null ? $detail : self::stored($code, $detail);
    }

    /** Lo guardado de una fila que siempre tiene motivo. */
    public static function stored(FineReasonCode|string $code, ?string $detail): string
    {
        $codigo = $code instanceof FineReasonCode ? $code : FineReasonCode::from($code);
        $detalle = trim((string) $detail);

        return match (true) {
            $detalle === '' => $codigo->label(),
            // Con «Otro» el detalle es el motivo: la etiqueta no agrega nada.
            $codigo === FineReasonCode::Other => $detalle,
            default => $codigo->label().': '.$detalle,
        };
    }
}
