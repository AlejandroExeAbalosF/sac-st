<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `pg_restore` trabaja con `search_path` vacío y crea con él las columnas
 * generadas de `people` y `expedientes`. Si `f_unaccent` depende del
 * `search_path`, esas dos tablas no se restauran y el backup no sirve.
 */
class UnaccentSinSearchPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_f_unaccent_works_with_an_empty_search_path(): void
    {
        // SET LOCAL: termina con la transacción de RefreshDatabase.
        DB::statement("SET LOCAL search_path TO ''");

        $this->assertSame(
            'Nandu Penalba',
            DB::selectOne("SELECT public.f_unaccent('Ñandú Peñalba') AS texto")->texto,
        );
    }
}
