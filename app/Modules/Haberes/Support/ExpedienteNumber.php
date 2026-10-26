<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

/**
 * El número de expediente de SiCE v3.0.
 *
 * La carátula lo trae desarmado en sus componentes:
 *
 *     0030064 - 125957 / 2026 - 0
 *        │        │       │     └── Inst.   = 0
 *        │        │       └──────── Período = 2026
 *        │        └──────────────── Num.    = 125957
 *        └───────────────────────── CDS 003 + IUD 0064
 *
 * `0030064` identifica a esta Secretaría y es constante. El `125957/2026`
 * que aparece escrito en los recibos es simplemente Num/Período: la forma
 * corta de uso diario.
 *
 * Se aceptan tres formas: la completa, la corta —que es la instancia 0— y
 * la corta con la instancia al final, `125957/2026-1`, como se escribe un
 * expediente de Multas. Sin esa tercera, otra instancia que no fuera la 0
 * solo podía cargarse escribiendo el número completo.
 *
 * Esta clase es la autoridad sobre el formato. El front descompone el
 * número mientras se escribe solo para dar respuesta inmediata; lo que
 * vale es lo que se valida acá.
 */
final class ExpedienteNumber
{
    private const COMPLETO = '/^(\d{3})(\d{4})-(\d{1,6})\/(\d{4})-(\d{1,2})$/';

    private const CORTO = '/^(\d{1,6})\/(\d{4})(?:-(\d{1,2}))?$/';

    public function __construct(
        public readonly string $canonical,
        public readonly string $display,
        public readonly string $cds,
        public readonly string $iud,
        public readonly string $number,
        public readonly string $period,
        public readonly string $instance,
    ) {}

    /**
     * Acepta el número completo o el corto, con o sin instancia. Con el corto
     * se completa el prefijo de la Secretaría, que es constante, y sin
     * instancia es la 0.
     */
    public static function parse(string $raw, string $defaultPrefix = '0030064'): ?self
    {
        $raw = trim($raw);

        if (preg_match(self::COMPLETO, $raw, $m) === 1) {
            return new self(
                canonical: sprintf('%s%s-%s/%s-%s', $m[1], $m[2], str_pad($m[3], 6, '0', STR_PAD_LEFT), $m[4], $m[5]),
                display: $m[3].'/'.$m[4],
                cds: $m[1],
                iud: $m[2],
                number: $m[3],
                period: $m[4],
                instance: $m[5],
            );
        }

        if (preg_match(self::CORTO, $raw, $m) === 1) {
            $cds = substr($defaultPrefix, 0, 3);
            $iud = substr($defaultPrefix, 3, 4);

            $instancia = ($m[3] ?? '') === '' ? '0' : $m[3];

            return new self(
                canonical: sprintf('%s%s-%s/%s-%s', $cds, $iud, str_pad($m[1], 6, '0', STR_PAD_LEFT), $m[2], $instancia),
                display: $m[1].'/'.$m[2],
                cds: $cds,
                iud: $iud,
                number: $m[1],
                period: $m[2],
                instance: $instancia,
            );
        }

        return null;
    }

    public static function isValid(string $raw): bool
    {
        return self::parse($raw) !== null;
    }
}
