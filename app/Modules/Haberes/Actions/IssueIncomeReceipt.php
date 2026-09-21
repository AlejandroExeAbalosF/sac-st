<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Models\User;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\ReceiptFinancialEvent;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\TakeNextDocumentNumber;
use App\Modules\Shared\Enums\ReceiptIssueMode;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\DocumentSeries;
use App\Modules\Shared\Models\Receipt;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Emite el recibo de ingreso de una cuota.
 *
 * **Una sola vez, y solo con la cuota completa** (§2.1.14): *«Un ingreso
 * parcial no genera comprobante de ningún tipo para el empleador»*. Lo
 * impone además un índice único parcial, porque dos recibos vigentes para
 * la misma cuota serían dos papeles entregados por el mismo dinero.
 *
 * **No espera acreditación de nada** (§2.5.4). Con el efectivo eso es el
 * punto: el empleador deja la plata en el mostrador y se lleva su recibo
 * en el momento. Que después el beneficiario no aparezca y ese efectivo
 * termine depositado en la cuenta del organismo es otro hecho, posterior,
 * que no toca este comprobante.
 *
 * **Congela lo que el papel dice.** Los `_snapshot` no son duplicados por
 * comodidad: el recibo es un documento entregado, y corregir mañana el
 * nombre en el maestro no puede cambiar lo que dice el papel que está en
 * poder del empleador.
 */
final class IssueIncomeReceipt
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
        ?int $actorId = null,
        ?string $talonarioNumber = null,
        ?CarbonInterface $issueDate = null,
        ?int $personId = null,
        ?int $signedById = null,
        bool $printsTalonarioNumber = false,
    ): Receipt {
        $installment->loadMissing(['haber.beneficiary', 'haber.expediente.employer']);

        $this->assertIssuable($installment);

        /*
         * El medio con el que se financió, que va impreso en el papel.
         *
         * No lleva valor por defecto a propósito. `assertIssuable` ya
         * garantizó que la cuota está completa, así que acá hay
         * asignaciones y hay medio; si no lo hubiera, poner «efectivo»
         * por descarte imprimiría en un documento entregado algo que
         * nadie verificó. Que falle es la respuesta correcta.
         */
        $medio = $this->financiacion->medium($installment);

        if ($medio === null) {
            throw ValidationException::withMessages([
                'installmentId' => 'La cuota figura completa pero no tiene asignaciones vigentes. '
                    .'Es un estado inconsistente: no se puede emitir el comprobante.',
            ]);
        }

        $haber = $installment->haber;
        $expediente = $haber->expediente;

        /*
         * «Recibí de»: quien puso el dinero. Es el empleador del
         * expediente salvo que se indique otro —un depósito de un tercero
         * ocurre—, y nunca el beneficiario, que es quien va a cobrar.
         */
        $recibiDe = $personId ?? $expediente->employer_id;

        if ($recibiDe === null) {
            throw ValidationException::withMessages([
                'personId' => 'El recibo dice «recibí de» y el expediente no tiene empleador cargado. '
                    .'Hay que indicar de quién se recibió el dinero.',
            ]);
        }

        /*
         * Quien firma el comprobante, congelado.
         *
         * El nombre y el cargo van copiados y no por relacion: el papel
         * entregado dice lo que decia el dia que se emitio, y un ascenso
         * no puede reescribir comprobantes viejos.
         */
        $firmante = $signedById === null ? null : User::query()->find($signedById);

        return DB::transaction(function () use (
            $installment, $medio, $recibiDe, $actorId,
            $talonarioNumber, $issueDate, $firmante, $printsTalonarioNumber
        ): Receipt {
            /*
             * El correlativo se toma bajo lock dentro de esta transacción
             * —lo hace `TakeNextDocumentNumber`—, así que dos operadores
             * emitiendo a la vez no pueden recibir el mismo número.
             */
            $serie = ReceiptType::Income->seriesCode();
            $numero = $this->numerar->handle($serie);

            /*
             * A quién reemplaza, si hubo un intento anulado antes.
             *
             * Se resuelve acá y no se le pregunta al operador: el que se
             * reemplaza es siempre el último anulado de esta cuota, y
             * pedírselo sería pedirle que repita un dato que el sistema ya
             * sabe.
             *
             * **El número no se hereda.** El anulado se queda con el suyo
             * —un comprobante que existió consume su número— y este toma
             * el siguiente libre de la serie, que puede no ser el que
             * seguía si otra cuota emitió en el medio.
             */
            $anulado = $this->ultimoAnulado($installment);

            $recibo = Receipt::query()->create([
                ...$this->componer(
                    $installment, $medio, $recibiDe, $talonarioNumber,
                    $issueDate, $firmante, $actorId, $printsTalonarioNumber,
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

            $this->linkFinancialEvents($recibo, $installment);

            $this->auditar->handle('recibo.emitido', $recibo, after: [
                'formatted_number' => $recibo->formatted_number,
                'talonario_number' => $recibo->talonario_number,
                'beneficiary_installment_id' => $installment->id,
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
     * Lo único que le falta es el número definitivo, que se toma al emitir
     * bajo lock y por lo tanto todavía no existe.
     */
    public function preview(
        BeneficiaryInstallment $installment,
        ?string $talonarioNumber = null,
        ?int $signedById = null,
        ?int $personId = null,
        bool $printsTalonarioNumber = false,
    ): Receipt {
        $installment->loadMissing(['haber.beneficiary', 'haber.expediente.employer']);

        /*
         * Con la cuota todavía sin financiar, esto se está mirando antes
         * de cobrar: la asignación que diría el importe y el medio no
         * existe todavía, y hay que leerlos de la cuota. Sin esto el papel
         * se previsualizaba por $ 0,00.
         */
        $porCobrar = ! $this->financiacion->isFullyFunded($installment);

        /*
         * `effectiveMedium` resuelve las dos situaciones en el orden
         * correcto: manda el medio real si ya hay plata, y recién si no la
         * hay se lee el previsto. Al revés —que era como estaba— la vista
         * previa de una cuota financiada por transferencia decía
         * «Efectivo» en cuanto alguien editaba el medio previsto.
         */
        /*
         * Sin respaldo a `Cash`: el medio previsto pasó a ser obligatorio y
         * `effectiveMedium` ya no puede devolver nulo. El respaldo cubría
         * la cuota sin medio declarado, que desde 09/2026 no existe.
         */
        $medio = $this->financiacion->effectiveMedium($installment);
        $recibiDe = $personId ?? $installment->haber->expediente->employer_id;
        $firmante = $signedById === null ? null : User::query()->find($signedById);

        $recibo = new Receipt($this->componer(
            $installment, $medio, $recibiDe ?? 0, $talonarioNumber, null, $firmante, null,
            $printsTalonarioNumber,
        ));

        if ($porCobrar) {
            $recibo->amount = $this->financiacion->remaining($installment);
        }

        $serie = DocumentSeries::query()->where('code', ReceiptType::Income->seriesCode())->first();

        /*
         * El número que le tocaría, como anticipo y no como promesa:
         * entre mirar y confirmar puede emitirse otro comprobante, y el
         * correlativo real se toma bajo lock recién en ese momento.
         */
        $recibo->formatted_number = $serie === null
            ? '—'
            : $serie->formatNumber($serie->next_number);

        return $recibo;
    }

    /**
     * El último recibo de ingreso anulado de esta cuota, si lo hubo.
     *
     * Solo el último: si hubo tres intentos, cada uno apunta al anterior y
     * la cadena se lee entera desde el vigente hacia atrás.
     */
    private function ultimoAnulado(BeneficiaryInstallment $installment): ?Receipt
    {
        return Receipt::query()
            ->where('receipt_type', ReceiptType::Income)
            ->where('beneficiary_installment_id', $installment->id)
            ->where('status', ReceiptStatus::Voided)
            ->latest('id')
            ->first();
    }

    /**
     * Lo que va a decir el papel.
     *
     * Una sola definición para la emisión y para la vista previa: es lo
     * que garantiza que lo que se mira sea lo que se imprime.
     *
     * @return array<string, mixed>
     */
    private function componer(
        BeneficiaryInstallment $installment,
        PaymentMedium $medio,
        int $recibiDe,
        ?string $talonarioNumber,
        ?CarbonInterface $issueDate,
        ?User $firmante,
        ?int $actorId,
        bool $printsTalonarioNumber = false,
    ): array {
        $haber = $installment->haber;
        $expediente = $haber->expediente;

        return [
            'talonario_number' => $talonarioNumber,
            /*
             * Encabezar con un numero que no se cargo no significa nada, y
             * la base tambien lo impide.
             */
            'prints_talonario_number' => $printsTalonarioNumber && $talonarioNumber !== null,
            'receipt_type' => ReceiptType::Income,
            'person_id' => $recibiDe,
            'beneficiary_installment_id' => $installment->id,
            'concept_snapshot' => $installment->description ?? $haber->concept,
            'medium_snapshot' => $medio->value,
            'counterparty_name_snapshot' => $expediente->employer?->name,
            'beneficiary_name_snapshot' => $haber->beneficiary->name,
            'beneficiary_document_snapshot' => $haber->beneficiary->document,
            'expediente_number_snapshot' => $expediente->display_number,
            'installment_label_snapshot' => 'Cuota '.$installment->installment_number,
            'amount' => $this->financiacion->allocated($installment),
            'issue_date' => $issueDate ?? now(),
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
            'signed_by' => $firmante?->id,
            'signed_by_name_snapshot' => $firmante?->name,
            'signed_by_title_snapshot' => $firmante?->position,
            'recorded_at' => $talonarioNumber === null ? null : now(),
            'recorded_by' => $talonarioNumber === null ? null : $actorId,
        ];
    }

    /**
     * Los hechos monetarios que este comprobante documenta.
     *
     * Son todas las asignaciones que completaron la cuota, no una: el
     * recibo se emite una sola vez aunque el dinero haya llegado en
     * varios ingresos (§2.1.9).
     */
    private function linkFinancialEvents(Receipt $recibo, BeneficiaryInstallment $installment): void
    {
        $eventos = FundingAllocation::query()
            ->live()
            ->where('beneficiary_installment_id', $installment->id)
            ->pluck('allocation_event_id')
            ->unique();

        foreach ($eventos as $eventoId) {
            ReceiptFinancialEvent::query()->create([
                'receipt_id' => $recibo->id,
                'financial_event_id' => $eventoId,
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertIssuable(BeneficiaryInstallment $installment): void
    {
        if (! $this->financiacion->isFullyFunded($installment)) {
            throw ValidationException::withMessages([
                'installmentId' => sprintf(
                    'La cuota todavía no está completa: le faltan $ %s. '
                    .'Un ingreso parcial no genera comprobante.',
                    Decimal::format($this->financiacion->remaining($installment)),
                ),
            ]);
        }

        $vigente = Receipt::query()
            ->issued()
            ->where('receipt_type', ReceiptType::Income)
            ->where('beneficiary_installment_id', $installment->id)
            ->first();

        if ($vigente !== null) {
            throw ValidationException::withMessages([
                'installmentId' => "La cuota ya tiene el recibo {$vigente->formatted_number}. "
                    .'Para emitir otro hay que anular ese primero.',
            ]);
        }
    }
}
