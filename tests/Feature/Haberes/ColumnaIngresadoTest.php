<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Actions\AllocateFundsToInstallment;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Ledger\Actions\RegisterCashFundReceipt;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * La columna «Ingresado» del listado, en sus dos lecturas.
 *
 * Venía clavada en `'0.00'` en los dos DTO del haber, así que el expediente
 * sumaba ceros y las dos tablas mostraban siempre cero. No fallaba nada: el
 * importe se dibuja atenuado cuando vale cero, de modo que la columna
 * desconectada se leía como «todavía no entró nada».
 *
 * Lo que se prueba acá es que el número exista y que sea **el mismo** que
 * muestra el detalle: el total del haber es la suma de sus cuotas, y el del
 * expediente la de sus haberes. Si alguna vez se recalculara por otra vía,
 * las tres cifras podrían discrepar y nadie sabría cuál creer.
 *
 * Ingresado es lo que **entró** —lo imputado desde una recepción de
 * fondos—, no lo entregado al beneficiario: eso es el egreso, y lo cuenta
 * la etapa de la cuota. El campo se sigue llamando `fundedAmount` porque el
 * código va en inglés y «funded» no arrastra la ambigüedad que tenía el
 * rótulo en pantalla.
 */
class ColumnaIngresadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->abrirLibrosSinSaldo();

        $this->seed(HaberesDemoSeeder::class);
    }

    /** Sin imputaciones la columna dice cero, que ahí sí es la verdad. */
    public function test_sin_plata_imputada_las_dos_vistas_dicen_cero(): void
    {
        $haber = $this->haber();

        $this->verListadoDeHaberes()
            ->assertInertia(fn (Assert $page) => $page
                ->where($this->filaDelHaber($haber).'.fundedAmount', '0.00')
            );

        $this->verListadoDeExpedientes()
            ->assertInertia(fn (Assert $page) => $page
                ->where($this->filaDelExpediente($haber->expediente).'.fundedTotalAmount', '0.00')
            );
    }

    /**
     * Imputar una cuota mueve la columna en las dos tablas.
     *
     * Media cuota y no una entera a propósito: un ingreso parcial no emite
     * comprobante (§2.1.14), pero la plata está y la columna tiene que
     * decirlo.
     */
    public function test_lo_imputado_a_una_cuota_llega_a_las_dos_vistas(): void
    {
        $haber = $this->haber();
        $cuota = $haber->installments()->orderBy('installment_number')->firstOrFail();
        $mitad = Decimal::scale(bcdiv($cuota->importeEsperado(), '2', 4));

        $this->financiar($cuota, $mitad);

        $this->verListadoDeHaberes()
            ->assertInertia(fn (Assert $page) => $page
                ->where($this->filaDelHaber($haber).'.fundedAmount', $mitad)
                // Y el total del haber es el de su propia cuota, no otro.
                ->where($this->filaDelHaber($haber).'.installments.0.fundedAmount', $mitad)
            );

        $this->verListadoDeExpedientes()
            ->assertInertia(fn (Assert $page) => $page
                ->where($this->filaDelExpediente($haber->expediente).'.fundedTotalAmount', $mitad)
            );
    }

    /**
     * El total del haber es la suma de sus cuotas, no la de una.
     *
     * Con dos cuotas imputadas, sumar mal —quedarse con la última, contar
     * dos veces— daría un número que igual parece razonable en pantalla.
     */
    public function test_el_total_suma_todas_las_cuotas_del_haber(): void
    {
        $haber = $this->haberConDosCuotas();
        $cuotas = $haber->installments()->orderBy('installment_number')->get();

        $esperado = '0.00';

        foreach ($cuotas as $cuota) {
            $parte = Decimal::scale(bcdiv($cuota->importeEsperado(), '4', 4));
            $this->financiar($cuota, $parte);
            $esperado = Decimal::add($esperado, $parte);
        }

        $this->verListadoDeHaberes()
            ->assertInertia(fn (Assert $page) => $page
                ->where($this->filaDelHaber($haber).'.fundedAmount', $esperado)
            );

        $this->verListadoDeExpedientes()
            ->assertInertia(fn (Assert $page) => $page
                ->where($this->filaDelExpediente($haber->expediente).'.fundedTotalAmount', $esperado)
            );
    }

    /**
     * El detalle del expediente cuenta lo mismo que el listado.
     *
     * Son la misma tarjeta de haber dibujada por dos pantallas distintas, y
     * ese es justamente el riesgo: la que no pasara el lote mostraría cero
     * al lado de un número que sí es real.
     */
    public function test_el_detalle_del_expediente_dice_lo_mismo(): void
    {
        $haber = $this->haber();
        $cuota = $haber->installments()->orderBy('installment_number')->firstOrFail();

        $this->financiar($cuota, $cuota->importeEsperado());

        $this->actingAs($this->operador())
            ->get(route('expedientes.show', $haber->expediente))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('expediente.fundedTotalAmount', $cuota->importeEsperado())
                ->where('expediente.haberes.0.fundedAmount', $cuota->importeEsperado())
            );
    }

    /** Un haber sin cuotas cargadas no rompe la suma. */
    public function test_un_haber_sin_cuotas_no_rompe_el_total(): void
    {
        $haber = $this->haber();
        $haber->installments()->delete();

        $this->verListadoDeHaberes()
            ->assertInertia(fn (Assert $page) => $page
                ->where($this->filaDelHaber($haber).'.fundedAmount', '0.00')
            );
    }

    /* ── Andamiaje ──────────────────────────────────────────────────── */

    private function verListadoDeHaberes(): TestResponse
    {
        return $this->actingAs($this->operador())
            ->get(route('expedientes.index', ['vista' => 'haberes']))
            ->assertOk();
    }

    private function verListadoDeExpedientes(): TestResponse
    {
        return $this->actingAs($this->operador())
            ->get(route('expedientes.index'))
            ->assertOk();
    }

    /**
     * Dónde cae este haber en la página, buscado por su id.
     *
     * El orden del listado es el del expediente que los trajo, así que
     * fijar un índice a mano ataría el test al sembrado.
     */
    private function filaDelHaber(Haber $haber): string
    {
        $posicion = $this->paginaDeHaberes()->search(
            fn (int $id): bool => $id === $haber->id,
        );

        $this->assertNotFalse($posicion, 'El haber no está en la primera página del listado.');

        return "haberes.{$posicion}";
    }

    private function filaDelExpediente(Expediente $expediente): string
    {
        $posicion = $this->paginaDeExpedientes()->search(
            fn (int $id): bool => $id === $expediente->id,
        );

        $this->assertNotFalse($posicion, 'El expediente no está en la primera página del listado.');

        return "expedientes.{$posicion}";
    }

    /** @return Collection<int, int> */
    private function paginaDeHaberes(): Collection
    {
        return Haber::query()
            ->select('haberes.id')
            ->join('expedientes', 'expedientes.id', '=', 'haberes.expediente_id')
            ->whereNot('workflow_status', 'cancelled')
            ->orderByDesc('expedientes.received_date')
            ->orderByDesc('expedientes.id')
            ->orderBy('haberes.id')
            ->limit(25)
            ->pluck('haberes.id')
            ->map(intval(...))
            ->values();
    }

    /** @return Collection<int, int> */
    private function paginaDeExpedientes(): Collection
    {
        return Expediente::query()
            ->whereNot('status', 'cancelled')
            ->orderByDesc('received_date')
            ->orderByDesc('id')
            ->limit(25)
            ->pluck('id')
            ->map(intval(...))
            ->values();
    }

    /** Un haber con al menos una cuota, del expediente más nuevo que la tenga. */
    private function haber(): Haber
    {
        return Haber::query()
            ->whereNot('workflow_status', 'cancelled')
            ->whereHas('installments')
            ->orderBy('id')
            ->firstOrFail();
    }

    private function haberConDosCuotas(): Haber
    {
        return Haber::query()
            ->whereNot('workflow_status', 'cancelled')
            ->whereHas('installments', null, '>=', 2)
            ->orderBy('id')
            ->firstOrFail();
    }

    /** Plata que entra por mostrador y se imputa a la cuota. */
    private function financiar(BeneficiaryInstallment $cuota, string $importe): void
    {
        $recepcion = app(RegisterCashFundReceipt::class)->handle(
            amount: $importe,
            idempotencyKey: 'efectivo-'.Str::random(10),
            cashBoxId: (int) CashBox::query()->where('code', CashBox::HABERES)->value('id'),
            receivedDate: now(),
        );

        app(AllocateFundsToInstallment::class)->handle(
            receipt: $recepcion,
            installment: $cuota,
            amount: $importe,
            idempotencyKey: 'asignacion-'.Str::random(10),
        );
    }
}
