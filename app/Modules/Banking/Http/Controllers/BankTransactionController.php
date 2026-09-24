<?php

declare(strict_types=1);

namespace App\Modules\Banking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banking\Actions\IgnoreBankTransaction;
use App\Modules\Banking\Data\BankAccountData;
use App\Modules\Banking\Data\BankTransactionListItemData;
use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Http\Requests\IgnoreBankTransactionRequest;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Support\Database\Like;
use App\Support\Ui\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class BankTransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $this->enumFilter($request->query('estado'), ReconciliationStatus::class);
        $direction = $this->enumFilter($request->query('sentido'), TransactionDirection::class);
        $search = trim((string) $request->query('buscar', ''));
        $accountId = (int) $request->query('cuenta', 0);

        $transactions = BankTransaction::query()
            // Cuántas veces se vio el movimiento: más de una es lo normal
            // cuando las descargas se pisan, y verlo evita la sospecha de
            // que el sistema lo duplicó.
            ->withCount('statementRows')
            ->when($accountId > 0, fn ($query) => $query->where('bank_account_id', $accountId))
            ->when($status !== null, fn ($query) => $query->where('reconciliation_status', $status?->value))
            ->when($direction !== null, fn ($query) => $query->where('direction', $direction?->value))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('description', 'ilike', Like::contains($search))
                        ->orWhere('operation_id', 'ilike', Like::contains($search))
                        ->orWhere('counterparty_identifier', 'ilike', Like::contains($search))
                        ->orWhere('counterparty_name', 'ilike', Like::contains($search));
                });
            })
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (BankTransaction $t): BankTransactionListItemData => BankTransactionListItemData::fromModel($t));

        return Inertia::render('banco/movimientos/index', [
            'transactions' => $transactions,
            'accounts' => BankAccount::query()
                ->orderBy('label')
                ->get()
                ->map(fn (BankAccount $a): BankAccountData => BankAccountData::fromModel($a))
                ->values()
                ->all(),
            'filters' => [
                'cuenta' => $accountId > 0 ? $accountId : null,
                'estado' => $status?->value,
                'sentido' => $direction?->value,
                'buscar' => $search === '' ? null : $search,
            ],
            'canIgnore' => $request->user()?->can('banco.movimientos.ignorar') ?? false,
            'canImport' => $request->user()?->can('banco.extractos.importar') ?? false,
            'hasAnyTransactions' => BankTransaction::query()->exists(),
        ]);
    }

    public function ignore(
        IgnoreBankTransactionRequest $request,
        BankTransaction $transaction,
        IgnoreBankTransaction $ignore,
    ): RedirectResponse {
        try {
            $ignore->handle($transaction, (string) $request->validated('reason'), (int) $request->user()?->id);
        } catch (RuntimeException $e) {
            Toast::error($e->getMessage());

            return back();
        }

        return back()->with('status', 'Movimiento marcado como fuera del circuito.');
    }

    /**
     * @template TEnum of \BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum|null
     */
    private function enumFilter(mixed $value, string $enum): ?object
    {
        return is_string($value) && $value !== '' ? $enum::tryFrom($value) : null;
    }
}
