<?php

declare(strict_types=1);

namespace App\Modules\Banking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banking\Actions\ImportBankStatement;
use App\Modules\Banking\Actions\ParseBankStatement;
use App\Modules\Banking\Actions\RollbackBankStatementImport;
use App\Modules\Banking\Data\BankAccountData;
use App\Modules\Banking\Data\StatementImportListItemData;
use App\Modules\Banking\Data\StatementPreviewData;
use App\Modules\Banking\Data\StatementPreviewRowData;
use App\Modules\Banking\Enums\MatchMethod;
use App\Modules\Banking\Enums\SourceFormat;
use App\Modules\Banking\Http\Requests\UploadBankStatementRequest;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankStatementRow;
use App\Support\Ui\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

final class BankStatementImportController extends Controller
{
    public function index(): Response
    {
        $imports = BankStatementImport::query()
            ->with(['account', 'importer'])
            ->latest('id')
            ->paginate(20)
            ->through(fn (BankStatementImport $import): StatementImportListItemData => StatementImportListItemData::fromModel($import));

        return Inertia::render('banco/extractos/index', [
            'imports' => $imports,
            'canImport' => request()->user()?->can('banco.extractos.importar') ?? false,
            'canRollback' => request()->user()?->can('banco.extractos.revertir') ?? false,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('banco/extractos/create', [
            'accounts' => $this->activeAccounts(),
        ]);
    }

    /**
     * Lee el archivo y devuelve qué pasaría, sin escribir nada.
     *
     * Responde con la misma pantalla en lugar de redirigir con el
     * resultado en la sesión. El flash compartido de `HandleInertiaRequests`
     * lleva `status` y nada más —a propósito: es para un mensaje de
     * confirmación, no para arrastrar la lectura entera de un extracto—, y
     * ampliarlo con una clave que solo entiende este módulo lo convertiría
     * en el lugar donde cada pantalla cuelga lo suyo.
     *
     * De paso evita que una vista previa vieja quede pegada en la sesión
     * esperando a que alguien recargue.
     *
     * Si el operador confirma, el archivo se sube de nuevo —es un
     * formulario, no hay estado de servidor que mantener— y el Action de
     * importación lo vuelve a leer.
     */
    public function preview(UploadBankStatementRequest $request, ParseBankStatement $parse): Response
    {
        $account = $this->account($request);
        $file = $request->file('file');
        $path = $file->getRealPath();

        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'No se pudo leer el archivo subido.']);
        }

        try {
            $format = SourceFormat::fromExtension($file->getClientOriginalExtension());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return Inertia::render('banco/extractos/create', [
            'accounts' => $this->activeAccounts(),
            'preview' => StatementPreviewData::fromPreview(
                $parse->handle($path, $account, $format),
            ),
            'previewFilename' => $file->getClientOriginalName(),
            'previewAccountId' => $account->id,
        ]);
    }

    /**
     * Importa y devuelve el último paso del asistente.
     *
     * No redirige al detalle: el resultado es parte del mismo recorrido
     * que empezó eligiendo el archivo, y cortarlo con una redirección
     * obliga al operador a reconstruir mentalmente qué acaba de pasar. El
     * detalle sigue estando a un clic, para cuando quiera ir.
     *
     * Un archivo rechazado también termina acá, con su motivo. Es el mismo
     * recorrido y merece el mismo cierre.
     */
    public function store(UploadBankStatementRequest $request, ImportBankStatement $import): Response
    {
        $account = $this->account($request);

        try {
            $record = $import->handle($request->file('file'), $account, (int) $request->user()?->id);
        } catch (RuntimeException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return Inertia::render('banco/extractos/create', [
            'accounts' => $this->activeAccounts(),
            'result' => StatementImportListItemData::fromModel($record->load(['account', 'importer'])),
        ]);
    }

    public function show(BankStatementImport $import): Response
    {
        $import->load(['account', 'importer']);

        $rows = $import->rows()
            ->orderBy('row_number')
            ->get()
            ->map(fn (BankStatementRow $row): StatementPreviewRowData => new StatementPreviewRowData(
                rowNumber: $row->row_number,
                status: $row->parse_status,
                errorMessage: $row->error_message,
                transactionDate: $row->parsed_transaction_date?->format('Y-m-d'),
                amount: $row->parsed_amount,
                direction: $row->parsed_direction,
                operationId: $row->parsed_operation_id,
                causalCode: $row->getAttribute('parsed_causal_code'),
                description: $row->parsed_description,
                counterpartyIdentifier: $row->getAttribute('parsed_counterparty_identifier'),
                balanceAfter: $row->parsed_balance_after,
                /*
                 * Acá «repetida» ya no es una previsión sino un hecho: la
                 * fila se vinculó por huella a un movimiento que existía,
                 * en vez de crear uno.
                 */
                repeated: $row->match_method === MatchMethod::Fingerprint,
            ))
            ->values()
            ->all();

        return Inertia::render('banco/extractos/show', [
            'import' => StatementImportListItemData::fromModel($import),
            'rows' => $rows,
            'canRollback' => request()->user()?->can('banco.extractos.revertir') ?? false,
        ]);
    }

    public function destroy(BankStatementImport $import, RollbackBankStatementImport $rollback): RedirectResponse
    {
        $filename = $import->original_filename;

        try {
            $rollback->handle($import, (int) request()->user()?->id);
        } catch (RuntimeException $e) {
            Toast::error($e->getMessage());

            return back();
        }

        return to_route('banco.extractos.index')->with('status', "Importación «{$filename}» revertida.");
    }

    private function account(UploadBankStatementRequest $request): BankAccount
    {
        /** @var BankAccount $account */
        $account = BankAccount::query()->findOrFail((int) $request->validated('bankAccountId'));

        return $account;
    }

    /**
     * @return list<BankAccountData>
     */
    private function activeAccounts(): array
    {
        return array_values(BankAccount::query()
            ->where('is_active', true)
            ->orderBy('label')
            ->get()
            ->map(fn (BankAccount $account): BankAccountData => BankAccountData::fromModel($account))
            ->all());
    }
}
