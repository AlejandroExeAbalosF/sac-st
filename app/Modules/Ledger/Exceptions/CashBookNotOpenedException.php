<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Exceptions;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\CashBookOpening;
use Carbon\CarbonInterface;
use Exception;

/**
 * Se quiso operar en un libro que todavía no se abrió, o antes de su apertura.
 *
 * Como el período cerrado, **no es un error de un campo**: el operador no
 * escribió nada mal. Falta declarar lo que ya estaba en el cajón, y eso se
 * hace en otra pantalla —la apertura—, que además es de un administrador.
 * Lleva la moneda para que la pantalla pueda llevar directo a ese libro.
 *
 * Sin ella el caso llegaba como `QueryException` de
 * `journal_lines_require_opening`, y en el peor de los casos ni eso: hasta
 * que la guarda existió, la caja operaba sin apertura y el primer arqueo
 * daba una diferencia igual a todo el saldo histórico.
 *
 * **Hereda de `Exception` y no de `RuntimeException`**, por la misma razón
 * que `ClosedPeriodException`: varios controladores convierten una
 * `RuntimeException` en un aviso suelto y se perdería la salida.
 */
final class CashBookNotOpenedException extends Exception
{
    private function __construct(
        public readonly Currency $currency,
        public readonly CarbonInterface $attemptedOn,
        public readonly ?CarbonInterface $openedOn,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** El libro de esa moneda no tiene apertura. */
    public static function missing(Currency $currency, CarbonInterface $attemptedOn): self
    {
        return new self($currency, $attemptedOn, null, sprintf(
            'Los libros en %s de esta caja todavía no se abrieron. '
            .'Antes de registrar movimientos hay que declarar lo que ya estaba en el cajón.',
            mb_strtolower($currency->label()),
        ));
    }

    /** La fecha es anterior a la apertura de ese libro. */
    public static function before(CashBookOpening $opening, CarbonInterface $attemptedOn): self
    {
        return new self($opening->currency, $attemptedOn, $opening->opened_on, sprintf(
            'La apertura en %s es del %s: no se registra nada con fecha %s.',
            mb_strtolower($opening->currency->label()),
            $opening->opened_on->format('d/m/Y'),
            $attemptedOn->format('d/m/Y'),
        ));
    }

    /**
     * Lo que la pantalla necesita para explicarlo y ofrecer la salida.
     *
     * @return array{currency: string, currencyLabel: string, attemptedOn: string, openedOn: string|null, message: string}
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency->value,
            'currencyLabel' => $this->currency->label(),
            'attemptedOn' => $this->attemptedOn->toDateString(),
            'openedOn' => $this->openedOn?->toDateString(),
            'message' => $this->getMessage(),
        ];
    }
}
