<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Models\CashToBankTransfer;

/**
 * Lo que viajó en un traslado: quién sabe qué papeles se llevaron al banco.
 *
 * Banking mueve dinero de la caja a la cuenta y no sabe de quién era: eso
 * lo dicen los ítems del traslado, que viven en el módulo que conoce a los
 * dueños. Pero cuando el traslado se cancela o el banco lo acredita, los
 * cheques que viajaron tienen que cambiar de estado —vuelven a custodia, o
 * quedan acreditados—, y si nadie se lo dice el inventario de la caja los
 * sigue contando donde no están.
 *
 * Este contrato es esa conversación sin invertir la dependencia: Banking
 * avisa, y el módulo dueño de los ítems —hoy Haberes— lo implementa y se
 * registra en `AppServiceProvider`. La base controla igual que el estado
 * del cheque coincida con el del traslado.
 */
interface CashTransferContents
{
    /** El traslado se canceló: lo que viajó vuelve a la caja. */
    public function cancelled(CashToBankTransfer $transfer): void;

    /** El extracto acreditó el traslado: lo que viajó ya está en la cuenta. */
    public function credited(CashToBankTransfer $transfer): void;
}
