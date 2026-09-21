<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Actions\ForceVerifyPersonBankAccount;
use App\Modules\Shared\Actions\RejectPersonBankAccount;
use App\Modules\Shared\Actions\SetPersonBankAccountActive;
use App\Modules\Shared\Actions\VerifyPersonBankAccount;
use App\Modules\Shared\Models\Person;
use App\Modules\Shared\Models\PersonBankAccount;
use App\Modules\Shared\Support\Cbu;
use App\Support\Ui\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Las cuentas bancarias particulares de una persona.
 *
 * Existe porque sin ella la Orden de Pago es imposible: el §2.2.9 exige
 * una cuenta `verified` y hasta ahora toda cuenta nacía `unverified` sin
 * que nada pudiera cambiarle el estado.
 *
 * Se opera desde el modal de la Orden, que es donde el dato hace falta: el
 * expediente informa el CBU en una foja, alguien lo carga, alguien lo
 * coteja y recién ahí el organismo puede transferir.
 */
final class PersonBankAccountController extends Controller
{
    /**
     * Carga un CBU nuevo para la persona.
     *
     * Nace `unverified` a propósito: cargar un número y darlo por bueno
     * son dos actos, y el segundo es el que evita que un CVU de billetera
     * termine en una Orden.
     */
    public function store(Request $request, Person $person): RedirectResponse
    {
        $validado = $request->validate([
            'cbu' => [
                'required', 'string', 'regex:/^\d{22}$/',
                Rule::unique('person_bank_accounts', 'cbu')->where('person_id', $person->id),
            ],
            'bankName' => ['nullable', 'string', 'max:120'],
            'accountNumber' => ['nullable', 'string', 'max:80'],
        ], [
            'cbu.regex' => 'Un CBU tiene exactamente 22 dígitos.',
            'cbu.unique' => 'Esa persona ya tiene cargado ese CBU.',
        ]);

        $cuenta = PersonBankAccount::query()->create([
            'person_id' => $person->id,
            'cbu' => $validado['cbu'],
            'bank_name' => $validado['bankName'] ?? null,
            'account_number' => $validado['accountNumber'] ?? null,
            'verification_status' => 'unverified',
            'is_active' => true,
        ]);

        /*
         * El número se guarda pase lo que pase: el expediente informa lo
         * que informa, y el área tiene que poder registrarlo aunque esté
         * mal. Lo que el aviso hace es adelantar qué va a pasar cuando
         * alguien intente darlo por bueno.
         */
        match (true) {
            Cbu::isVirtualWallet($cuenta->cbu) => Toast::warning(
                'CBU cargado, pero es un CVU de billetera virtual.',
                'El organismo no transfiere a billeteras: corresponde rechazarlo y pedir un CBU de banco.',
            ),
            ! Cbu::isValid($cuenta->cbu) => Toast::warning(
                'CBU cargado, pero no pasa el verificador del BCRA.',
                'Revisá si hay un dígito mal tipeado antes de darlo por bueno.',
            ),
            default => Toast::success(
                'CBU cargado.',
                'Falta verificarlo contra el expediente para poder emitir la Orden.',
            ),
        };

        return back();
    }

    /**
     * Da por buena la cuenta después de cotejarla.
     *
     * **La negativa se convierte en toast a mano, y hace falta.** El Action
     * rechaza con una `ValidationException` bajo la clave `accountId`, que
     * es correcta pero no la dibuja nadie: el bloque de cuentas vive dentro
     * del formulario de la Orden y sólo muestra los errores de sus propios
     * campos. Sin esto, apretar «Verificar» sobre un CVU o sobre un CBU que
     * no pasa el dígito del BCRA no producía **ningún** cambio visible, y
     * el operador volvía a apretar creyendo que la pantalla no respondía.
     *
     * Va acá y no en el Action porque el motivo es de presentación: quien
     * llame al Action desde una consola o un test tiene que seguir
     * recibiendo la excepción.
     */
    public function verify(
        Request $request,
        PersonBankAccount $account,
        VerifyPersonBankAccount $verificar,
    ): RedirectResponse {
        try {
            $verificar->handle($account, $request->user()?->id);
        } catch (ValidationException $e) {
            Toast::error('No se puede dar por buena esa cuenta.', $e->getMessage());

            return back();
        }

        Toast::success('Cuenta verificada.', 'Ya puede usarse para emitir la Orden.');

        return back();
    }

    /** Deja constancia de que ese número no sirve, y por qué. */
    public function reject(
        Request $request,
        PersonBankAccount $account,
        RejectPersonBankAccount $rechazar,
    ): RedirectResponse {
        $validado = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ], [
            'reason.required' => 'Hay que decir por qué se rechaza la cuenta.',
            'reason.min' => 'El motivo tiene que explicar algo: un par de palabras no alcanzan.',
        ]);

        $rechazar->handle($account, (string) $validado['reason'], $request->user()?->id);

        Toast::warning('Cuenta rechazada.', 'No vuelve a ofrecerse para pagar.');

        return back();
    }

    /**
     * Da por buena una cuenta que no pasa las guardas. Sólo `super-admin`.
     *
     * La ruta la protege `can:dev.forzar-cbu`. El prefijo `dev.` es lo que
     * la deja fuera del comodín de `Gate::before`: ni el administrador la
     * recibe. Es una herramienta para atravesar el circuito con datos
     * inventados, no un camino del trámite.
     */
    public function force(
        Request $request,
        PersonBankAccount $account,
        ForceVerifyPersonBankAccount $forzar,
    ): RedirectResponse {
        $validado = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ], [
            'reason.required' => 'Hay que decir por qué se fuerza la verificación.',
            'reason.min' => 'El motivo tiene que explicar algo: un par de palabras no alcanzan.',
        ]);

        try {
            $forzar->handle($account, (string) $validado['reason'], $request->user()?->id);
        } catch (ValidationException $e) {
            Toast::error('No se pudo forzar la verificación.', $e->getMessage());

            return back();
        }

        Toast::warning(
            'Verificación forzada.',
            'La cuenta queda marcada: ese CBU no se cotejó contra ninguna foja.',
        );

        return back();
    }

    /**
     * Saca de circulación una cuenta cargada por error.
     *
     * No es lo mismo que rechazar: rechazar deja constancia de por qué ese
     * número no sirve y la cuenta se sigue viendo con su motivo. La baja es
     * para el CBU que nunca debió estar ahí —mal tipeado, duplicado, en la
     * persona equivocada— y lo saca de la pantalla sin dejar un renglón
     * tachado que después haya que explicar.
     */
    public function deactivate(
        Request $request,
        PersonBankAccount $account,
        SetPersonBankAccountActive $darDeBaja,
    ): RedirectResponse {
        $darDeBaja->handle($account, active: false, actorId: $request->user()?->id);

        Toast::success('Cuenta dada de baja.', 'Deja de ofrecerse para emitir la Orden.');

        return back();
    }
}
