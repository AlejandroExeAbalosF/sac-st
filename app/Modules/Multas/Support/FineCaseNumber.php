<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

/**
 * El número de un expediente de Multas, normalizado.
 *
 * Acepta lo que escribe el área: «169607/24-0», «120276/25», «36524/2020».
 * El sufijo se conserva sin el guion; un sufijo ausente queda nulo y **no**
 * se equipara a «0» (P03).
 */
final readonly class FineCaseNumber
{
    private const PATTERN = '~^\s*(\d{1,9})\s*/\s*(\d{2}|\d{4})\s*(?:-\s*([0-9A-Za-z]{1,10}))?\s*$~';

    public function __construct(
        public int $number,
        public int $year,
        public ?string $suffix,
    ) {}

    public static function parse(string $text): ?self
    {
        if (preg_match(self::PATTERN, $text, $partes) !== 1) {
            return null;
        }

        return new self(
            (int) $partes[1],
            TwoDigitYear::expand((int) $partes[2]),
            ($partes[3] ?? '') === '' ? null : $partes[3],
        );
    }

    public static function isValid(string $text): bool
    {
        return self::parse($text) !== null;
    }

    /** La forma con que la pantalla lo muestra: «169607/2024-0». */
    public static function format(?int $number, ?int $year, ?string $suffix): ?string
    {
        if ($number === null) {
            return null;
        }

        return $number.'/'.($year ?? '?').($suffix === null ? '' : '-'.$suffix);
    }
}
