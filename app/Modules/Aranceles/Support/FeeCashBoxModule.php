<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Support;

use App\Modules\Aranceles\Models\FeeChargeReceipt;
use App\Modules\Ledger\Data\CashMonthLinkData;
use App\Modules\Ledger\Data\CashMonthPanelData;
use App\Modules\Ledger\Enums\CashBoxAbility;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\CashCountLine;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CashBoxModule;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * La caja de Aranceles, para las pantallas de caja de Ledger. Se opera con
 * permisos propios y sin cheques: no se aceptan (regla A03).
 */
final class FeeCashBoxModule implements CashBoxModule
{
    public function permission(CashBoxAbility $ability): string
    {
        return match ($ability) {
            CashBoxAbility::View, CashBoxAbility::ViewClosings => 'aranceles.caja.ver',
            CashBoxAbility::Count => 'aranceles.caja.arquear',
            CashBoxAbility::ReviewCount => 'aranceles.caja.revisar-arqueo',
            CashBoxAbility::AdjustDifference => 'aranceles.caja.ajustar-diferencia',
            CashBoxAbility::Close => 'aranceles.caja.cerrar',
            CashBoxAbility::Reopen => 'aranceles.caja.reabrir',
            CashBoxAbility::Export => 'aranceles.caja.exportar',
            CashBoxAbility::RegenerateSheet => 'aranceles.caja.regenerar-planilla',
            CashBoxAbility::Open => 'aranceles.caja.abrir',
            CashBoxAbility::ManageAccounts => 'aranceles.caja.cuentas',
        };
    }

    public function currencies(): array
    {
        return [Currency::Ars];
    }

    /**
     * Los billetes de las demás cajas: no circula nada menor de $ 50 (decisión del
     * usuario, 05/11/2026; **sin relevar con el área**). Lo que no se pueda pagar justo
     * se redondea al cobrar, con su motivo. Un importe sin redondear, como el del
     * 0,07 %, queda como diferencia explicada.
     */
    public function countDenominations(Currency $currency): array
    {
        return CashCountLine::suggestedDenominations($currency);
    }

    public function chequesInCustody(PeriodClosing $closing): array
    {
        return [];
    }

    public function chequeColumns(): array
    {
        return [];
    }

    public function bankColumnNote(): string
    {
        return 'Lo reconocido en el libro de Aranceles. La cuenta es compartida con Multas: no es su saldo.';
    }

    /** Un ingreso parcial queda vinculado y el recibo sale al completar (regla A55). */
    public function bankIncomePrecedesReceipt(): bool
    {
        return true;
    }

    public function bankLedgerLabel(): string
    {
        return 'Reconocido en el libro de Aranceles';
    }

    /** Sin papeles: el informe es una consulta y no hay modelo de rendición (regla A105). */
    public function monthPanel(CarbonImmutable $month, ?PeriodClosing $closing, ?Authorizable $viewer): ?CashMonthPanelData
    {
        if (! ($viewer?->can('aranceles.ver') ?? false)) {
            return null;
        }

        return new CashMonthPanelData(
            title: 'Informe del mes',
            documents: [],
            emptyText: 'Es una consulta con Excel: el área todavía no definió un modelo de rendición.',
            emit: null,
            links: [new CashMonthLinkData('Movimientos del mes', route('aranceles.movimientos.index', ['mes' => $month->format('Y-m')], absolute: false))],
        );
    }

    /** Cada recibo abre su cobro en el listado, a quien puede ver aranceles. */
    public function rowLinks(array $receiptIds, ?Authorizable $viewer): array
    {
        if ($receiptIds === [] || ! ($viewer?->can('aranceles.ver') ?? false)) {
            return [];
        }

        return FeeChargeReceipt::query()
            ->whereIn('receipt_id', $receiptIds)
            ->pluck('fee_charge_id', 'receipt_id')
            ->map(fn (mixed $cobro): string => route('aranceles.cobros.index', ['cobro' => (int) $cobro], absolute: false))
            ->all();
    }
}
