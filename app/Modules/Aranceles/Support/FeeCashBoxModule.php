<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Support;

use App\Modules\Aranceles\Models\FeeChargeReceipt;
use App\Modules\Ledger\Data\CashMonthPanelData;
use App\Modules\Ledger\Enums\CashBoxAbility;
use App\Modules\Ledger\Enums\Currency;
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

    public function bankLedgerLabel(): string
    {
        return 'Reconocido en el libro de Aranceles';
    }

    public function monthPanel(CarbonImmutable $month, ?PeriodClosing $closing, ?Authorizable $viewer): ?CashMonthPanelData
    {
        return null;
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
