<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Enums;

use App\Modules\Ledger\Enums\PaymentMedium;

/**
 * Cómo entró el dinero a la cuenta del organismo.
 *
 * Lo dice el propio ticket: el del área aportado por la contadora
 * imprime `DEPÓSITO EFECTIVO EN CUENTA`. Importa porque cambia qué se
 * puede esperar del movimiento en el extracto: un depósito por ventanilla
 * llega **sin CUIT ni nombre** —aparece como
 * `995979830 - Numero de Operacion`—, mientras que una transferencia suele
 * traer el CUIT del ordenante embebido en el concepto.
 */
enum DepositKind: string
{
    /** Efectivo por ventanilla o cajero, acreditado en la cuenta. */
    case CashDeposit = 'cash_deposit';

    /** Transferencia desde otra cuenta. */
    case Transfer = 'transfer';

    /** Cheque depositado en la cuenta. */
    case ChequeDeposit = 'cheque_deposit';

    /**
     * El tipo que le corresponde a una cuota por el medio con que se paga.
     *
     * La carga del comprobante ya no lo pregunta: entrando desde la cuota,
     * el medio ya está decidido y un select ahí solo ofrece contradecirlo.
     *
     * **`Bank` cae en depósito por ventanilla y no en transferencia.** La
     * contadora definió que las transferencias no son una categoría aparte
     * —«todo ingreso de dinero a la cuenta bancaria se considera depósito
     * en cuenta»— así que el medio previsto no alcanza para distinguirlas.
     * Se elige el caso documentado: el ticket que aportó el área imprime
     * `DEPÓSITO EFECTIVO EN CUENTA`. Si el papel dice «Transferencia», se
     * corrige desde la ficha del comprobante, que es donde se arregla todo
     * lo demás que se transcribió.
     */
    public static function forMedium(PaymentMedium $medio): self
    {
        return match ($medio) {
            PaymentMedium::Cheque => self::ChequeDeposit,
            PaymentMedium::Cash, PaymentMedium::Bank => self::CashDeposit,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CashDeposit => 'Depósito en efectivo',
            self::Transfer => 'Transferencia',
            self::ChequeDeposit => 'Depósito de cheque',
        };
    }

    /**
     * Si conviene esperar que el extracto identifique al depositante.
     *
     * Con un depósito por ventanilla no hay a quién buscar en el concepto,
     * y por eso el ticket es la única forma de saber de quién es.
     */
    public function usuallyIdentifiesDepositor(): bool
    {
        return $this === self::Transfer;
    }
}
