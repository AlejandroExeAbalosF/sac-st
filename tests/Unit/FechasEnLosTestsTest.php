<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Los tests arman «hoy» como lo arma el sistema: en Salta.
 *
 * `now()->toDateString()` es el día en UTC. Entre las 21:00 y la medianoche
 * de Salta ya es el día siguiente, y el servidor —que valida contra
 * `BusinessDate::today()`— rechaza esa fecha por futura. Treinta y dos tests
 * fallaban solo de noche por esto, que es la peor clase de falla: la suite
 * pasa en la oficina y se rompe en el CI nocturno.
 *
 * Un instante sigue siendo `now()`: `created_at`, `voided_at` y compañía se
 * guardan en UTC a propósito. Lo que no puede salir de `now()` es un día.
 */
class FechasEnLosTestsTest extends TestCase
{
    public function test_ningun_test_arma_el_dia_operativo_en_utc(): void
    {
        $patron = '/\bnow\(\)(?:->(?:add|sub)Days?\(\d*\))*->(?:toDateString\(\)|format\(\'Y-m-d\'\))/';
        $hallazgos = [];

        /** @var SplFileInfo $archivo */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__))) as $archivo) {
            if ($archivo->getExtension() !== 'php' || $archivo->getRealPath() === __FILE__) {
                continue;
            }

            foreach (file($archivo->getPathname()) ?: [] as $numero => $linea) {
                if (preg_match($patron, $linea) === 1) {
                    $hallazgos[] = $archivo->getFilename().':'.($numero + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $hallazgos,
            'Estos tests arman el día operativo en UTC. Usá BusinessDate::today() '
            .'(o BusinessDate::fromInstant($instante) para el día de un instante).',
        );
    }
}
