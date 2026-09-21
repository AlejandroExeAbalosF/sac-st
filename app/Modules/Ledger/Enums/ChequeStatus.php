<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

/**
 * El cheque, mientras la Secretaría lo tiene — §9.4 del DER.
 *
 * De su ciclo como instrumento —librador, vencimiento, rechazo bancario—
 * el DER difiere casi todo al modelo ideal. Lo que sí está en el núcleo es
 * dónde está el papel, y no por prolijidad: el reverso de la planilla de
 * caja lleva el inventario de cheques uno por uno, y ese inventario sale
 * de consultar las recepciones que siguen `InCustody`.
 */
enum ChequeStatus: string
{
    /** En poder de la Secretaría. Es lo que el arqueo cuenta. */
    case InCustody = 'in_custody';

    /** Entregado al beneficiario. */
    case Delivered = 'delivered';

    /** Depositado en la cuenta del organismo. */
    case Deposited = 'deposited';

    /** Acreditado por el banco. */
    case Cleared = 'cleared';

    /** Rechazado. El dinero nunca llegó. */
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::InCustody => 'En custodia',
            self::Delivered => 'Entregado',
            self::Deposited => 'Depositado',
            self::Cleared => 'Acreditado',
            self::Rejected => 'Rechazado',
        };
    }
}
