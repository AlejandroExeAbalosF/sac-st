<?php

declare(strict_types=1);

namespace App\Modules\Banking\Models;

use App\Models\User;
use App\Modules\Banking\Enums\MatchMethod;
use App\Modules\Banking\Enums\ParseStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una fila del archivo, con lo que el parser entendió de ella.
 *
 * @property int $id
 * @property int $bank_statement_import_id
 * @property int $row_number
 * @property list<string> $raw_data
 * @property CarbonInterface|null $parsed_transaction_date
 * @property numeric-string|null $parsed_amount
 * @property TransactionDirection|null $parsed_direction
 * @property string|null $parsed_operation_id
 * @property string|null $parsed_description
 * @property numeric-string|null $parsed_balance_after
 * @property string|null $fingerprint
 * @property ParseStatus $parse_status
 * @property string|null $error_message
 * @property int|null $bank_transaction_id
 * @property MatchMethod|null $match_method
 * @property int|null $match_confidence
 */
final class BankStatementRow extends Model
{
    protected $fillable = [
        'bank_statement_import_id',
        'row_number',
        'raw_data',
        'parsed_transaction_date',
        'parsed_value_date',
        'parsed_amount',
        'parsed_direction',
        'parsed_operation_id',
        'parsed_causal_code',
        'parsed_description',
        'parsed_counterparty',
        'parsed_counterparty_identifier',
        'parsed_balance_after',
        'fingerprint',
        'parse_status',
        'error_message',
        'bank_transaction_id',
        'match_method',
        'match_confidence',
        'linked_by',
    ];

    /** @return BelongsTo<BankStatementImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'bank_statement_import_id');
    }

    /** @return BelongsTo<BankTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }

    /** @return BelongsTo<User, $this> */
    public function linker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
            'parse_status' => ParseStatus::class,
            'parsed_direction' => TransactionDirection::class,
            'match_method' => MatchMethod::class,
            'row_number' => 'integer',
            'match_confidence' => 'integer',
            'parsed_transaction_date' => 'date',
            'parsed_value_date' => 'date',
            'parsed_amount' => 'decimal:2',
            'parsed_balance_after' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
