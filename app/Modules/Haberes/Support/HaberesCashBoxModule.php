<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Ledger\Data\CashMonthPanelData;
use App\Modules\Ledger\Enums\CashBoxAbility;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Excel\CashSheet;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CashBoxModule;
use App\Modules\Ledger\Support\ChequeCustodyAt;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * La caja de Haberes, para las pantallas de caja de Ledger.
 *
 * Sus permisos son los de siempre —`caja.*` y `cierres.*`—, que nacieron
 * cuando era la única caja con circuito.
 */
final class HaberesCashBoxModule implements CashBoxModule
{
    public function __construct(private readonly ChequeCustodyAt $custodia) {}

    public function permission(CashBoxAbility $ability): string
    {
        return match ($ability) {
            CashBoxAbility::View => 'caja.ver',
            CashBoxAbility::ViewClosings => 'cierres.ver',
            CashBoxAbility::Count => 'caja.arquear',
            CashBoxAbility::ReviewCount => 'caja.revisar-arqueo',
            CashBoxAbility::AdjustDifference => 'caja.ajustar-diferencia',
            CashBoxAbility::Close => 'cierres.cerrar',
            CashBoxAbility::Reopen => 'cierres.reabrir',
            CashBoxAbility::Export => 'cierres.exportar',
            CashBoxAbility::RegenerateSheet => 'cierres.regenerar-planilla',
            CashBoxAbility::Open => 'caja.abrir-saldo-inicial',
        };
    }

    /** Haberes recibe depósitos en pesos y en dólares: dos libros. */
    public function currencies(): array
    {
        return [Currency::Ars, Currency::Usd];
    }

    /** Las de la planilla del área, que son las de siempre. */
    public function chequeColumns(): array
    {
        return CashSheet::HABERES_CHEQUE_COLUMNS;
    }

    public function bankColumnNote(): ?string
    {
        return null;
    }

    /** Ninguno: la cuenta es solo de Haberes y su saldo lo dice el banco. */
    public function bankLedgerLabel(): ?string
    {
        return null;
    }

    /** Nada: el cierre mensual de Haberes no emite papeles propios. */
    public function monthPanel(CarbonImmutable $month, ?PeriodClosing $closing, ?Authorizable $viewer): ?CashMonthPanelData
    {
        return null;
    }

    /**
     * Ninguno: en Haberes el renglón abre el panel del recibo en la misma
     * pantalla, con la cuota que ya viaja en la fila.
     */
    public function rowLinks(array $receiptIds, ?Authorizable $viewer): array
    {
        return [];
    }

    /**
     * El inventario de cheques, como el reverso lo lista.
     *
     * La posición a la fecha la da `ChequeCustodyAt`; acá se suma la salida
     * que solo Haberes tiene —el cheque entregado al beneficiario— y quién
     * es cada cheque.
     *
     * El expediente y el beneficiario vienen de los **snapshots del
     * recibo**, que están en `receipts`: son texto congelado al emitir.
     *
     * ─── Por qué el camino pasa por `funding_allocations` ────────────────
     *
     * Un cobro produce **dos** eventos: la recepción del dinero y su
     * asignación a la cuota. El cheque cuelga del primero y el recibo del
     * segundo —`receipt_financial_events` vincula asignaciones—. Unirlos por
     * el evento de recepción devuelve el importe correcto y **todas las
     * columnas que identifican el cheque en blanco**, que es lo que el
     * reverso necesita. `funding_allocations` es la única tabla que registra
     * qué recepción pagó qué cuota, así que es el único puente posible. Un
     * cheque que financia varias cuotas aparece una vez, con todos sus
     * recibos.
     */
    public function chequesInCustody(PeriodClosing $closing): array
    {
        $hasta = $closing->period_to->toDateString();

        $filas = $this->custodia->query($closing)
            ->leftJoin('funding_allocations AS imputacion', function ($join) use ($hasta): void {
                $join->on('imputacion.fund_receipt_id', '=', 'fund_receipts.id')
                    ->whereNull('imputacion.reversal_of_id')
                    ->whereRaw('(SELECT event_date FROM financial_events WHERE id = imputacion.allocation_event_id) <= ?', [$hasta])
                    ->whereRaw('imputacion.amount > (SELECT COALESCE(SUM(r.amount), 0)
                        FROM funding_allocations r JOIN financial_events re ON re.id = r.allocation_event_id
                        WHERE r.reversal_of_id = imputacion.id AND re.event_date <= ?)', [$hasta]);
            })
            ->leftJoin('receipt_financial_events AS pivote', 'pivote.financial_event_id', '=', 'imputacion.allocation_event_id')
            ->leftJoin('receipts', 'receipts.id', '=', 'pivote.receipt_id')
            ->whereNotExists(function ($query) use ($closing): void {
                $query->selectRaw('1')->from('disbursements AS payment')
                    ->join('financial_events AS paid', 'paid.id', '=', 'payment.financial_event_id')
                    ->where('payment.method', 'cheque')
                    ->whereDate('paid.event_date', '<=', $closing->period_to)
                    ->whereRaw('(SELECT COALESCE(SUM(CASE WHEN a.reversal_of_id IS NULL THEN a.amount ELSE -a.amount END), 0)
                        FROM funding_allocations a JOIN financial_events ae ON ae.id = a.allocation_event_id
                        WHERE a.fund_receipt_id = fund_receipts.id
                          AND a.beneficiary_installment_id = payment.beneficiary_installment_id
                          AND ae.id <= paid.id) > 0')
                    ->whereNotExists(function ($reversal) use ($closing): void {
                        $reversal->selectRaw('1')->from('financial_events AS undone')
                            ->whereColumn('undone.reversal_of_id', 'paid.id')
                            ->whereDate('undone.event_date', '<=', $closing->period_to);
                    });
            })
            ->select([
                // `id` va en el SELECT porque el DISTINCT lo exige para
                // poder ordenar por él.
                'fund_receipts.id',
                'fund_receipts.amount',
                'fund_receipts.cheque_number',
                'fund_receipts.cheque_bank',
                'fund_receipts.cheque_issue_date',
                'receipts.talonario_number',
                'receipts.formatted_number',
                /*
                 * El snapshot del recibo manda; el de la recepción es el
                 * respaldo. Un cheque cargado en la apertura no tiene
                 * recibo emitido ni cuota asignada, pero sí lo que decía
                 * el papel cuando se abrieron los libros, y el reverso lo
                 * lista igual. El día que ese cheque se impute a una cuota
                 * real, el recibo pasa a tener la última palabra.
                 */
                'receipts.expediente_number_snapshot',
                'receipts.counterparty_name_snapshot',
                'receipts.beneficiary_name_snapshot',
                'fund_receipts.expediente_number_snapshot AS expediente_declarado',
                'fund_receipts.counterparty_name_snapshot AS empresa_declarada',
                'fund_receipts.beneficiary_name_snapshot AS beneficiario_declarado',
            ])
            ->distinct()
            ->orderBy('fund_receipts.id')
            ->get();

        $inventario = [];

        foreach ($filas as $fila) {
            $detalle = [
                'receipt' => (string) ($fila->talonario_number ?? $fila->formatted_number ?? ''),
                'expediente' => (string) ($fila->expediente_number_snapshot ?? $fila->expediente_declarado ?? ''),
                'company' => (string) ($fila->counterparty_name_snapshot ?? $fila->empresa_declarada ?? ''),
                'beneficiary' => (string) ($fila->beneficiary_name_snapshot ?? $fila->beneficiario_declarado ?? ''),
                'number' => (string) ($fila->cheque_number ?? ''),
                'bank' => (string) ($fila->cheque_bank ?? ''),
                'date' => $fila->cheque_issue_date === null
                    ? ''
                    : date('d/m/Y', (int) strtotime((string) $fila->cheque_issue_date)),
                'amount' => Decimal::scale((string) $fila->amount),
            ];

            // Un cheque puede financiar varias cuotas: se identifican todas,
            // pero su importe físico se suma una sola vez en el inventario.
            if (isset($inventario[$fila->id])) {
                foreach (['receipt', 'expediente', 'company', 'beneficiary'] as $field) {
                    $detalle[$field] = implode(' / ', array_unique(array_filter([
                        ...explode(' / ', $inventario[$fila->id][$field]), $detalle[$field],
                    ])));
                }
            }
            $inventario[$fila->id] = $detalle;
        }

        return array_values($inventario);
    }
}
