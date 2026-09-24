<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Detrás del nginx del stack y del proxy del data center.
 *
 * Lo que está en juego es doble: que la app sepa cuándo el pedido llegó por
 * HTTPS —cookies seguras, HSTS, URLs— y que la IP que registra la auditoría
 * sea la del usuario y no la del proxy. Y del otro lado, que nadie que no
 * sea un proxy declarado pueda dictarle a la app su esquema o su IP.
 */
class ProxiesDeConfianzaTest extends TestCase
{
    private const RED_DEL_STACK = '10.200.1.0/24';

    private const NGINX = '10.200.1.3';

    /*
     * Direcciones de documentación (RFC 5737), no las del data center: el
     * repo es público y la red del servidor no tiene por qué figurar en él.
     */
    private const PROXY_DEL_DC = '192.0.2.10';

    private const CLIENTE_DIRECTO = '198.51.100.99';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_prueba/pedido', fn (Request $request): array => [
            'seguro' => $request->isSecure(),
            'ip' => $request->ip(),
        ]);

        config(['trustedproxy.proxies' => self::RED_DEL_STACK.','.self::PROXY_DEL_DC]);
    }

    public function test_toma_esquema_e_ip_del_usuario_a_traves_de_los_proxies_declarados(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::NGINX])
            ->withHeaders([
                'X-Forwarded-Proto' => 'https',
                // El proxy del DC agrega al usuario; el nginx, al proxy.
                'X-Forwarded-For' => '192.168.5.20, '.self::PROXY_DEL_DC,
            ])
            ->getJson('/_prueba/pedido')
            ->assertExactJson(['seguro' => true, 'ip' => '192.168.5.20']);
    }

    public function test_ignora_los_encabezados_de_quien_no_es_un_proxy_declarado(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::CLIENTE_DIRECTO])
            ->withHeaders([
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-For' => '1.2.3.4',
            ])
            ->getJson('/_prueba/pedido')
            ->assertExactJson(['seguro' => false, 'ip' => self::CLIENTE_DIRECTO]);
    }

    public function test_la_lista_sale_de_trusted_proxies(): void
    {
        $_SERVER['TRUSTED_PROXIES'] = self::RED_DEL_STACK;

        try {
            $config = require config_path('trustedproxy.php');
        } finally {
            unset($_SERVER['TRUSTED_PROXIES']);
        }

        $this->assertSame(['proxies' => self::RED_DEL_STACK], $config);
    }
}
