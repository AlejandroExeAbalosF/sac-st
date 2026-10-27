<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Un motivo: el código de la lista del acto y, si hace falta, el detalle.
 *
 * Se guarda en dos columnas —el código para contar, el detalle para
 * entender— y se lee junto: «Error de carga: el importe tenía un cero de
 * más». Con «Otro» el detalle es obligatorio y es el motivo. La base lo
 * impone en cada tabla; esto lo valida antes y arma la lectura.
 *
 * Las listas son de cada módulo (`ReasonCode`, `ReasonAct`); la pantalla
 * las recibe en la prop `motivos`, por acto, desde `options()`.
 */
final readonly class Reason
{
    public const string OTHER = 'other';

    public ?string $detail;

    public function __construct(public ReasonCode $code, ?string $detail = null, string $detailField = 'reason')
    {
        $limpio = trim((string) $detail);
        $this->detail = $limpio === '' ? null : $limpio;

        if ($code->value === self::OTHER && $this->detail === null) {
            throw ValidationException::withMessages([$detailField => 'Con «Otro», contá el motivo en el detalle.']);
        }
    }

    /**
     * El motivo de un Form Request validado con `rules()`; nulo si no se eligió.
     *
     * @param  array<string, mixed>  $validado
     */
    public static function fromValidated(array $validado, ReasonAct $acto, string $detailField = 'reason'): ?self
    {
        $valor = $validado['reasonCode'] ?? null;

        if ($valor === null || $valor === '') {
            return null;
        }

        foreach ($acto->codes() as $codigo) {
            if ($codigo->value === $valor) {
                $detalle = $validado[$detailField] ?? null;

                return new self($codigo, $detalle === null ? null : (string) $detalle, $detailField);
            }
        }

        throw new LogicException("El motivo «{$valor}» no es de este acto: la validación tenía que rechazarlo.");
    }

    /**
     * Las reglas del motivo de un acto. Opcional solo donde el Action decide
     * si hace falta, como al completar o corregir un dato.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(ReasonAct $acto, bool $required = true, string $detailField = 'reason', int $max = 500): array
    {
        return [
            'reasonCode' => [
                $required ? 'required' : 'nullable',
                Rule::in(array_map(static fn (ReasonCode $codigo): string|int => $codigo->value, $acto->codes())),
            ],
            $detailField => ['nullable', 'string', 'max:'.$max, 'required_if:reasonCode,'.self::OTHER],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $detailField = 'reason'): array
    {
        return [
            'reasonCode.required' => 'Elegí el motivo.',
            'reasonCode.in' => 'Ese motivo no corresponde a este acto.',
            $detailField.'.required_if' => 'Con «Otro», contá el motivo en el detalle.',
        ];
    }

    /**
     * Lo que ofrece cada pantalla, por acto y en el orden de la lista.
     *
     * @param  class-string<ReasonAct>  $actos
     * @return array<string, list<array{value: string|int, label: string}>>
     */
    public static function options(string $actos): array
    {
        $opciones = [];

        foreach ($actos::cases() as $acto) {
            $opciones[(string) $acto->value] = array_map(
                static fn (ReasonCode $codigo): array => ['value' => $codigo->value, 'label' => $codigo->label()],
                $acto->codes(),
            );
        }

        return $opciones;
    }

    /** Cómo se lee en la ficha y en la auditoría. */
    public function describe(): string
    {
        return self::stored($this->code, $this->detail);
    }

    /** Lo mismo, desde lo guardado: una fila puede no tener motivo. */
    public static function text(?ReasonCode $code, ?string $detail): ?string
    {
        return $code === null ? $detail : self::stored($code, $detail);
    }

    /** Lo guardado de una fila que siempre tiene motivo. */
    public static function stored(ReasonCode $code, ?string $detail): string
    {
        $detalle = trim((string) $detail);

        return match (true) {
            $detalle === '' => $code->label(),
            // Con «Otro» el detalle es el motivo: la etiqueta no agrega nada.
            $code->value === self::OTHER => $detalle,
            default => $code->label().': '.$detalle,
        };
    }
}
