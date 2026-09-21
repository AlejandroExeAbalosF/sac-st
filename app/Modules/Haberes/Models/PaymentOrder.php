<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Models;

use App\Models\User;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Haberes\Enums\PaymentOrderStatus;
use App\Modules\Haberes\Enums\ReceiptNumberSource;
use App\Modules\Shared\Models\DocumentSeries;
use App\Modules\Shared\Models\PersonBankAccount;
use App\Modules\Shared\Models\Receipt;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Una Orden de Pago — §9.6 del DER.
 *
 * Append-only en todo lo que el papel dice: una Orden mal emitida no se
 * corrige, se anula y se emite otra que la referencia. Lo impone un
 * trigger, no la buena voluntad del Action.
 *
 * **Los `_snapshot` no son duplicados por comodidad.** El documento está
 * en manos del organismo; que mañana alguien corrija el domicilio del
 * beneficiario en el maestro no puede cambiar lo que dice el papel que
 * está viajando.
 *
 * @property int $id
 * @property int $document_series_id
 * @property int $number
 * @property string $formatted_number
 * @property CarbonInterface $order_date
 * @property int $beneficiary_installment_id
 * @property numeric-string $amount
 * @property PaymentOrderStatus $status
 * @property int|null $replaces_order_id
 * @property int|null $beneficiary_bank_account_id
 * @property int|null $beneficiary_person_id
 * @property string|null $beneficiary_cbu_snapshot
 * @property string|null $beneficiary_bank_name_snapshot
 * @property string|null $beneficiary_account_number_snapshot
 * @property string $beneficiary_name_snapshot
 * @property string|null $beneficiary_document_snapshot
 * @property string|null $beneficiary_address_snapshot
 * @property string|null $beneficiary_phone_snapshot
 * @property string|null $cbu_folio_snapshot
 * @property string|null $employer_name_snapshot
 * @property string|null $employer_tax_identifier_snapshot
 * @property string|null $employer_address_snapshot
 * @property string|null $employer_phone_snapshot
 * @property string $expediente_number_snapshot
 * @property string|null $expediente_canonical_snapshot
 * @property string|null $expediente_subject_snapshot
 * @property CarbonInterface|null $custody_start_date_snapshot
 * @property int|null $organism_bank_account_id
 * @property int $income_receipt_id
 * @property ReceiptNumberSource $income_receipt_number_source
 * @property string $income_receipt_number_snapshot
 * @property int|null $expense_receipt_id
 * @property string|null $cheque_number_snapshot
 * @property string|null $cheque_bank_snapshot
 * @property int|null $treasurer_id
 * @property string|null $treasurer_name_snapshot
 * @property string|null $treasurer_title_snapshot
 * @property CarbonInterface|null $treasurer_signed_at
 * @property int|null $created_by
 * @property int|null $voided_by
 * @property CarbonInterface|null $voided_at
 * @property string|null $rejection_or_void_reason
 * @property string|null $notes
 */
final class PaymentOrder extends Model
{
    protected $fillable = [
        'document_series_id',
        'number',
        'formatted_number',
        'order_date',
        'beneficiary_installment_id',
        'amount',
        'status',
        'replaces_order_id',
        'beneficiary_bank_account_id',
        'beneficiary_person_id',
        'beneficiary_cbu_snapshot',
        'beneficiary_bank_name_snapshot',
        'beneficiary_account_number_snapshot',
        'beneficiary_name_snapshot',
        'beneficiary_document_snapshot',
        'beneficiary_address_snapshot',
        'beneficiary_phone_snapshot',
        'cbu_folio_snapshot',
        'employer_name_snapshot',
        'employer_tax_identifier_snapshot',
        'employer_address_snapshot',
        'employer_phone_snapshot',
        'expediente_number_snapshot',
        'expediente_canonical_snapshot',
        'expediente_subject_snapshot',
        'custody_start_date_snapshot',
        'organism_bank_account_id',
        'income_receipt_id',
        'income_receipt_number_source',
        'income_receipt_number_snapshot',
        'expense_receipt_id',
        'cheque_number_snapshot',
        'cheque_bank_snapshot',
        'treasurer_id',
        'treasurer_name_snapshot',
        'treasurer_title_snapshot',
        'treasurer_signed_at',
        'created_by',
        'voided_by',
        'voided_at',
        'rejection_or_void_reason',
        'notes',
    ];

    /** @return BelongsTo<DocumentSeries, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(DocumentSeries::class, 'document_series_id');
    }

    /** @return BelongsTo<BeneficiaryInstallment, $this> */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryInstallment::class, 'beneficiary_installment_id');
    }

    /** La cuenta del beneficiario a la que se pide transferir. */
    /** @return BelongsTo<PersonBankAccount, $this> */
    public function beneficiaryBankAccount(): BelongsTo
    {
        return $this->belongsTo(PersonBankAccount::class, 'beneficiary_bank_account_id');
    }

    /** La cuenta del organismo desde la que sale el dinero. */
    /** @return BelongsTo<BankAccount, $this> */
    public function organismBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'organism_bank_account_id');
    }

    /** @return BelongsTo<Receipt, $this> */
    public function incomeReceipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'income_receipt_id');
    }

    /** @return BelongsTo<Receipt, $this> */
    public function expenseReceipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'expense_receipt_id');
    }

    /** La nota que la acompaña. Es una sola: lo impone un índice único. */
    /** @return HasOne<Pase, $this> */
    public function pase(): HasOne
    {
        return $this->hasOne(Pase::class, 'payment_order_id');
    }

    /** La tabla de depósitos impresa, congelada. */
    /** @return HasMany<PaymentOrderFundingSource, $this> */
    public function fundingSources(): HasMany
    {
        return $this->hasMany(PaymentOrderFundingSource::class, 'payment_order_id');
    }

    /** @return BelongsTo<self, $this> */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_order_id');
    }

    /**
     * La Orden que ocupó su lugar, si ésta se anuló y se rehízo.
     *
     * Es el otro extremo de `replaces()`, y existe para poder preguntar lo
     * contrario: cuál de las anuladas todavía no fue reemplazada. Sin eso,
     * la tercera Orden de una cuota volvería a encadenarse con la primera.
     *
     * @return HasOne<self, $this>
     */
    public function replacedBy(): HasOne
    {
        return $this->hasOne(self::class, 'replaces_order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * Las que ocupan el lugar de su cuota.
     *
     * La lista de estados es la misma que la del índice único parcial
     * `payment_orders_one_active_per_installment`, y sale de un solo
     * lugar: `PaymentOrderStatus::isActive()`.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn(
            'status',
            array_map(
                fn (PaymentOrderStatus $estado): string => $estado->value,
                array_filter(
                    PaymentOrderStatus::cases(),
                    fn (PaymentOrderStatus $estado): bool => $estado->isActive(),
                ),
            ),
        );
    }

    /**
     * Las que salieron hacia el SAF y esperan el informe de transferencia.
     *
     * Es el tramo del circuito que no depende del área: el papel ya se
     * remitió y lo único que puede pasar es que vuelva el informe. Que el
     * tablero las cuente es lo que evita que una Orden se quede meses en
     * ese estado sin que nadie la reclame.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeEnSaf(Builder $query): Builder
    {
        return $query->where('status', PaymentOrderStatus::Sent);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PaymentOrderStatus::class,
            'income_receipt_number_source' => ReceiptNumberSource::class,
            'amount' => 'decimal:2',
            'order_date' => 'date',
            'custody_start_date_snapshot' => 'date',
            'treasurer_signed_at' => 'datetime',
            'voided_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
