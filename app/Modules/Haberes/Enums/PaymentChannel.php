<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Enums;

/**
 * Por dónde va a salir el dinero de esta cuota.
 *
 * **No es el medio por el que entró.** El §2.4.6 del DER lo dice sin
 * rodeos: *«cómo entró el dinero y cómo sale son independientes»*. Entrar
 * en efectivo y salir por transferencia es la combinación habitual, y es
 * exactamente lo que pasa cuando el beneficiario no se presenta y la
 * contadora deposita.
 *
 * De este canal depende que exista o no la Orden de Pago: el mostrador se
 * paga entregando el dinero y firmando el recibo de egreso, sin pedirle
 * nada al organismo superior.
 */
enum PaymentChannel: string
{
    /**
     * Se entrega en mano: efectivo o el cheque mismo.
     *
     * No lleva Orden ni Pase. Es la mitad del circuito que no sale del
     * área.
     */
    case Counter = 'counter';

    /**
     * El dinero está en la cuenta del organismo y sale por transferencia.
     *
     * Dos caminos llegan acá: entró por banco, o entró por mostrador y la
     * contadora lo depositó (§2.4.7, *«depositado el efectivo, el pago
     * solo puede hacerse por transferencia»*).
     */
    case Transfer = 'transfer';

    /**
     * El efectivo salió de la caja y el banco todavía no lo acreditó.
     *
     * Va a ser `Transfer`, pero no lo es aún. Es la ventana de
     * `CASH_IN_TRANSIT`, y ofrecer la Orden acá sería pedirle al organismo
     * que transfiera plata que su cuenta todavía no tiene: el área
     * confirmó que la Orden se habilita *«cuando esté acreditado»*.
     */
    case Undetermined = 'undetermined';

    public function label(): string
    {
        return match ($this) {
            self::Counter => 'Entrega por mostrador',
            self::Transfer => 'Transferencia del organismo',
            self::Undetermined => 'Depósito sin acreditar',
        };
    }
}
