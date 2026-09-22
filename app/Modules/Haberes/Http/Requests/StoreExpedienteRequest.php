<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Support\ExpedienteNumber;
use App\Modules\Shared\Models\Person;
use App\Support\BusinessDate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Alta de un expediente, sin sus haberes.
 *
 * Los haberes se agregan después, desde el detalle. No es una concesión:
 * es cómo llega el trabajo. El expediente vuelve con el tiempo trayendo el
 * ticket de la cuota siguiente, así que el sistema tiene que saber abrir
 * uno ya cargado y completarlo. Ese camino hay que construirlo igual, y el
 * alta usa el mismo en lugar de uno propio.
 *
 * El costo asumido: puede quedar un expediente sin ningún haber si alguien
 * abandona a mitad. No hace daño —no mueve plata— y el listado los deja a
 * la vista para completarlos o descartarlos.
 */
final class StoreExpedienteRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'number' => ['required', 'string', 'max:40'],
            // Opcional: lo que identifica al expediente es su número.
            // La carátula ayuda a reconocerlo, pero hay expedientes que
            // llegan sin ella y esperar a tenerla frena la carga.
            'subject' => ['nullable', 'string', 'max:255'],
            'receivedDate' => ['required', 'date', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'employerId' => ['required', 'integer'],
            'employerRepresentative' => ['nullable', 'string', 'max:160'],

            // El total declarado es un control, no una obligación: el DER
            // lo define como «total informado en el documento fuente».
            'declaredTotalAmount' => ['nullable', 'numeric', 'gt:0'],
            'externalId' => ['nullable', 'string', 'max:120'],
            'externalReference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'number' => 'número de expediente',
            'subject' => 'carátula',
            'receivedDate' => 'fecha de recepción',
            'employerId' => 'empleador',
            'employerRepresentative' => 'representante',
            'declaredTotalAmount' => 'total declarado',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            $this->validarFormato(...),
            $this->validarQueNoEsteCargado(...),
            $this->validarEmpleador(...),
        ];
    }

    private function validarFormato(Validator $validator): void
    {
        $number = (string) $this->input('number');

        if ($number !== '' && ! ExpedienteNumber::isValid($number)) {
            $validator->errors()->add(
                'number',
                'El número no tiene el formato de SiCE. Se espera 0030064-125957/2026-0 o la forma corta 125957/2026.',
            );
        }
    }

    /**
     * El expediente no puede cargarse dos veces.
     *
     * La pantalla ya avisa mientras se escribe, pero eso es una ayuda al
     * operador: el control tiene que estar acá, porque un duplicado
     * duplica los importes en todos los reportes. Se compara por el número
     * canónico, no por lo tipeado: `125957/2026` y `0030064-125957/2026-0`
     * son el mismo expediente.
     *
     * La base repite la garantía con un índice UNIQUE para cubrir carreras
     * entre dos operadores que validan al mismo tiempo.
     */
    private function validarQueNoEsteCargado(Validator $validator): void
    {
        $numero = ExpedienteNumber::parse((string) $this->input('number'));

        if ($numero === null) {
            return;
        }

        $existente = Expediente::query()
            ->where('canonical_number', $numero->canonical)
            ->value('display_number');

        if ($existente !== null) {
            $validator->errors()->add(
                'number',
                "El expediente {$existente} ya está cargado. Abrilo desde el listado para agregarle haberes.",
            );
        }
    }

    private function validarEmpleador(Validator $validator): void
    {
        $id = $this->integer('employerId');

        if ($id === 0) {
            return;
        }

        $exists = Person::query()
            ->active()
            ->forRole('employer')
            ->whereKey($id)
            ->exists();

        if (! $exists) {
            $validator->errors()->add('employerId', 'Elegí un empleador registrado y activo.');
        }
    }
}
