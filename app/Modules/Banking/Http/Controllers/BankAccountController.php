<?php

declare(strict_types=1);

namespace App\Modules\Banking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banking\Data\BankAccountData;
use App\Modules\Banking\Enums\ImportStatus;
use App\Modules\Banking\Http\Requests\SaveBankAccountRequest;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class BankAccountController extends Controller
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    public function index(): Response
    {
        $accounts = BankAccount::query()
            ->withCount('imports')
            // El saldo que se muestra es el del último extracto que
            // efectivamente entró: uno rechazado no informa nada.
            ->addSelect([
                'last_imported_at' => $this->latestCompletedImportValue('imported_at'),
                'last_known_balance' => $this->latestCompletedImportValue('closing_balance'),
            ])
            ->orderByDesc('is_active')
            ->orderBy('label')
            ->get()
            ->map(fn (BankAccount $account): BankAccountData => BankAccountData::fromModel($account))
            ->values()
            ->all();

        return Inertia::render('banco/cuentas', [
            'accounts' => $accounts,
            'canManage' => request()->user()?->can('banco.cuentas.gestionar') ?? false,
        ]);
    }

    public function store(SaveBankAccountRequest $request): RedirectResponse
    {
        $account = BankAccount::query()->create($this->attributes($request));

        $this->auditar->handle('banco.cuenta.registrada', $account, after: [
            'label' => $account->label,
            'bank_name' => $account->bank_name,
            'account_number' => $account->account_number,
            'currency' => $account->currency,
        ]);

        return to_route('banco.cuentas.index')->with('status', "Cuenta «{$account->label}» registrada.");
    }

    public function update(SaveBankAccountRequest $request, BankAccount $account): RedirectResponse
    {
        $before = $account->only(['label', 'bank_name', 'account_number', 'cbu', 'alias', 'currency', 'is_active']);

        $account->fill($this->attributes($request))->save();

        $after = $account->only(['label', 'bank_name', 'account_number', 'cbu', 'alias', 'currency', 'is_active']);
        [$antes, $despues] = RecordAuditEvent::diff($before, $after);

        if ($despues !== []) {
            $this->auditar->handle('banco.cuenta.corregida', $account, before: $antes, after: $despues);
        }

        return to_route('banco.cuentas.index')->with('status', "Cuenta «{$account->label}» actualizada.");
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(SaveBankAccountRequest $request): array
    {
        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        return [
            'label' => $datos['label'],
            'bank_name' => $datos['bankName'],
            'account_number' => $datos['accountNumber'] ?? null,
            'cbu' => $datos['cbu'] ?? null,
            'alias' => $datos['alias'] ?? null,
            'currency' => $datos['currency'],
            'is_active' => (bool) $datos['isActive'],
        ];
    }

    /** @return Builder<BankStatementImport> */
    private function latestCompletedImportValue(string $column): Builder
    {
        return BankStatementImport::query()
            ->select($column)
            ->whereColumn('bank_account_id', 'bank_accounts.id')
            ->where('status', ImportStatus::Completed->value)
            ->orderByDesc('imported_at')
            ->orderByDesc('id')
            ->limit(1);
    }
}
