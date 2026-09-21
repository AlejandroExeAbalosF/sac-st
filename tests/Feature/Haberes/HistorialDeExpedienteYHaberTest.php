<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Models\User;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Shared\Models\Person;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El rastro del expediente y del haber, leído desde el panel.
 *
 * Los tres historiales —cuota, haber y expediente— devuelven la misma
 * forma porque los arma `AuditTimeline`, y el panel los dibuja con el
 * mismo componente. Lo que se prueba acá es que esa forma llegue completa
 * para los dos sujetos nuevos: la acción, quién la hizo, el antes y el
 * después traducidos, y **el motivo**, que no vive entre los campos
 * cambiados sino en la metadata y por eso es el que más fácil se pierde.
 */
class HistorialDeExpedienteYHaberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    public function test_el_historial_del_expediente_cuenta_la_correccion_con_su_antes_y_su_despues(): void
    {
        $expediente = $this->expediente();
        $operador = $this->operador();

        $this->actingAs($operador)
            ->patch(route('expedientes.update', $expediente), [
                ...$this->ficha($expediente),
                'subject' => 'Carátula corregida',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($operador)
            ->getJson(route('expedientes.history', $expediente))
            ->assertOk()
            ->assertJsonPath('events.0.action', 'expediente.corregido')
            ->assertJsonPath('events.0.by', $operador->refresh()->name)
            ->assertJsonPath('events.0.changes.0.field', 'Carátula')
            ->assertJsonPath('events.0.changes.0.before', $expediente->subject)
            ->assertJsonPath('events.0.changes.0.after', 'Carátula corregida');
    }

    /**
     * Las fechas se escriben como en el resto del sistema.
     *
     * Salían como las guarda la base —`2026-06-10`— mientras cada otra
     * pantalla dice `10/06/2026`.
     *
     * **Y el día no se corre.** Se reordenan los tres números sin parsear
     * ni convertir de huso: un `date` describe un día del almanaque, y
     * leerlo como medianoche UTC para presentarlo en Salta lo mandaría al
     * día anterior. El 1 de un mes retrocedería al mes pasado, que es
     * donde eso deja de ser un detalle.
     */
    public function test_las_fechas_del_historial_no_salen_en_iso(): void
    {
        $expediente = $this->expediente();
        $operador = $this->operador();

        $this->actingAs($operador)
            ->patch(route('expedientes.update', $expediente), [
                ...$this->ficha($expediente),
                'receivedDate' => '2026-06-01',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($operador)
            ->getJson(route('expedientes.history', $expediente))
            ->assertOk()
            ->assertJsonFragment([
                'field' => 'Fecha de recepción',
                'after' => '01/06/2026',
            ]);
    }

    /**
     * El motivo de la anulación llega, aunque el estado también cambie.
     *
     * Es el caso que se pierde solo: el evento trae su antes y su después
     * —de `active` a `cancelled`—, así que un historial que mire nada más
     * que los campos cambiados muestra el cambio de estado y se come la
     * única parte que explica por qué alguien lo hizo.
     */
    public function test_la_anulacion_del_haber_trae_el_motivo(): void
    {
        $haber = $this->expediente()->haberes()->orderBy('id')->firstOrFail();
        $contador = $this->operador('contador');

        $this->actingAs($contador)
            ->patch(route('haberes.haber.cancel', [$haber->expediente, $haber]), [
                'reason' => 'El acta no lo reconocía; se cargó de más.',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($contador)
            ->getJson(route('haberes.haber.history', [$haber->expediente, $haber]))
            ->assertOk()
            ->assertJsonPath('events.0.action', 'haber.anulado')
            ->assertJsonFragment(['field' => 'Estado', 'before' => 'Activo', 'after' => 'Anulado'])
            ->assertJsonFragment([
                'field' => 'Motivo',
                'before' => null,
                'after' => 'El acta no lo reconocía; se cargó de más.',
            ]);
    }

    /**
     * El alta del haber traduce el id del beneficiario a su nombre.
     *
     * El registro guarda el id y hace bien —el nombre puede corregirse y
     * el rastro tiene que seguir señalando a la misma persona—, pero un
     * número en pantalla no le dice nada a nadie.
     */
    public function test_el_alta_del_haber_nombra_al_beneficiario(): void
    {
        $expediente = $this->expediente();
        $operador = $this->operador();

        /*
         * El haber se da de alta de verdad y no se toma uno del sembrado:
         * el seeder escribe las filas sin pasar por el Action, así que
         * esos haberes no tienen evento de alta —y no tenerlo es correcto,
         * nadie los reconoció—.
         */
        $this->actingAs($operador)
            ->post(route('haberes.haber.store', $expediente), [
                'beneficiaryId' => 207,
                'assignedAmount' => '1200000.00',
                'expectedInstallmentCount' => 1,
                'concept' => 'Alta para el historial',
                'installments' => [
                    ['number' => 1, 'amount' => '1200000.00', 'concept' => '', 'dueDate' => null, 'expectedMedium' => 'cash'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $haber = $expediente->haberes()->orderByDesc('id')->firstOrFail();
        $nombre = Person::query()->findOrFail($haber->beneficiary_id)->name;

        $this->actingAs($operador)
            ->getJson(route('haberes.haber.history', [$expediente, $haber]))
            ->assertOk()
            ->assertJsonPath('events.0.action', 'haber.reconocido')
            ->assertJsonFragment(['field' => 'Beneficiario', 'after' => $nombre])
            ->assertJsonFragment(['field' => 'Importe reconocido', 'after' => '$ 1.200.000,00']);
    }

    /** Un haber que nadie tocó devuelve una lista vacía, no un error. */
    public function test_un_haber_sin_cambios_devuelve_una_lista_vacia(): void
    {
        $haber = Haber::query()
            ->whereDoesntHave('expediente', fn ($q) => $q->whereNull('employer_id'))
            ->orderByDesc('id')
            ->firstOrFail();

        $this->actingAs($this->operador())
            ->getJson(route('haberes.haber.history', [$haber->expediente, $haber]))
            ->assertOk()
            ->assertJsonCount(0, 'events');
    }

    public function test_sin_permiso_de_ver_expedientes_no_hay_historial(): void
    {
        $expediente = $this->expediente();

        $this->actingAs(User::factory()->create())
            ->getJson(route('expedientes.history', $expediente))
            ->assertForbidden();
    }

    /*
    |---------------------------------------------------------------------
    | Andamiaje
    |---------------------------------------------------------------------
    */

    private function expediente(): Expediente
    {
        return Expediente::query()
            ->whereNotNull('employer_id')
            ->orderBy('id')
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function ficha(Expediente $expediente): array
    {
        return [
            'subject' => $expediente->subject,
            'receivedDate' => $expediente->received_date?->toDateString(),
            'employerId' => $expediente->employer_id,
            'employerRepresentative' => $expediente->employer_representative,
            'declaredTotalAmount' => $expediente->declared_total_amount,
            'externalReference' => $expediente->external_reference,
            'notes' => $expediente->notes,
        ];
    }
}
