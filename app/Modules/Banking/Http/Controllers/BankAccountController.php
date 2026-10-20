<?php

declare(strict_types=1);

namespace App\Modules\Banking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banking\Actions\DeclareBankAccountCutoff;
use App\Modules\Banking\Data\BankAccountCutoffData;
use App\Modules\Banking\Data\BankAccountData;
use App\Modules\Banking\Enums\ImportStatus;
use App\Modules\Banking\Http\Requests\DeclareBankAccountCutoffRequest;
use App\Modules\Banking\Http\Requests\SaveBankAccountRequest;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankAccountOpening;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Support\AccountCutoffBalance;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

final class BankAccountController extends Controller
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
        private readonly AccountCutoffBalance $saldos,
    ) {}

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
            'cutoffs' => $this->cutoffs(),
            'canManage' => request()->user()?->can('banco.cuentas.gestionar') ?? false,
            'canDeclareCutoff' => request()->user()?->can('banco.cuentas.declarar-corte') ?? false,
        ]);
    }

    /**
     * El saldo de una cuenta compartida al corte, una sola vez.
     */
    public function declareCutoff(
        DeclareBankAccountCutoffRequest $request,
        BankAccount $account,
        DeclareBankAccountCutoff $declarar,
    ): RedirectResponse {
        $comprobante = $request->file('evidence');

        $declarar->handle(
            $account,
            CarbonImmutable::parse((string) $request->validated('cutoffOn')),
            (string) $request->validated('statementBalance'),
            $request->validated('notes'),
            $comprobante instanceof UploadedFile ? $comprobante : null,
            (int) $request->user()?->id,
        );

        Toast::success("Corte de «{$account->label}» declarado.");

        return to_route('banco.cuentas.index');
    }

    /**
     * Los cortes declarados, con su comprobante y su conciliación.
     *
     * @return list<BankAccountCutoffData>
     */
    private function cutoffs(): array
    {
        $cortes = BankAccountOpening::query()->with('declarer:id,name')->orderBy('id')->get();

        $adjuntos = Attachment::query()
            ->where('subject_type', AttachmentSubject::BankAccountOpening)
            ->whereIn('subject_id', $cortes->modelKeys())
            ->pluck('id', 'subject_id');

        return array_values($cortes
            ->map(fn (BankAccountOpening $corte): BankAccountCutoffData => BankAccountCutoffData::fromModel(
                $corte,
                isset($adjuntos[$corte->id]) ? (int) $adjuntos[$corte->id] : null,
                // Se recalcula al mirar: un extracto importado después completa la verificación.
                $this->saldos->atEndOf($corte->bank_account_id, $corte->cutoff_on),
                $this->saldos->reconcile($corte),
            ))
            ->all());
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
