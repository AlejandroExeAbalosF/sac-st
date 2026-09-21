<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Exceptions;

use App\Modules\Ledger\Models\PeriodClosing;
use Carbon\CarbonInterface;
use Exception;

/**
 * Se quiso mover dinero con fecha dentro de un período ya cerrado.
 *
 * **No es un error de validación de un campo**, y por eso no es una
 * `ValidationException`: el operador no escribió nada mal. Lo que pasa es
 * que la caja está cerrada para esa fecha, y eso se arregla en otra
 * pantalla —reabriendo el período— o no se arregla.
 *
 * Lleva el cierre entero para que la pantalla pueda decir cuál es y llevar
 * hasta él. Antes esto llegaba como `QueryException` del trigger
 * `financial_events_period_open`: una traza de PostgreSQL en la cara del
 * operador, sin decirle qué hacer.
 *
 * **Hereda de `Exception` y no de `RuntimeException` a propósito.** Varios
 * controladores atrapan `RuntimeException` para convertirla en un aviso
 * suelto, y este caso quedaría atrapado ahí: perdería el cierre que lleva
 * adentro y con él el camino hasta la pantalla que lo arregla.
 */
final class ClosedPeriodException extends Exception
{
    private function __construct(
        public readonly PeriodClosing $closing,
        public readonly CarbonInterface $attemptedOn,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** El asiento cae adentro de un período que ya está cerrado. */
    public static function covering(PeriodClosing $closing, CarbonInterface $attemptedOn): self
    {
        return new self($closing, $attemptedOn, sprintf(
            'El período %s del %s al %s está cerrado: no admite movimientos con fecha %s.',
            mb_strtolower($closing->period_type->label()),
            $closing->period_from->format('d/m/Y'),
            $closing->period_to->format('d/m/Y'),
            $attemptedOn->format('d/m/Y'),
        ));
    }

    /**
     * El asiento es anterior, pero cambiaría el saldo con el que abrió un
     * cierre posterior.
     *
     * La apertura de cada cierre es el saldo del libro a su víspera, así
     * que un movimiento con fecha anterior lo deja diciendo otra cosa que
     * el libro. El mensaje nombra el cierre que hay que reabrir, no el
     * día del asiento: es ahí donde está la salida.
     */
    public static function invalidatedBy(PeriodClosing $closing, CarbonInterface $attemptedOn): self
    {
        return new self($closing, $attemptedOn, sprintf(
            'Un movimiento del %s cambiaría el saldo inicial del cierre %s del %s al %s, que ya está cerrado. '
            .'Reabrilo antes de cargarlo.',
            $attemptedOn->format('d/m/Y'),
            mb_strtolower($closing->period_type->label()),
            $closing->period_from->format('d/m/Y'),
            $closing->period_to->format('d/m/Y'),
        ));
    }

    /**
     * Lo que la pantalla necesita para explicarlo y ofrecer la salida.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'periodType' => $this->closing->period_type->label(),
            'from' => $this->closing->period_from->toDateString(),
            'to' => $this->closing->period_to->toDateString(),
            'attemptedOn' => $this->attemptedOn->toDateString(),
            'message' => $this->getMessage(),
        ];
    }
}
