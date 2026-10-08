<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Excel\CashSheetArchivist;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Shared\Models\Attachment;
use Illuminate\Validation\ValidationException;

/**
 * Genera la planilla de caja del período y la guarda como evidencia.
 *
 * **No es una descarga: es un adjunto.** El Excel queda en `attachments`
 * con su sha256, su fecha y su autor, igual que el extracto que el banco
 * entrega o el recibo que se imprime. Un archivo que se regenera cada vez
 * que alguien lo pide no prueba nada — dos personas podrían bajar dos
 * planillas distintas del mismo día y las dos parecerían oficiales.
 *
 * De ahí la consecuencia práctica: **si el cierre ya tiene su planilla, se
 * devuelve esa**. Volver a generarla produciría un archivo con otra huella
 * aunque los números fueran idénticos, y entonces el papel firmado y el
 * archivo del sistema dejarían de ser el mismo objeto.
 *
 * Cuando lo que cambió es el **dibujo** y no los números —un arreglo del
 * generador— rehacerla es un acto aparte y explícito: `RegenerateCashSheet`.
 *
 * Exportar un cierre **mensual** arma el libro completo: las dos hojas de
 * cada día cerrado del mes más la hoja del mes. Es exactamente la forma del
 * archivo que el área lleva hoy —`CAJA HABERES EN CONSIGNACION JUNIO
 * 2026.xlsx`, cuarenta hojas—.
 */
final class ExportCashSheet
{
    public function __construct(
        private readonly CashSheetArchivist $archivist,
    ) {}

    /** @throws ValidationException */
    public function handle(PeriodClosing $closing, ?int $actorId = null): Attachment
    {
        if ($closing->status !== PeriodClosingStatus::Closed) {
            throw ValidationException::withMessages([
                'status' => 'La planilla se emite de un período cerrado. Este todavía no lo está.',
            ]);
        }

        /*
         * ─── La planilla vale para el cierre que la produjo ──────────────
         *
         * Si el cierre ya tiene planilla, se devuelve esa: el archivista lo
         * decide con la caja bloqueada y comprobando que el cierre no cambió
         * de versión. Un período reabierto y vuelto a cerrar tiene otro
         * detalle congelado y por lo tanto otra planilla; la anterior **no
         * se borra**, porque pudo imprimirse y firmarse.
         */
        return $this->archivist->archive($closing, $actorId);
    }
}
