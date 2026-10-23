<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Los acordeones de la barra que el usuario dejó abiertos.
 *
 * Los guarda el front en una cookie sin cifrar y el servidor los devuelve
 * como prop, para que el renderizado inicial dibuje la barra igual que el
 * navegador. Si la prop no llegara, la barra se vería bien igual pero React
 * rehacería el árbol en cada recarga.
 */
class BarraLateralTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_sections_the_browser_saved(): void
    {
        $this->actingAs($this->operador())
            ->withUnencryptedCookie('sidebar_sections', json_encode(['Multas', 'Configuración'], JSON_THROW_ON_ERROR))
            ->get(route('inicio'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sidebarSections', ['Multas', 'Configuración']));
    }

    public function test_starts_empty_without_the_cookie(): void
    {
        $this->actingAs($this->operador())
            ->get(route('inicio'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('sidebarSections', []));
    }

    public function test_discards_what_is_not_a_list_of_titles(): void
    {
        foreach (['{roto', '{"Haberes":true}', '"Haberes"', '["Haberes",3,null]'] as $cookie) {
            $esperado = $cookie === '["Haberes",3,null]' ? ['Haberes'] : [];

            $this->actingAs($this->operador())
                ->withUnencryptedCookie('sidebar_sections', $cookie)
                ->get(route('inicio'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('sidebarSections', $esperado));
        }
    }
}
