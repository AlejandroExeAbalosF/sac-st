<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\CashToBankTransferItem;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Support\AuditTimeline;
use App\Modules\Shared\Models\AuditEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Qué le pasó a esta cuota, quién y cuándo.
 *
 * El rastro se venía guardando desde el principio —cada corrección deja su
 * `cuota.corregida` con el antes y el después— pero no había dónde
 * leerlo. Un registro que nadie puede consultar no es auditoría: es un
 * archivo que crece.
 *
 * Va acotado a una cuota y no a una pantalla general de auditoría porque
 * es la pregunta que aparece en el momento: «esta cuota decía otra cosa la
 * semana pasada, ¿quién la cambió?». La pantalla general es otra cosa, y
 * es de la etapa de reportes.
 *
 * **Incluye lo que le pasó a su efectivo camino al banco.** El traslado se
 * audita con el traslado como sujeto, no con la cuota, así que quedaba
 * afuera: un depósito cargado y después cancelado desaparecía de la
 * pantalla —la tarjeta deja de mostrar los cancelados— y no había dónde
 * ver que había existido. El rastro estaba en la base y no llegaba a
 * nadie, que es el mismo problema que este historial vino a resolver.
 */
final class InstallmentHistoryController extends Controller
{
    /**
     * Los nombres de los campos como los ve el operador.
     *
     * La columna se llama `expected_amount` y en la pantalla dice
     * «Importe». Mostrar el nombre técnico obligaría a traducir de memoria
     * justo cuando alguien está tratando de entender qué pasó.
     *
     * @var array<string, string>
     */
    private const CAMPOS = [
        'expected_amount' => 'Importe',
        'management_label_id' => 'Etiqueta de gestión',
        'due_date' => 'Vencimiento',
        'description' => 'Concepto',
        'expected_medium' => 'Medio previsto',
        'notes' => 'Observaciones',
        'workflow_status' => 'Estado',
        'block_reason' => 'Motivo del bloqueo',
        // Del traslado del efectivo al banco.
        'amount' => 'Importe',
        'bank_account_id' => 'Cuenta',
        'bank_transaction_id' => 'Movimiento del extracto',
        'deposit_date' => 'Fecha del depósito',
        'motivo' => 'Motivo',
    ];

    /** @var array<string, string> */
    private const MEDIOS = [
        'cash' => 'Efectivo',
        'cheque' => 'Cheque',
        'bank' => 'Depósito en cuenta',
    ];

    public function __construct(private readonly AuditTimeline $linea) {}

    public function __invoke(BeneficiaryInstallment $installment): JsonResponse
    {
        $traslados = $this->trasladosDe($installment);

        $eventos = AuditEvent::query()
            ->with('user:id,name')
            ->where(function (Builder $consulta) use ($installment, $traslados): void {
                $consulta->where(fn (Builder $q) => $q
                    ->where('subject_type', 'BeneficiaryInstallment')
                    ->where('subject_id', $installment->id));

                if ($traslados !== []) {
                    $consulta->orWhere(fn (Builder $q) => $q
                        ->where('subject_type', 'CashToBankTransfer')
                        ->whereIn('subject_id', $traslados));
                }
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'events' => $this->linea->shape($eventos, self::CAMPOS, [
                'expected_medium' => self::MEDIOS,
                'bank_account_id' => $this->etiquetasDeCuenta($eventos),
                'bank_transaction_id' => $this->etiquetasDeMovimiento($eventos),
            ]),
        ]);
    }

    /**
     * Los traslados de esta cuota, cancelados incluidos.
     *
     * Se llega por la imputación: el traslado no apunta a la cuota, apunta
     * a lo que se le había asignado. Y se miran todas las imputaciones, no
     * solo las vigentes, porque el historial tiene que poder contar
     * justamente lo que se deshizo.
     *
     * @return list<int>
     */
    private function trasladosDe(BeneficiaryInstallment $installment): array
    {
        /** @var list<int> $ids */
        $ids = CashToBankTransferItem::query()
            ->whereIn(
                'funding_allocation_id',
                FundingAllocation::query()
                    ->where('beneficiary_installment_id', $installment->id)
                    ->select('id'),
            )
            ->distinct()
            ->pluck('cash_to_bank_transfer_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }

    /**
     * El nombre de las cuentas que nombran los eventos.
     *
     * El registro guarda el id, que es lo correcto —la etiqueta de la
     * cuenta puede cambiar y el historial tiene que seguir señalando a la
     * misma—, pero un número en pantalla no le dice nada a nadie.
     *
     * @param  Collection<int, AuditEvent>  $eventos
     * @return array<int, string>
     */
    private function etiquetasDeCuenta($eventos): array
    {
        $ids = $eventos
            ->map(fn (AuditEvent $e): mixed => ($e->new_values['bank_account_id'] ?? null))
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();

        if ($ids === []) {
            return [];
        }

        /** @var array<int, string> $etiquetas */
        $etiquetas = BankAccount::query()
            ->whereIn('id', $ids)
            ->pluck('label', 'id')
            ->all();

        return $etiquetas;
    }

    /**
     * Una referencia bancaria legible sin perder el ID cuando el banco no
     * informó número de operación.
     *
     * @param  Collection<int, AuditEvent>  $eventos
     * @return array<int, string>
     */
    private function etiquetasDeMovimiento($eventos): array
    {
        $ids = $this->linea->idsMencionados($eventos, 'bank_transaction_id');

        if ($ids === []) {
            return [];
        }

        $etiquetas = [];
        foreach (BankTransaction::query()->whereIn('id', $ids)->get(['id', 'operation_id']) as $movimiento) {
            $etiquetas[$movimiento->id] = $movimiento->operation_id
                ? 'Operación '.$movimiento->operation_id
                : 'Movimiento n.º '.$movimiento->id;
        }

        return $etiquetas;
    }
}
