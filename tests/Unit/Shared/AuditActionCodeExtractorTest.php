<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use PHPUnit\Framework\TestCase;
use Tests\Support\AuditActionCodeExtractor;

/**
 * El analizador que alimenta la prueba del catálogo.
 *
 * Si se pierde una forma de llamada, la prueba del catálogo sigue verde con
 * un código sin registrar adentro: por eso cada forma que existe en el
 * código tiene su caso acá.
 */
class AuditActionCodeExtractorTest extends TestCase
{
    public function test_lee_la_llamada_posicional_en_una_linea(): void
    {
        $this->assertSame(['cuota.corregida'], $this->codes(<<<'PHP'
            final class A {
                public function __construct(private readonly RecordAuditEvent $auditar) {}
                public function handle($c): void { $this->auditar->handle('cuota.corregida', $c, $a, $b); }
            }
            PHP));
    }

    public function test_lee_la_llamada_partida_en_varias_lineas(): void
    {
        $this->assertSame(['expediente.anulado'], $this->codes(<<<'PHP'
            final class A {
                public function __construct(private readonly RecordAuditEvent $auditar) {}
                public function handle($e): void {
                    $this->auditar->handle(
                        'expediente.anulado',
                        $e,
                        before: ['status' => 'active'],
                    );
                }
            }
            PHP));
    }

    public function test_lee_el_codigo_pasado_como_argumento_nombrado(): void
    {
        $this->assertSame(['periodo.reabierto'], $this->codes(<<<'PHP'
            final class A {
                public function __construct(private readonly RecordAuditEvent $auditar) {}
                public function handle($c): void {
                    $this->auditar->handle(subject: $c, action: 'periodo.reabierto', actorId: 1);
                }
            }
            PHP));
    }

    public function test_reconoce_la_instancia_con_cualquier_nombre(): void
    {
        $this->assertSame(['usuario.creado', 'rol.permisos_actualizados'], $this->codes(<<<'PHP'
            final class A {
                private RecordAuditEvent $recordAuditEvent;
                public function uno(RecordAuditEvent $registro, $u): void { $registro->handle('usuario.creado', $u); }
                public function dos($r): void { $this->recordAuditEvent->handle('rol.permisos_actualizados', $r); }
            }
            PHP));
    }

    public function test_reconoce_la_instancia_pedida_al_contenedor(): void
    {
        $this->assertSame(['usuario.activado'], $this->codes(<<<'PHP'
            function prueba($u): void { app(RecordAuditEvent::class)->handle('usuario.activado', $u); }
            PHP));
    }

    public function test_lee_las_dos_ramas_de_un_ternario(): void
    {
        $this->assertSame(['usuario.activado', 'usuario.desactivado'], $this->codes(<<<'PHP'
            final class A {
                public function __construct(private readonly RecordAuditEvent $auditar) {}
                public function handle($u, bool $active): void {
                    $this->auditar->handle($active ? 'usuario.activado' : 'usuario.desactivado', $u);
                }
            }
            PHP));
    }

    public function test_ignora_el_handle_de_otra_clase(): void
    {
        $this->assertSame([], $this->codes(<<<'PHP'
            final class A {
                public function __construct(
                    private readonly RecordAuditEvent $auditar,
                    private readonly IssueReceipt $emitir,
                ) {}
                public function handle($c): void { $this->emitir->handle('no.es.auditoria', $c); }
            }
            PHP));
    }

    public function test_un_codigo_armado_en_una_variable_queda_como_no_resuelto(): void
    {
        $resultado = (new AuditActionCodeExtractor)->scan($this->php(<<<'PHP'
            final class A {
                public function __construct(private readonly RecordAuditEvent $auditar) {}
                public function handle($c, string $codigo): void { $this->auditar->handle($codigo, $c); }
            }
            PHP), 'Prueba.php');

        $this->assertSame([], $resultado['codes']);
        $this->assertSame(['Prueba.php:5'], $resultado['unresolved']);
    }

    /**
     * @return list<string>
     */
    private function codes(string $body): array
    {
        $resultado = (new AuditActionCodeExtractor)->scan($this->php($body));

        $this->assertSame([], $resultado['unresolved']);

        return array_column($resultado['codes'], 'code');
    }

    private function php(string $body): string
    {
        return "<?php\nuse App\\Modules\\Shared\\Actions\\RecordAuditEvent;\n".$body;
    }
}
