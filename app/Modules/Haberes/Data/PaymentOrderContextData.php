<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Shared\Models\PersonBankAccount;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Lo que el modal de la Orden necesita y es igual para todo el haber.
 *
 * Va al nivel de la página y no de cada cuota a propósito: el
 * beneficiario, el empleador, el expediente y las cuentas bancarias son
 * los mismos para las sesenta cuotas de un plan, y repetirlos en cada
 * tarjeta multiplicaría por sesenta un dato que no cambia.
 *
 * Lo que sí es de la cuota —la tabla de depósitos, el número de recibo, la
 * cuenta del organismo, qué falta— vive en `InstallmentOrderStateData`.
 */
#[TypeScript]
final class PaymentOrderContextData extends Data
{
    /** @param  list<VerifiableAccountData>  $accounts */
    public function __construct(
        public int $beneficiaryId,
        public string $beneficiaryName,
        public ?string $beneficiaryDocument,
        public ?string $beneficiaryAddress,
        public ?string $beneficiaryPhone,
        public ?int $employerId,
        public ?string $employerName,
        public ?string $employerDocument,
        public ?string $employerAddress,
        public ?string $employerPhone,
        public string $expedienteNumber,
        public ?string $expedienteCanonical,
        public ?string $expedienteSubject,
        /** La «FECHA INI.» del formulario. */
        public ?string $custodyStartDate,
        public array $accounts,
        /** El destino que la nota de Pase propone. */
        public string $defaultPaseDestination,
    ) {}

    /**
     * @param  Expediente  $expediente  Se pasa en vez de leerlo del haber
     *                                  porque la pantalla ya lo tiene
     *                                  cargado con su empleador, y volver
     *                                  a pedirlo sería una consulta de más
     *                                  —o un 500, con el modo estricto—.
     */
    public static function fromHaber(
        Haber $haber,
        Expediente $expediente,
        string $defaultPaseDestination,
    ): self {
        $beneficiario = $haber->beneficiary;
        $empleador = $expediente->employer;

        $filas = PersonBankAccount::query()
            ->where('person_id', $beneficiario->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $cuentas = [];

        foreach ($filas as $fila) {
            $cuentas[] = VerifiableAccountData::fromModel($fila);
        }

        return new self(
            beneficiaryId: $beneficiario->id,
            beneficiaryName: $beneficiario->name,
            beneficiaryDocument: $beneficiario->document,
            beneficiaryAddress: $beneficiario->address,
            beneficiaryPhone: $beneficiario->phone,
            employerId: $empleador?->id,
            employerName: $empleador?->name,
            employerDocument: $empleador?->document,
            employerAddress: $empleador?->address,
            employerPhone: $empleador?->phone,
            expedienteNumber: $expediente->display_number,
            expedienteCanonical: $expediente->canonical_number,
            expedienteSubject: $expediente->subject,
            custodyStartDate: $expediente->received_date?->format('Y-m-d'),
            accounts: $cuentas,
            defaultPaseDestination: $defaultPaseDestination,
        );
    }
}
