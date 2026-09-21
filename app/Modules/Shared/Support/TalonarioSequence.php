<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\Receipt;

/**
 * El último número de talonario que se cargó en cada serie.
 *
 * **No es una secuencia que el sistema controle.** El número sale de un
 * lote de papel impreso afuera: el sistema no lo genera, no lo exige —es
 * opcional— y no puede suponer que venga correlativo. Un talonario nuevo
 * empieza donde el fabricante quiso, y en la planilla de junio de 2026 hay
 * un salto de cincuenta números en el medio de un día.
 *
 * Lo único que esto hace es **decirle al operador cuál fue el último**, para
 * que un `72912` tipeado donde iba `72192` se note en el momento y no tres
 * meses después. Es información, no una regla: el aviso no bloquea nada y
 * un salto legítimo se ignora sin más.
 */
final class TalonarioSequence
{
    /**
     * El número más alto cargado en la serie, o `null` si no hay ninguno.
     *
     * Se ordena **por longitud y después alfabéticamente**, no como texto a
     * secas: en orden alfabético `9` es mayor que `72192`, y el último
     * número de un talonario de cinco cifras quedaría siendo el `9`. Y
     * tampoco como entero, porque el número del papel puede traer ceros a la
     * izquierda —`00071514`— que forman parte de cómo el área lo escribe.
     */
    public function lastUsed(ReceiptType $type): ?string
    {
        return Receipt::query()
            ->whereHas('series', fn ($query) => $query->where('code', $type->seriesCode()))
            ->whereNotNull('talonario_number')
            ->orderByRaw('length(talonario_number) DESC, talonario_number DESC')
            ->value('talonario_number');
    }

    /**
     * Los últimos de las dos series, listos para la pantalla.
     *
     * @return array<string, string|null>
     */
    public function all(): array
    {
        $ultimos = [];

        foreach (ReceiptType::cases() as $tipo) {
            $ultimos[$tipo->value] = $this->lastUsed($tipo);
        }

        return $ultimos;
    }
}
