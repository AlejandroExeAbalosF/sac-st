<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Models\Expediente;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El haber se numera dentro de su expediente.
 *
 * Antes se identificaba en la dirección por su id, que es un contador
 * global: dos haberes del mismo expediente podían ser el 3 y el 148. El
 * ordinal hace que la dirección se lea como el papel.
 */
class NumeroDelHaberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    /** Cruz Mirta Elena c/ Servicios Integrales SRL. */
    private const EXPEDIENTE = '77420/2024';

    public function test_the_numbering_starts_at_one_in_each_expediente(): void
    {
        $numeros = Expediente::query()
            ->has('haberes')
            ->with('haberes')
            ->get()
            ->map(fn (Expediente $expediente): array => $expediente->haberes
                ->pluck('haber_number')
                ->sort()
                ->values()
                ->all());

        $this->assertNotEmpty($numeros);

        foreach ($numeros as $delExpediente) {
            $this->assertSame(range(1, count($delExpediente)), $delExpediente);
        }
    }

    public function test_a_new_haber_takes_the_next_number(): void
    {
        $expediente = $this->expediente();
        $ultimo = (int) $expediente->haberes()->max('haber_number');

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $expediente), $this->haber())
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $ultimo + 1,
            $expediente->haberes()->latest('id')->firstOrFail()->haber_number,
        );
    }

    /**
     * El número no se recicla, y no hizo falta inventar la regla: acá nada
     * se borra, así que el haber anulado sigue ocupando el suyo y
     * `max + 1` no puede chocar con él.
     */
    public function test_an_annulled_haber_keeps_its_number(): void
    {
        $expediente = $this->expediente();
        $operador = $this->operador();

        $this->actingAs($operador)
            ->post(route('haberes.haber.store', $expediente), $this->haber())
            ->assertSessionHasNoErrors();

        $cargado = $expediente->haberes()->latest('id')->firstOrFail();

        $this->actingAs($operador)
            ->patch(route('haberes.haber.cancel', [$expediente, $cargado]), [
                'reason' => 'Cargado por error.',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($operador)
            ->post(route('haberes.haber.store', $expediente), [
                ...$this->haber(),
                'beneficiaryId' => 202, // García Claudio Adrián
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $cargado->haber_number + 1,
            $expediente->haberes()->latest('id')->firstOrFail()->haber_number,
        );
    }

    /** La garantía no es el Action: es el índice de la base. */
    public function test_the_database_rejects_a_repeated_number(): void
    {
        $expediente = $this->expediente();
        $primero = $expediente->haberes()->orderBy('id')->firstOrFail();

        $this->expectException(QueryException::class);

        $expediente->haberes()->create([
            'haber_number' => $primero->haber_number,
            'beneficiary_id' => 202,
            'beneficiary_role' => 'beneficiary',
            'assigned_amount' => '1000.00',
            'expected_installment_count' => 1,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function haber(): array
    {
        return [
            'beneficiaryId' => 207, // Tinte, Olga Isabel
            'assignedAmount' => '600000.00',
            'expectedInstallmentCount' => 1,
            'concept' => 'Pago convenio homologado',
            'installments' => [
                ['number' => 1, 'amount' => '600000.00', 'concept' => '', 'dueDate' => null, 'expectedMedium' => 'cash'],
            ],
        ];
    }

    private function expediente(): Expediente
    {
        return Expediente::query()->where('display_number', self::EXPEDIENTE)->firstOrFail();
    }
}
