<?php

declare(strict_types=1);

namespace App\Modules\Banking\Models;

use App\Models\User;
use App\Modules\Banking\Enums\ChequeDisposition;
use App\Modules\Banking\Enums\ChequeRejectionBankStatus;
use App\Modules\Ledger\Models\FundReceipt;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El rechazo de un cheque, con sus dos seguimientos (DER físico de Multas §6.6).
 *
 * - La **regularización** de la empresa: abierta hasta que llega el cheque
 *   que lo reemplaza (`replacement_fund_receipt_id`).
 * - El **seguimiento bancario**: qué se sabe del crédito y del débito
 *   (`bank_status`), con un asiento por hecho real.
 *
 * Y lo que se completa después, una vez: el aviso a la empresa y qué se hizo
 * con el papel. Vive en Banking porque toca traslados y extracto; el pago al
 * que pertenece el cheque lo sabe el módulo de la caja.
 *
 * @property int $id
 * @property int $fund_receipt_id
 * @property int|null $cash_to_bank_transfer_id
 * @property CarbonInterface $rejected_on
 * @property string $reason
 * @property int $rejection_event_id
 * @property int|null $registered_by
 * @property CarbonInterface $registered_at
 * @property CarbonInterface|null $company_notified_on
 * @property int|null $company_notice_recorded_by
 * @property CarbonInterface|null $company_notice_recorded_at
 * @property ChequeDisposition|null $disposition
 * @property int|null $disposition_recorded_by
 * @property CarbonInterface|null $disposition_recorded_at
 * @property ChequeRejectionBankStatus $bank_status
 * @property int|null $credit_bank_transaction_id
 * @property int|null $credit_event_id
 * @property int|null $debit_bank_transaction_id
 * @property int|null $debit_event_id
 * @property string|null $never_credited_note
 * @property int|null $never_credited_by
 * @property CarbonInterface|null $never_credited_at
 * @property int|null $replacement_fund_receipt_id
 * @property int|null $replacement_event_id
 * @property CarbonInterface|null $regularized_at
 * @property-read FundReceipt $fundReceipt
 * @property-read FundReceipt|null $replacement
 * @property-read CashToBankTransfer|null $transfer
 * @property-read User|null $registrar
 */
final class ChequeRejection extends Model
{
    protected $fillable = [
        'fund_receipt_id',
        'cash_to_bank_transfer_id',
        'rejected_on',
        'reason',
        'rejection_event_id',
        'registered_by',
        'registered_at',
        'company_notified_on',
        'company_notice_recorded_by',
        'company_notice_recorded_at',
        'disposition',
        'disposition_recorded_by',
        'disposition_recorded_at',
        'bank_status',
        'credit_bank_transaction_id',
        'credit_event_id',
        'debit_bank_transaction_id',
        'debit_event_id',
        'never_credited_note',
        'never_credited_by',
        'never_credited_at',
        'replacement_fund_receipt_id',
        'replacement_event_id',
        'regularized_at',
    ];

    /** Si la empresa todavía no lo regularizó con otro cheque. */
    public function isOpen(): bool
    {
        return $this->replacement_fund_receipt_id === null;
    }

    /** @return BelongsTo<FundReceipt, $this> */
    public function fundReceipt(): BelongsTo
    {
        return $this->belongsTo(FundReceipt::class);
    }

    /** @return BelongsTo<FundReceipt, $this> */
    public function replacement(): BelongsTo
    {
        return $this->belongsTo(FundReceipt::class, 'replacement_fund_receipt_id');
    }

    /** @return BelongsTo<CashToBankTransfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(CashToBankTransfer::class, 'cash_to_bank_transfer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    protected function casts(): array
    {
        return [
            'rejected_on' => 'date',
            'registered_at' => 'datetime',
            'company_notified_on' => 'date',
            'company_notice_recorded_at' => 'datetime',
            'disposition' => ChequeDisposition::class,
            'disposition_recorded_at' => 'datetime',
            'bank_status' => ChequeRejectionBankStatus::class,
            'never_credited_at' => 'datetime',
            'regularized_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
