<?php

declare(strict_types=1);

namespace App\Modules\Banking\Models;

use App\Models\User;
use App\Modules\Banking\Enums\ImportStatus;
use App\Modules\Banking\Enums\SourceFormat;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un archivo de extracto que entró al sistema.
 *
 * @property int $id
 * @property int $bank_account_id
 * @property int $imported_by
 * @property string $original_filename
 * @property int $file_size
 * @property string $file_sha256
 * @property SourceFormat $source_format
 * @property string $parser_version
 * @property string|null $account_number_in_file
 * @property string|null $currency_in_file
 * @property CarbonInterface|null $downloaded_at
 * @property string|null $operator_in_file
 * @property CarbonInterface|null $period_from
 * @property CarbonInterface|null $period_to
 * @property numeric-string|null $opening_balance
 * @property numeric-string|null $closing_balance
 * @property bool|null $balance_chain_ok
 * @property string|null $continuity_warning
 * @property ImportStatus $status
 * @property string|null $failure_reason
 * @property int $rows_total
 * @property int $rows_valid
 * @property int $rows_rejected
 * @property int $rows_new
 * @property int $rows_duplicate
 * @property CarbonInterface|null $imported_at
 * @property CarbonInterface|null $created_at
 */
final class BankStatementImport extends Model
{
    protected $fillable = [
        'bank_account_id',
        'imported_by',
        'original_filename',
        'file_size',
        'file_sha256',
        'source_format',
        'parser_version',
        'account_number_in_file',
        'currency_in_file',
        'downloaded_at',
        'operator_in_file',
        'period_from',
        'period_to',
        'opening_balance',
        'closing_balance',
        'balance_chain_ok',
        'continuity_warning',
        'status',
        'failure_reason',
        'rows_total',
        'rows_valid',
        'rows_rejected',
        'rows_new',
        'rows_duplicate',
        'imported_at',
    ];

    /** @return BelongsTo<BankAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    /** @return HasMany<BankStatementRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(BankStatementRow::class);
    }

    /**
     * Los movimientos que nacieron con esta importación.
     *
     * Es lo que hay que resolver para poder revertirla: la FK es
     * `restrictOnDelete` justamente para que nadie la borre sin decidir
     * qué pasa con ellos.
     *
     * @return HasMany<BankTransaction, $this>
     */
    public function originatedTransactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'first_seen_import_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_format' => SourceFormat::class,
            'status' => ImportStatus::class,
            'file_size' => 'integer',
            'rows_total' => 'integer',
            'rows_valid' => 'integer',
            'rows_rejected' => 'integer',
            'rows_new' => 'integer',
            'rows_duplicate' => 'integer',
            'balance_chain_ok' => 'boolean',
            'period_from' => 'date',
            'period_to' => 'date',
            'opening_balance' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'downloaded_at' => 'datetime',
            'imported_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
