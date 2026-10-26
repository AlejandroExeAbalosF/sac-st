<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Ledger\Enums\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * La puesta en marcha en el tablero de inicio.
 *
 * Se deriva de los datos y no del usuario: aparece mientras falte un paso
 * obligatorio, para cualquiera que entre, y desaparece sola. Lo que cambia
 * con los permisos es si el paso lleva a su pantalla.
 */
class PuestaEnMarchaTest extends TestCase
{
    use BuildsSharedBankAccount;
    use RefreshDatabase;

    public function test_un_sistema_recien_instalado_muestra_los_pasos_en_orden(): void
    {
        $this->actingAs($this->operador('administrador'))
            ->get(route('inicio'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('setup.0.key', 'cuentas')
                ->where('setup.0.done', false)
                ->where('setup.0.href', route('banco.cuentas.index', absolute: false))
                ->where('setup.1.key', 'apertura_haberes')
                ->where('setup.2.key', 'apertura_multas')
                ->where('setup.3.key', 'apertura_haberes_usd')
                ->where('setup.3.optional', true));
    }

    public function test_multas_no_se_puede_abrir_sin_una_cuenta_en_pesos(): void
    {
        $this->cuenta('310000999888777', 'USD');

        $this->actingAs($this->operador('administrador'))
            ->get(route('inicio'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('setup.0.done', true)
                ->where('setup.2.href', null)
                ->where('setup.2.blockedBy', 'Necesita una cuenta bancaria en pesos.'));

        $this->cuenta('310000111222333');

        $this->get(route('inicio'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('setup.2.href', route('multas.caja.index', absolute: false))
                ->where('setup.2.blockedBy', null));
    }

    public function test_quien_no_tiene_el_permiso_ve_el_paso_sin_enlace(): void
    {
        $this->actingAs($this->operador())
            ->get(route('inicio'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('setup.0.done', false)
                ->where('setup.0.href', null)
                ->where('setup.1.href', null));
    }

    public function test_desaparece_cuando_estan_los_pasos_obligatorios_aunque_falten_los_dolares(): void
    {
        $cuenta = $this->cuenta();
        $this->abrirLibrosSinSaldo(Currency::Ars);
        $this->abrirMultas($cuenta);

        $this->actingAs($this->operador('administrador'))
            ->get(route('inicio'))
            ->assertInertia(fn (Assert $page) => $page->where('setup', null));
    }

    public function test_una_apertura_hecha_queda_marcada_mientras_falte_otra(): void
    {
        $this->cuenta();
        $this->abrirLibrosSinSaldo(Currency::Ars);

        $this->actingAs($this->operador('administrador'))
            ->get(route('inicio'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('setup.1.done', true)
                ->where('setup.2.done', false));
    }
}
