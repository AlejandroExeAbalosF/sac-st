<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Haberes\Data\InstallmentReceiptData;
use App\Modules\Haberes\Data\ReceiptPanelData;
use App\Modules\Haberes\Data\ReceiptSubjectData;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Shared\Models\Receipt;
use Illuminate\Http\JsonResponse;

/**
 * De quién es este comprobante.
 *
 * Contesta la pregunta que aparece conciliando la caja: el libro del día
 * lista números de recibo y nada más, y para saber a qué haber pertenece
 * uno había que salir de la pantalla.
 *
 * **Vive en Haberes y no en Ledger.** `receipts.beneficiary_installment_id`
 * es un entero suelto, sin clave foránea, porque `receipts` es de `Shared`
 * y `Shared` no puede depender de `Haberes`. El libro de caja lo transporta
 * sin saber qué significa; traducirlo a cuota, haber y expediente es
 * trabajo de este módulo, que es el único que puede hacerlo sin romper la
 * dirección de las dependencias.
 *
 * Responde JSON y no Inertia porque el panel se abre encima de la pantalla
 * que estés mirando: una visita de Inertia la reemplazaría, que es
 * exactamente lo que el panel viene a evitar.
 */
final class ReceiptPanelController extends Controller
{
    public function __invoke(Receipt $receipt): JsonResponse
    {
        return response()->json(
            ReceiptPanelData::fromModel(
                $receipt->loadMissing(InstallmentReceiptData::RELATIONS),
                $this->sujetoDe($receipt),
            ),
        );
    }

    /**
     * La cuota que originó el comprobante, con su contexto.
     *
     * Devuelve `null` en dos casos distintos y los dos son normales: el
     * pago de un haber anterior, que nunca tuvo cuota, y el comprobante
     * cuya cuota ya no está. El panel muestra igual lo que el papel dice;
     * lo único que pierde es a dónde ir.
     */
    private function sujetoDe(Receipt $receipt): ?ReceiptSubjectData
    {
        if ($receipt->beneficiary_installment_id === null) {
            return null;
        }

        $cuota = BeneficiaryInstallment::query()
            ->with(['haber.beneficiary', 'haber.expediente.employer'])
            ->find($receipt->beneficiary_installment_id);

        return $cuota === null ? null : ReceiptSubjectData::fromInstallment($cuota);
    }
}
