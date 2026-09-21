<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Pdf;

use App\Modules\Banking\Models\BankAccount;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Haberes\Support\FundingSourceRow;
use App\Support\Money\Decimal;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;

/**
 * La Orden de Pago, impresa sobre el formulario del área.
 *
 * Una sola plantilla para el PDF y para la pantalla, que es lo que
 * garantiza que la vista previa no muestre algo distinto de lo que sale
 * por la impresora.
 *
 * **Sin modo «sobre preimpreso»**, al revés que el recibo de ingreso. El
 * recibo lo necesita porque se escribe a mano sobre el talonario del área;
 * la Orden pasó a generarla el sistema, así que se dibuja entera sobre
 * papel en blanco. Si algún día hubiera que imprimirla sobre el formulario
 * preimpreso, eso exige medir la hoja real y es otra plantilla.
 *
 * Vive en `Haberes` y no en `Shared` porque conoce el modelo de la Orden,
 * que es dominio. El recibo pudo quedar en `Shared` porque `receipts` es
 * infraestructura de comprobantes; esto no.
 */
final class PaymentOrderPdf
{
    /**
     * @param  list<FundingSourceRow>|null  $renglones  La tabla de depósitos
     *                                                  todavía sin congelar,
     *                                                  para la vista previa.
     */
    public function render(PaymentOrder $order, ?array $renglones = null): PdfDocument
    {
        return Pdf::loadView('pdf.orden-de-pago', $this->datos($order, $renglones))
            ->setPaper('a4');
    }

    /**
     * La misma vista, sin PDF: es lo que la pantalla muestra como anticipo.
     *
     * @param  list<FundingSourceRow>|null  $renglones
     */
    public function preview(PaymentOrder $order, ?array $renglones = null): View
    {
        return view('pdf.orden-de-pago', $this->datos($order, $renglones));
    }

    /**
     * @param  bool  $borrador  Un papel sin emitir, que se marca en el nombre.
     */
    public function filename(PaymentOrder $order, bool $borrador = false): string
    {
        // Con barra el nombre de archivo se rompe en Windows.
        $numero = str_replace('/', '-', (string) $order->formatted_number);

        /*
         * El borrador lo dice en el nombre. La hoja sale igual que la
         * emitida —esa es la gracia— y su número es el que *le tocaría*,
         * no el definitivo: dos archivos idénticos en Descargas, uno de
         * ellos sin valor, es la clase de confusión que termina en un
         * papel sin respaldo circulando por el organismo.
         */
        return 'orden-de-pago-'.$numero.($borrador ? '-borrador' : '').'.pdf';
    }

    /**
     * @param  list<FundingSourceRow>|null  $renglones
     * @return array<string, mixed>
     */
    private function datos(PaymentOrder $order, ?array $renglones): array
    {
        $order->loadMissing('expenseReceipt');

        return [
            'orden' => $order,
            /*
             * «RECIBO EGRESO N°» del pie, cuando ya existe.
             *
             * Se imprimía siempre en blanco y se completaba a mano porque
             * el circuito de egreso no existía. Ahora existe:
             * `IssueExpenseReceipt` ata el recibo a su Orden en el mismo
             * acto de emitirlo —invariante 24— y la reimpresión posterior
             * puede decirlo.
             *
             * **Al emitir sigue saliendo vacío, y no es un olvido.** El
             * recibo de egreso nace cuando el egreso se valida, que es
             * semanas después de que la Orden se remitió: al imprimirla
             * para mandarla no hay número que poner porque el comprobante
             * todavía no existe.
             */
            'reciboDeEgreso' => $this->numeroDelReciboDeEgreso($order),
            /*
             * El número completo con su serie: `0030/00000003`.
             *
             * Empezó saliendo pelado —`3582`— copiando el formulario del
             * área, que es preimpreso y no dice de qué serie viene porque
             * sólo hay un talonario. Acá el número lo genera el sistema y
             * hay tres series conviviendo, así que el pelado no identifica
             * nada por sí solo: la 3 de Órdenes y la 3 de recibos de
             * ingreso son papeles distintos.
             *
             * Es además lo que los dos recibos ya imprimían; la Orden era
             * la excepción.
             */
            'numeroImpreso' => $order->formatted_number,
            'importe' => Decimal::format($order->amount),
            'extra' => $this->formulario($order, $renglones),
        ];
    }

    /**
     * El cuadro de depósitos y las cuentas del organismo.
     *
     * @param  list<FundingSourceRow>|null  $renglones
     */
    private function formulario(PaymentOrder $order, ?array $renglones): PaymentOrderPrintData
    {
        $crudo = $renglones === null
            ? $this->desdeLoCongelado($order)
            : $this->desdeLosRenglones($renglones);

        $total = '0.00';
        $depositos = [];

        foreach ($crudo as $fila) {
            /*
             * El total se suma con los importes crudos y se formatea una
             * sola vez al final. Sumar textos ya formateados obligaría a
             * deshacer los puntos de miles, que es la clase de ida y
             * vuelta donde se pierde un centavo.
             */
            $total = Decimal::add($total, $fila['importe']);

            $depositos[] = new PaymentOrderDepositRow(
                operacion: $fila['operacion'],
                fecha: $fila['fecha']?->format('j/n/Y'),
                cuenta: $fila['cuenta'],
                importe: Decimal::format($fila['importe']),
            );
        }

        $cuentaDelOrganismo = $order->organism_bank_account_id === null
            ? null
            : BankAccount::query()->find($order->organism_bank_account_id);

        return new PaymentOrderPrintData(
            depositos: $depositos,
            cuentas: $this->cuentasDelOrganismo($order),
            total: Decimal::format($total),
            banco: $cuentaDelOrganismo?->bank_name,
            fechaBanco: $depositos[0]->fecha ?? null,
        );
    }

    /**
     * Cuál de los dos números del recibo de egreso va en el pie.
     *
     * Se resuelve con el criterio con el que **ese recibo se emitió**
     * —`prints_talonario_number`— y no con el de la Orden, que describe el
     * recibo de *ingreso* y es otra decisión. Que los dos papeles de la
     * misma cuota se refieran a un comprobante de maneras distintas es lo
     * que hace que después nadie los cruce.
     */
    private function numeroDelReciboDeEgreso(PaymentOrder $order): ?string
    {
        $recibo = $order->expenseReceipt;

        if ($recibo === null) {
            return null;
        }

        return $recibo->prints_talonario_number && $recibo->talonario_number !== null
            ? $recibo->talonario_number
            : $recibo->formatted_number;
    }

    /**
     * La tabla tal como quedó congelada al emitir.
     *
     * **No se recalcula.** Es lo que el §9.6 del DER protege: una
     * reversión posterior, o un extracto releído con otro parser, darían
     * un resultado distinto del que el Tesorero firmó.
     *
     * @return list<array{operacion: string|null, fecha: CarbonInterface|null, cuenta: string|null, importe: numeric-string}>
     */
    private function desdeLoCongelado(PaymentOrder $order): array
    {
        $order->loadMissing('fundingSources');

        $renglones = [];

        foreach ($order->fundingSources as $fuente) {
            $renglones[] = [
                'operacion' => $fuente->operation_number_snapshot,
                'fecha' => $fuente->operation_date_snapshot,
                'cuenta' => FormAccountNumber::acortar(
                    $fuente->bank_account_snapshot,
                    FormAccountNumber::EN_LOS_DEPOSITOS,
                ),
                'importe' => Decimal::scale($fuente->amount),
            ];
        }

        return $renglones;
    }

    /**
     * La tabla todavía sin congelar, para mirarla antes de emitir.
     *
     * @param  list<FundingSourceRow>  $renglones
     * @return list<array{operacion: string|null, fecha: CarbonInterface|null, cuenta: string|null, importe: numeric-string}>
     */
    private function desdeLosRenglones(array $renglones): array
    {
        return array_map(
            fn (FundingSourceRow $fila): array => [
                'operacion' => $fila->operationNumber,
                'fecha' => $fila->operationDate,
                'cuenta' => FormAccountNumber::acortar(
                    $fila->bankAccountNumber,
                    FormAccountNumber::EN_LOS_DEPOSITOS,
                ),
                'importe' => $fila->amount,
            ],
            $renglones,
        );
    }

    /**
     * Las cuentas que el formulario lista, con la cruz en la que va.
     *
     * El papel del área las trae preimpresas y alguien cruza la que
     * corresponde. Acá se listan las cuentas activas del organismo y la
     * marca la pone el sistema, que sabe en cuál está el dinero.
     *
     * La cuenta congelada de la Orden va primero aunque hoy esté inactiva:
     * un documento emitido no puede perder la casilla que tenía marcada
     * porque después alguien dio de baja esa cuenta.
     *
     * @return list<array{label: string, accountNumber: string|null, marked: bool}>
     */
    private function cuentasDelOrganismo(PaymentOrder $order): array
    {
        $filas = BankAccount::query()
            ->where(function ($query) use ($order): void {
                $query->where('is_active', true)
                    ->orWhere('id', $order->organism_bank_account_id);
            })
            ->orderBy('id')
            ->get(['id', 'label', 'account_number']);

        $cuentas = [];

        foreach ($filas as $fila) {
            $cuentas[] = [
                'label' => $fila->label,
                'accountNumber' => FormAccountNumber::acortar(
                    $fila->account_number,
                    FormAccountNumber::EN_LA_FICHA,
                ),
                'marked' => $fila->id === $order->organism_bank_account_id,
            ];
        }

        return $cuentas;
    }
}
