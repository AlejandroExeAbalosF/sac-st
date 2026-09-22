<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\DisbursementMethod;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Ledger\Models\ReceiptFinancialEvent;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\TakeNextDocumentNumber;
use App\Modules\Shared\Enums\ReceiptIssueMode;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\DocumentSeries;
use App\Modules\Shared\Models\Receipt;
use App\Support\BusinessDate;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Emite el recibo de egreso de una cuota.
 *
 * **El papel que firma el beneficiario**: *«Recibí conforme de la
 * Secretaría de Trabajo la suma de…»*. Es el otro extremo del recibo de
 * ingreso —aquel dice de quién entró el dinero, éste hacia quién salió— y
 * el expediente los archiva juntos.
 *
 * ── Lo que lo distingue del de ingreso, y no es el formato ─────────────
 *
 * **El de ingreso se emite al recibir y no espera nada** (§2.5.4): el
 * empleador deja la plata en el mostrador y se lleva su papel en el
 * momento. **Éste es al revés**: solo puede emitirse después de que el
 * egreso esté confirmado (invariante 13). No documenta una promesa de
 * pago sino un pago hecho, y por eso exige que el hecho exista primero.
 *
 * **Firma el beneficiario, no el área.** En el de ingreso el pie lleva la
 * aclaración de quien emitió; acá el renglón «Firma y Aclaración» lo
 * completa quien cobra, de puño y letra sobre el papel. Por eso este
 * Action no acepta firmante: no hay ninguno que imprimir. Quién entregó el
 * dinero queda registrado en `disbursements.cash_delivered_by`, que es
 * donde corresponde —es un dato de la operación, no del comprobante—.
 *
 * **Y por eso `person_id` cambia de persona.** En el de ingreso apunta a
 * quien depositó; acá, al beneficiario que cobró. El empleador no
 * desaparece: el formulario también lo imprime, y va en
 * `counterparty_name_snapshot`.
 */
final class IssueExpenseReceipt
{
    public function __construct(
        private readonly TakeNextDocumentNumber $numerar,
        private readonly InstallmentFunding $financiacion,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  string|null  $talonarioNumber  El número preimpreso, cuando el
     *                                        papel salió del talonario. El
     *                                        identificador sigue siendo el
     *                                        del sistema.
     *
     * @throws ValidationException
     */
    public function handle(
        BeneficiaryInstallment $installment,
        Disbursement $disbursement,
        ?int $actorId = null,
        ?string $talonarioNumber = null,
        ?CarbonInterface $issueDate = null,
        bool $printsTalonarioNumber = false,
    ): Receipt {
        $installment->loadMissing(['haber.beneficiary', 'haber.expediente.employer']);

        $this->assertIssuable($installment, $disbursement);

        return DB::transaction(function () use (
            $installment, $disbursement, $actorId, $talonarioNumber,
            $issueDate, $printsTalonarioNumber
        ): Receipt {
            /*
             * El correlativo se toma bajo lock dentro de esta transacción
             * —lo hace `TakeNextDocumentNumber`—, así que dos operadores
             * emitiendo a la vez no pueden recibir el mismo número.
             */
            $serie = ReceiptType::Expense->seriesCode();
            $numero = $this->numerar->handle($serie);

            /*
             * A quién reemplaza, si hubo un intento anulado antes.
             *
             * **El número no se hereda.** El anulado se queda con el suyo
             * —un comprobante que existió consume su número— y éste toma
             * el siguiente libre de la serie.
             */
            $anulado = $this->ultimoAnulado($installment);

            $recibo = Receipt::query()->create([
                ...$this->componer(
                    $installment,
                    $disbursement->method,
                    $disbursement->amount,
                    $talonarioNumber,
                    $issueDate ?? $disbursement->payment_date,
                    $actorId,
                    $printsTalonarioNumber,
                ),
                'document_series_id' => DocumentSeries::query()->where('code', $serie)->value('id'),
                'number' => $numero['number'],
                'formatted_number' => $numero['formatted'],
                'replaces_receipt_id' => $anulado?->id,
            ]);

            /*
             * El anulado pasa de `voided` a `replaced`: la diferencia es
             * si alguien ya emitió el que ocupa su lugar. Leer «anulado» a
             * secas deja abierta la pregunta de si el comprobante llegó a
             * rehacerse.
             */
            $anulado?->forceFill(['status' => ReceiptStatus::Replaced])->save();

            /*
             * El hecho monetario que este comprobante documenta: uno solo,
             * el asiento del egreso. Es la asimetría con el de ingreso,
             * donde son varias asignaciones —una cuota puede financiarse
             * con muchos ingresos (§2.1.9), pero se paga de una vez—.
             */
            if ($disbursement->financial_event_id !== null) {
                ReceiptFinancialEvent::query()->firstOrCreate([
                    'receipt_id' => $recibo->id,
                    'financial_event_id' => $disbursement->financial_event_id,
                ]);
            }

            /*
             * Invariante 24: el pie de la Orden referencia el recibo de
             * egreso de su misma cuota. Se completa acá y no al validar
             * porque hasta que el papel no existe no hay número que poner,
             * y el trigger append-only deja mover justamente esta columna.
             */
            $disbursement->paymentOrder?->forceFill([
                'expense_receipt_id' => $recibo->id,
            ])->save();

            $this->auditar->handle('recibo-egreso.emitido', $recibo, after: [
                'formatted_number' => $recibo->formatted_number,
                'talonario_number' => $recibo->talonario_number,
                'beneficiary_installment_id' => $installment->id,
                'disbursement_id' => $disbursement->id,
                'amount' => $recibo->amount,
            ], actorId: $actorId);

            return $recibo;
        });
    }

    /**
     * El comprobante sin emitir, para que la pantalla lo muestre antes.
     *
     * **Es el mismo armado que la emisión**, y por eso vive acá y no en el
     * controlador: una vista previa que compone los datos por su cuenta
     * termina mostrando algo distinto de lo que se va a imprimir, y eso es
     * peor que no tener vista previa.
     *
     * Se mira **antes de pagar**, así que el egreso todavía no existe: el
     * método y el importe llegan de `DisbursementEligibility`, que es
     * quien los resuelve para el botón que abrió este diálogo. Lo único
     * que le falta es el número definitivo, que se toma al emitir bajo
     * lock.
     *
     * @param  numeric-string|null  $amount
     */
    public function preview(
        BeneficiaryInstallment $installment,
        ?DisbursementMethod $method = null,
        ?string $amount = null,
        ?string $talonarioNumber = null,
        bool $printsTalonarioNumber = false,
    ): Receipt {
        $installment->loadMissing(['haber.beneficiary', 'haber.expediente.employer']);

        $recibo = new Receipt($this->componer(
            $installment,
            $method ?? DisbursementMethod::Cash,
            $amount ?? $this->financiacion->allocated($installment),
            $talonarioNumber,
            null,
            null,
            $printsTalonarioNumber,
        ));

        $serie = DocumentSeries::query()->where('code', ReceiptType::Expense->seriesCode())->first();

        /*
         * El número que le tocaría, como anticipo y no como promesa: entre
         * mirar y confirmar puede emitirse otro comprobante, y el
         * correlativo real se toma bajo lock recién en ese momento.
         */
        $recibo->formatted_number = $serie === null
            ? '—'
            : $serie->formatNumber($serie->next_number);

        return $recibo;
    }

    /**
     * Lo que va a decir el papel.
     *
     * Una sola definición para la emisión y para la vista previa: es lo
     * que garantiza que lo que se mira sea lo que se imprime.
     *
     * @param  numeric-string  $amount
     * @return array<string, mixed>
     */
    private function componer(
        BeneficiaryInstallment $installment,
        DisbursementMethod $method,
        string $amount,
        ?string $talonarioNumber,
        ?CarbonInterface $issueDate,
        ?int $actorId,
        bool $printsTalonarioNumber,
    ): array {
        $haber = $installment->haber;
        $expediente = $haber->expediente;

        return [
            'talonario_number' => $talonarioNumber,
            /*
             * Encabezar con un número que no se cargó no significa nada, y
             * la base también lo impide.
             */
            'prints_talonario_number' => $printsTalonarioNumber && $talonarioNumber !== null,
            'receipt_type' => ReceiptType::Expense,
            /*
             * Quien cobra. Es el cambio de persona respecto del recibo de
             * ingreso, y no un detalle: el papel dice «Recibí conforme» en
             * primera persona, y quien lo dice es el trabajador.
             */
            'person_id' => $haber->beneficiary_id,
            'beneficiary_installment_id' => $installment->id,
            'concept_snapshot' => $installment->description ?? $haber->concept,
            /*
             * La casilla que el formulario marca describe **la salida**
             * (§2.4.6): con qué se le pagó al beneficiario, no cómo había
             * entrado el dinero. Entrar en efectivo y salir por
             * transferencia es una combinación válida y habitual.
             */
            'medium_snapshot' => $method->asReceiptMedium()->value,
            'counterparty_name_snapshot' => $expediente->employer?->name,
            'beneficiary_name_snapshot' => $haber->beneficiary->name,
            'beneficiary_document_snapshot' => $haber->beneficiary->document,
            'expediente_number_snapshot' => $expediente->display_number,
            'installment_label_snapshot' => 'Cuota '.$installment->installment_number,
            'amount' => $amount,
            'issue_date' => $issueDate ?? BusinessDate::today(),
            'status' => ReceiptStatus::Issued,
            /*
             * Si trae número de talonario, el papel se escribió a mano y
             * se cargó después: eso es lo que distingue el modo, y lo que
             * da sentido a `recorded_at`.
             */
            'issue_mode' => $talonarioNumber === null
                ? ReceiptIssueMode::Online
                : ReceiptIssueMode::OfflineTalonario,
            'issued_by' => $actorId,
            /*
             * Sin firmante. El pie del recibo de egreso lo firma el
             * beneficiario sobre el papel, así que no hay nombre ni cargo
             * del área que congelar.
             */
            'signed_by' => null,
            'signed_by_name_snapshot' => null,
            'signed_by_title_snapshot' => null,
            'recorded_at' => $talonarioNumber === null ? null : now(),
            'recorded_by' => $talonarioNumber === null ? null : $actorId,
        ];
    }

    /**
     * El último recibo de egreso anulado de esta cuota, si lo hubo.
     *
     * Solo el último: si hubo tres intentos, cada uno apunta al anterior y
     * la cadena se lee entera desde el vigente hacia atrás.
     */
    private function ultimoAnulado(BeneficiaryInstallment $installment): ?Receipt
    {
        return Receipt::query()
            ->where('receipt_type', ReceiptType::Expense)
            ->where('beneficiary_installment_id', $installment->id)
            ->where('status', ReceiptStatus::Voided)
            ->latest('id')
            ->first();
    }

    /**
     * @throws ValidationException
     */
    private function assertIssuable(BeneficiaryInstallment $installment, Disbursement $disbursement): void
    {
        if ($disbursement->beneficiary_installment_id !== $installment->id) {
            throw ValidationException::withMessages([
                'installmentId' => 'El egreso no pertenece a esta cuota.',
            ]);
        }

        /*
         * Invariante 13. La base lo impone con un trigger; esto da el
         * mensaje que el operador entiende, que es el reparto de
         * responsabilidades de siempre.
         */
        if ($disbursement->status !== DisbursementStatus::Confirmed) {
            throw ValidationException::withMessages([
                'installmentId' => 'El recibo de egreso documenta un pago hecho, y este egreso todavía '
                    ."está «{$disbursement->status->label()}».",
            ]);
        }

        $vigente = Receipt::query()
            ->issued()
            ->where('receipt_type', ReceiptType::Expense)
            ->where('beneficiary_installment_id', $installment->id)
            ->first();

        if ($vigente !== null) {
            throw ValidationException::withMessages([
                'installmentId' => "La cuota ya tiene el recibo de egreso {$vigente->formatted_number}. "
                    .'Para emitir otro hay que anular ese primero.',
            ]);
        }
    }
}
