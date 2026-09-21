<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Shared\Actions\TakeNextDocumentNumber;
use App\Modules\Shared\Models\DocumentSeries;
use Database\Seeders\DocumentSeriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * El correlativo de los comprobantes.
 *
 * Es la pieza delicada del sistema de numeración, y el DER lo dice sin
 * rodeos: *«El correlativo se toma de `next_number` bajo lock de la fila.
 * Nunca se calcula con `MAX(number) + 1`, porque eso produce duplicados
 * bajo concurrencia»*.
 *
 * El escenario a evitar es concreto: dos personas emitiendo un recibo al
 * mismo tiempo. Un recibo con número repetido no es un error de sistema:
 * es un comprobante que el área ya entregó firmado y que ahora existe dos
 * veces.
 */
class NumeracionDeDocumentosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

    }

    /**
     * Una serie por tipo de comprobante, no dos.
     *
     * Había una serie extra por tipo para los comprobantes cargados del
     * talonario, y se retiró cuando el área confirmó que **el número del
     * sistema es siempre el identificador**: el del papel se registra al
     * lado, en `receipts.talonario_number`. Dos correlativos para el
     * mismo documento volvían cada reporte una suma de series.
     */
    public function test_el_catalogo_tiene_una_serie_por_tipo(): void
    {
        $this->assertSame(3, DocumentSeries::query()->count());

        $this->assertEqualsCanonicalizing(
            ['0010', '0020', '0030'],
            DocumentSeries::query()->pluck('code')->all(),
        );
    }

    /** Sembrar dos veces no duplica ni reinicia nada. */
    public function test_el_seeder_es_idempotente(): void
    {
        $numero = $this->tomar('0010');
        $this->assertSame(1, $numero['number']);

        $this->seed(DocumentSeriesSeeder::class);

        $this->assertSame(3, DocumentSeries::query()->count());
        // Y sobre todo: el correlativo no volvió a empezar.
        $this->assertSame(2, $this->tomar('0010')['number']);
    }

    public function test_el_formato_sigue_la_convencion_de_afip(): void
    {
        $this->assertSame('0010/00000001', $this->tomar('0010')['formatted']);
        $this->assertSame('0030/00000001', $this->tomar('0030')['formatted']);
    }

    /** Cada serie lleva su propio correlativo, sin relación entre sí. */
    public function test_cada_serie_cuenta_por_su_cuenta(): void
    {
        $this->tomar('0010');
        $this->tomar('0010');

        $this->assertSame(3, $this->tomar('0010')['number']);
        $this->assertSame(1, $this->tomar('0020')['number']);
        $this->assertSame(1, $this->tomar('0030')['number']);
    }

    /**
     * La fila se lee con `FOR UPDATE`.
     *
     * Es lo único que impide que dos personas emitiendo un recibo al mismo
     * tiempo se lleven el mismo número. La concurrencia real no se puede
     * reproducir acá —`RefreshDatabase` mantiene todo dentro de una
     * transacción y una segunda conexión no vería estos datos—, así que se
     * verifica lo que sí es verificable y es exactamente lo que importa:
     * que el bloqueo esté en la consulta.
     *
     * Sin `FOR UPDATE` este test pasa a rojo, que es su razón de existir.
     */
    public function test_el_correlativo_se_toma_bloqueando_la_fila(): void
    {
        $consultas = [];

        DB::listen(function ($query) use (&$consultas): void {
            $consultas[] = strtolower($query->sql);
        });

        $this->tomar('0010');

        $lecturas = array_filter(
            $consultas,
            fn (string $sql): bool => str_contains($sql, 'select') && str_contains($sql, 'document_series'),
        );

        $this->assertNotEmpty($lecturas, 'No se leyó la serie.');

        foreach ($lecturas as $sql) {
            $this->assertStringContainsString('for update', $sql);
        }
    }

    public function test_una_serie_inexistente_falla_con_su_codigo(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('0099');

        DB::transaction(fn () => app(TakeNextDocumentNumber::class)->handle('0099'));
    }

    public function test_una_serie_inactiva_no_entrega_numeros(): void
    {
        DocumentSeries::query()->where('code', '0020')->update(['is_active' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inactiva');

        DB::transaction(fn () => app(TakeNextDocumentNumber::class)->handle('0020'));
    }

    /**
     * El número vuelve atrás si el comprobante no se llega a crear.
     *
     * Es la razón por la que el Action exige una transacción: el
     * correlativo y el documento que lo usa se confirman juntos.
     */
    public function test_el_numero_se_devuelve_si_la_transaccion_falla(): void
    {
        try {
            DB::transaction(function (): void {
                app(TakeNextDocumentNumber::class)->handle('0010');

                throw new RuntimeException('algo salió mal al emitir');
            });
        } catch (RuntimeException) {
            // Esperado.
        }

        $this->assertSame(1, $this->tomar('0010')['number']);
    }

    /**
     * @return array{number: int, formatted: string}
     */
    private function tomar(string $code): array
    {
        return DB::transaction(fn (): array => app(TakeNextDocumentNumber::class)->handle($code));
    }
}
