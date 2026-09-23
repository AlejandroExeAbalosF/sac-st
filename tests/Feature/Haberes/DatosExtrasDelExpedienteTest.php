<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Models\Expediente;
use App\Support\BusinessDate;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lo que el expediente guarda y la tarjeta muestra plegado.
 *
 * El alta ofrece esos campos bajo «Más datos del expediente» y hasta ahora
 * no había dónde volver a leerlos: quien cargaba el código de SiCE no
 * podía comprobar que hubiera quedado. Ahora vuelven al detalle, con el
 * mismo rótulo.
 */
class DatosExtrasDelExpedienteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    public function test_the_detail_carries_the_extra_data_of_the_expediente(): void
    {
        $operador = $this->operador();

        $this->actingAs($operador)->post(route('expedientes.store'), [
            'number' => '0030064-222222/2026-0',
            'subject' => 'Acta acuerdo — García Claudio Adrián c/ CIACSA',
            'receivedDate' => BusinessDate::today()->subDay()->toDateString(),
            'employerId' => 101,
            'declaredTotalAmount' => '1204500.00',
            'externalId' => 'EXP-2026-000123',
            'externalReference' => 'Nota 4471/2026',
        ])->assertSessionHasNoErrors();

        $nuevo = Expediente::query()->where('display_number', '222222/2026')->firstOrFail();

        $this->actingAs($operador)
            ->get(route('expedientes.show', $nuevo))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('haberes/show')
                ->where('extras.declaredTotalAmount', '1204500.00')
                ->where('extras.externalId', 'EXP-2026-000123')
                ->where('extras.externalReference', 'Nota 4471/2026')
                // Quién lo cargó no se veía en ninguna pantalla: estaba
                // solo en la auditoría, que el operador no abre.
                ->where('extras.createdByName', $operador->name)
                ->whereNot('extras.createdAt', null));
    }

    /**
     * Que el acta no traiga el total no es que el total sea cero.
     *
     * La tarjeta lo dice con esas palabras, así que el dato tiene que
     * llegar distinguible: el listado, que solo suma, sí lo aplana a cero.
     */
    public function test_a_missing_declared_total_is_not_zero(): void
    {
        $expediente = Expediente::query()->where('display_number', '125957/2026')->firstOrFail();
        $expediente->forceFill(['declared_total_amount' => null])->save();

        $this->actingAs($this->operador())
            ->get(route('expedientes.show', $expediente))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('extras.declaredTotalAmount', null)
                ->where('expediente.declaredTotalAmount', '0.00'));
    }

    /**
     * El haber dice quién lo cargó, y no hereda el autor del expediente.
     *
     * Son dos cargas distintas: el expediente llega una vez y el haber
     * puede aparecer meses después, cargado por otra persona. Se comprueba
     * en las dos pantallas que lo muestran.
     */
    public function test_each_haber_says_who_loaded_it(): void
    {
        $operador = $this->operador();

        // Expediente propio: en los sembrados el primer haber ya existe y
        // no tiene autor, así que no serviría para ver el del nuevo.
        $this->actingAs($operador)->post(route('expedientes.store'), [
            'number' => '0030064-222222/2026-0',
            'receivedDate' => BusinessDate::today()->subDay()->toDateString(),
            'employerId' => 101,
        ])->assertSessionHasNoErrors();

        $expediente = Expediente::query()->where('display_number', '222222/2026')->firstOrFail();

        $this->actingAs($operador)
            ->post(route('haberes.haber.store', $expediente), [
                'beneficiaryId' => 207, // Tinte, Olga Isabel
                'assignedAmount' => '600000.00',
                'expectedInstallmentCount' => 1,
                'concept' => 'Pago convenio homologado por Res. N° 3269/2025',
                'installments' => [
                    ['number' => 1, 'amount' => '600000.00', 'concept' => '', 'dueDate' => null, 'expectedMedium' => 'cash'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $haber = $expediente->haberes()->latest('id')->firstOrFail();

        // En su tarjeta, dentro del expediente.
        $this->actingAs($operador)
            ->get(route('expedientes.show', $expediente))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('expediente.haberes.0.createdByName', $operador->name)
                ->whereNot('expediente.haberes.0.createdAt', null));

        // Y en su propia ficha.
        $this->actingAs($operador)
            ->get(route('haberes.haber.show', [$expediente, $haber]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('haber.createdByName', $operador->name));
    }

    /**
     * El haber sembrado no tiene autor, y las pantallas abren igual.
     *
     * Es el caso que rompería si alguna vía dejara de cargar la relación:
     * con el modo estricto encendido, leerla suelta es una excepción.
     */
    public function test_a_haber_without_an_author_does_not_break_the_screen(): void
    {
        $expediente = Expediente::query()->where('display_number', '45905/2018')->firstOrFail();

        $this->actingAs($this->operador())
            ->get(route('expedientes.show', $expediente))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('expediente.haberes.0.createdByName', null)
                ->whereNot('expediente.haberes.0.createdAt', null));
    }

    /** El expediente sembrado no tiene autor, y la pantalla abre igual. */
    public function test_it_opens_when_nobody_is_recorded_as_the_author(): void
    {
        $expediente = Expediente::query()->where('display_number', '45905/2018')->firstOrFail();

        $this->actingAs($this->operador())
            ->get(route('expedientes.show', $expediente))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('extras.createdByName', null));
    }
}
