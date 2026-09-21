<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Actions\DeliverAndIssueExpenseReceipt;
use App\Modules\Haberes\Actions\FindTransferDebitCandidates;
use App\Modules\Haberes\Actions\IssueExpenseReceipt;
use App\Modules\Haberes\Actions\LinkTransferDebit;
use App\Modules\Haberes\Actions\RegisterTransferReport;
use App\Modules\Haberes\Actions\ValidateTransferDisbursement;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Support\DisbursementEligibility;
use App\Modules\Haberes\Support\ReceiptFormData;
use App\Modules\Haberes\Support\TransferDebitCandidate;
use App\Modules\Shared\Pdf\ExpenseReceiptPdf;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * El pago al beneficiario y su comprobante.
 *
 * Vive aparte de `HaberController` porque es otro acto: aquel administra
 * el derecho y su ingreso; esto es el dinero saliendo.
 *
 * ── Por qué tiene tantos métodos ───────────────────────────────────────
 *
 * Porque son dos circuitos con formas distintas. El mostrador es **un solo
 * acto**: el trabajador está enfrente, se le paga y firma. La
 * transferencia son **tres** (§2.3), y no por burocracia: el organismo
 * avisa, el banco muestra el débito, y recién cuando alguien coteja los
 * dos contra la Orden el pago es un hecho. Cada uno ocurre en un momento
 * distinto y a veces en orden inverso (§12.3 y §12.4).
 */
final class DisbursementController extends Controller
{
    /**
     * Entrega el dinero y emite el recibo que el beneficiario firma.
     *
     * **Un solo acto**, como el cobro por mostrador en el otro extremo: el
     * trabajador está enfrente, se le paga y firma. Lo que el formulario
     * no manda es el importe ni el medio: los deriva el Action de la
     * propia cuota, porque un importe propuesto por el navegador es una
     * forma de asentar un movimiento que no ocurrió.
     */
    public function store(
        Request $request,
        BeneficiaryInstallment $installment,
        DeliverAndIssueExpenseReceipt $pagarYEmitir,
    ): RedirectResponse {
        $validado = $request->validate([
            /*
             * El número del papel, cuando el recibo se escribió a mano.
             * Opcional: si no viene, el comprobante lo genera el sistema y
             * el único número es el suyo.
             *
             * 20 y no los 40 de la columna: encabeza el papel a 10,5 pt y
             * más largo se sale del marco. Un número de talonario real
             * tiene ocho dígitos.
             */
            'talonarioNumber' => ['nullable', 'string', 'max:20'],
            'printsTalonarioNumber' => ['nullable', 'boolean'],
            /*
             * Cuándo se entregó. Puede no ser hoy: el papel se firma en el
             * mostrador y a veces se carga después.
             */
            'paymentDate' => [
                'nullable',
                'date',
                'before_or_equal:today',
                /*
                 * Y no antes del alta de la cuota. El sistema no sabe nada
                 * de ese dinero antes de que el expediente llegara: una
                 * fecha anterior sería afirmar un pago que el expediente
                 * no respalda, y quedaría asentada en el libro.
                 */
                'after_or_equal:'.$installment->created_at->toDateString(),
            ],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ], [
            'paymentDate.before_or_equal' => 'La fecha de la entrega no puede ser futura.',
            'paymentDate.after_or_equal' => 'La fecha de la entrega no puede ser anterior al alta de la cuota ('
                .$installment->created_at->format('d/m/Y').'), que es cuando llegó el expediente.',
        ]);

        $recibo = $pagarYEmitir->handle(
            installment: $installment,
            idempotencyKey: (string) $validado['idempotencyKey'],
            actorId: $request->user()?->id,
            talonarioNumber: $validado['talonarioNumber'] ?? null,
            printsTalonarioNumber: (bool) ($validado['printsTalonarioNumber'] ?? false),
            paymentDate: isset($validado['paymentDate'])
                ? CarbonImmutable::parse((string) $validado['paymentDate'])
                : null,
            notes: $validado['notes'] ?? null,
        );

        return back()->with(
            'status',
            "Entrega registrada y recibo de egreso {$recibo->formatted_number} emitido.",
        );
    }

    /**
     * El organismo avisó que transfirió — §2.3.1.
     *
     * No prueba nada por sí solo: el egreso queda esperando el débito, y
     * el invariante 12 lo dice sin rodeos. Lo que este acto hace es dejar
     * registrado el aviso con su fecha y su referencia, que es lo que
     * después permite reconocer el movimiento en el extracto.
     */
    public function report(
        Request $request,
        BeneficiaryInstallment $installment,
        RegisterTransferReport $informar,
    ): RedirectResponse {
        $validado = $request->validate([
            'reportedAt' => ['nullable', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'reportedAt.before_or_equal' => 'La fecha del informe no puede ser futura.',
        ]);

        $informar->handle(
            installment: $installment,
            reportedAt: isset($validado['reportedAt'])
                ? CarbonImmutable::parse((string) $validado['reportedAt'])
                : null,
            reference: $validado['reference'] ?? null,
            actorId: $request->user()?->id,
            notes: $validado['notes'] ?? null,
        );

        return back()->with(
            'status',
            'Informe del organismo registrado. Falta reconocer el débito en el extracto.',
        );
    }

    /**
     * Los débitos del extracto que podrían ser esta transferencia.
     *
     * Propone, no vincula: la lista sale con las señales en palabras para
     * que quien confirma entienda por qué un candidato está primero, sobre
     * todo cuando el orden está equivocado.
     */
    public function debitCandidates(
        BeneficiaryInstallment $installment,
        FindTransferDebitCandidates $buscar,
    ): JsonResponse {
        return response()->json([
            'candidates' => array_map(
                fn (TransferDebitCandidate $c): array => [
                    'id' => $c->transaction->id,
                    'date' => $c->transaction->transaction_date?->format('Y-m-d'),
                    'amount' => $c->transaction->amount,
                    'description' => $c->transaction->description ?? $c->transaction->counterparty_name,
                    'operationId' => $c->transaction->operation_id,
                    'signals' => $c->signals,
                ],
                $buscar->handle($installment),
            ),
        ]);
    }

    /** Reconoce el débito que prueba que el dinero salió — §2.3.2. */
    public function linkDebit(
        Request $request,
        BeneficiaryInstallment $installment,
        LinkTransferDebit $vincular,
    ): RedirectResponse {
        $validado = $request->validate([
            'bankTransactionId' => ['required', 'integer', 'exists:bank_transactions,id'],
        ]);

        /** @var BankTransaction $movimiento */
        $movimiento = BankTransaction::query()->findOrFail($validado['bankTransactionId']);

        $vincular->handle($installment, $movimiento, $request->user()?->id);

        return back()->with(
            'status',
            'Débito reconocido. Con el informe cargado, el egreso queda listo para validar.',
        );
    }

    /**
     * Deshace el reconocimiento de un débito que era de otra Orden.
     *
     * Solo mientras el egreso no esté validado: después hay un asiento
     * posteado y una imputación bancaria que lo referencian.
     */
    public function unlinkDebit(
        Request $request,
        BeneficiaryInstallment $installment,
        LinkTransferDebit $vincular,
        DisbursementEligibility $habilitacion,
    ): RedirectResponse {
        $validado = $request->validate([
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $vincular->undo(
            $this->egresoEnCurso($installment, $habilitacion),
            $request->user()?->id,
            $validado['reason'] ?? null,
        );

        Toast::success('Débito desvinculado.', 'El egreso vuelve a esperar el movimiento correcto.');

        return back();
    }

    /**
     * El contador coteja Orden, informe y débito — §2.3.4.
     *
     * Es el acto que convierte dos papeles en un pago: postea el asiento,
     * deja la cuota pagada y habilita el recibo de egreso. Va con su
     * propio permiso porque es una responsabilidad distinta de la de
     * cargar los datos.
     *
     * Se llama `confirm` y no `validate` porque `Controller` ya trae ese
     * nombre de `ValidatesRequests`.
     */
    public function confirm(
        Request $request,
        BeneficiaryInstallment $installment,
        ValidateTransferDisbursement $validar,
        DisbursementEligibility $habilitacion,
    ): RedirectResponse {
        $validado = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $validar->handle(
            $this->egresoEnCurso($installment, $habilitacion),
            $request->user()?->id,
            $validado['notes'] ?? null,
        );

        return back()->with(
            'status',
            'Pago validado: la cuota queda pagada y el recibo de egreso está habilitado.',
        );
    }

    /**
     * Emite el recibo de un egreso que ya está confirmado.
     *
     * Es el paso normal después de validar una transferencia, y el que
     * rehace el papel en el mostrador cuando el anterior se anuló. La
     * entrega no se toca: el dinero ya salió.
     */
    public function issueReceipt(
        Request $request,
        BeneficiaryInstallment $installment,
        IssueExpenseReceipt $emitir,
        DisbursementEligibility $habilitacion,
    ): RedirectResponse {
        $validado = $request->validate([
            'talonarioNumber' => ['nullable', 'string', 'max:20'],
            'printsTalonarioNumber' => ['nullable', 'boolean'],
        ]);

        $recibo = $emitir->handle(
            installment: $installment,
            disbursement: $this->egresoEnCurso($installment, $habilitacion),
            actorId: $request->user()?->id,
            talonarioNumber: $validado['talonarioNumber'] ?? null,
            printsTalonarioNumber: (bool) ($validado['printsTalonarioNumber'] ?? false),
        );

        return back()->with('status', "Recibo de egreso {$recibo->formatted_number} emitido.");
    }

    /**
     * El comprobante antes de emitirlo.
     *
     * Devuelve el mismo HTML que después se imprime, armado por el propio
     * Action: ver una maqueta parecida no serviría de nada —lo que hay que
     * poder controlar es lo que va a salir en el papel—.
     *
     * El número que muestra es el que *le tocaría*. El definitivo se toma
     * bajo lock al confirmar.
     */
    public function preview(
        Request $request,
        BeneficiaryInstallment $installment,
        IssueExpenseReceipt $emitir,
        DisbursementEligibility $habilitacion,
        ExpenseReceiptPdf $pdf,
        ReceiptFormData $formulario,
    ): SymfonyResponse {
        /*
         * El método y el importe salen de la misma fuente que habilitó el
         * botón. Pedírselos al navegador dejaría que la previa mostrara un
         * papel distinto del que la emisión va a escribir.
         */
        $estado = $habilitacion->for($installment);

        $borrador = $emitir->preview(
            installment: $installment,
            method: $estado->method,
            amount: $estado->amount,
            talonarioNumber: $request->string('talonarioNumber')->toString() ?: null,
            printsTalonarioNumber: $request->boolean('printsTalonarioNumber'),
        );

        return response($pdf->preview(
            $borrador,
            conFondo: ! $request->boolean('talonario'),
            extra: $formulario->forInstallment($installment),
        )->render());
    }

    /**
     * El egreso vivo de la cuota, o el error que corresponde.
     *
     * Los tres actos del circuito bancario operan sobre él y ninguno tiene
     * sentido sin él. Resolverlo en un solo lugar evita que cada método
     * invente su propio mensaje para la misma ausencia.
     *
     * @throws ValidationException
     */
    private function egresoEnCurso(
        BeneficiaryInstallment $installment,
        DisbursementEligibility $habilitacion,
    ): Disbursement {
        $egreso = $habilitacion->for($installment)->disbursement;

        if ($egreso === null) {
            throw ValidationException::withMessages([
                'installmentId' => 'La cuota no tiene ningún egreso registrado.',
            ]);
        }

        return $egreso;
    }
}
