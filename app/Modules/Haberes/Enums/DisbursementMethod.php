<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Enums;

use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;

/**
 * Por dónde salió el dinero — §9.7 del DER.
 *
 * **No es el medio por el que entró.** El §2.4.6 lo dice sin rodeos:
 * *«cómo entró el dinero y cómo sale son independientes»*. `fund_receipts.medium`
 * describe la entrada y no cambia jamás; esto describe la salida. Entrar en
 * efectivo y salir por transferencia es la combinación habitual, y es lo que
 * pasa cuando el beneficiario no se presenta y la contadora deposita.
 *
 * Los dos primeros son la entrega en mano; el tercero sale del organismo y
 * exige Orden de Pago. Esa es toda la diferencia de circuito.
 */
enum DisbursementMethod: string
{
    /** Se le entregó el efectivo en el mostrador. */
    case Cash = 'cash';

    /**
     * Se le entregó el cheque mismo (§2.5.5).
     *
     * El papel que estaba en custodia cambia de manos. El formulario del
     * recibo de egreso tiene su casilla `Cheque Terc.` justamente para
     * esto, y la Orden que a veces lo acompaña se titula «VALORES EN
     * CUSTODIA».
     */
    case Cheque = 'cheque';

    /** El organismo transfirió desde su cuenta a la del beneficiario. */
    case BankTransfer = 'bank_transfer';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::Cheque => 'Cheque de terceros',
            self::BankTransfer => 'Transferencia',
        };
    }

    /** Si se paga en el mostrador, sin pedirle nada al organismo superior. */
    public function isCounter(): bool
    {
        return $this !== self::BankTransfer;
    }

    /**
     * De qué cuenta sale el dinero.
     *
     * El §10 del DER fija el asiento del egreso confirmado —débito
     * `BENEFICIARY_FUNDS`— y deja el crédito atado a dónde estaba la
     * plata. El cheque acredita `CHEQUES_IN_CUSTODY` y no la caja: es el
     * asiento de «Entrega del cheque al beneficiario», y es lo que
     * mantiene separado el inventario de cheques del efectivo contado.
     */
    public function sourceAccount(): LedgerAccount
    {
        return match ($this) {
            self::Cash => LedgerAccount::CashOnHand,
            self::Cheque => LedgerAccount::ChequesInCustody,
            self::BankTransfer => LedgerAccount::BankAccount,
        };
    }

    /**
     * Qué casilla marca el formulario del recibo.
     *
     * El papel tiene tres —`Efectivo`, `Cheque Terc.` y `Depósito Cta.`— y
     * son las mismas tres del recibo de ingreso, con `medium_snapshot`
     * guardando en cada caso lo que ese comprobante documenta: la entrada
     * en uno, la salida en el otro.
     */
    public function asReceiptMedium(): PaymentMedium
    {
        return match ($this) {
            self::Cash => PaymentMedium::Cash,
            self::Cheque => PaymentMedium::Cheque,
            self::BankTransfer => PaymentMedium::Bank,
        };
    }

    /**
     * El hecho monetario que este egreso asienta.
     *
     * El cheque entregado va con `CashDisbursement` y no con uno propio:
     * el §2.5.2 es explícito en que **el cheque sigue el circuito del
     * efectivo**, y lo que lo distingue —la cuenta que se acredita— ya
     * queda dicho en las líneas del asiento.
     */
    public function eventType(): FinancialEventType
    {
        return $this === self::BankTransfer
            ? FinancialEventType::BankDisbursement
            : FinancialEventType::CashDisbursement;
    }
}
