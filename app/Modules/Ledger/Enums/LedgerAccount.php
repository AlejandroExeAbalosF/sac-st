<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

/**
 * El plan de cuentas — §9.4 del DER.
 *
 * Son ocho y alcanzan, porque el sistema no lleva la contabilidad del
 * organismo: lleva **dónde está el dinero de terceros y de quién es**.
 * Esa es toda la pregunta que este libro responde.
 *
 * Las dos que hacen el trabajo pesado son `UnassignedFunds` y
 * `BeneficiaryFunds`. Leídas juntas dicen algo que el área hoy calcula a
 * mano: el saldo de la primera es la plata que está en la cuenta y
 * todavía no se sabe de quién es — la cola de trabajo. El de la segunda,
 * la que ya tiene dueño y espera que le paguen.
 */
enum LedgerAccount: string
{
    /** Efectivo en la caja física. */
    case CashOnHand = 'CASH_ON_HAND';

    /**
     * Cheques recibidos y todavía en poder de la Secretaría.
     *
     * Separado del efectivo desde el ingreso, como la columna `CHEQUES`
     * de la planilla de caja.
     */
    case ChequesInCustody = 'CHEQUES_IN_CUSTODY';

    /**
     * El dinero que salió de un lado y todavía no llegó al otro.
     *
     * Existe por una ventana real: entre que el efectivo sale de la caja
     * camino al banco y el banco lo acredita, ese dinero no está en
     * ninguno de los dos lugares. Sin esta cuenta, el arqueo del día no
     * cerraría.
     */
    case CashInTransit = 'CASH_IN_TRANSIT';

    /** El saldo en las cuentas del organismo. */
    case BankAccount = 'BANK_ACCOUNT';

    /**
     * Dinero recibido cuyo dueño todavía no se determinó.
     *
     * Es donde entra **todo** ingreso: primero llega, después se
     * identifica. Su saldo es la cola de trabajo del área.
     */
    case UnassignedFunds = 'UNASSIGNED_FUNDS';

    /** Dinero ya imputado a la cuota de un beneficiario. */
    case BeneficiaryFunds = 'BENEFICIARY_FUNDS';

    /**
     * Saldos que vienen del sistema anterior sin respaldo documental
     * completo. Se separan para no ensuciar lo que sí se puede probar.
     */
    case LegacyFunds = 'LEGACY_FUNDS';

    /** Diferencias de arqueo, que exigen explicación (invariante 28). */
    case CashDifference = 'CASH_DIFFERENCE';

    public function label(): string
    {
        return match ($this) {
            self::CashOnHand => 'Efectivo en caja',
            self::ChequesInCustody => 'Cheques en custodia',
            self::CashInTransit => 'Fondos en tránsito',
            self::BankAccount => 'Cuenta bancaria',
            self::UnassignedFunds => 'Fondos sin identificar',
            self::BeneficiaryFunds => 'Fondos de beneficiarios',
            self::LegacyFunds => 'Fondos del sistema anterior',
            self::CashDifference => 'Diferencia de arqueo',
        };
    }

    /**
     * Dónde está el dinero, frente a de quién es.
     *
     * Las cuentas de ubicación —caja, banco, tránsito— responden «dónde
     * está»; las de atribución responden «de quién». Todo asiento del
     * sistema mueve una de cada tipo, o pasa de una atribución a otra.
     */
    public function isLocation(): bool
    {
        return in_array($this, [
            self::CashOnHand,
            self::ChequesInCustody,
            self::CashInTransit,
            self::BankAccount,
        ], true);
    }
}
