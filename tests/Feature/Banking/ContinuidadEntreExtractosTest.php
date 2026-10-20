<?php

declare(strict_types=1);

namespace Tests\Feature\Banking;

use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Que no falte un período entre dos importaciones.
 *
 * El operador descarga «Últimos movimientos» a mano. Si se saltea una
 * semana e importa la siguiente, nada lo delataba: los créditos de esa
 * semana no existen, las cuotas que financiaban nunca quedan financiadas,
 * y el trabajador no cobra sin que nadie sepa por qué.
 *
 * Advierte y deja seguir. El archivo que se está subiendo está bien; el
 * que falta es otro.
 */
class ContinuidadEntreExtractosTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Dos extractos contiguos: el saldo encadena y no hay advertencia.
     */
    public function test_dos_extractos_contiguos_no_advierten_nada(): void
    {
        $cuenta = $this->cuenta();

        // Julio: arranca en 22.656.189,76 y cierra en 20.165.030,40.
        $this->importar($cuenta, $this->extracto([
            ['07/07/2026', '85634410', '3913', 'Transf. MacrOnline E-set D/T', '2.781.074,00', '', '19.875.115,76'],
            ['07/07/2026', '85634410', '3914', 'Comision Trf. MacrOL E-set', '121,00', '', '19.874.994,76'],
            ['22/07/2026', '1379263760', '4329', 'Deposito en efectivo', '', '290.035,64', '20.165.030,40'],
        ]));

        // Agosto: arranca justo donde julio terminó.
        $this->previsualizar($cuenta, $this->extracto([
            ['04/08/2026', '86972028', '3862', 'TRF MO CCDO DIST T', '1.253.491,05', '', '18.911.539,35'],
            ['05/08/2026', '1145832449', '4397', 'TRANSF 20111111112 VAR', '', '473.191,20', '19.384.730,55'],
        ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('preview.continuityWarning', null)
                ->where('preview.importable', true),
            );
    }

    /**
     * El caso que motiva todo: falta el extracto del medio.
     *
     * Entre el cierre de julio y la apertura de agosto hay $500.000 que
     * ningún movimiento explica.
     */
    public function test_un_periodo_faltante_se_advierte_con_su_importe(): void
    {
        $cuenta = $this->cuenta();

        $this->importar($cuenta, $this->extracto([
            ['07/07/2026', '85634410', '3913', 'Transf. MacrOnline E-set D/T', '2.781.074,00', '', '19.875.115,76'],
            ['22/07/2026', '1379263760', '4329', 'Deposito en efectivo', '', '289.914,64', '20.165.030,40'],
        ]));

        // Agosto arranca $500.000 más arriba: falta un crédito del medio.
        $this->previsualizar($cuenta, $this->extracto([
            ['04/08/2026', '86972028', '3862', 'TRF MO CCDO DIST T', '1.253.491,05', '', '19.411.539,35'],
            ['05/08/2026', '1145832449', '4397', 'TRANSF 20111111112 VAR', '', '473.191,20', '19.884.730,55'],
        ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Advierte, pero deja importar: el archivo está bien.
                ->where('preview.importable', true)
                ->where(
                    'preview.continuityWarning',
                    fn (?string $aviso): bool => $aviso !== null
                        && str_contains($aviso, '500.000,00')
                        && str_contains($aviso, '22/07/2026')
                        && str_contains($aviso, '04/08/2026'),
                ),
            );
    }

    /**
     * Con solapamiento no hay nada que verificar.
     *
     * El saldo posterior forma parte de la huella del movimiento, así que
     * si el archivo comparte aunque sea uno con lo ya importado, la
     * continuidad queda probada por construcción.
     */
    public function test_si_los_extractos_se_pisan_no_hay_nada_que_verificar(): void
    {
        $cuenta = $this->cuenta();

        $this->importar($cuenta, 'macro-online.csv');

        // El mismo período en el otro formato: catorce movimientos en común.
        $this->actingAs($this->operador())
            ->post(route('banco.extractos.preview'), [
                'bankAccountId' => $cuenta->id,
                'file' => new UploadedFile(
                    base_path('tests/Fixtures/Banking/macro-excel.xls'),
                    'macro-excel.xls',
                    null,
                    null,
                    true,
                ),
            ])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('preview.continuityWarning', null)
                ->where('preview.duplicateCount', 14),
            );
    }

    /** La primera importación de una cuenta no tiene con qué encadenar. */
    public function test_la_primera_importacion_no_advierte(): void
    {
        $cuenta = $this->cuenta();

        $this->previsualizar($cuenta, $this->extracto([
            ['07/07/2026', '85634410', '3913', 'Transf. MacrOnline E-set D/T', '2.781.074,00', '', '19.875.115,76'],
        ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('preview.continuityWarning', null),
            );
    }

    /**
     * También detecta el hueco al importar hacia atrás.
     *
     * Pasa cuando alguien sube un extracto viejo después de uno nuevo: la
     * comprobación es la misma al revés.
     */
    public function test_detecta_el_hueco_al_importar_un_extracto_anterior(): void
    {
        $cuenta = $this->cuenta();

        // Primero agosto.
        $this->importar($cuenta, $this->extracto([
            ['04/08/2026', '86972028', '3862', 'TRF MO CCDO DIST T', '1.253.491,05', '', '19.411.539,35'],
        ]));

        // Después julio, que cierra $500.000 por debajo de lo que agosto
        // supone que había antes de su primer movimiento.
        $this->previsualizar($cuenta, $this->extracto([
            ['22/07/2026', '1379263760', '4329', 'Deposito en efectivo', '', '289.914,64', '20.165.030,40'],
        ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where(
                    'preview.continuityWarning',
                    fn (?string $aviso): bool => $aviso !== null
                        && str_contains($aviso, '500.000,00'),
                ),
            );
    }

    /**
     * El saldo de cierre es el del último movimiento del día, no el del
     * último que entró a la base.
     *
     * MacroOnline exporta lo más nuevo arriba también dentro de un día, y
     * los movimientos se insertan en el orden del archivo: el de `id` más
     * alto del 22/07 es el depósito de las 8 de la mañana, no el cierre.
     */
    public function test_varios_movimientos_el_ultimo_dia_no_inventan_un_salto(): void
    {
        $cuenta = $this->cuenta();

        // Julio cierra en 20.265.030,40, con tres movimientos el último día.
        $this->importar($cuenta, $this->extracto([
            ['07/07/2026', '85634410', '3913', 'Transf. MacrOnline E-set D/T', '2.781.074,00', '', '19.875.115,76'],
            ['22/07/2026', '1379263760', '4329', 'Deposito en efectivo', '', '290.035,64', '20.165.151,40'],
            ['22/07/2026', '85634411', '3914', 'Comision Trf. MacrOL E-set', '121,00', '', '20.165.030,40'],
            ['22/07/2026', '1145832450', '4397', 'TRANSF 20111111112 VAR', '', '100.000,00', '20.265.030,40'],
        ]));

        // Agosto arranca justo ahí.
        $this->previsualizar($cuenta, $this->extracto([
            ['04/08/2026', '86972028', '3862', 'TRF MO CCDO DIST T', '1.253.491,05', '', '19.011.539,35'],
            ['05/08/2026', '1145832449', '4397', 'TRANSF 20111111112 VAR', '', '473.191,20', '19.484.730,55'],
        ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('preview.continuityWarning', null),
            );
    }

    /**
     * Y el mismo error al revés escondería un salto real.
     *
     * Agosto arranca en el saldo que dejó el primer movimiento del 22/07,
     * no el último: faltan los $121,00 de la comisión. Leyendo por `id`, el
     * saldo «conocido» era justo el del depósito y la diferencia no se veía.
     */
    public function test_varios_movimientos_el_ultimo_dia_no_esconden_un_salto(): void
    {
        $cuenta = $this->cuenta();

        $this->importar($cuenta, $this->extracto([
            ['07/07/2026', '85634410', '3913', 'Transf. MacrOnline E-set D/T', '2.781.074,00', '', '19.875.115,76'],
            ['22/07/2026', '1379263760', '4329', 'Deposito en efectivo', '', '290.035,64', '20.165.151,40'],
            ['22/07/2026', '85634411', '3914', 'Comision Trf. MacrOL E-set', '121,00', '', '20.165.030,40'],
        ]));

        $this->previsualizar($cuenta, $this->extracto([
            ['04/08/2026', '86972028', '3862', 'TRF MO CCDO DIST T', '1.253.491,05', '', '18.911.660,35'],
        ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where(
                    'preview.continuityWarning',
                    fn (?string $aviso): bool => $aviso !== null
                        && str_contains($aviso, '$121,00')
                        && str_contains($aviso, '22/07/2026'),
                ),
            );
    }

    /**
     * Hacia atrás vale lo mismo: el saldo previo a agosto sale del primer
     * movimiento del 04/08, que en el archivo es el de más abajo.
     */
    public function test_varios_movimientos_el_primer_dia_conocido_no_inventan_un_salto(): void
    {
        $cuenta = $this->cuenta();

        // Agosto arranca en 20.265.030,40, con tres movimientos el primer día.
        $this->importar($cuenta, $this->extracto([
            ['04/08/2026', '86972028', '3862', 'TRF MO CCDO DIST T', '1.253.491,05', '', '19.011.539,35'],
            ['04/08/2026', '86972029', '3914', 'Comision Trf. MacrOL E-set', '121,00', '', '19.011.418,35'],
            ['04/08/2026', '1145832451', '4397', 'TRANSF 20111111112 VAR', '', '50.000,00', '19.061.418,35'],
            ['05/08/2026', '1145832449', '4397', 'TRANSF 20111111112 VAR', '', '473.191,20', '19.534.609,55'],
        ]));

        // Julio, subido después, cierra justo ahí.
        $this->previsualizar($cuenta, $this->extracto([
            ['22/07/2026', '1379263760', '4329', 'Deposito en efectivo', '', '290.035,64', '20.265.030,40'],
        ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('preview.continuityWarning', null),
            );
    }

    /** La advertencia queda escrita en la importación, no solo en pantalla. */
    public function test_la_advertencia_se_guarda_con_la_importacion(): void
    {
        $cuenta = $this->cuenta();

        $this->importar($cuenta, $this->extracto([
            ['22/07/2026', '1379263760', '4329', 'Deposito en efectivo', '', '289.914,64', '20.165.030,40'],
        ]));

        $this->importar($cuenta, $this->extracto([
            ['04/08/2026', '86972028', '3862', 'TRF MO CCDO DIST T', '1.253.491,05', '', '19.411.539,35'],
        ]));

        $segunda = BankStatementImport::query()->latest('id')->firstOrFail();

        $this->assertNotNull($segunda->continuity_warning);
        $this->assertStringContainsString('500.000,00', $segunda->continuity_warning);
        // Y entró igual: la advertencia no bloquea.
        $this->assertSame(1, $segunda->rows_new);
        $this->assertSame(2, BankTransaction::query()->count());
    }

    /**
     * Un extracto viejo importado después no hace retroceder lo que se sabe.
     *
     * El 22/07 trae A y después B. Un archivo anterior repite solo A: lo
     * compartido une los dos órdenes, y el último del día sigue siendo B
     * —no el archivo que entró último—. El 23/07 encadena con B.
     */
    public function test_un_extracto_viejo_importado_despues_no_inventa_un_salto(): void
    {
        $cuenta = $this->cuenta();

        $this->importar($cuenta, $this->extracto([
            ['22/07/2026', '100001', '4397', 'TRANSFERENCIA A', '', '100,00', '1.100,00'],
            ['22/07/2026', '100002', '4397', 'TRANSFERENCIA B', '', '200,00', '1.300,00'],
        ]));
        $this->importar($cuenta, $this->extracto([
            ['21/07/2026', '100000', '4397', 'TRANSFERENCIA PREVIA', '', '50,00', '1.000,00'],
            ['22/07/2026', '100001', '4397', 'TRANSFERENCIA A', '', '100,00', '1.100,00'],
        ]));

        $this->previsualizar($cuenta, $this->extracto([
            ['23/07/2026', '100003', '4397', 'TRANSFERENCIA C', '', '50,00', '1.350,00'],
        ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('preview.continuityWarning', null),
            );
    }

    /**
     * Dos archivos del mismo día sin ningún movimiento en común: nadie
     * demuestra cuál fue el último, así que no se afirma un salto.
     */
    public function test_un_dia_sin_orden_demostrado_no_afirma_un_salto(): void
    {
        $cuenta = $this->cuenta();

        $this->importar($cuenta, $this->extracto([
            ['22/07/2026', '100001', '4397', 'TRANSFERENCIA A', '', '100,00', '1.100,00'],
        ]));
        $this->importar($cuenta, $this->extracto([
            ['22/07/2026', '100009', '4397', 'TRANSFERENCIA X', '', '30,00', '1.130,00'],
        ]));

        // Con cualquiera de los dos como «último» el 23/07 daría un salto.
        $this->previsualizar($cuenta, $this->extracto([
            ['23/07/2026', '100003', '4397', 'TRANSFERENCIA C', '', '50,00', '9.050,00'],
        ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('preview.continuityWarning', null),
            );
    }

    /**
     * Un extracto armado a mano, con la cadena de saldos ya cerrada.
     *
     * @param  list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:string}>  $filas
     *                                                                                              fecha, referencia, causal, concepto, débito, crédito, saldo
     */
    private function extracto(array $filas): string
    {
        $lineas = ['Fecha,Referencia,Codigo Causal,Concepto,Debito,Credito,Saldo'];

        // El banco exporta del más nuevo al más viejo.
        foreach (array_reverse($filas) as $fila) {
            $lineas[] = implode(',', array_map(
                fn (string $celda): string => '"'.$celda.'"',
                $fila,
            ));
        }

        $ruta = tempnam(sys_get_temp_dir(), 'ext').'.csv';
        file_put_contents($ruta, implode("\n", $lineas));

        return $ruta;
    }

    private function cuenta(): BankAccount
    {
        return BankAccount::query()->create([
            'label' => 'Cta. Cte. 2693 — Haberes',
            'bank_name' => 'Banco Macro',
            'account_number' => '310000123456789',
            'currency' => 'ARS',
            'is_active' => true,
        ]);
    }

    private function importar(BankAccount $cuenta, string $ruta): void
    {
        $this->actingAs($this->operador())
            ->post(route('banco.extractos.store'), [
                'bankAccountId' => $cuenta->id,
                'file' => $this->archivo($ruta),
            ])
            ->assertSessionHasNoErrors();
    }

    private function previsualizar(BankAccount $cuenta, string $ruta): TestResponse
    {
        return $this->actingAs($this->operador())
            ->post(route('banco.extractos.preview'), [
                'bankAccountId' => $cuenta->id,
                'file' => $this->archivo($ruta),
            ]);
    }

    /** Acepta tanto una ruta temporal como el nombre de una fixture. */
    private function archivo(string $rutaOFixture): UploadedFile
    {
        $origen = file_exists($rutaOFixture)
            ? $rutaOFixture
            : base_path('tests/Fixtures/Banking/'.$rutaOFixture);

        return new UploadedFile($origen, basename($origen), null, null, true);
    }
}
