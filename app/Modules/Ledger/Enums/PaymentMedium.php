<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

/**
 * Cómo entró el dinero — §2.5 del DER.
 *
 * No describe cómo sale: eso es `disbursements.method`, y son
 * independientes. Entrar en efectivo y salir por transferencia es la
 * combinación habitual cuando el beneficiario no se presenta y la
 * contadora deposita.
 *
 * El cheque **no es un medio bancario**: sigue el circuito del efectivo
 * (§2.5). Entra por mostrador, queda en custodia y se entrega o se
 * deposita. Tratarlo como bancario sería esperar un crédito en el extracto
 * que no va a aparecer hasta que alguien lo deposite.
 *
 * `Bank` se rotula **«Depósito en cuenta»** y no «Transferencia»: la
 * contadora definió que los medios son tres —*«cheque, efectivo y depósito
 * en cuenta»*— y que las transferencias existen pero no son una categoría
 * aparte, porque *«todo ingreso de dinero a la cuenta bancaria se considera
 * depósito en cuenta/depósito directo»* (Dudas_Consultas D-001 y D-002).
 * Es el mismo rótulo que ya imprime la casilla `Depósito Cta.` del recibo.
 * Cómo llegó ese depósito —ventanilla, transferencia o cheque depositado—
 * lo dice `DepositKind`, que sigue distinguiéndolos porque de eso depende
 * qué se puede esperar del extracto.
 */
enum PaymentMedium: string
{
    case Cash = 'cash';
    case Cheque = 'cheque';
    case Bank = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::Cheque => 'Cheque',
            self::Bank => 'Depósito en cuenta',
        };
    }

    /** Si se espera encontrarlo en un extracto. Solo lo bancario. */
    public function isBank(): bool
    {
        return $this === self::Bank;
    }

    /** El cheque acompaña al efectivo por mostrador (§2.5). */
    public function followsCashCircuit(): bool
    {
        return $this === self::Cash || $this === self::Cheque;
    }
}
