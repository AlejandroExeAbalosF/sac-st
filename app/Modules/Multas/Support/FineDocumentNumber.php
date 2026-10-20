<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Modules\Multas\Enums\FineActType;

/**
 * Número y año de una resolución o un acta, tal como los escribe el papel.
 *
 * «N°0649/26», «Nº 1711/25», «HYS N° 1025288/25», «LAB N°25045/25»,
 * «H Y S N°169607». El año es opcional: el Excel de 2026 tiene actas sin él.
 * Si el texto empieza con el tipo de acta, se reconoce.
 */
final readonly class FineDocumentNumber
{
    private const PATTERN = '~^\s*(?:(LAB|H\s*Y\s*S|HYS)\b)?\s*(?:N\s*[°º]?|Nro\.?)?\s*(\d{1,9})\s*(?:/\s*(\d{2}|\d{4}))?\s*$~iu';

    public function __construct(
        public int $number,
        public ?int $year,
        public ?FineActType $actType,
    ) {}

    public static function parse(string $text): ?self
    {
        if (preg_match(self::PATTERN, $text, $partes) !== 1) {
            return null;
        }

        $tipo = strtoupper((string) preg_replace('~\s+~', '', $partes[1]));

        return new self(
            (int) $partes[2],
            ($partes[3] ?? '') === '' ? null : TwoDigitYear::expand((int) $partes[3]),
            match ($tipo) {
                'LAB' => FineActType::Labor,
                'HYS' => FineActType::HealthSafety,
                default => null,
            },
        );
    }
}
