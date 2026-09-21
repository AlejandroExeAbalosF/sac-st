<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Shared\Actions\ForceVerifyPersonBankAccount;
use App\Modules\Shared\Actions\RejectPersonBankAccount;
use App\Modules\Shared\Actions\SetPersonBankAccountActive;
use App\Modules\Shared\Actions\VerifyPersonBankAccount;
use App\Modules\Shared\Models\Person;
use App\Modules\Shared\Models\PersonBankAccount;
use App\Modules\Shared\Support\Cbu;
use App\Support\Ui\ToastType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Support\SessionKey;
use Tests\TestCase;

/**
 * La puerta de la Orden de Pago: dar por buena una cuenta.
 *
 * El §2.2.9 del DER exige cuenta `verified` y explica con qué caso real se
 * topó el área: *«una cuenta que no resulte un CBU válido —por ejemplo, un
 * CVU de billetera virtual— se registra como rejected con su motivo y no
 * habilita la emisión de la Orden, porque el organismo no transfiere a
 * billeteras virtuales»*.
 *
 * **Las dos guardas son independientes y hay que probarlas por separado**,
 * porque la intuición las confunde: un CVU está bien escrito y pasa el
 * dígito verificador igual que un CBU. Detectarlo por el verificador —que
 * es lo que este código hacía— dejaba pasar exactamente el caso que la
 * regla quiere impedir.
 */
class VerificacionDeCbuTest extends TestCase
{
    use RefreshDatabase;

    /** Banco Macro: entidad 285, con los dos dígitos verificadores correctos. */
    private const CBU_BANCARIO = '2850000300000000000017';

    /** Billetera virtual: entidad 000, y **también** con los DV correctos. */
    private const CVU_BILLETERA = '0000031400012345678907';

    /*
    |---------------------------------------------------------------------
    | La aritmética, que es la misma para todos los bancos
    |---------------------------------------------------------------------
    */

    /**
     * El punto que hace falta dejar escrito: **un CVU pasa el
     * verificador**.
     *
     * Si esta afirmación fuera falsa, alcanzaría con `isValid()` para
     * atrapar las billeteras y `isVirtualWallet()` sobraría. Es verdadera,
     * y por eso hacen falta las dos.
     */
    public function test_un_cvu_bien_formado_pasa_el_digito_verificador(): void
    {
        $this->assertTrue(Cbu::isValid(self::CVU_BILLETERA));
        $this->assertTrue(Cbu::isVirtualWallet(self::CVU_BILLETERA));
    }

    /** Un CBU de banco no es una billetera, y su entidad lo dice. */
    public function test_un_cbu_bancario_no_es_billetera(): void
    {
        $this->assertTrue(Cbu::isValid(self::CBU_BANCARIO));
        $this->assertFalse(Cbu::isVirtualWallet(self::CBU_BANCARIO));
        $this->assertSame('285', Cbu::entityCode(self::CBU_BANCARIO));
    }

    /** Veintidós dígitos iguales no son un CBU por más que tengan el largo. */
    public function test_un_numero_inventado_no_pasa(): void
    {
        $this->assertFalse(Cbu::isValid('1111111111111111111111'));
    }

    /*
    |---------------------------------------------------------------------
    | Verificar
    |---------------------------------------------------------------------
    */

    public function test_una_cuenta_bancaria_valida_se_verifica(): void
    {
        $cuenta = $this->cuenta(self::CBU_BANCARIO);

        app(VerifyPersonBankAccount::class)->handle($cuenta, $this->operador()->id);

        $this->assertSame('verified', $cuenta->refresh()->verification_status);
        $this->assertNotNull($cuenta->verified_at);
    }

    /**
     * §2.2.9. Es el caso real del área, y el que el verificador **no**
     * atrapa: el número está perfecto, lo que no sirve es quién lo emite.
     */
    public function test_un_cvu_no_se_puede_verificar(): void
    {
        $cuenta = $this->cuenta(self::CVU_BILLETERA);

        try {
            app(VerifyPersonBankAccount::class)->handle($cuenta, $this->operador()->id);
            $this->fail('Se verificó un CVU de billetera virtual.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('billetera', $e->getMessage());
        }

        $this->assertSame('unverified', $cuenta->refresh()->verification_status);
    }

    /** Y el otro caso, que sí es un error de tipeo. */
    public function test_un_numero_mal_tipeado_no_se_puede_verificar(): void
    {
        $cuenta = $this->cuenta('1111111111111111111111');

        try {
            app(VerifyPersonBankAccount::class)->handle($cuenta, $this->operador()->id);
            $this->fail('Se verificó un número que no pasa el verificador.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('verificador del BCRA', $e->getMessage());
        }
    }

    /**
     * **Cargar no es verificar.** Ninguna de las dos guardas impide
     * registrar el número: el expediente informa lo que informa, y el área
     * tiene que poder anotarlo aunque esté mal. Lo que impiden es darlo
     * por bueno.
     */
    public function test_el_numero_se_puede_cargar_aunque_no_sirva(): void
    {
        $persona = $this->beneficiario();

        $this->actingAs($this->operador('contador'))
            ->post(route('personas.cuentas.store', $persona), ['cbu' => self::CVU_BILLETERA])
            ->assertRedirect();

        $this->assertDatabaseHas('person_bank_accounts', [
            'person_id' => $persona->id,
            'cbu' => self::CVU_BILLETERA,
            'verification_status' => 'unverified',
        ]);
    }

    /*
    |---------------------------------------------------------------------
    | Rechazar
    |---------------------------------------------------------------------
    */

    /** Rechazar exige motivo: es lo que explica por qué se pidió otro CBU. */
    public function test_rechazar_exige_motivo(): void
    {
        $this->expectException(ValidationException::class);

        app(RejectPersonBankAccount::class)->handle(
            $this->cuenta(self::CVU_BILLETERA),
            '   ',
            $this->operador()->id,
        );
    }

    public function test_la_cuenta_rechazada_guarda_su_motivo(): void
    {
        $cuenta = $this->cuenta(self::CVU_BILLETERA);

        app(RejectPersonBankAccount::class)->handle(
            $cuenta,
            'Es un CVU de billetera virtual; el organismo no transfiere a billeteras.',
            $this->operador()->id,
        );

        $cuenta->refresh();

        $this->assertSame('rejected', $cuenta->verification_status);
        $this->assertStringContainsString('billetera', (string) $cuenta->rejection_reason);
    }

    /** Verificar después de un rechazo limpia el motivo: la base exige el par. */
    public function test_verificar_limpia_el_rechazo_anterior(): void
    {
        $cuenta = $this->cuenta(self::CBU_BANCARIO);

        app(RejectPersonBankAccount::class)->handle(
            $cuenta,
            'Se cargó en el expediente equivocado.',
            $this->operador()->id,
        );

        app(VerifyPersonBankAccount::class)->handle($cuenta->refresh(), $this->operador()->id);

        $cuenta->refresh();

        $this->assertSame('verified', $cuenta->verification_status);
        $this->assertNull($cuenta->rejection_reason);
    }

    /*
    |---------------------------------------------------------------------
    | Cómo se lo cuenta la pantalla
    |---------------------------------------------------------------------
    */

    /**
     * Verificar y rechazar terminan bien, pero no significan lo mismo.
     *
     * Las dos operaciones salen sin error, así que las dos llegaban como
     * confirmación verde. Una habilita la Orden y la otra clausura el
     * número para siempre: el tono es parte del mensaje, no decoración.
     */
    public function test_verificar_confirma_y_rechazar_advierte(): void
    {
        $operador = $this->operador('contador');

        $this->actingAs($operador)
            ->post(route('personas.cuentas.verify', $this->cuenta(self::CBU_BANCARIO)))
            ->assertRedirect();

        $this->assertToast('Cuenta verificada.', 'Ya puede usarse para emitir la Orden.');

        $this->actingAs($operador)
            ->post(route('personas.cuentas.reject', $this->cuenta(self::CVU_BILLETERA)), [
                'reason' => 'Es un CVU de billetera virtual y el organismo no transfiere ahí.',
            ])
            ->assertRedirect();

        $this->assertToast(
            'Cuenta rechazada.',
            'No vuelve a ofrecerse para pagar.',
            ToastType::Warning,
        );
    }

    /**
     * Y cargar un CVU avisa en el momento, sin frenar la carga.
     *
     * El número se guarda igual —el expediente informa lo que informa—,
     * así que la operación es un éxito con reparo: exactamente lo que el
     * tono `warning` dice y lo que el verde no decía.
     */
    public function test_cargar_un_cvu_avisa_sin_frenar_la_carga(): void
    {
        $this->actingAs($this->operador('contador'))
            ->post(route('personas.cuentas.store', $this->beneficiario()), [
                'cbu' => self::CVU_BILLETERA,
            ])
            ->assertRedirect();

        $this->assertToast(
            'CBU cargado, pero es un CVU de billetera virtual.',
            'El organismo no transfiere a billeteras: corresponde rechazarlo y pedir un CBU de banco.',
            ToastType::Warning,
        );
    }

    /**
     * La negativa del Action llega a la pantalla, que es lo que faltaba.
     *
     * El Action rechazaba correctamente y la base quedaba intacta, pero la
     * `ValidationException` viajaba bajo la clave `accountId`, que no la
     * dibuja nadie: el bloque de cuentas vive dentro del formulario de la
     * Orden y sólo muestra los errores de sus propios campos. El operador
     * apretaba «Verificar» y **no pasaba nada visible**, así que volvía a
     * apretar creyendo que la pantalla se había colgado.
     */
    public function test_verificar_un_cvu_desde_la_pantalla_lo_dice(): void
    {
        $cuenta = $this->cuenta(self::CVU_BILLETERA);

        $this->actingAs($this->operador('contador'))
            ->post(route('personas.cuentas.verify', $cuenta))
            ->assertRedirect();

        $flasheado = session(SessionKey::FLASH_DATA);

        $this->assertIsArray($flasheado);
        $this->assertSame(ToastType::Error->value, $flasheado['toast']['type']);
        $this->assertSame('No se puede dar por buena esa cuenta.', $flasheado['toast']['message']);
        $this->assertStringContainsString('CVU de billetera virtual', $flasheado['toast']['description']);

        // Y lo que importa: la cuenta no se movió.
        $this->assertSame('unverified', $cuenta->refresh()->verification_status);
    }

    /** Lo mismo para el número que no pasa el dígito del BCRA. */
    public function test_verificar_un_numero_mal_tipeado_desde_la_pantalla_lo_dice(): void
    {
        $cuenta = $this->cuenta('2850000300000000000018');

        $this->actingAs($this->operador('contador'))
            ->post(route('personas.cuentas.verify', $cuenta))
            ->assertRedirect();

        $flasheado = session(SessionKey::FLASH_DATA);

        $this->assertIsArray($flasheado);
        $this->assertSame(ToastType::Error->value, $flasheado['toast']['type']);
        $this->assertStringContainsString('verificador del BCRA', $flasheado['toast']['description']);
        $this->assertSame('unverified', $cuenta->refresh()->verification_status);
    }

    /*
    |---------------------------------------------------------------------
    | La baja, que no es el rechazo
    |---------------------------------------------------------------------
    */

    /**
     * El CBU cargado por error sale de la lista.
     *
     * `VerifyPersonBankAccount` tenía escrita la guarda —*«Esa cuenta está
     * dada de baja»*— contra un estado que nada podía producir: la columna
     * `is_active` existía y ningún camino la ponía en `false`.
     */
    public function test_una_cuenta_cargada_por_error_se_da_de_baja(): void
    {
        $cuenta = $this->cuenta(self::CBU_BANCARIO);

        $this->actingAs($this->operador('contador'))
            ->post(route('personas.cuentas.deactivate', $cuenta))
            ->assertRedirect();

        $this->assertToast('Cuenta dada de baja.', 'Deja de ofrecerse para emitir la Orden.');
        $this->assertFalse($cuenta->refresh()->is_active);
    }

    /** Y una vez de baja, ya no se puede dar por buena. */
    public function test_una_cuenta_de_baja_no_se_verifica(): void
    {
        $cuenta = $this->cuenta(self::CBU_BANCARIO);

        app(SetPersonBankAccountActive::class)
            ->handle($cuenta, active: false, actorId: $this->operador()->id);

        try {
            app(VerifyPersonBankAccount::class)->handle($cuenta->refresh(), $this->operador()->id);
            $this->fail('Se verificó una cuenta dada de baja.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('dada de baja', $e->getMessage());
        }
    }

    /**
     * La baja es simétrica aunque la pantalla sólo ofrezca un sentido.
     *
     * Una cuenta de baja no aparece en ningún listado —tanto
     * `PaymentOrderContextData` como `verifiedAccounts()` filtran por
     * `is_active`—, así que sin el camino de vuelta una baja por error
     * sería irreparable salvo a mano contra la base.
     */
    public function test_la_baja_se_puede_revertir(): void
    {
        $cuenta = $this->cuenta(self::CBU_BANCARIO);
        $accion = app(SetPersonBankAccountActive::class);
        $operador = $this->operador()->id;

        $accion->handle($cuenta, active: false, actorId: $operador);
        $this->assertFalse($cuenta->refresh()->is_active);

        $accion->handle($cuenta, active: true, actorId: $operador);
        $this->assertTrue($cuenta->refresh()->is_active);
    }

    /*
    |---------------------------------------------------------------------
    | Forzar: la herramienta de desarrollo, y su correa
    |---------------------------------------------------------------------
    */

    /**
     * El administrador **no** puede forzar, y eso es el rol entero.
     *
     * `Gate::before` le concede todo lo demás sin enumerarlo, así que sin
     * la excepción del prefijo `dev.` el permiso le llegaría gratis y
     * `super-admin` sería una etiqueta sin efecto: no se puede estar por
     * encima de «todo».
     */
    public function test_el_administrador_no_puede_forzar_aunque_reciba_todo(): void
    {
        $admin = $this->operador('administrador');

        // Recibe lo que no tiene enumerado: el comodín sigue funcionando.
        $this->assertTrue($admin->can('personas.verificar-cbu'));
        $this->assertTrue($admin->can('usuarios.crear'));

        // Menos esto.
        $this->assertFalse($admin->can('dev.forzar-cbu'));

        $this->actingAs($admin)
            ->post(route('personas.cuentas.force', $this->cuenta(self::CVU_BILLETERA)), [
                'reason' => 'Quiero probar el circuito sin tener un CBU bueno.',
            ])
            ->assertForbidden();
    }

    /**
     * Y la regla es del **prefijo**, no de este permiso en particular.
     *
     * Es lo que hace que agregar una capacidad de desarrollador mañana
     * —una pantalla a medio construir, una función en prueba— no exija
     * acordarse de tocar `AppServiceProvider`. Si alguien cambiara el
     * comodín por una lista enumerada, este test es el que avisa.
     */
    public function test_cualquier_capacidad_dev_queda_fuera_del_comodin_del_administrador(): void
    {
        $admin = $this->operador('administrador');

        // Capacidades que no existen como permiso sembrado: al
        // administrador el comodín se las concede igual...
        $this->assertTrue($admin->can('una.capacidad.que.no.existe'));

        // ...salvo si llevan el prefijo.
        $this->assertFalse($admin->can('dev.pantalla-en-construccion'));
        $this->assertFalse($admin->can('dev.lo-que-se-invente-manana'));
    }

    /**
     * `super-admin` recibe **todo**, no «lo del admin más algunas cosas».
     *
     * El comodín le contesta que sí a cualquier capacidad antes de mirar
     * ninguna otra regla, incluidas las que no están sembradas.
     */
    public function test_el_super_admin_recibe_todo_sin_filtro(): void
    {
        $super = $this->operador('super-admin');

        $this->assertTrue($super->can('dev.forzar-cbu'));
        $this->assertTrue($super->can('dev.lo-que-se-invente-manana'));
        $this->assertTrue($super->can('usuarios.crear'));
        $this->assertTrue($super->can('personas.verificar-cbu'));
        $this->assertTrue($super->can('una.capacidad.que.no.existe'));
    }

    /** Y el contador tampoco, por si alguien lo cuelga del permiso de verificar. */
    public function test_el_contador_no_puede_forzar(): void
    {
        $this->actingAs($this->operador('contador'))
            ->post(route('personas.cuentas.force', $this->cuenta(self::CVU_BILLETERA)), [
                'reason' => 'Quiero probar el circuito sin tener un CBU bueno.',
            ])
            ->assertForbidden();
    }

    /** El super-admin sí, y la cuenta queda marcada. */
    public function test_el_super_admin_fuerza_y_la_cuenta_queda_marcada(): void
    {
        $cuenta = $this->cuenta(self::CVU_BILLETERA);

        $this->actingAs($this->operador('super-admin'))
            ->post(route('personas.cuentas.force', $cuenta), [
                'reason' => 'Prueba del circuito de emisión con datos inventados.',
            ])
            ->assertRedirect();

        $cuenta->refresh();

        $this->assertSame('verified', $cuenta->verification_status);
        $this->assertSame('virtual_wallet', $cuenta->forced_verification_bypass);
        $this->assertStringContainsString('Prueba del circuito', (string) $cuenta->forced_verification_reason);

        $this->assertToast(
            'Verificación forzada.',
            'La cuenta queda marcada: ese CBU no se cotejó contra ninguna foja.',
            ToastType::Warning,
        );
    }

    /** Un CBU mal tipeado saltea el dígito; un CVU mal tipeado saltea los dos. */
    public function test_el_salteo_registra_contra_que_se_forzo(): void
    {
        $forzar = app(ForceVerifyPersonBankAccount::class);
        $actor = $this->operador('super-admin')->id;

        $malTipeado = $this->cuenta('2850000300000000000018');
        $forzar->handle($malTipeado, 'Prueba con un número inventado.', $actor);
        $this->assertSame('checksum', $malTipeado->refresh()->forced_verification_bypass);

        // CVU con el último dígito cambiado: falla las dos guardas.
        $cvuRoto = $this->cuenta('0000031400012345678908');
        $forzar->handle($cvuRoto, 'Prueba con un CVU inventado.', $actor);
        $this->assertSame('checksum+virtual_wallet', $cvuRoto->refresh()->forced_verification_bypass);
    }

    /**
     * Forzar exige motivo, igual que rechazar y por lo mismo.
     *
     * Un número dado por bueno **contra la evidencia** y sin explicación no
     * se puede auditar después.
     */
    public function test_forzar_exige_motivo(): void
    {
        $this->expectException(ValidationException::class);

        app(ForceVerifyPersonBankAccount::class)
            ->handle($this->cuenta(self::CVU_BILLETERA), '   ', $this->operador('super-admin')->id);
    }

    /**
     * Un CBU que pasa las dos guardas no se fuerza: se verifica.
     *
     * Sin esta negativa, forzar sería un atajo cómodo para el uso normal y
     * en un mes todas las cuentas estarían marcadas como forzadas, que es
     * exactamente como muere un control de este tipo.
     */
    public function test_un_cbu_bueno_no_se_fuerza(): void
    {
        try {
            app(ForceVerifyPersonBankAccount::class)
                ->handle($this->cuenta(self::CBU_BANCARIO), 'Por las dudas.', $this->operador('super-admin')->id);
            $this->fail('Se forzó una cuenta que no lo necesitaba.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('se verifica con el botón normal', $e->getMessage());
        }
    }

    /** Rechazar una cuenta forzada limpia la marca, y la base lo exige. */
    public function test_rechazar_limpia_la_marca_de_forzado(): void
    {
        $cuenta = $this->cuenta(self::CVU_BILLETERA);
        $actor = $this->operador('super-admin')->id;

        app(ForceVerifyPersonBankAccount::class)->handle($cuenta, 'Prueba del circuito.', $actor);
        $this->assertNotNull($cuenta->refresh()->forced_verification_reason);

        app(RejectPersonBankAccount::class)->handle(
            $cuenta,
            'Al final el trabajador informó un CBU de banco.',
            $actor,
        );

        $cuenta->refresh();

        $this->assertSame('rejected', $cuenta->verification_status);
        $this->assertNull($cuenta->forced_verification_reason);
        $this->assertNull($cuenta->forced_verification_bypass);
    }

    /**
     * Y la base sostiene la marca por su cuenta.
     *
     * `person_bank_accounts_forced_check` ata el motivo de forzado al
     * estado `verified`: una cuenta rechazada que conserve la marca es un
     * registro que se contradice a sí mismo, y no depende de que el Action
     * se acuerde de limpiarla.
     */
    public function test_la_base_rechaza_una_marca_de_forzado_sin_verificar(): void
    {
        $cuenta = $this->cuenta(self::CVU_BILLETERA);

        $this->expectException(QueryException::class);

        DB::table('person_bank_accounts')
            ->where('id', $cuenta->id)
            ->update([
                'verification_status' => 'unverified',
                'forced_verification_reason' => 'Sin haberla verificado.',
                'forced_verification_bypass' => 'virtual_wallet',
            ]);
    }

    private function cuenta(string $cbu): PersonBankAccount
    {
        return PersonBankAccount::query()->create([
            'person_id' => $this->beneficiario()->id,
            'cbu' => $cbu,
            'verification_status' => 'unverified',
            'is_active' => true,
        ]);
    }

    private function beneficiario(): Person
    {
        return Person::query()->firstOrCreate(
            ['document' => '38.357.040'],
            [
                'type' => 'individual',
                'first_name' => 'Cristian Fernando',
                'last_name' => 'Mendoza',
                'is_active' => true,
            ],
        );
    }
}
