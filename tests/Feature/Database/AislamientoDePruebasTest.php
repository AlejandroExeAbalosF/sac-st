<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AislamientoDePruebasTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_conexion_real_usa_la_base_del_proceso(): void
    {
        $token = ParallelTesting::token();
        $esperada = $token ? 'sacst_test_test_'.$token : 'sacst_test';

        $this->assertSame('testing', app()->environment());
        $this->assertSame($esperada, DB::selectOne('SELECT current_database() AS name')->name);
    }

    public function test_los_adjuntos_usan_el_directorio_del_proceso(): void
    {
        $token = ParallelTesting::token();
        $directorio = storage_path('framework/testing/disks/local'.($token ? '_test_'.$token : ''));

        Storage::disk('local')->put('aislamiento.txt', 'solo este proceso');

        $this->assertSame(
            str_replace('\\', '/', $directorio.'/aislamiento.txt'),
            str_replace('\\', '/', Storage::disk('local')->path('aislamiento.txt')),
        );
        $this->assertFileExists($directorio.'/aislamiento.txt');
    }
}
