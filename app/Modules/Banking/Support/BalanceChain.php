<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Support\Money\Decimal;

/**
 * Comprueba que los saldos del extracto encadenen.
 *
 * En orden cronológico, cada saldo posterior tiene que ser el anterior más
 * el movimiento: `saldo[n] = saldo[n-1] + importe[n]`. En el extracto real
 * del área la cadena cierra exacta en las catorce filas, así que se puede
 * exigir.
 *
 * Es el control más fuerte que da el archivo. Si no cierra, no es que un
 * importe esté mal escrito: **falta una fila** —el archivo se descargó
 * mientras el banco escribía, o alguien lo abrió en Excel y borró algo— y
 * importarlo dejaría un extracto con agujeros que después nadie va a poder
 * conciliar.
 *
 * No es una advertencia: la importación se rechaza entera.
 */
final class BalanceChain
{
    public function __construct(
        /** `null` significa que faltan saldos y la cadena no se puede comprobar. */
        public readonly ?bool $isValid,
        public readonly ?string $failure = null,
    ) {}

    public static function verify(ParsedStatement $statement): self
    {
        $rows = $statement->chronologicalRows();

        if (count($rows) === 1 && $rows[0]->balanceAfter !== null && $rows[0]->signedAmount() !== null) {
            // Con una sola fila no hay cadena que verificar. No es un
            // error: es un extracto de un día.
            return new self(true);
        }

        $previous = null;
        $incomplete = false;

        foreach ($rows as $row) {
            $balance = $row->balanceAfter;
            $movement = $row->signedAmount();

            if ($balance === null || $movement === null) {
                // Sin saldo no se puede seguir la cadena desde acá. La
                // fila entra igual —el movimiento es real— pero marcada,
                // y la cadena se corta sin declarar un error que no hubo.
                $previous = null;
                $incomplete = true;

                continue;
            }

            if ($previous !== null) {
                $expected = Decimal::add($previous, $movement);

                if (! Decimal::equals($expected, $balance)) {
                    return new self(false, sprintf(
                        'El saldo no encadena en la fila %d (%s): del saldo anterior %s más el movimiento %s '
                        .'debería resultar %s, y el archivo informa %s. Falta al menos un movimiento en el extracto.',
                        $row->rowNumber,
                        $row->transactionDate ?? 'sin fecha',
                        $previous,
                        $movement,
                        $expected,
                        $balance,
                    ));
                }
            }

            $previous = $balance;
        }

        return new self($incomplete ? null : true);
    }
}
