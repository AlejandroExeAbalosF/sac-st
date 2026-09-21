<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Models\Person;
use Illuminate\Support\Facades\DB;

/**
 * Completa los datos del maestro que la Orden de Pago necesita.
 *
 * **No es parte de la emisión, y por eso va aparte.** Lo que se corrige
 * acá —el domicilio del beneficiario, el CUIT de la empresa, la fecha en
 * que entró el expediente— es cierto con independencia de que la Orden
 * llegue a emitirse. Meterlo en la misma transacción que la emisión haría
 * que un rechazo del papel borrara el domicilio recién tipeado, y quien lo
 * cargó tendría que escribirlo de nuevo.
 *
 * El circuito descubre estos huecos tarde a propósito: nadie carga el
 * domicilio de un trabajador cuando abre el expediente, porque hasta que
 * hay que pagarle no hace falta. El modal de la Orden es el primer momento
 * en que el dato importa, y es donde se pide.
 *
 * **Escribe en el maestro, no en la Orden.** La Orden se va a llevar su
 * copia congelada al emitirse; lo que esto deja cargado sirve para la
 * próxima cuota del mismo beneficiario, que es el punto.
 */
final class CompletePaymentOrderData
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  array{
     *     beneficiaryDocument?: string|null,
     *     beneficiaryAddress?: string|null,
     *     beneficiaryPhone?: string|null,
     *     employerDocument?: string|null,
     *     employerAddress?: string|null,
     *     employerPhone?: string|null,
     *     custodyStartDate?: string|null,
     * }  $datos
     */
    public function handle(BeneficiaryInstallment $installment, array $datos, ?int $actorId = null): void
    {
        $installment->loadMissing(['haber.beneficiary', 'haber.expediente.employer']);

        $beneficiario = $installment->haber->beneficiary;
        $empleador = $installment->haber->expediente->employer;
        $expediente = $installment->haber->expediente;

        DB::transaction(function () use ($datos, $beneficiario, $empleador, $expediente, $actorId): void {
            $this->actualizarPersona(
                $beneficiario,
                $this->camposPresentes($datos, [
                    'beneficiaryDocument' => 'document',
                    'beneficiaryAddress' => 'address',
                    'beneficiaryPhone' => 'phone',
                ]),
                'beneficiario',
                $actorId,
            );

            if ($empleador !== null) {
                $this->actualizarPersona(
                    $empleador,
                    $this->camposPresentes($datos, [
                        'employerDocument' => 'document',
                        'employerAddress' => 'address',
                        'employerPhone' => 'phone',
                    ]),
                    'empleador',
                    $actorId,
                );
            }

            if (! array_key_exists('custodyStartDate', $datos)) {
                return;
            }

            $fechaIngreso = $this->limpiar($datos['custodyStartDate']);

            if ($expediente->received_date?->toDateString() !== $fechaIngreso) {
                $antes = ['received_date' => $expediente->received_date?->toDateString()];

                $expediente->forceFill(['received_date' => $fechaIngreso])->save();

                $this->auditar->handle(
                    'expediente.fecha-ingreso-corregida',
                    $expediente,
                    before: $antes,
                    after: ['received_date' => $fechaIngreso],
                    metadata: ['origen' => 'orden-de-pago'],
                    actorId: $actorId,
                );
            }
        });
    }

    /**
     * Escribe solo lo que llegó y cambió.
     *
     * Un campo ausente no se toca. Uno presente y vacío sí se limpia: si el
     * operador borra un teléfono incorrecto, guardar no puede aparentar que
     * aceptó el cambio mientras conserva silenciosamente el dato anterior.
     *
     * @param  array<string, string|null>  $campos
     */
    private function actualizarPersona(Person $persona, array $campos, string $rol, ?int $actorId): void
    {
        $cambios = [];
        $antes = [];

        foreach ($campos as $campo => $valor) {
            $limpio = $this->limpiar($valor);

            if ($persona->{$campo} === $limpio) {
                continue;
            }

            $antes[$campo] = $persona->{$campo};
            $cambios[$campo] = $limpio;
        }

        if ($cambios === []) {
            return;
        }

        $persona->forceFill($cambios)->save();

        $this->auditar->handle(
            'persona.datos-completados',
            $persona,
            before: $antes,
            after: $cambios,
            metadata: ['rol' => $rol, 'origen' => 'orden-de-pago', 'actor_id' => $actorId],
            actorId: $actorId,
        );
    }

    /**
     * @param  array<string, string|null>  $datos
     * @param  array<string, string>  $mapa  campo del formulario => columna
     * @return array<string, string|null>
     */
    private function camposPresentes(array $datos, array $mapa): array
    {
        $presentes = [];

        foreach ($mapa as $entrada => $columna) {
            if (array_key_exists($entrada, $datos)) {
                $presentes[$columna] = $datos[$entrada];
            }
        }

        return $presentes;
    }

    private function limpiar(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $limpio = trim($valor);

        return $limpio === '' ? null : $limpio;
    }
}
