<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Text\PersonName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PersonNameTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function nombres(): array
    {
        return [
            'todo en mayúsculas, como el DNI' => ['PÉREZ', 'Pérez'],
            'todo en minúsculas' => ['maría elena', 'María Elena'],
            'espacios de más' => ['  juan   carlos ', 'Juan Carlos'],
            'partículas en minúscula' => ['JUAN DE LA CRUZ', 'Juan de la Cruz'],
            'partícula al principio' => ['DE LA FUENTE', 'De la Fuente'],
            'apellidos con y' => ['ortega y gasset', 'Ortega y Gasset'],
            'compuesto con guion' => ['MARÍA-JOSÉ', 'María-José'],
            'apóstrofo' => ["D'ANDREA", "D'Andrea"],
            'eñe y diéresis' => ['MUÑOZ ARGÜELLO', 'Muñoz Argüello'],
            'mezcla intencional, se respeta' => ['McKay', 'McKay'],
            'mezcla intencional con espacios de más' => ['  de la  Fuente ', 'de la Fuente'],
            'ya escrito bien' => ['Pérez', 'Pérez'],
        ];
    }

    #[DataProvider('nombres')]
    public function test_el_nombre_se_guarda_como_se_muestra(string $cargado, string $esperado): void
    {
        $this->assertSame($esperado, PersonName::format($cargado));
    }

    public function test_es_idempotente(): void
    {
        $una = PersonName::format('JUAN DE LA CRUZ');

        $this->assertSame($una, PersonName::format($una));
    }
}
