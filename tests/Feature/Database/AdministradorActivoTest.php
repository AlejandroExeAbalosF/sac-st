<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El sistema no se queda sin un administrador activo, lo intente quien lo
 * intente.
 *
 * Las Actions lo comprobaban sin bloquear nada, y dos administradores que
 * se desactivaban uno al otro al mismo tiempo pasaban los dos. La base lo
 * impone con un trigger diferido que cuenta con un bloqueo tomado.
 *
 * El trigger es diferido y `RefreshDatabase` nunca confirma: cada test
 * pasa a `IMMEDIATE`, que dispara en ese momento lo que estaba pendiente.
 */
class AdministradorActivoTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_base_no_deja_desactivar_al_ultimo_administrador(): void
    {
        $unico = $this->operador('administrador');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('El sistema no puede quedarse sin un administrador activo.');

        DB::table('users')->where('id', $unico->id)->update(['is_active' => false]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_la_base_no_deja_quitarle_el_rol_al_ultimo_administrador(): void
    {
        $unico = $this->operador('administrador');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('El sistema no puede quedarse sin un administrador activo.');

        DB::table('model_has_roles')
            ->where('model_id', $unico->id)
            ->where('model_type', User::class)
            ->delete();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    /** Con otro administrador activo, uno se puede ir. */
    public function test_con_otro_administrador_activo_se_puede_desactivar_uno(): void
    {
        $uno = $this->operador('administrador');
        $this->operador('administrador');

        DB::table('users')->where('id', $uno->id)->update(['is_active' => false]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertFalse((bool) DB::table('users')->where('id', $uno->id)->value('is_active'));
    }

    /**
     * `syncRoles` quita el rol y lo vuelve a poner: en el medio el único
     * administrador parece no serlo. Por eso el trigger es diferido.
     */
    public function test_reasignar_el_mismo_rol_al_ultimo_administrador_no_se_rechaza(): void
    {
        $unico = $this->operador('administrador');

        $unico->syncRoles(['administrador']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertTrue($unico->refresh()->hasRole('administrador'));
    }

    /** Lo que no toca a un administrador no se controla. */
    public function test_desactivar_a_quien_no_es_administrador_no_se_controla(): void
    {
        $operador = $this->operador();

        DB::table('users')->where('id', $operador->id)->update(['is_active' => false]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertFalse((bool) DB::table('users')->where('id', $operador->id)->value('is_active'));
    }
}
