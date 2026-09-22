<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Models\User;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Audit\AuditActionDefinition;
use App\Modules\Shared\Audit\AuditCatalog;
use App\Modules\Shared\Audit\AuditReferenceResolver;
use App\Modules\Shared\Audit\Subjects\UserSubject;
use LogicException;
use Tests\Support\AuditActionCodeExtractor;
use Tests\TestCase;

/**
 * El catálogo de auditoría y el código que lo usa no pueden desfasarse.
 *
 * Ya pasó una vez: los rótulos vivían en el front y traducían códigos que
 * el servidor no emitía, mientras otros que sí emitía salían en crudo.
 * Esta prueba mira el código fuente en las dos direcciones; la guarda de
 * `RecordAuditEvent` lo cubre en tiempo de ejecución.
 */
class AuditCatalogTest extends TestCase
{
    public function test_todo_codigo_que_emite_el_sistema_esta_registrado(): void
    {
        $catalogo = app(AuditCatalog::class);
        $resultado = (new AuditActionCodeExtractor)->scanDirectory(app_path());

        $this->assertSame([], $resultado['unresolved'], 'Hay códigos de auditoría que no se pueden leer sin ejecutar el código.');
        $this->assertNotEmpty($resultado['codes'], 'El analizador no encontró ninguna llamada: se rompió él, no el catálogo.');

        $faltantes = array_values(array_filter(
            $resultado['codes'],
            fn (array $uso): bool => ! $catalogo->has($uso['code']),
        ));

        $this->assertSame([], $faltantes, 'Estos códigos se emiten y no están en el catálogo.');
    }

    public function test_todo_codigo_registrado_se_emite_en_algun_lado(): void
    {
        $emitidos = array_column((new AuditActionCodeExtractor)->scanDirectory(app_path())['codes'], 'code');

        $sobrantes = array_values(array_diff(app(AuditCatalog::class)->codes(), $emitidos));

        $this->assertSame([], $sobrantes, 'Estos códigos están en el catálogo y nadie los emite: un error de tipeo o un resto.');
    }

    public function test_registrar_dos_veces_el_mismo_codigo_falla(): void
    {
        $catalogo = new AuditCatalog;
        $catalogo->registerCategory('Pruebas', 1);
        $catalogo->registerActions(new AuditActionDefinition('prueba.hecha', 'Uno', 'Pruebas'));

        $this->expectException(LogicException::class);

        $catalogo->registerActions(new AuditActionDefinition('prueba.hecha', 'Otro', 'Pruebas'));
    }

    public function test_una_accion_con_categoria_desconocida_falla(): void
    {
        $this->expectException(LogicException::class);

        (new AuditCatalog)->registerActions(new AuditActionDefinition('prueba.hecha', 'Uno', 'Inexistente'));
    }

    public function test_registrar_dos_veces_el_mismo_sujeto_falla(): void
    {
        $catalogo = new AuditCatalog;
        $catalogo->registerSubject(new UserSubject);

        $this->expectException(LogicException::class);

        $catalogo->registerSubject(new UserSubject);
    }

    public function test_dos_referencias_para_el_mismo_campo_fallan(): void
    {
        $referencia = new class implements AuditReferenceResolver
        {
            public function fields(): array
            {
                return ['bank_account_id'];
            }

            public function labels(array $ids): array
            {
                return [];
            }
        };

        $catalogo = new AuditCatalog;
        $catalogo->registerReference($referencia);

        $this->expectException(LogicException::class);

        $catalogo->registerReference(clone $referencia);
    }

    public function test_un_codigo_sin_registrar_se_muestra_tal_cual(): void
    {
        $accion = app(AuditCatalog::class)->action('algo.nuevo');

        $this->assertSame('algo.nuevo', $accion->label);
        $this->assertSame('Otras', $accion->category);
        $this->assertFalse($accion->isCritical());
    }

    public function test_un_sujeto_desconocido_se_describe_sin_enlace(): void
    {
        $descripciones = app(AuditCatalog::class)->describe('Inventado', [7], null);

        $this->assertSame('Inventado #7', $descripciones[7]->label);
        $this->assertNull($descripciones[7]->url);
    }

    public function test_record_audit_event_rechaza_un_codigo_sin_registrar(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('algo.nuevo');

        app(RecordAuditEvent::class)->handle('algo.nuevo', new User);
    }
}
