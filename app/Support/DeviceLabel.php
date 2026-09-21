<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Convierte un user agent en algo que un contador pueda leer.
 *
 * "Chrome 138 / Windows 11" le sirve a quien revisa el historial de
 * accesos; la cadena cruda del navegador, no. El user agent completo se
 * conserva igual en la fila, esto es solo la etiqueta de presentación.
 */
final class DeviceLabel
{
    /** @var array<string, string> Orden importante: Edge se anuncia como Chrome. */
    private const BROWSERS = [
        'Edg' => 'Edge',
        'OPR' => 'Opera',
        'Firefox' => 'Firefox',
        'Chrome' => 'Chrome',
        'Safari' => 'Safari',
    ];

    public static function fromUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }

        $browser = self::browser($userAgent);
        $platform = self::platform($userAgent);

        return match (true) {
            $browser !== null && $platform !== null => "{$browser} / {$platform}",
            $browser !== null => $browser,
            $platform !== null => $platform,
            default => null,
        };
    }

    private static function browser(string $userAgent): ?string
    {
        foreach (self::BROWSERS as $token => $name) {
            if (! str_contains($userAgent, $token)) {
                continue;
            }

            if (preg_match('#'.preg_quote($token, '#').'/(\d+)#', $userAgent, $matches) === 1) {
                return "{$name} {$matches[1]}";
            }

            return $name;
        }

        return null;
    }

    private static function platform(string $userAgent): ?string
    {
        return match (true) {
            // Windows 11 se anuncia como "Windows NT 10.0" igual que el 10:
            // el user agent no permite distinguirlos y no vale la pena mentir.
            str_contains($userAgent, 'Windows NT 10.0') => 'Windows 10/11',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };
    }
}
