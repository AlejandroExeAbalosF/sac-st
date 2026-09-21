<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

/**
 * Qué pasa con lo que sobró de una recepción — §2.4 del DER.
 *
 * **No guarda un importe.** El remanente se calcula restándole a la
 * recepción sus asignaciones, siempre (§5.1). Esto declara otra cosa: si
 * ese sobrante sigue esperando que alguien lo identifique o si ya se lo
 * miró y se lo dio por cerrado.
 *
 * El caso típico es el redondeo bancario: la cuota es de $100.000, el
 * depósito llegó por $100.005, y esos $5 no son un error ni tienen dueño
 * conocido. Reconocerlos los saca de la cola de trabajo sin inventar un
 * procedimiento de devolución que el área todavía no tiene.
 *
 * ── Hoy es un estado sin puerta ────────────────────────────────────────
 *
 * **Nada escribe `AcknowledgedExcess`**: no hay Action, ni ruta, ni botón,
 * y el front no lee esta columna. Toda recepción nace `Open` y se queda
 * así. Está a propósito y no es un olvido: el área confirmó que los
 * depósitos vienen por el importe justo, y que el efectivo de más se suma
 * al pago en el papel y en el sistema, así que la cuota vale ese total y
 * no sobra nada que reconocer.
 *
 * Se conserva porque el excedente es un hecho posible del mundo —un
 * redondeo del banco, un tipeo de quien transfiere— y el día que aparezca
 * uno conviene tener dónde ponerlo. Lo que falta entonces es el acto de
 * reconocerlo: un Action que ponga este valor con su motivo, la ruta con
 * `recepciones.asignar`, y mostrarlo en la ficha de la recepción.
 *
 * Mientras tanto, un excedente quedaría como saldo sin asignar de su
 * recepción: visible en «Pendientes» del listado, sin forma de cerrarlo.
 * Ver el punto 48 de `Correcciones-al-DER-pendientes.md`.
 */
enum ResidualStatus: string
{
    /** Todavía en la cola: puede que falte asignar. */
    case Open = 'open';

    /** Mirado y cerrado como excedente. Sigue visible y conciliable. */
    case AcknowledgedExcess = 'acknowledged_excess';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Pendiente de identificar',
            self::AcknowledgedExcess => 'Excedente reconocido',
        };
    }
}
