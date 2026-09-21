<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\PaymentTerms;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\HaberManagementLabel;
use App\Modules\Shared\Models\Person;
use Illuminate\Database\Seeder;

/**
 * Los seis casos del relevamiento, para recorrer el circuito.
 *
 * Son datos reales anonimizados de la planilla del área, no inventados: es
 * lo que hace que las pantallas se prueben contra la forma que tienen los
 * expedientes de verdad —un expediente con cinco beneficiarios, otro con
 * cuotas de importes distintos, uno bloqueado por CVU—.
 *
 * **Es opcional y no se carga solo.** Antes lo llamaba `DatabaseSeeder` en
 * desarrollo, así que una base recién migrada nacía con seis expedientes
 * que nadie pidió y no había forma de arrancar limpio. Ahora se pide:
 *
 * ```
 * php artisan db:seed --class=HaberesDemoSeeder
 * ```
 *
 * Es aditivo y no borra nada: si ya hay expedientes cargados, no hace nada.
 */
final class HaberesDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Expediente::query()->exists()) {
            return;
        }

        /*
         * El catálogo es un maestro y tiene seeder propio: si el demo lo
         * creara, apagarlo se llevaría puestas las etiquetas que el
         * formulario de la cuota necesita.
         *
         * Se lo llama igual —es idempotente— porque las cuotas de acá lo
         * usan: quien siembre solo este seeder, como hacen los tests de
         * haberes, tiene que encontrar el catálogo puesto.
         */
        $this->call(HaberManagementLabelSeeder::class);

        /*
         * Y las contrapartes: los expedientes de acá buscan a CIACSA y a
         * García por nombre y por documento. Antes las ponía la migración
         * de `people`, que las insertaba en cualquier entorno.
         */
        $this->call(PersonaDemoSeeder::class);

        $etiquetas = HaberManagementLabel::query()->pluck('id', 'code')->all();

        foreach ($this->casos() as $caso) {
            $this->crearExpediente($caso, $etiquetas);
        }
    }

    /**
     * @param  array<string, mixed>  $caso
     * @param  array<string, int>  $etiquetas
     */
    private function crearExpediente(array $caso, array $etiquetas): void
    {
        $empleador = Person::query()->where('name', $caso['employer'])->firstOrFail();

        $expediente = Expediente::query()->create([
            'source_system' => 'SiCE v3.0',
            'canonical_number' => $caso['canonical'],
            'display_number' => $caso['display'],
            'year' => (int) substr($caso['display'], -4),
            'received_date' => $caso['receivedDate'],
            'employer_id' => $empleador->id,
            'employer_role' => 'employer',
            'employer_representative' => $caso['representative'] ?? null,
            'subject' => $caso['subject'],
            'declared_total_amount' => $caso['declared'],
            'status' => $caso['status'],
        ]);

        foreach ($caso['haberes'] as $datos) {
            $beneficiario = Person::query()->where('document', $datos['document'])->firstOrFail();

            /*
             * El concepto base es el de la primera cuota, y las que lo
             * comparten se guardan sin concepto propio. Así los datos de
             * muestra tienen la forma real: el caso Tinte —dos cuotas del
             * mismo haber con conceptos distintos— queda como la excepción
             * que es, y el resto ejercita la herencia.
             */
            $conceptoBase = $datos['installments'][0]['concept'];

            $haber = $expediente->haberes()->create([
                'concept' => $conceptoBase,
                'beneficiary_id' => $beneficiario->id,
                'beneficiary_role' => 'beneficiary',
                'assigned_amount' => $datos['amount'],
                'expected_installment_count' => $datos['expected'],
                'payment_terms' => $datos['expected'] > 1
                    ? PaymentTerms::Installments
                    : PaymentTerms::Single,
                'workflow_status' => $datos['status'],
                'block_reason' => $datos['blockReason'] ?? null,
            ]);

            foreach ($datos['installments'] as $numero => $cuota) {
                $haber->installments()->create([
                    'installment_number' => $numero + 1,
                    'expected_amount' => $cuota['amount'],
                    'management_label_id' => $etiquetas[$cuota['label']] ?? null,
                    'description' => $cuota['concept'] === $conceptoBase
                        ? null
                        : $cuota['concept'],
                    /*
                     * Efectivo por defecto: es cómo entra la mayoría en
                     * Haberes, y desde que el medio es obligatorio el
                     * catálogo no puede dejarlo sin declarar. Los escenarios
                     * que necesitan otro lo dicen en su propia fila.
                     */
                    'expected_medium' => $cuota['medium'] ?? 'cash',
                    'notes' => $cuota['notes'] ?? null,
                    'workflow_status' => $cuota['status'],
                ]);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function casos(): array
    {
        $pagada = InstallmentWorkflowStatus::Paid;
        $activa = InstallmentWorkflowStatus::Active;

        return [
            [
                'display' => '125957/2026',
                'canonical' => '0030064-125957/2026-0',
                'subject' => 'Acta acuerdo — García Claudio Adrián c/ CIACSA',
                'employer' => 'CIACSA',
                'representative' => 'Sosa, Ricardo Daniel',
                'declared' => '1204500.00',
                'status' => ExpedienteStatus::Active,
                'receivedDate' => '2026-07-18',
                'haberes' => [[
                    'document' => '28114902',
                    'amount' => '1204500.00',
                    'expected' => 2,
                    'status' => HaberWorkflowStatus::Active,
                    'installments' => [
                        ['amount' => '602250.00', 'label' => 'T.CONOC.', 'concept' => 'Pago convenio homologado', 'status' => $activa,
                            'medium' => 'cash', 'notes' => 'Depositada por mostrador junto con la carátula.'],
                        ['amount' => '602250.00', 'label' => 'PAGAR', 'concept' => 'Pago convenio homologado', 'status' => $activa,
                            'medium' => 'bank'],
                    ],
                ]],
            ],
            [
                'display' => '120326/2019',
                'canonical' => '0030064-120326/2019-0',
                'subject' => 'Bulacio Víctor Mario y otros c/ Transporte Andino SRL',
                'employer' => 'Transporte Andino SRL',
                'declared' => '3410000.00',
                'status' => ExpedienteStatus::Active,
                'receivedDate' => '2019-11-04',
                // Un acto, cinco haberes: es el caso que demostró que no
                // hacía falta una tabla intermedia entre expediente y haber.
                'haberes' => [
                    [
                        'document' => '22908114', 'amount' => '682000.00', 'expected' => 1,
                        'status' => HaberWorkflowStatus::Closed,
                        'installments' => [['amount' => '682000.00', 'label' => 'PAGAR', 'concept' => 'Indemnización', 'status' => $pagada]],
                    ],
                    [
                        'document' => '25114780', 'amount' => '954000.00', 'expected' => 3,
                        'status' => HaberWorkflowStatus::Active,
                        'installments' => [
                            ['amount' => '318000.00', 'label' => 'PAGAR', 'concept' => 'Indemnización', 'status' => $pagada],
                            ['amount' => '318000.00', 'label' => 'PAGAR', 'concept' => 'Indemnización', 'status' => $pagada],
                            ['amount' => '318000.00', 'label' => 'T.CONOC.', 'concept' => 'Indemnización', 'status' => $activa],
                        ],
                    ],
                    [
                        'document' => '20887431', 'amount' => '745000.00', 'expected' => 1,
                        'status' => HaberWorkflowStatus::Closed,
                        'installments' => [['amount' => '745000.00', 'label' => 'PAGAR', 'concept' => 'Indemnización', 'status' => $pagada]],
                    ],
                    [
                        'document' => '24551209', 'amount' => '620000.00', 'expected' => 2,
                        'status' => HaberWorkflowStatus::Active,
                        'installments' => [
                            ['amount' => '310000.00', 'label' => 'PAGAR', 'concept' => 'Indemnización', 'status' => $pagada],
                            ['amount' => '310000.00', 'label' => 'T.CONOC.', 'concept' => 'Indemnización', 'status' => $activa],
                        ],
                    ],
                    [
                        'document' => '18774300', 'amount' => '409000.00', 'expected' => 1,
                        'status' => HaberWorkflowStatus::Closed,
                        'installments' => [['amount' => '409000.00', 'label' => 'PAGAR', 'concept' => 'Indemnización', 'status' => $pagada]],
                    ],
                ],
            ],
            [
                'display' => '45905/2018',
                'canonical' => '0030064-045905/2018-0',
                'subject' => 'Tinte Olga Isabel c/ Frigorífico del Norte SA',
                'employer' => 'Frigorífico del Norte SA',
                'declared' => '5790.06',
                'status' => ExpedienteStatus::Closed,
                'receivedDate' => '2018-03-22',
                // El caso que confirmó que el concepto vive en la cuota:
                // dos cuotas del mismo haber con conceptos distintos.
                'haberes' => [[
                    'document' => '13998220',
                    'amount' => '5790.06',
                    'expected' => 2,
                    'status' => HaberWorkflowStatus::Closed,
                    'installments' => [
                        ['amount' => '2895.03', 'label' => 'T.CONOC.', 'concept' => 'Haberes adeudados', 'status' => $pagada, 'medium' => 'cash'],
                        ['amount' => '2895.03', 'label' => 'PAGAR', 'concept' => 'Sac proporcional', 'status' => $pagada, 'medium' => 'cheque'],
                    ],
                ]],
            ],
            [
                'display' => '101206/2026',
                'canonical' => '0030064-101206/2026-0',
                'subject' => 'Zerpa Rosana Beatriz c/ Distribuidora Sur SA',
                'employer' => 'Distribuidora Sur SA',
                'declared' => '780000.00',
                'status' => ExpedienteStatus::Active,
                'receivedDate' => '2026-05-30',
                'haberes' => [[
                    'document' => '30225118',
                    'amount' => '780000.00',
                    'expected' => 1,
                    'status' => HaberWorkflowStatus::Blocked,
                    'blockReason' => 'La cuenta informada es un CVU: el organismo no transfiere a billeteras virtuales',
                    'installments' => [['amount' => '780000.00', 'label' => 'PAGAR', 'concept' => 'Indemnización', 'status' => $activa]],
                ]],
            ],
            [
                'display' => '98311/2025',
                'canonical' => '0030064-098311/2025-0',
                'subject' => 'Ledesma Ariel Osvaldo c/ Metalúrgica Salta SRL',
                'employer' => 'Metalúrgica Salta SRL',
                'declared' => '415800.00',
                'status' => ExpedienteStatus::Active,
                'receivedDate' => '2025-09-12',
                'haberes' => [[
                    'document' => '27443190',
                    'amount' => '415800.00',
                    'expected' => 2,
                    'status' => HaberWorkflowStatus::Blocked,
                    'blockReason' => 'Cuota con etiqueta P.P. HOMOL.: no se libera sin homologación previa',
                    'installments' => [
                        ['amount' => '207900.00', 'label' => 'P.P. HOMOL.', 'concept' => 'Diferencias salariales', 'status' => $activa],
                        ['amount' => '207900.00', 'label' => 'P.P. HOMOL.', 'concept' => 'Diferencias salariales', 'status' => $activa],
                    ],
                ]],
            ],
            [
                'display' => '77420/2024',
                'canonical' => '0030064-077420/2024-0',
                'subject' => 'Cruz Mirta Elena c/ Servicios Integrales SRL',
                'employer' => 'Servicios Integrales SRL',
                'declared' => '96000.00',
                'status' => ExpedienteStatus::Active,
                'receivedDate' => '2024-06-07',
                'haberes' => [[
                    'document' => '26330871',
                    'amount' => '96000.00',
                    'expected' => 1,
                    'status' => HaberWorkflowStatus::Active,
                    'installments' => [['amount' => '96000.00', 'label' => 'T.CONOC.', 'concept' => 'Haberes adeudados', 'status' => $activa]],
                ]],
            ],
        ];
    }
}
