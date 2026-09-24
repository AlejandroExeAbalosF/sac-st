<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * En producción solo se atienden pedidos dirigidos al dominio del sistema.
 *
 * El enlace de recuperación de contraseña se arma con el `Host` del pedido:
 * sin este control, se podía pedir la recuperación de otro con un `Host`
 * propio y el correo le llegaba a la víctima con un enlace —y su token— a
 * otro dominio. Laravel no lo aplica en `local` ni en `testing`, así que
 * cada test se pone en producción.
 */
class HostsDeConfianzaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://sacst.example.gob.ar']);
        $this->app['env'] = 'production';
    }

    protected function tearDown(): void
    {
        // La lista de hosts de Symfony es estática: no puede quedar puesta
        // para el test siguiente.
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    public function test_un_host_ajeno_no_se_atiende(): void
    {
        $this->get('http://atacante.example/login')->assertBadRequest();
    }

    public function test_el_dominio_del_sistema_se_atiende(): void
    {
        $this->get('https://sacst.example.gob.ar/login')->assertOk();
    }

    /** El healthcheck de nginx pega a `/up` por la IP local. */
    public function test_el_healthcheck_por_la_ip_local_se_atiende(): void
    {
        $this->get('http://127.0.0.1/up')->assertOk();
    }
}
