<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Enums;

/**
 * Dónde está un egreso — §9.7 del DER.
 *
 * Los cinco primeros son etapas de un pago en curso; los dos últimos son
 * finales. La diferencia no es cosmética: mientras el egreso está vivo
 * **ocupa el lugar de su cuota** y no puede haber otro, porque serían dos
 * pagos por el mismo dinero. Lo impone un índice único parcial que enumera
 * exactamente los mismos estados que `isLive()`.
 *
 * **Los estados intermedios son de la transferencia.** El §12.3 y el §12.4
 * del DER describen los dos órdenes en que pueden llegar el informe del
 * organismo y el débito del extracto, y ninguno de los dos alcanza solo:
 * el egreso queda esperando al otro. El mostrador no los recorre —nace
 * `Confirmed`, porque el beneficiario está enfrente y no hay nada
 * posterior que esperar—.
 *
 * Esta tanda produce únicamente `Confirmed`. El resto se declara entero
 * porque un juego de estados se define de una vez: viven en un `CHECK` de
 * una tabla append-only, y agregarlos de a uno significaría una migración
 * por etapa del circuito.
 */
enum DisbursementStatus: string
{
    /** Preparado. Todavía no salió ni un peso. */
    case Pending = 'pending';

    /** El organismo informó la transferencia (§2.3.1). */
    case ReportReceived = 'report_received';

    /** El débito apareció en el extracto (§2.3.3). */
    case BankDebitObserved = 'bank_debit_observed';

    /** Están el informe y el débito; falta que el contador los coteje. */
    case ReadyForValidation = 'ready_for_validation';

    /**
     * El dinero salió. Es lo único que habilita el recibo de egreso.
     *
     * Invariante 13 del §11, impuesto por un trigger sobre `receipts`.
     */
    case Confirmed = 'confirmed';

    /** Se deshizo con su contrapartida. El asiento original no se borra. */
    case Reversed = 'reversed';

    /** No se pudo completar, con su motivo. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Preparado',
            self::ReportReceived => 'Transferencia informada',
            self::BankDebitObserved => 'Débito observado',
            self::ReadyForValidation => 'Listo para validar',
            self::Confirmed => 'Confirmado',
            self::Reversed => 'Revertido',
            self::Failed => 'Fallido',
        };
    }

    /**
     * Si ocupa el lugar de su cuota.
     *
     * Espeja el índice `disbursements_one_live_per_installment`. Si alguna
     * vez dejaran de coincidir, la pantalla ofrecería un pago que la base
     * va a rechazar; por eso la lista está una sola vez en cada lado y las
     * dos se leen juntas.
     */
    public function isLive(): bool
    {
        return ! in_array($this, [self::Reversed, self::Failed], true);
    }
}
