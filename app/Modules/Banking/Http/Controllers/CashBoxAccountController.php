<?php

declare(strict_types=1);

namespace App\Modules\Banking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banking\Actions\LinkBankAccountToCashBox;
use App\Modules\Banking\Actions\MoveCashBoxBankAccountStart;
use App\Modules\Banking\Actions\RetireCashBoxBankAccount;
use App\Modules\Banking\Actions\SetDefaultCashBoxBankAccount;
use App\Modules\Banking\Http\Requests\LinkCashBoxAccountRequest;
use App\Modules\Banking\Http\Requests\MoveCashBoxAccountStartRequest;
use App\Modules\Banking\Http\Requests\RetireCashBoxAccountRequest;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\CashBoxBankAccount;
use App\Modules\Banking\Support\CashBoxBankAccounts;
use App\Modules\Ledger\Enums\CashBoxAbility;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Http\Controllers\Concerns\ResolvesCashBox;
use App\Support\BusinessDate;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las cuentas bancarias con las que opera una caja.
 *
 * Es la misma pantalla para cada caja, con su familia de rutas y su permiso
 * (`CashBoxAbility::ManageAccounts`): habilitar Multas no habilita Haberes.
 * Vive en Banking y no en la apertura porque la apertura es de Ledger, que no
 * conoce las cuentas; cada apertura enlaza acá.
 */
final class CashBoxAccountController extends Controller
{
    use ResolvesCashBox;

    public function __construct(private readonly CashBoxBankAccounts $cuentas) {}

    public function index(Request $request): Response
    {
        $caja = $this->cashBox($request);
        $monedas = array_map(fn (Currency $c): string => $c->value, $this->cashBoxModule($request)->currencies());
        $hoy = BusinessDate::today();
        $vinculos = $this->cuentas->of((int) $caja->id);
        $vigentesIds = $vinculos->whereNull('valid_until')->pluck('bank_account_id')->all();

        return Inertia::render('caja/cuentas', [
            'cashBox' => ['code' => $caja->code, 'name' => $caja->name],
            'accounts' => $vinculos->map(fn (CashBoxBankAccount $v): array => [
                'id' => $v->id,
                'bankAccountId' => $v->bank_account_id,
                'label' => $v->bankAccount->label,
                'accountNumber' => $v->bankAccount->account_number,
                'citation' => $v->bankAccount->document_citation,
                'currency' => $v->currency,
                'validFrom' => $v->valid_from?->toDateString(),
                'validUntil' => $v->valid_until?->toDateString(),
                'isDefault' => $v->is_default,
                'active' => $v->covers($hoy),
                'createdBy' => $v->creator?->name,
                'createdAt' => $v->created_at->toIso8601String(),
            ])->values()->all(),
            // Las que se pueden habilitar: activas, de una moneda de la caja y sin vínculo vigente.
            'available' => BankAccount::query()
                ->where('is_active', true)
                ->whereIn('currency', $monedas)
                ->whereNotIn('id', $vigentesIds)
                ->orderBy('label')
                ->get()
                ->map(fn (BankAccount $a): array => ['id' => $a->id, 'label' => $a->label, 'currency' => $a->currency])
                ->values()
                ->all(),
            'today' => $hoy->toDateString(),
            'can' => ['manage' => $this->canOnCashBox($request, CashBoxAbility::ManageAccounts)],
        ]);
    }

    public function store(LinkCashBoxAccountRequest $request, LinkBankAccountToCashBox $habilitar): RedirectResponse
    {
        $habilitar->handle(
            (int) $this->cashBox($request)->id,
            (int) $request->validated('bankAccountId'),
            $this->fecha($request->validated('validFrom')),
            $request->boolean('makeDefault'),
            (int) $request->user()?->id,
        );

        Toast::success('Cuenta habilitada para la caja.');

        return back();
    }

    public function retire(RetireCashBoxAccountRequest $request, CashBoxBankAccount $link, RetireCashBoxBankAccount $retirar): RedirectResponse
    {
        $this->assertOwn($request, $link);

        $nueva = $request->validated('newDefaultBankAccountId');
        $retirar->handle(
            $link,
            CarbonImmutable::parse((string) $request->validated('until')),
            is_numeric($nueva) ? (int) $nueva : null,
            (int) $request->user()?->id,
        );

        Toast::success('Cuenta dada de baja.', 'Lo que ya estaba en tránsito se sigue acreditando contra ella.');

        return back();
    }

    public function default(Request $request, CashBoxBankAccount $link, SetDefaultCashBoxBankAccount $predeterminar): RedirectResponse
    {
        $this->assertOwn($request, $link);
        $predeterminar->handle($link, (int) $request->user()?->id);

        Toast::success('Cuenta predeterminada actualizada.');

        return back();
    }

    public function start(MoveCashBoxAccountStartRequest $request, CashBoxBankAccount $link, MoveCashBoxBankAccountStart $mover): RedirectResponse
    {
        $this->assertOwn($request, $link);
        $mover->handle($link, $this->fecha($request->validated('validFrom')), (string) $request->validated('reason'), (int) $request->user()?->id);

        Toast::success('Vigencia actualizada.');

        return back();
    }

    /** Un vínculo de otra caja no existe en esta familia de rutas. */
    private function assertOwn(Request $request, CashBoxBankAccount $link): void
    {
        abort_unless($link->cash_box_id === (int) $this->cashBox($request)->id, 404);
    }

    private function fecha(mixed $valor): ?CarbonImmutable
    {
        return is_string($valor) && $valor !== '' ? CarbonImmutable::parse($valor) : null;
    }
}
