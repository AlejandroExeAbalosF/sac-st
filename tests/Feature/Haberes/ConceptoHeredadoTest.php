<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Actions\AddHaber;
use App\Modules\Haberes\Actions\AddInstallment;
use App\Modules\Haberes\Data\InstallmentListItemData;
use App\Modules\Haberes\Data\SaveInstallmentData;
use App\Modules\Haberes\Enums\ExpectedMedium;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Shared\Models\Person;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El concepto vive en el haber y la cuota lo hereda.
 *
 * Las dos vías que crean una cuota tienen que dejar el mismo dato para la
 * misma intención: «esta cuota no tiene concepto propio». Si una guarda una
 * copia y la otra guarda `null`, corregir el concepto del haber parte el
 * expediente en dos —las cuotas viejas conservan la copia vieja y las
 * nuevas toman la corregida—, y las dos salen impresas en comprobantes
 * distintos del mismo haber.
 */
class ConceptoHeredadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    public function test_both_paths_store_the_same_thing_for_an_inherited_concept(): void
    {
        $haber = $this->haberConDosCuotas();

        $descripciones = $haber->installments()
            ->orderBy('installment_number')
            ->pluck('description', 'installment_number')
            ->all();

        $this->assertSame(
            $descripciones[1],
            $descripciones[2],
            'Las dos cuotas se cargaron sin concepto propio, así que tienen que haber guardado lo mismo.',
        );
    }

    public function test_correcting_the_haber_concept_reaches_every_inherited_installment(): void
    {
        $haber = $this->haberConDosCuotas();

        $haber->update(['concept' => 'CONCEPTO CORREGIDO']);
        $haber->refresh()->load('installments.managementLabel');

        $conceptos = $haber->installments
            ->map(fn ($cuota): ?string => InstallmentListItemData::fromModel($cuota, $haber->concept)->concept)
            ->unique()
            ->values();

        $this->assertCount(
            1,
            $conceptos,
            'Ninguna cuota tenía concepto propio, así que todas tienen que mostrar el corregido: '.$conceptos->implode(' | '),
        );
        $this->assertSame('CONCEPTO CORREGIDO', $conceptos->first());
    }

    /**
     * Un haber cuya primera cuota se carga en el alta y la segunda llega
     * después, ninguna con concepto propio. Es el camino real: el
     * expediente vuelve con el ticket de la cuota siguiente.
     */
    private function haberConDosCuotas(): Haber
    {
        $expediente = Expediente::query()->where('display_number', '77420/2024')->firstOrFail();
        $beneficiario = Person::query()->where('document', '13998220')->firstOrFail();

        $haber = app(AddHaber::class)->handle($expediente, [
            'beneficiaryId' => $beneficiario->id,
            'assignedAmount' => '1000.00',
            'expectedInstallmentCount' => 2,
            'concept' => 'CONCEPTO ORIGINAL',
            'installments' => [['number' => 1, 'amount' => '500.00', 'expectedMedium' => 'cash']],
        ], null);

        app(AddInstallment::class)->handle($haber, new SaveInstallmentData(
            amount: '500.00',
            managementLabelId: null,
            concept: null,
            dueDate: null,
            expectedMedium: ExpectedMedium::Cash,
            notes: null,
            version: null,
        ));

        return $haber->refresh();
    }
}
