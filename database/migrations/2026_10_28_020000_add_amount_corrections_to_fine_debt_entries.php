<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La corrección del importe de una multa, sin resolución.
 *
 * Un aumento o una reducción eran siempre lo que disponía una resolución
 * (M04). El área confirmó (28/10/2026) que el importe mal cargado no se
 * corrige con un papel, así que un ajuste puede ser una de dos cosas:
 *
 * - **con documento**: lo dispone una resolución, con un motivo de la lista
 *   del ajuste;
 * - **sin documento**: es una corrección del importe, con un motivo de su
 *   propia lista y siempre con el detalle, que es lo único que la explica.
 *
 * Cuál de las dos es lo dice el documento, no una columna nueva: un ajuste
 * sin resolución ya no se confunde con uno que la perdió, porque los motivos
 * de cada lista no se cruzan. El resto de la regla no cambia.
 */
return new class extends Migration
{
    private const AJUSTE = "'appeal_admitted', 'court_ruling', 'resolution_correction', 'other'";

    private const CORRECCION_DE_IMPORTE = "'data_entry_error', 'misread_paper', 'other'";

    private const REVERSION = "'data_entry_error', 'wrong_fine', 'duplicate', 'other'";

    public function up(): void
    {
        $ajuste = self::AJUSTE;
        $correccion = self::CORRECCION_DE_IMPORTE;
        $reversion = self::REVERSION;

        DB::unprepared(<<<SQL
            ALTER TABLE fine_debt_entries
                DROP CONSTRAINT fine_debt_entries_reason_check,
                ADD CONSTRAINT fine_debt_entries_reason_check CHECK (
                    (kind = 'constitution') = (reason_code IS NULL)
                    AND (kind <> 'reversal' OR reason_code IN ({$reversion}))
                    AND (kind NOT IN ('increase', 'reduction')
                        OR (fine_document_id IS NOT NULL AND reason_code IN ({$ajuste}))
                        OR (fine_document_id IS NULL AND reason_code IN ({$correccion}) AND reason IS NOT NULL))
                    AND (reason IS NULL OR btrim(reason) <> '')
                    AND (reason_code IS DISTINCT FROM 'other' OR reason IS NOT NULL)
                );
        SQL);
    }

    public function down(): void
    {
        $ajuste = self::AJUSTE;
        $reversion = self::REVERSION;

        DB::unprepared(<<<SQL
            ALTER TABLE fine_debt_entries
                DROP CONSTRAINT fine_debt_entries_reason_check,
                ADD CONSTRAINT fine_debt_entries_reason_check CHECK (
                    (kind = 'constitution') = (reason_code IS NULL)
                    AND (kind <> 'reversal' OR reason_code IN ({$reversion}))
                    AND (kind NOT IN ('increase', 'reduction') OR reason_code IN ({$ajuste}))
                    AND (reason IS NULL OR btrim(reason) <> '')
                    AND (reason_code IS DISTINCT FROM 'other' OR reason IS NOT NULL)
                ) NOT VALID;
        SQL);
    }
};
