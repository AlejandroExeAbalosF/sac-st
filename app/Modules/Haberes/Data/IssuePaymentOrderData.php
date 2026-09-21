<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Enums\ReceiptNumberSource;
use App\Modules\Haberes\Http\Requests\IssuePaymentOrderRequest;
use Illuminate\Http\Request;

/**
 * Lo que el operador elige al generar la Orden y su Pase.
 *
 * **Es todo lo que el formulario aporta, y nada más.** El importe, el
 * beneficiario, el empleador y la tabla de depósitos no viajan desde el
 * navegador: los deriva el Action de la propia cuota, por el mismo motivo
 * que en el recibo de ingreso (Correcciones §33, desvío 4). Un importe
 * propuesto por el cliente en un documento que pide transferir dinero es
 * exactamente lo que no puede pasar.
 *
 * Lo que sí es una decisión de quien emite:
 *
 * - **cuál de las cuentas verificadas** del beneficiario se usa, cuando
 *   tiene más de una;
 * - **la foja donde el expediente informa el CBU**, que no vive en
 *   ninguna tabla y que redacta los dos renglones: la cita de la nota de
 *   Pase y el campo OBS de la Orden (§47, D-003);
 * - **cuál de los dos números del recibo** se imprime;
 * - **el destino del Pase**, hoy siempre el mismo pero previsto para que
 *   cambie sin tocar código.
 */
final readonly class IssuePaymentOrderData
{
    /**
     * A dónde va el Pase mientras nadie diga otra cosa.
     *
     * Está acá y no en un `config`: es un dato del circuito administrativo,
     * no de la instalación, y el día que el receptor cambie se va a cambiar
     * desde la pantalla, que es donde el área lo va a querer cambiar.
     */
    public const DEFAULT_DESTINATION = 'SAF GOBIERNO';

    public function __construct(
        public ?int $beneficiaryBankAccountId,
        public ?string $cbuFolio,
        public ReceiptNumberSource $incomeReceiptNumberSource,
        public string $paseDestination,
        /**
         * Quién firma como Tesorero.
         *
         * Nulo es lo normal: el área pidió que la Orden salga con la firma
         * en blanco para que la firme quien corresponda. Se guarda cuando
         * se lo elige, congelado con su cargo, porque un ascenso no puede
         * reescribir un papel ya entregado.
         */
        public ?int $treasurerId,
    ) {}

    public static function fromRequest(IssuePaymentOrderRequest $request): self
    {
        return self::desde($request);
    }

    /**
     * La misma lectura, para la vista previa.
     *
     * Va por su propia puerta porque la previa **no valida**: se mira un
     * borrador con lo que haya escrito hasta ese momento, y exigirle la
     * foja del CBU para poder mirarlo obligaría a completar el formulario
     * antes de saber qué va a decir el papel.
     */
    public static function fromPreviewRequest(Request $request): self
    {
        return self::desde($request);
    }

    private static function desde(Request $request): self
    {
        return new self(
            beneficiaryBankAccountId: $request->filled('beneficiaryBankAccountId')
                ? $request->integer('beneficiaryBankAccountId')
                : null,
            cbuFolio: $request->filled('cbuFolio') ? $request->string('cbuFolio')->toString() : null,
            incomeReceiptNumberSource: $request->enum('incomeReceiptNumberSource', ReceiptNumberSource::class)
                ?? ReceiptNumberSource::System,
            paseDestination: $request->filled('paseDestination')
                ? $request->string('paseDestination')->toString()
                : self::DEFAULT_DESTINATION,
            treasurerId: $request->filled('treasurerId') ? $request->integer('treasurerId') : null,
        );
    }
}
