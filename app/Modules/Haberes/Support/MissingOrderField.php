<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

/**
 * Un dato que la Orden necesita y el sistema todavía no tiene.
 *
 * **No es un error de validación de un formulario.** Es un hueco del
 * maestro —el beneficiario nunca tuvo domicilio cargado, el expediente no
 * anotó su fecha de ingreso— que el circuito recién descubre al querer
 * imprimir un papel que lo pide.
 *
 * Por eso viaja con su `section`: el modal que emite la Orden agrupa por
 * ahí, y quien lo abre completa cada bloque en su lugar en vez de salir a
 * buscar seis pantallas distintas.
 *
 * **La distinción entre obligatorio y sugerido es del papel, no del
 * modelo.** El formulario tolera renglones en blanco —la Orden 3582 sale
 * con el teléfono de la empresa vacío— y el área lo firma igual. Lo que no
 * tolera es que falte lo que identifica a las partes o a la cuenta: sin
 * eso el organismo no sabe a quién ni desde dónde transferir.
 */
final readonly class MissingOrderField
{
    public function __construct(
        /** Identificador estable: `beneficiary.address`. */
        public string $code,
        /** El bloque del modal en el que se completa. */
        public string $section,
        /** Cómo se llama en la pantalla. */
        public string $label,
        /** Por qué hace falta, en términos del papel que lo pide. */
        public string $reason,
        /** Si su ausencia impide emitir, o solo deja un renglón en blanco. */
        public bool $required = true,
    ) {}

    public static function beneficiaryDocument(): self
    {
        return new self(
            'beneficiary.document',
            'beneficiary',
            'DNI o CUIT del beneficiario',
            'El Pase pide la transferencia «a favor de» una persona identificada por su documento.',
        );
    }

    public static function beneficiaryAddress(): self
    {
        return new self(
            'beneficiary.address',
            'beneficiary',
            'Domicilio del beneficiario',
            'La Orden lo imprime debajo del nombre.',
        );
    }

    public static function beneficiaryPhone(): self
    {
        return new self(
            'beneficiary.phone',
            'beneficiary',
            'Teléfono del beneficiario',
            'La Orden tiene el renglón y el área lo usa para avisarle que cobre.',
            required: false,
        );
    }

    public static function verifiedAccount(): self
    {
        return new self(
            'account.verified',
            'account',
            'CBU verificado del beneficiario',
            'El sistema opera únicamente con CBU y la cuenta tiene que estar verificada: '
            .'una que no resulte un CBU válido —un CVU de billetera, por ejemplo— no habilita la Orden.',
        );
    }

    public static function employer(): self
    {
        return new self(
            'employer.missing',
            'employer',
            'Empleador del expediente',
            'La Orden imprime la empresa que depositó, y sin ella el bloque queda entero en blanco.',
        );
    }

    public static function employerTaxIdentifier(): self
    {
        return new self(
            'employer.taxIdentifier',
            'employer',
            'CUIT del empleador',
            'Va en el renglón «DNI/CUIT» del bloque de la empresa. '
            .'El área confirmó que un empleador puede no tenerlo cargado.',
            required: false,
        );
    }

    public static function employerAddress(): self
    {
        return new self(
            'employer.address',
            'employer',
            'Domicilio del empleador',
            'Va en el renglón «DOMICILIO» del bloque de la empresa.',
            required: false,
        );
    }

    public static function employerPhone(): self
    {
        return new self(
            'employer.phone',
            'employer',
            'Teléfono del empleador',
            'La Orden 3582 lo deja en blanco, así que no frena nada.',
            required: false,
        );
    }

    public static function custodyStartDate(): self
    {
        return new self(
            'expediente.custodyStartDate',
            'expediente',
            'Fecha de ingreso del expediente',
            'Es la «FECHA INI.» del formulario: desde cuándo el organismo tiene estos fondos en custodia.',
        );
    }

    public static function organismAccount(): self
    {
        return new self(
            'organism.account',
            'organism',
            'Cuenta del organismo',
            'Es la casilla que el formulario marca, y el sistema la deriva de dónde está el dinero. '
            .'Que no se pueda derivar significa que ningún ingreso de esta cuota llegó a una cuenta.',
        );
    }
}
