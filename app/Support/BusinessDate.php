<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Día operativo del área: una fecha de calendario, no un instante UTC. */
final class BusinessDate
{
    public static function today(): CarbonImmutable
    {
        return self::fromInstant(CarbonImmutable::now());
    }

    public static function fromInstant(CarbonInterface $instant): CarbonImmutable
    {
        $localDate = $instant->copy()->timezone((string) config('app.display_timezone'))->toDateString();

        // Se representa a medianoche UTC para compararla con los otros
        // valores DATE del dominio, que Carbon también materializa en UTC.
        return CarbonImmutable::parse($localDate, 'UTC')->startOfDay();
    }

    /** Inicio UTC de un día que el operador ingresó en el calendario de Salta. */
    public static function startOfDay(string $localDate): CarbonImmutable
    {
        return CarbonImmutable::parse($localDate, (string) config('app.display_timezone'))
            ->startOfDay()
            ->utc();
    }
}
