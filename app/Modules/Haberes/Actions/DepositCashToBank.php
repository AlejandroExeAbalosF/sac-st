<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\CashToBankTransferItem;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Support\DepositItem;
use App\Modules\Haberes\Support\IncomeEvidence;
use App\Modules\Haberes\Support\InstallmentDeposit;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Haberes\Support\TransferredCheques;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\ChequeStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSource;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * El efectivo que nadie retiró se lleva al banco.
 *
 * Es el caso que el área describió: el empleador pagó, se emitió el
 * recibo, y el beneficiario no vino a buscar lo suyo. Ese efectivo no
 * puede quedarse en la caja indefinidamente, así que se deposita en la
 * cuenta del organismo.
 *
 * **Es un traslado interno, no un ingreso nuevo.** El dinero sigue siendo
 * del mismo beneficiario y sigue imputado a la misma cuota:
 * `BENEFICIARY_FUNDS` no se toca. Lo único que cambia es dónde está.
 *
 * **Un cheque viaja como llegó: entero.** Si la cuota tiene solo una parte
 * de un cheque, el papel se deposita completo y lleva consigo el resto —la
 * parte de otra cuota, o la que todavía no tiene dueño—. Ver
 * `InstallmentDeposit`.
 *
 * **Y el recibo de ingreso no se toca nunca.** Sigue diciendo «Efectivo»,
 * porque eso fue lo que ocurrió y el empleador tiene su copia firmada.
 * Este es un hecho nuevo, no una corrección de aquel. Ver
 * docs/haberes-reglas.md#canal-de-pago.
 *
 * **Por qué el asiento va a `CASH_IN_TRANSIT` y no directo al banco.**
 * Entre que el efectivo sale de la caja y el banco lo acredita hay una
 * ventana real. Postear directo a `BANK_ACCOUNT` haría que el libro
 * afirme que el banco tiene una plata que el banco todavía no confirmó, y
 * dejaría un depósito que nunca acreditó indistinguible de uno que
 * todavía no se importó. En tránsito queda como un saldo que envejece,
 * con nombre y fecha.
 */
final class DepositCashToBank
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly InstallmentFunding $financiacion,
        private readonly RecordAuditEvent $auditar,
        private readonly StoreAttachment $attachments,
        private readonly TransferredCheques $cheques,
        private readonly InstallmentDeposit $deposito,
    ) {}

    /**
     * @param  array{
     *     bankAccountId: int,
     *     depositDate: CarbonInterface,
     *     depositTime?: string|null,
     *     operationNumber?: string|null,
     *     terminal?: string|null,
     *     notes?: string|null,
     * }  $ticket
     *
     * @throws ValidationException
     */
    public function handle(
        BeneficiaryInstallment $installment,
        array $ticket,
        UploadedFile $foto,
        string $idempotencyKey,
        ?int $actorId = null,
    ): CashToBankTransfer {
        $adjunto = null;

        try {
            return DB::transaction(function () use (
                $installment, $ticket, $foto, $idempotencyKey, $actorId, &$adjunto
            ): CashToBankTransfer {
                /*
                 * La idempotencia se resuelve **antes** que la validación,
                 * y el orden importa. En un segundo envío del mismo
                 * formulario ese efectivo ya está depositado —por este
                 * mismo acto—, así que validar primero le contestaría «ya
                 * se depositó» a alguien que solo apretó dos veces.
                 */
                $yaTrasladado = CashToBankTransfer::query()
                    ->whereRelation('depositEvent', 'idempotency_key', $idempotencyKey)
                    ->first();

                if ($yaTrasladado !== null) {
                    return $yaTrasladado;
                }

                $items = $this->loQueViaja($installment);
                $importe = InstallmentDeposit::total($items);
                $cajaId = $items[0]->receipt->cash_box_id;
                $moneda = $installment->currency();

                // La base lo rechaza igual (`journal_lines_bank_currency`).
                if (BankAccount::query()->whereKey($ticket['bankAccountId'])->value('currency') !== $moneda->value) {
                    throw ValidationException::withMessages([
                        'bankAccountId' => 'La cuenta tiene que estar en la moneda del haber.',
                    ]);
                }

                $evento = $this->asentar->handle(
                    type: FinancialEventType::CashDepositedToBank,
                    idempotencyKey: $idempotencyKey,
                    lines: [
                        EntryLine::debit(LedgerAccount::CashInTransit, $importe)
                            ->in($moneda)
                            ->onBankAccount($ticket['bankAccountId'])
                            ->onCashBox($cajaId),
                        ...$this->salidas($items, $moneda, $cajaId),
                    ],
                    date: $ticket['depositDate'],
                    cashBoxId: $cajaId,
                    description: $ticket['notes'] ?? null,
                    actorId: $actorId,
                );

                $traslado = CashToBankTransfer::query()->create([
                    'deposit_event_id' => $evento->id,
                    'cash_box_id' => $cajaId,
                    'bank_account_id' => $ticket['bankAccountId'],
                    'amount' => $importe,
                    'deposit_date' => $ticket['depositDate'],
                    'deposit_time' => $ticket['depositTime'] ?? null,
                    'deposit_operation_number' => $ticket['operationNumber'] ?? null,
                    'deposit_terminal' => $ticket['terminal'] ?? null,
                    'deposited_by' => $actorId,
                    'notes' => $ticket['notes'] ?? null,
                    'status' => CashTransferStatus::Deposited,
                ]);

                foreach ($items as $item) {
                    CashToBankTransferItem::query()->create([
                        'cash_to_bank_transfer_id' => $traslado->id,
                        'fund_receipt_id' => $item->receipt->id,
                        'funding_allocation_id' => $item->allocationId,
                        'amount' => $item->amount,
                    ]);
                }

                // Los cheques salen de custodia: el papel está en el banco.
                $this->cheques->deposited($traslado);

                /*
                 * La foto es obligatoria, a diferencia del comprobante que
                 * trae el expediente: aquel documenta plata que entró y que
                 * el extracto va a confirmar igual; este es la única prueba
                 * de que el efectivo salió de la caja.
                 */
                $adjunto = $this->attachments->handle(
                    file: $foto,
                    subject: AttachmentSubject::CashTransfer,
                    subjectId: $traslado->id,
                    documentType: 'cash_transfer_ticket',
                    userId: $actorId,
                    title: 'Ticket del depósito',
                    source: AttachmentSource::Scanned,
                );

                $this->auditar->handle('traslado.depositado', $traslado, after: [
                    'amount' => $traslado->amount,
                    'bank_account_id' => $traslado->bank_account_id,
                    'deposit_date' => $traslado->deposit_date->toDateString(),
                    'beneficiary_installment_id' => $installment->id,
                    // El resto de los cheques, que viaja con el papel.
                    'carried_amount' => InstallmentDeposit::carried($items),
                ], actorId: $actorId);

                return $traslado;
            });
        } catch (Throwable $excepcion) {
            if ($adjunto !== null) {
                $this->attachments->deleteFile([
                    'disk' => $adjunto->storage_disk,
                    'key' => $adjunto->object_key,
                ]);
            }

            throw $excepcion;
        }
    }

    /**
     * Lo que sale de la caja: **todo** lo que la cuota tiene en pie, y el
     * resto de cada uno de sus cheques. Ver `InstallmentDeposit`.
     *
     * Una cuota cubierta con dos cheques los lleva a los dos, cada
     * asignación por lo que le queda en pie, y un cheque que la cuota tiene
     * solo en parte viaja entero, como llegó a la Secretaría.
     *
     * @return non-empty-list<DepositItem>
     *
     * @throws ValidationException
     */
    private function loQueViaja(BeneficiaryInstallment $installment): array
    {
        if (! $this->financiacion->isFullyFunded($installment)) {
            throw ValidationException::withMessages([
                'installmentId' => 'La cuota no está completa: no hay efectivo suyo en la caja.',
            ]);
        }

        $medio = $this->financiacion->medium($installment);

        if ($medio === null || $medio === PaymentMedium::Bank) {
            throw ValidationException::withMessages([
                'installmentId' => 'Esta cuota entró por transferencia: su dinero ya está en el banco.',
            ]);
        }

        /*
         * Sin recibo no se traslada. No es formalismo: el recibo es lo que
         * documenta de quién se recibió ese efectivo, y depositar primero
         * dejaría un movimiento bancario respaldado por nada. Vale el de
         * papel de la plata apartada del sistema anterior.
         */
        if (IncomeEvidence::of($installment) === null) {
            throw ValidationException::withMessages([
                'installmentId' => 'Primero hay que emitir el recibo de ingreso de esta cuota.',
            ]);
        }

        $this->assertChequesEnCustodia($installment);

        $items = $this->deposito->items($installment);

        if ($items === []) {
            throw ValidationException::withMessages([
                'installmentId' => 'La cuota no tiene ninguna asignación vigente.',
            ]);
        }

        $yaDepositada = CashToBankTransferItem::query()
            ->whereIn('funding_allocation_id', array_values(array_filter(array_map(
                static fn (DepositItem $item): ?int => $item->allocationId,
                $items,
            ))))
            ->whereRelation('transfer', 'status', '!=', CashTransferStatus::Cancelled)
            ->exists();

        if ($yaDepositada) {
            throw ValidationException::withMessages([
                'installmentId' => 'Ese efectivo ya se depositó en el banco.',
            ]);
        }

        $cajas = array_unique(array_map(
            static fn (DepositItem $item): ?int => $item->receipt->cash_box_id,
            $items,
        ));

        if (count($cajas) > 1) {
            throw ValidationException::withMessages([
                'installmentId' => 'La plata de esta cuota está en cajas distintas: un traslado sale de una sola.',
            ]);
        }

        return $items;
    }

    /**
     * Los cheques de la cuota, bloqueados y en custodia.
     *
     * El bloqueo serializa dos depósitos que comparten un cheque: el
     * segundo lo encuentra ya depositado. Y uno entregado o depositado no
     * está en la caja, así que no se puede llevar al banco.
     *
     * @throws ValidationException
     */
    private function assertChequesEnCustodia(BeneficiaryInstallment $installment): void
    {
        $cheques = FundReceipt::query()
            ->whereIn('id', FundingAllocation::query()
                ->withRemainingBalance()
                ->where('beneficiary_installment_id', $installment->id)
                ->select('fund_receipt_id'))
            ->where('medium', PaymentMedium::Cheque->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($cheques as $cheque) {
            if ($cheque->cheque_status !== ChequeStatus::InCustody) {
                throw ValidationException::withMessages([
                    'installmentId' => sprintf(
                        'El cheque n.º %s ya no está en la caja: figura %s.',
                        $cheque->cheque_number ?? '(sin número)',
                        $cheque->cheque_status?->label() ?? 'sin estado',
                    ),
                ]);
            }
        }
    }

    /**
     * Las patas que sacan la plata de donde estaba.
     *
     * El cheque sale de custodia y el efectivo de la caja, igual que
     * entraron por lados distintos al recibirse. El cheque sigue el
     * circuito del efectivo (§2.5), y esta es la línea donde eso se nota.
     *
     * @param  non-empty-list<DepositItem>  $items
     * @return list<EntryLine>
     */
    private function salidas(array $items, Currency $moneda, ?int $cajaId): array
    {
        $porCuenta = [];

        foreach ($items as $item) {
            $cuenta = $item->receipt->medium === PaymentMedium::Cheque
                ? LedgerAccount::ChequesInCustody
                : LedgerAccount::CashOnHand;

            $porCuenta[$cuenta->value] = Decimal::add($porCuenta[$cuenta->value] ?? '0.00', $item->amount);
        }

        $lineas = [];

        foreach ($porCuenta as $cuenta => $importe) {
            $lineas[] = EntryLine::credit(LedgerAccount::from($cuenta), $importe)
                ->in($moneda)
                ->onCashBox($cajaId);
        }

        return $lineas;
    }
}
