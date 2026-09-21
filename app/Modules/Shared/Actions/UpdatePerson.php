<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Modules\Shared\Models\Person;
use Illuminate\Support\Facades\DB;

/**
 * Corrige una ficha del maestro.
 *
 * Existe porque el documento del empleador es opcional: alguien cargado
 * hoy con la razón social nada más tiene que poder recibir su CUIT cuando
 * llegue el expediente siguiente. Sin esta vía, un nombre mal tipeado
 * quedaría impreso en los comprobantes para siempre.
 *
 * El tipo NO se puede cambiar acá. Una persona que ya tiene roles está
 * atada a su tipo por la FK compuesta de `person_roles`, y convertir una
 * organización en persona física —o al revés— no es una corrección: es
 * decir que la ficha estaba mal desde el principio, y eso se resuelve
 * dando de baja y volviendo a cargar.
 */
final class UpdatePerson
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    /** Lo que se audita: las columnas que el operador realmente escribe. */
    private const AUDITED = [
        'first_name',
        'last_name',
        'legal_name',
        'owner_person_id',
        'document',
        'tax_identifier',
        'is_active',
    ];

    /**
     * @param  array{first_name?: string|null, last_name?: string|null, legal_name?: string|null, owner_person_id?: int|null, document?: string|null, tax_identifier?: string|null, is_active?: bool}  $datos
     */
    public function handle(Person $person, array $datos): Person
    {
        return DB::transaction(function () use ($person, $datos): Person {
            $antes = [];

            foreach (self::AUDITED as $columna) {
                $antes[$columna] = $person->getOriginal($columna);
            }

            $person->fill($datos);
            $person->save();

            [$viejos, $nuevos] = RecordAuditEvent::diff($antes, $person->only(self::AUDITED));

            if ($viejos !== []) {
                $this->auditar->handle('persona.corregida', $person, $viejos, $nuevos);
            }

            return $person;
        });
    }
}
