<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Models\Person;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * La salida completa de los tres historiales, fijada campo por campo.
 *
 * Los otros tests del historial miran un fragmento cada uno. Este mira el
 * evento entero —qué campos aparecen, en qué orden, cómo se escribe cada
 * valor— porque existe para que un cambio en cómo se arma el historial no
 * altere lo que el operador ya lee. Los eventos se escriben a mano y no
 * pasando por los Actions: lo que se fija es la presentación, no quién la
 * dispara.
 */
class HistorialSinRegresionTest extends TestCase
{
    use RefreshDatabase;

    private BeneficiaryInstallment $cuota;

    private Person $unaPersona;

    private Person $otraPersona;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);

        $this->cuota = BeneficiaryInstallment::query()
            ->whereHas('haber.expediente', fn ($q) => $q->whereNotNull('employer_id'))
            ->orderBy('id')
            ->firstOrFail();

        [$this->unaPersona, $this->otraPersona] = Person::query()->orderBy('id')->limit(2)->get()->all();
    }

    public function test_el_historial_del_expediente_no_cambia(): void
    {
        $expediente = $this->cuota->haber->expediente;
        $operador = $this->operador('contador');

        $this->evento('expediente.corregido', 'Expediente', $expediente->id, 20, null, [
            'employer_id' => $this->unaPersona->id,
            'declared_total_amount' => '1000.00',
            'received_date' => '2026-06-10',
        ], [
            'employer_id' => $this->otraPersona->id,
            'declared_total_amount' => '2500.50',
            'received_date' => '2026-06-01',
        ]);

        $this->evento('expediente.anulado', 'Expediente', $expediente->id, 10, $operador->id, [
            'status' => 'active',
        ], [
            'status' => 'cancelled',
        ], ['motivo' => 'Se cargó dos veces', 'haberes' => 2, 'cuotas' => 5]);

        $this->assertEventos($this->actingAs($operador)->getJson(route('expedientes.history', $expediente)), [
            [
                'action' => 'expediente.anulado',
                'by' => $operador->name,
                'changes' => [
                    ['field' => 'Estado', 'before' => 'Activo', 'after' => 'Anulado'],
                    ['field' => 'Motivo', 'before' => null, 'after' => 'Se cargó dos veces'],
                ],
            ],
            [
                'action' => 'expediente.corregido',
                'by' => null,
                'changes' => [
                    ['field' => 'Empleador', 'before' => $this->unaPersona->name, 'after' => $this->otraPersona->name],
                    ['field' => 'Fecha de recepción', 'before' => '10/06/2026', 'after' => '01/06/2026'],
                    ['field' => 'Total declarado', 'before' => '$ 1.000,00', 'after' => '$ 2.500,50'],
                ],
            ],
        ]);
    }

    public function test_el_historial_del_haber_no_cambia(): void
    {
        $haber = $this->cuota->haber;
        $operador = $this->operador('contador');

        $this->evento('haber.reconocido', 'Haber', $haber->id, 20, $operador->id, null, [
            'beneficiary_id' => $this->unaPersona->id,
            'assigned_amount' => '1200000.00',
            'expected_installment_count' => 2,
        ], ['expediente_id' => $haber->expediente_id, 'installments' => [['number' => 1]]]);

        $this->evento('haber.anulado', 'Haber', $haber->id, 10, $operador->id, [
            'workflow_status' => 'active',
            'block_reason' => null,
        ], [
            'workflow_status' => 'cancelled',
        ], ['motivo' => 'El acta no lo reconocía', 'importe' => '1200000.00']);

        $this->assertEventos($this->actingAs($operador)->getJson(route('haberes.haber.history', [$haber->expediente, $haber])), [
            [
                'action' => 'haber.anulado',
                'by' => $operador->name,
                'changes' => [
                    ['field' => 'Motivo del bloqueo', 'before' => null, 'after' => null],
                    ['field' => 'Estado', 'before' => 'Activo', 'after' => 'Anulado'],
                    ['field' => 'Motivo', 'before' => null, 'after' => 'El acta no lo reconocía'],
                ],
            ],
            [
                'action' => 'haber.reconocido',
                'by' => $operador->name,
                'changes' => [
                    ['field' => 'Beneficiario', 'before' => null, 'after' => $this->unaPersona->name],
                    ['field' => 'Importe reconocido', 'before' => null, 'after' => '$ 1.200.000,00'],
                    ['field' => 'Cuotas previstas', 'before' => null, 'after' => '2'],
                ],
            ],
        ]);
    }

    public function test_el_historial_de_la_cuota_no_cambia(): void
    {
        $operador = $this->operador('contador');
        $id = $this->cuota->id;

        $this->evento('cuota.corregida', 'BeneficiaryInstallment', $id, 30, $operador->id, [
            'expected_amount' => '100.00',
            'expected_medium' => 'cash',
            'due_date' => '2026-07-01',
            'beneficiary_installment_id' => $id,
        ], [
            'expected_amount' => '150.00',
            'expected_medium' => 'bank',
            'due_date' => '2026-08-01',
            'beneficiary_installment_id' => $id,
        ], ['haber_id' => $this->cuota->haber_id, 'unlock_reason' => 'Pedido del SAF']);

        $this->evento('cobro.anulado', 'BeneficiaryInstallment', $id, 20, null, null, null, [
            'fund_receipt_id' => 1,
            'amount' => '150.00',
            'motivo' => 'Error de carga',
        ]);

        $this->evento('cuota.edicion-habilitada', 'BeneficiaryInstallment', $id, 10, $operador->id, null, [
            'reason' => 'Pedido del SAF',
            'payment_order_id' => 5,
            'payment_order_number' => 'OP-0001/2026',
        ]);

        $this->assertEventos($this->actingAs($operador)->getJson(route('haberes.installments.history', $id)), [
            [
                'action' => 'cuota.edicion-habilitada',
                'by' => $operador->name,
                // Hasta que el catálogo pasó al servidor estos tres salían
                // con el nombre de la columna.
                'changes' => [
                    ['field' => 'Motivo', 'before' => null, 'after' => 'Pedido del SAF'],
                    ['field' => 'Orden de pago (id interno)', 'before' => null, 'after' => '5'],
                    ['field' => 'Orden de pago', 'before' => null, 'after' => 'OP-0001/2026'],
                ],
            ],
            [
                'action' => 'cobro.anulado',
                'by' => null,
                'changes' => [
                    ['field' => 'Importe', 'before' => null, 'after' => '$ 150,00'],
                    ['field' => 'Motivo', 'before' => null, 'after' => 'Error de carga'],
                ],
            ],
            [
                'action' => 'cuota.corregida',
                'by' => $operador->name,
                'changes' => [
                    ['field' => 'Vencimiento', 'before' => '01/07/2026', 'after' => '01/08/2026'],
                    ['field' => 'Importe', 'before' => '$ 100,00', 'after' => '$ 150,00'],
                    ['field' => 'Medio previsto', 'before' => 'Efectivo', 'after' => 'Depósito en cuenta'],
                ],
            ],
        ]);
    }

    /*
    |---------------------------------------------------------------------
    | Andamiaje
    |---------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>|null  $antes
     * @param  array<string, mixed>|null  $despues
     * @param  array<string, mixed>|null  $metadata
     */
    private function evento(
        string $accion,
        string $tipo,
        int $id,
        int $haceMinutos,
        ?int $usuario,
        ?array $antes,
        ?array $despues,
        ?array $metadata = null,
    ): void {
        AuditEvent::query()->create([
            'user_id' => $usuario,
            'action' => $accion,
            'subject_type' => $tipo,
            'subject_id' => $id,
            'old_values' => $antes,
            'new_values' => $despues,
            'metadata' => $metadata,
            'occurred_at' => now()->subMinutes($haceMinutos),
        ]);
    }

    /**
     * Compara acción, autor y cambios. El id y la hora dependen de la
     * corrida, y las claves que se sumen después no son regresión.
     *
     * El orden de los campos es el de `jsonb`, que no conserva el de
     * escritura: primero las claves más cortas.
     *
     * @param  list<array{action: string, by: string|null, changes: list<array<string, string|null>>}>  $esperados
     */
    private function assertEventos(TestResponse $respuesta, array $esperados): void
    {
        $respuesta->assertOk();

        /** @var list<array<string, mixed>> $eventos */
        $eventos = $respuesta->json('events');

        $this->assertSame($esperados, array_map(
            fn (array $evento): array => [
                'action' => $evento['action'],
                'by' => $evento['by'],
                'changes' => $evento['changes'],
            ],
            $eventos,
        ));
    }
}
