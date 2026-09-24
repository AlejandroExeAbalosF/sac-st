<?php

declare(strict_types=1);

namespace Tests\Feature\Banking;

use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Que cada pantalla del módulo abra.
 *
 * Existen porque faltaban: los tests de importación ejercitaban el `POST`
 * y ninguno abría el `GET`, así que la pantalla de carga se rompía sin que
 * nada lo dijera. El modelo corre con `preventAccessingMissingAttributes`
 * y un `withCount` olvidado no falla al escribirlo: falla al abrir la
 * página.
 */
class PantallasDeBancoTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pantalla_de_cuentas_abre_sin_extractos(): void
    {
        $this->cuenta();

        $this->actingAs($this->operador())
            ->get(route('banco.cuentas.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('banco/cuentas')
                ->has('accounts', 1)
                ->where('accounts.0.importCount', 0)
                ->where('accounts.0.lastKnownBalance', null),
            );
    }

    public function test_la_pantalla_de_carga_abre(): void
    {
        $this->cuenta();

        $this->actingAs($this->operador())
            ->get(route('banco.extractos.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('banco/extractos/create')
                ->has('accounts', 1),
            );
    }

    public function test_la_pantalla_de_carga_abre_sin_ninguna_cuenta_cargada(): void
    {
        $this->actingAs($this->operador())
            ->get(route('banco.extractos.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('accounts', 0));
    }

    /**
     * La vista previa vuelve con la pantalla, no por el flash.
     *
     * `HandleInertiaRequests` comparte `flash.status` y nada más, así que
     * un `->with('preview', …)` se perdía en el camino sin que nada
     * fallara: el archivo se leía bien, el POST devolvía 302, y la
     * pantalla volvía vacía.
     */
    public function test_analizar_un_archivo_devuelve_la_vista_previa(): void
    {
        $cuenta = $this->cuenta();

        $this->actingAs($this->operador())
            ->post(route('banco.extractos.preview'), [
                'bankAccountId' => $cuenta->id,
                'file' => new UploadedFile(
                    base_path('tests/Fixtures/Banking/macro-online.csv'),
                    'macro-online.csv',
                    null,
                    null,
                    true,
                ),
            ])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('banco/extractos/create')
                ->where('preview.importable', true)
                ->where('preview.newCount', 14)
                ->where('preview.duplicateCount', 0)
                ->where('preview.balanceChainOk', true)
                ->where('preview.closingBalance', '17692768.55')
                ->has('preview.rows', 14)
                ->where('previewFilename', 'macro-online.csv')
                ->where('previewAccountId', $cuenta->id),
            );

        // Analizar no escribe: es la mitad del sentido de que exista.
        $this->assertSame(0, BankStatementImport::query()->count());
    }

    /**
     * Recargar sobre la vista previa devuelve al primer paso.
     *
     * `previsualizar` solo acepta POST, así que un GET —una entrada vieja
     * del historial, un enlace copiado— devolvía «405 Method Not Allowed».
     * No hay nada que reconstruir: el archivo vive en el navegador, no en
     * el servidor, de modo que empezar de nuevo es la única respuesta
     * honesta.
     */
    public function test_entrar_por_get_a_la_vista_previa_devuelve_al_primer_paso(): void
    {
        $this->cuenta();

        $this->actingAs($this->operador())
            ->get('/banco/extractos/previsualizar')
            ->assertRedirect(route('banco.extractos.create'));
    }

    /**
     * Importar cierra el asistente en su tercer paso, no redirige.
     *
     * El resultado es parte del mismo recorrido que empezó eligiendo el
     * archivo; soltarlo en otra pantalla obliga a reconstruir qué pasó.
     */
    public function test_importar_devuelve_el_paso_de_resultado(): void
    {
        $cuenta = $this->cuenta();

        $this->actingAs($this->operador())
            ->post(route('banco.extractos.store'), [
                'bankAccountId' => $cuenta->id,
                'file' => new UploadedFile(
                    base_path('tests/Fixtures/Banking/macro-online.csv'),
                    'macro-online.csv',
                    null,
                    null,
                    true,
                ),
            ])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('banco/extractos/create')
                ->where('result.status', 'completed')
                ->where('result.rowsNew', 14)
                ->where('result.rowsDuplicate', 0)
                ->where('result.closingBalance', '17692768.55')
                ->where('result.originalFilename', 'macro-online.csv')
                // Sin vista previa: el paso 2 ya quedó atrás.
                ->missing('preview'),
            );
    }

    /** Un archivo rechazado también termina en el paso 3, con su motivo. */
    public function test_un_archivo_rechazado_cierra_el_asistente_explicando(): void
    {
        $dolares = BankAccount::query()->create([
            'label' => 'Cta. en dólares',
            'bank_name' => 'Banco Macro',
            'account_number' => '310000123456789',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($this->operador())
            ->post(route('banco.extractos.store'), [
                'bankAccountId' => $dolares->id,
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
                ->where('result.status', 'failed')
                ->where('result.rowsNew', 0)
                ->where(
                    'result.failureReason',
                    fn (?string $motivo): bool => str_contains((string) $motivo, 'No se mezclan monedas'),
                ),
            );
    }

    /**
     * La vista previa señala qué filas ya estaban, no solo cuántas.
     *
     * Un total de «12 repetidos» sin decir cuáles obliga a comparar el
     * archivo contra la pantalla a mano, que es justo el trabajo que el
     * sistema viene a sacar de encima.
     */
    public function test_la_vista_previa_marca_las_filas_ya_registradas(): void
    {
        $cuenta = $this->cuenta();
        $this->importar($cuenta);

        // El mismo período otra vez, en el otro formato: mismas huellas,
        // archivo distinto.
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
                ->where('preview.newCount', 0)
                ->where('preview.duplicateCount', 14)
                ->where(
                    'preview.rows',
                    fn (Collection $filas): bool => $filas->every(
                        fn (array $fila): bool => $fila['repeated'] === true,
                    ),
                ),
            );
    }

    /** Y no marca nada cuando todo es nuevo. */
    public function test_la_vista_previa_no_marca_filas_en_un_extracto_nuevo(): void
    {
        $cuenta = $this->cuenta();

        $this->actingAs($this->operador())
            ->post(route('banco.extractos.preview'), [
                'bankAccountId' => $cuenta->id,
                'file' => new UploadedFile(
                    base_path('tests/Fixtures/Banking/macro-online.csv'),
                    'macro-online.csv',
                    null,
                    null,
                    true,
                ),
            ])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('preview.newCount', 14)
                ->where(
                    'preview.rows',
                    fn (Collection $filas): bool => $filas->every(
                        fn (array $fila): bool => $fila['repeated'] === false,
                    ),
                ),
            );
    }

    /** Un archivo que no se puede importar lo dice antes de intentarlo. */
    public function test_la_vista_previa_avisa_cuando_el_archivo_no_sirve(): void
    {
        $dolares = BankAccount::query()->create([
            'label' => 'Cta. en dólares',
            'bank_name' => 'Banco Macro',
            'account_number' => '310000123456789',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($this->operador())
            ->post(route('banco.extractos.preview'), [
                'bankAccountId' => $dolares->id,
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
                ->where('preview.importable', false)
                ->has('preview.problems', 1),
            );
    }

    /**
     * La moneda de una cuenta con movimientos no se toca.
     *
     * Los movimientos la heredan sin llevarla encima (§4.4 del DER), así
     * que cambiarla acá no corregiría un dato: reinterpretaría todos los
     * importes ya importados.
     */
    public function test_no_se_cambia_la_moneda_de_una_cuenta_con_movimientos(): void
    {
        $cuenta = $this->cuenta();
        $this->importar($cuenta);

        $this->actingAs($this->operador('contador'))
            ->patch(route('banco.cuentas.update', $cuenta), [
                'label' => $cuenta->label,
                'bankName' => $cuenta->bank_name,
                'accountNumber' => $cuenta->account_number,
                'cbu' => null,
                'alias' => null,
                'currency' => 'USD',
                'isActive' => true,
            ])
            ->assertSessionHasErrors('currency');

        $this->assertSame('ARS', $cuenta->refresh()->currency);
    }

    /** Sin movimientos todavía, corregir la moneda es legítimo. */
    public function test_la_moneda_se_corrige_mientras_la_cuenta_no_tenga_movimientos(): void
    {
        $cuenta = $this->cuenta();

        $this->actingAs($this->operador('contador'))
            ->patch(route('banco.cuentas.update', $cuenta), [
                'label' => $cuenta->label,
                'bankName' => $cuenta->bank_name,
                'accountNumber' => $cuenta->account_number,
                'cbu' => null,
                'alias' => null,
                'currency' => 'USD',
                'isActive' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('USD', $cuenta->refresh()->currency);
    }

    /** Y la base lo impone aunque nadie pase por el formulario. */
    public function test_la_base_impide_cambiar_la_moneda_de_una_cuenta_usada(): void
    {
        $cuenta = $this->cuenta();
        $this->importar($cuenta);

        $this->expectException(QueryException::class);

        DB::table('bank_accounts')->where('id', $cuenta->id)->update(['currency' => 'USD']);
    }

    public function test_el_listado_de_extractos_abre(): void
    {
        $this->actingAs($this->operador())
            ->get(route('banco.extractos.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('banco/extractos/index'));
    }

    public function test_el_listado_de_movimientos_abre(): void
    {
        $this->actingAs($this->operador())
            ->get(route('banco.movimientos.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('banco/movimientos/index'));
    }

    public function test_las_pantallas_muestran_lo_importado(): void
    {
        $cuenta = $this->cuenta();
        $this->importar($cuenta);

        $import = BankStatementImport::query()->firstOrFail();

        $this->actingAs($this->operador())
            ->get(route('banco.extractos.show', $import))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('banco/extractos/show')
                ->has('rows', 14)
                ->where('import.rowsNew', 14),
            );

        $this->actingAs($this->operador())
            ->get(route('banco.movimientos.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('transactions.data', 14));

        // Y la cuenta ya informa su saldo y su cantidad de extractos.
        $this->actingAs($this->operador())
            ->get(route('banco.cuentas.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('accounts.0.importCount', 1)
                ->where('accounts.0.lastKnownBalance', '17692768.55'),
            );
    }

    public function test_la_cuenta_muestra_el_saldo_del_ultimo_extracto_aunque_sea_menor(): void
    {
        $cuenta = $this->cuenta();
        $this->importar($cuenta);

        $primero = BankStatementImport::query()->firstOrFail();
        $segundo = $primero->replicate();
        $segundo->forceFill([
            'file_sha256' => str_repeat('a', 64),
            'closing_balance' => '100.00',
            'imported_at' => $primero->imported_at?->copy()->addDay(),
        ])->save();

        $this->actingAs($this->operador())
            ->get(route('banco.cuentas.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('accounts.0.importCount', 2)
                ->where('accounts.0.lastKnownBalance', '100.00'),
            );
    }

    /**
     * El detalle muestra de dónde salió el archivo.
     *
     * Cuenta declarada, moneda, operador y fecha de descarga se guardaban
     * desde la primera importación y no se veían en ninguna pantalla —la
     * peor forma de tener un dato de trazabilidad—. Son los que responden
     * «¿este extracto era de esta cuenta?» cuando un saldo no cuadra.
     */
    public function test_el_detalle_muestra_la_procedencia_del_archivo(): void
    {
        $cuenta = $this->cuenta();

        $this->actingAs($this->operador())
            ->post(route('banco.extractos.store'), [
                'bankAccountId' => $cuenta->id,
                'file' => new UploadedFile(
                    base_path('tests/Fixtures/Banking/macro-excel.xls'),
                    'macro-excel.xls',
                    null,
                    null,
                    true,
                ),
            ])
            ->assertSessionHasNoErrors();

        $import = BankStatementImport::query()->firstOrFail();

        $this->actingAs($this->operador())
            ->get(route('banco.extractos.show', $import))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('import.accountNumberInFile', '310000123456789')
                ->where('import.currencyInFile', 'ARS')
                ->where('import.operatorInFile', 'ANA MARIA GONZALEZ')
                ->where('import.openingBalance', '22656189.76')
                ->where('import.closingBalance', '17692768.55')
                ->where('import.fileSha256', hash_file(
                    'sha256',
                    base_path('tests/Fixtures/Banking/macro-excel.xls'),
                ))
                ->has('import.fileSize'),
            );
    }

    public function test_los_filtros_de_movimientos_funcionan(): void
    {
        $cuenta = $this->cuenta();
        $this->importar($cuenta);

        $this->actingAs($this->operador())
            ->get(route('banco.movimientos.index', ['sentido' => 'credit']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('transactions.data', 4));

        // El CUIT del empleador se busca tal como está en la base.
        $this->actingAs($this->operador())
            ->get(route('banco.movimientos.index', ['buscar' => '20444444445']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('transactions.data', 1)
                ->where('transactions.data.0.counterpartyName', 'SANDOVAL'),
            );
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

    private function importar(BankAccount $cuenta): void
    {
        $this->actingAs($this->operador())
            ->post(route('banco.extractos.store'), [
                'bankAccountId' => $cuenta->id,
                'file' => new UploadedFile(
                    base_path('tests/Fixtures/Banking/macro-online.csv'),
                    'macro-online.csv',
                    null,
                    null,
                    true,
                ),
            ])
            ->assertSessionHasNoErrors();
    }
}
