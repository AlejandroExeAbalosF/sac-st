<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Data\InstallmentReceiptData;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Shared\Models\Receipt;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lo que hay que traer cargado para armar el recibo de una cuota.
 *
 * `InstallmentReceiptData::fromModel()` lee dos relaciones —quién emitió y
 * quién anuló— y las consultas que lo alimentan traían una sola. Con
 * `shouldBeStrict` encendido eso no es una consulta de más: es un 500 en
 * la cara del operador, y así apareció.
 *
 * **Las dos pruebas cobran dos cuotas, y ese detalle es la prueba misma.**
 * Eloquent marca los modelos como celosos del lazy loading solo cuando la
 * consulta trajo más de una fila (`Builder::hydrate`): da por hecho que
 * quien pide uno solo sabe lo que hace. Con un único recibo en la base la
 * relación faltante se resuelve en silencio y no hay nada que ver —que es
 * exactamente por qué el error apareció recién en una pantalla con varias
 * cuotas cobradas—.
 */
class InstallmentReceiptDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);

        $this->assertTrue(
            Model::preventsLazyLoading(),
            'Sin modo estricto esta prueba no verifica nada.',
        );

        $this->cobrarLasDosCuotas();
    }

    /** Con lo que la constante declara, el DTO se arma sin tocar la base. */
    public function test_las_relaciones_declaradas_alcanzan(): void
    {
        $recibos = $this->recibos(InstallmentReceiptData::RELATIONS);

        $this->assertCount(2, $recibos);

        foreach ($recibos as $recibo) {
            $dto = InstallmentReceiptData::fromModel($recibo);

            $this->assertSame($recibo->formatted_number, $dto->formattedNumber);
            $this->assertNotNull($dto->issuedByName);
            $this->assertNull($dto->voidedByName);
        }
    }

    /**
     * Y hacen falta las dos.
     *
     * Es la prueba de que la constante no es decorativa: cargar de menos
     * —lo que hacía la consulta original— revienta, aunque los recibos
     * estén vigentes y `voided_by` sea nulo. Eloquent no pregunta si la
     * columna tiene valor; pregunta si la relación se cargó.
     */
    public function test_cargar_de_menos_revienta(): void
    {
        $recibos = $this->recibos(['issuedBy:id,name']);

        $this->expectException(LazyLoadingViolationException::class);
        $this->expectExceptionMessageMatches('/voidedBy/');

        InstallmentReceiptData::fromModel($recibos->firstOrFail());
    }

    /**
     * @param  list<string>  $relaciones
     * @return Collection<int, Receipt>
     */
    private function recibos(array $relaciones): Collection
    {
        return Receipt::query()
            ->with($relaciones)
            ->issued()
            ->orderBy('id')
            ->get();
    }

    /** Las dos cuotas del haber, cobradas por mostrador. */
    private function cobrarLasDosCuotas(): void
    {
        $haber = Expediente::query()
            ->where('display_number', '125957/2026')
            ->firstOrFail()
            ->haberes()
            ->orderBy('id')
            ->firstOrFail();

        $cuotas = $haber->installments()->orderBy('installment_number')->get();

        $this->assertCount(2, $cuotas);

        $operador = $this->operador();

        $cuotas->each(function (BeneficiaryInstallment $cuota) use ($operador): void {
            $cuota->forceFill(['expected_medium' => 'cash'])->save();

            app(CollectAndIssueReceipt::class)->handle(
                installment: $cuota->refresh(),
                idempotencyKey: 'recibo-data-'.Str::random(8),
                actorId: $operador->id,
            );
        });
    }
}
