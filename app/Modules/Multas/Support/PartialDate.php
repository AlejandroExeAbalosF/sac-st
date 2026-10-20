<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Modules\Multas\Enums\DatePrecision;
use Carbon\CarbonImmutable;

/**
 * Una fecha con lo que de verdad se sabe de ella.
 *
 * Viaja como texto: «2025-07-14» es un día, «2025-07» es solo el mes y
 * vacío es desconocida. Un mes se guarda como su primer día con precisión
 * `month`: el día no se inventa.
 */
final readonly class PartialDate
{
    private function __construct(
        public ?string $date,
        public DatePrecision $precision,
    ) {}

    public static function unknown(): self
    {
        return new self(null, DatePrecision::Unknown);
    }

    public static function day(CarbonImmutable $date): self
    {
        return new self($date->toDateString(), DatePrecision::Day);
    }

    public static function parse(?string $text): ?self
    {
        $text = trim((string) $text);

        if ($text === '') {
            return self::unknown();
        }

        if (preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $text, $p) === 1 && checkdate((int) $p[2], (int) $p[3], (int) $p[1])) {
            return new self($text, DatePrecision::Day);
        }

        if (preg_match('~^(\d{4})-(\d{2})$~', $text, $p) === 1 && (int) $p[2] >= 1 && (int) $p[2] <= 12) {
            return new self("{$p[1]}-{$p[2]}-01", DatePrecision::Month);
        }

        return null;
    }

    public static function fromStored(?string $date, DatePrecision $precision): self
    {
        return new self($precision === DatePrecision::Unknown ? null : $date, $precision);
    }

    /** Lo mismo que acepta `parse`: la forma en que vuelve al formulario. */
    public function toInput(): string
    {
        return match ($this->precision) {
            DatePrecision::Day => (string) $this->date,
            DatePrecision::Month => substr((string) $this->date, 0, 7),
            DatePrecision::Unknown => '',
        };
    }

    public function isAfter(CarbonImmutable $day): bool
    {
        return $this->date !== null && CarbonImmutable::parse($this->date)->greaterThan($day);
    }
}
