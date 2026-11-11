<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Models\User;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Shared\Data\AttachmentData;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Support\Attachments;
use Illuminate\Support\Facades\DB;

/**
 * Lo que cuelga de cada comprobante de depósito, en dos consultas.
 *
 * La tarjeta de la cuota necesita dos datos que no viven en el ticket: la
 * foto que se le adjuntó y la recepción que nació del crédito con el que
 * se lo cruzó. Preguntarlos ticket por ticket es una consulta por fila en
 * un plan que puede tener sesenta.
 *
 * **Indexado por ticket y no por cuota.** Una cuota puede tener más de un
 * comprobante vigente —el depósito fraccionado del §2.1.9—, y agrupar por
 * cuota obligaba a elegir uno con un `MAX()` que no tenía por qué ser el
 * que la pantalla termina mostrando: la tarjeta llegó a exhibir un ticket
 * con la foto de otro.
 */
final class DepositTicketLinks
{
    /**
     * La recepción ya registrada del crédito que cruzó cada ticket.
     *
     * Para qué sirve: ver `InstallmentTicketData`.
     *
     * @param  list<int>  $installmentIds
     * @return array<int, int> id de ticket => id de recepción
     */
    public function receipts(array $installmentIds): array
    {
        if ($installmentIds === []) {
            return [];
        }

        $filas = DB::table('deposit_tickets')
            ->join(
                'bank_transaction_allocations',
                'bank_transaction_allocations.bank_transaction_id',
                '=',
                'deposit_tickets.bank_transaction_id',
            )
            ->join(
                'fund_receipts',
                'fund_receipts.financial_event_id',
                '=',
                'bank_transaction_allocations.financial_event_id',
            )
            ->whereIn('deposit_tickets.beneficiary_installment_id', $installmentIds)
            ->whereNotNull('deposit_tickets.bank_transaction_id')
            // Una reversión no registra nada: deshace.
            ->whereNull('bank_transaction_allocations.reversal_of_id')
            // Y una recepción revertida no es una «ya registrada».
            ->whereNull('fund_receipts.reversal_event_id')
            ->orderBy('fund_receipts.id')
            ->get([
                'deposit_tickets.id as ticket',
                'fund_receipts.id as recepcion',
            ]);

        $porTicket = [];

        foreach ($filas as $fila) {
            // Si el crédito se dividió, al menos el enlace queda estable:
            // el Action detecta la multiplicidad y deriva al flujo manual.
            $porTicket[(int) $fila->ticket] ??= (int) $fila->recepcion;
        }

        return $porTicket;
    }

    /**
     * La apertura de los libros de Haberes en la moneda de esa cuenta.
     *
     * Un crédito anterior ya está dentro del saldo declarado: no se concilia.
     * Lo impone `BankMovementAfterOpening` al registrarlo y la base
     * (`bank_allocation_after_opening`); esto permite decirlo antes.
     */
    public function openingDate(int $bankAccountId): ?string
    {
        $caja = (int) CashBox::query()->where('code', CashBox::HABERES)->valueOrFail('id');
        $moneda = Currency::from((string) BankAccount::query()->whereKey($bankAccountId)->valueOrFail('currency'));

        return CashBookOpening::for($caja, $moneda)?->opened_on->format('Y-m-d');
    }

    /**
     * Los tickets cruzados con un crédito anterior a esa apertura.
     *
     * @param  list<int>  $installmentIds
     * @return array<int, string> id de ticket => fecha de apertura
     */
    public function beforeOpening(array $installmentIds): array
    {
        if ($installmentIds === []) {
            return [];
        }

        return DB::table('deposit_tickets')
            ->join('bank_transactions', 'bank_transactions.id', '=', 'deposit_tickets.bank_transaction_id')
            ->join('bank_accounts', 'bank_accounts.id', '=', 'bank_transactions.bank_account_id')
            ->join('cash_book_openings', 'cash_book_openings.currency', '=', 'bank_accounts.currency')
            ->join('cash_boxes', 'cash_boxes.id', '=', 'cash_book_openings.cash_box_id')
            ->where('cash_boxes.code', CashBox::HABERES)
            ->whereIn('deposit_tickets.beneficiary_installment_id', $installmentIds)
            ->whereColumn('bank_transactions.transaction_date', '<', 'cash_book_openings.opened_on')
            ->pluck('cash_book_openings.opened_on', 'deposit_tickets.id')
            ->map(fn ($fecha): string => substr((string) $fecha, 0, 10))
            ->all();
    }

    /**
     * La foto vigente de cada ticket —la más nueva—, con lo que la
     * pantalla necesita para mostrarla.
     *
     * @param  list<int>  $installmentIds
     * @return array<int, AttachmentData> id de ticket => su foto
     */
    public function attachments(array $installmentIds, ?User $viewer): array
    {
        if ($installmentIds === []) {
            return [];
        }

        /** @var list<int> $tickets */
        $tickets = DB::table('deposit_tickets')
            ->whereIn('beneficiary_installment_id', $installmentIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return app(Attachments::class)->latestFor(AttachmentSubject::DepositTicket, $tickets, $viewer);
    }
}
