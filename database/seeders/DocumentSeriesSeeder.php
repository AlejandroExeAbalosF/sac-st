<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Shared\Models\DocumentSeries;
use Illuminate\Database\Seeder;

/**
 * El catálogo de series — §9.8 del DER.
 *
 * Idempotente y fuera de las migraciones, igual que los permisos: una
 * serie es un dato, no un cambio de esquema.
 *
 * **`next_number` no se toca al reseedear.** Es lo único de esta tabla que
 * el sistema escribe solo, y pisarlo con `1` volvería a emitir números ya
 * usados. Por eso el seeder crea lo que falta y actualiza únicamente lo
 * descriptivo.
 */
class DocumentSeriesSeeder extends Seeder
{
    /**
     * @var list<array{code: string, document_type: string, origin: string, label: string}>
     */
    private const SERIES = [
        /*
         * Una sola serie por tipo de comprobante.
         *
         * Había dos —`system` y `talonario_loaded`— y nacieron de suponer
         * que el papel preimpreso traía su propia numeración en pie de
         * igualdad. El área confirmó lo contrario: **el número del sistema
         * es siempre el identificador**, y el del talonario se registra
         * al lado cuando el papel existe. Con eso, dos correlativos para
         * el mismo documento sobran y vuelven cada reporte una suma de
         * series. De dónde vino el comprobante lo dice `issue_mode`.
         */
        [
            'code' => '0010',
            'document_type' => 'receipt_income',
            'origin' => 'system',
            'label' => 'Recibo de ingreso — Haberes',
        ],
        [
            'code' => '0020',
            'document_type' => 'receipt_expense',
            'origin' => 'system',
            'label' => 'Recibo de egreso — Haberes',
        ],
        [
            'code' => '0030',
            'document_type' => 'payment_order',
            'origin' => 'system',
            'label' => 'Orden de Pago — Haberes',
        ],
    ];

    public function run(): void
    {
        foreach (self::SERIES as $serie) {
            $existente = DocumentSeries::query()->where('code', $serie['code'])->first();

            if ($existente !== null) {
                // Solo lo descriptivo. El correlativo es del sistema.
                $existente->forceFill([
                    'label' => $serie['label'],
                    'document_type' => $serie['document_type'],
                    'origin' => $serie['origin'],
                ])->save();

                continue;
            }

            DocumentSeries::query()->create([
                ...$serie,
                'format_pattern' => '{code}/{number}',
                'code_padding' => 4,
                'number_padding' => 8,
                'reset_rule' => 'never',
                'next_number' => 1,
                'is_active' => true,
            ]);
        }
    }
}
