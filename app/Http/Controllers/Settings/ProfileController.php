<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Support\Ui\Toast;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Los datos propios.
     *
     * Van también los que el usuario **no** puede cambiar —nombre de
     * usuario, DNI, cargo y rol—, en solo lectura. Mostrarlos no es
     * decorativo: el rol es con lo que opera, el cargo es lo que se imprime
     * al pie de los recibos que firma, y hasta ahora no tenía dónde
     * verlos. La pantalla dice quién los asigna, así que nadie los busca.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('mi-cuenta/perfil', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'assigned' => [
                'username' => $user->username,
                'documentNumber' => $user->document_number,
                'position' => $user->position,
                'role' => $user->getRoleNames()->first(),
            ],
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        // Los campos del formulario llegan en camelCase y las columnas no
        // se llaman igual, así que el mapeo lo hace el FormRequest.
        $request->user()->fill($request->userAttributes());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Toast::success(__('Perfil actualizado.'));

        return to_route('mi-cuenta.perfil');
    }

    /*
     * No hay baja de cuenta autogestionada, y no es un olvido.
     *
     * Un usuario que registró una recepción, emitió un recibo o validó un
     * egreso tiene que seguir siendo identificable para siempre: borrarlo
     * dejaría comprobantes firmados por nadie. La baja se resuelve
     * marcando `is_active = false` desde el módulo de usuarios, lo que le
     * impide ingresar sin tocar una sola línea de su historial.
     */
}
