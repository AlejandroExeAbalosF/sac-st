<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Aranceles\Enums\FeeCalculationMode;
use App\Modules\Aranceles\Models\FeeType;
use App\Modules\Aranceles\Models\FeeTypePrice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * El catálogo inicial: las 25 tasas del Anexo del Decreto 1202/2019 (Boletín
 * Oficial N.º 20.577, págs. 13-14).
 *
 * **Idempotente y sin pisar.** Un código que ya existe no se toca: lo que el
 * área corrigió desde la pantalla no vuelve al valor del decreto.
 *
 * El código numérico como posición en el anexo es una interpretación de la foto
 * del recibo (códigos 14 y 25): está para confirmar con el área. La vigencia
 * arranca en la fecha del decreto: desde cuándo se aplicó no está relevado.
 */
class FeeTypeSeeder extends Seeder
{
    private const string VIGENCIA = '2019-09-02';

    private const string REFERENCIA = 'Decreto 1202/2019';

    /** @var list<array{0: string, 1: string, 2: string, 3?: string}> */
    private const array TIPOS = [
        ['a', 'Autorización y Rúbrica de Libro de Registro Único (Art. 52 Ley 20.744)', '175.00'],
        ['b', 'Autorización de Sistema de Planillas de Hojas Móviles o similar', '85.00'],
        ['c', 'Rúbrica de Hojas Móviles o similar por folio', '5.00', 'folio'],
        ['d', 'Rúbrica de Libro de Accidentes de Trabajo, Homologación de Incapacidades Laborales, Juntas Médicas solicitadas por el Empleador', '85.00'],
        ['e', 'Rúbrica de Libro de Contaminantes', '85.00'],
        ['f', 'Rúbrica de Libros de Viajantes de Comercio (Ley 14.546) y de Trabajadores a Domicilio (Ley 12.713)', '85.00'],
        ['g', 'Rúbrica de Hojas de Ruta de Chóferes de Camiones (CCT 40/89) por folio', '5.00', 'folio'],
        ['h', 'Rúbrica de Libreta de Chóferes de Transporte Automotor (Ley 6440)', '60.00'],
        ['i', 'Rúbrica de Libreta de Chóferes de Taxis y Remises (Dcto. Nac. 1038, Resol. DPTS N° 615)', '60.00'],
        ['j', 'Solicitud de informes por escrito, solicitud de antecedentes de archivo', '30.00'],
        ['k', 'Solicitud de prórroga por presentación de documentación laboral y por audiencias', '30.00'],
        ['l', 'Asesoramientos empresariales', '300.00'],
        ['m', 'Procedimiento Arbitral (Ley 6.291/84 Art. 28)', '600.00'],
        ['n', 'Homologación de acuerdos individuales pasibles de apreciación pecuniaria', '120.00'],
        ['o', 'Homologación de conciliación en conflictos colectivos (% sobre monto)', '0.07'],
        ['p', 'Certificación de antecedentes para licitación y proveedores del Estado', '60.00'],
        ['q', 'Certificación de firma fuera de la sede de administración', '60.00'],
        ['r', 'Verificación de Higiene y Seguridad solicitada por empresas para certificar condiciones y estándares de Higiene y Seguridad', '500.00'],
        ['s', 'Inscripción de inicio de obras de construcción', '175.00'],
        ['t', 'Iniciación de Procedimiento Preventivo de Crisis previsto en la Ley 24.013; Dcto. 328/88; Dcto. 264 y 265/02 y demás disposiciones complementarias que se dicten', '300.00'],
        ['u', 'Fotocopias de Expedientes, Escalas Salariales, Convenios Colectivos, Contratos de Trabajo (Por folio)', '3.00', 'folio'],
        ['v', 'Homologación de Convenios de Acciones de Trabajo y Contratos en General', '120.00'],
        ['w', 'Homologación de Exámenes Preocupacionales, Posocupacionales y Periódicos (Ley 24.557 y Art. 188 Ley 20.744)', '85.00'],
        ['x', 'Libro de Visitas de Inspectores de la DPTS', '85.00'],
        ['y', 'Notificaciones', '50.00'],
    ];

    public function run(): void
    {
        foreach (self::TIPOS as $indice => $fila) {
            $codigo = $indice + 1;

            if (FeeType::query()->where('code', $codigo)->exists()) {
                continue;
            }

            $esPorcentaje = $fila[0] === 'o';

            DB::transaction(function () use ($codigo, $fila, $esPorcentaje): void {
                $tipo = FeeType::query()->create([
                    'code' => $codigo,
                    'decree_letter' => $fila[0],
                    'name' => $fila[1],
                    'calculation_mode' => $esPorcentaje ? FeeCalculationMode::Percentage : FeeCalculationMode::Unit,
                    'unit_label' => $fila[3] ?? null,
                ]);

                FeeTypePrice::query()->create([
                    'fee_type_id' => $tipo->id,
                    'valid_from' => self::VIGENCIA,
                    'unit_price' => $esPorcentaje ? null : $fila[2],
                    'rate' => $esPorcentaje ? $fila[2] : null,
                    'legal_reference' => self::REFERENCIA,
                ]);
            });
        }
    }
}
