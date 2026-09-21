<?php

declare(strict_types=1);

namespace App\Modules\Banking\Models;

use App\Models\User;
use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un crédito o un débito real de la cuenta.
 *
 * Append-only impuesto por trigger: los datos que informó el banco no se
 * editan. Lo único mutable es el estado de conciliación y su motivo.
 *
 * @property int $id
 * @property int $bank_account_id
 * @property int $first_seen_import_id
 * @property CarbonInterface|null $transaction_date
 * @property CarbonInterface|null $value_date
 * @property numeric-string $amount
 * @property TransactionDirection $direction
 * @property string|null $operation_id
 * @property string|null $causal_code
 * @property string|null $description
 * @property string|null $counterparty_name
 * @property string|null $counterparty_identifier
 * @property numeric-string|null $balance_after
 * @property string $fingerprint
 * @property ReconciliationStatus $reconciliation_status
 * @property string|null $ignored_reason
 * @property int|null $ignored_by
 * @property CarbonInterface|null $ignored_at
 */
final class BankTransaction extends Model
{
    protected $fillable = [
        'bank_account_id',
        'first_seen_import_id',
        'transaction_date',
        'value_date',
        'amount',
        'direction',
        'operation_id',
        'causal_code',
        'description',
        'counterparty_name',
        'counterparty_identifier',
        'balance_after',
        'fingerprint',
        'reconciliation_status',
        'ignored_reason',
        'ignored_by',
        'ignored_at',
    ];

    /** @return BelongsTo<BankAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    /** @return BelongsTo<BankStatementImport, $this> */
    public function firstSeenImport(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'first_seen_import_id');
    }

    /**
     * Todas las filas que trajeron este movimiento.
     *
     * Son varias cuando el operador descargó rangos que se pisan: el
     * movimiento es uno, los archivos que lo informaron pueden ser tres.
     *
     * @return HasMany<BankStatementRow, $this>
     */
    public function statementRows(): HasMany
    {
        return $this->hasMany(BankStatementRow::class);
    }

    /** @return BelongsTo<User, $this> */
    public function ignoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ignored_by');
    }

    /**
     * Dinero que entró al banco y todavía no se identificó.
     *
     * Es la primera cola del circuito: un crédito sin conciliar es plata
     * de alguien que el sistema todavía no sabe de quién es. Los débitos
     * quedan afuera —no son fondos que entren— y también los `partial`,
     * que ya tienen una recepción empezada.
     *
     * El estado y el sentido son exactamente los dos filtros que acepta
     * la pantalla de movimientos: el número del tablero y las filas que
     * el click muestra salen del mismo par, así que no pueden discrepar.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSinIdentificar(Builder $query): Builder
    {
        return $query
            ->where('direction', TransactionDirection::Credit)
            ->where('reconciliation_status', ReconciliationStatus::Pending);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => TransactionDirection::class,
            'reconciliation_status' => ReconciliationStatus::class,
            'transaction_date' => 'date',
            'value_date' => 'date',
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'ignored_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
