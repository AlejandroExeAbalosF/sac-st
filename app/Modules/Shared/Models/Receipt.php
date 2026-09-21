<?php

declare(strict_types=1);

namespace App\Modules\Shared\Models;

use App\Models\User;
use App\Modules\Shared\Enums\ReceiptIssueMode;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Enums\ReceiptType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un comprobante emitido — §9.8 del DER.
 *
 * Append-only: lo que dice el papel no se edita. Un recibo mal emitido se
 * anula y se emite otro que lo referencia, igual que en un talonario.
 *
 * **No tiene relación hacia la cuota.** `beneficiary_installment_id` es un
 * puntero suelto porque Shared no puede depender de Haberes; quien
 * necesite resolverlo lo hace desde ahí, que es el módulo que sabe qué
 * significa.
 *
 * @property int $id
 * @property int $document_series_id
 * @property int $number
 * @property string $formatted_number
 * @property string|null $talonario_number
 * @property ReceiptType $receipt_type
 * @property int $person_id
 * @property int|null $beneficiary_installment_id
 * @property string|null $concept_snapshot
 * @property string $medium_snapshot
 * @property string|null $counterparty_name_snapshot
 * @property string|null $beneficiary_name_snapshot
 * @property string|null $beneficiary_document_snapshot
 * @property string|null $expediente_number_snapshot
 * @property string|null $installment_label_snapshot
 * @property numeric-string $amount
 * @property CarbonInterface $issue_date
 * @property ReceiptStatus $status
 * @property ReceiptIssueMode $issue_mode
 * @property int|null $issued_by
 * @property int|null $signed_by
 * @property string|null $signed_by_name_snapshot
 * @property string|null $signed_by_title_snapshot
 * @property CarbonInterface|null $recorded_at
 * @property int|null $recorded_by
 * @property int|null $voided_by
 * @property CarbonInterface|null $voided_at
 * @property string|null $void_reason
 * @property int|null $replaces_receipt_id
 */
final class Receipt extends Model
{
    protected $fillable = [
        'document_series_id',
        'number',
        'formatted_number',
        'talonario_number',
        'prints_talonario_number',
        'receipt_type',
        'person_id',
        'beneficiary_installment_id',
        'concept_snapshot',
        'medium_snapshot',
        'counterparty_name_snapshot',
        'beneficiary_name_snapshot',
        'beneficiary_document_snapshot',
        'expediente_number_snapshot',
        'installment_label_snapshot',
        'amount',
        'issue_date',
        'status',
        'issue_mode',
        'issued_by',
        'signed_by',
        'signed_by_name_snapshot',
        'signed_by_title_snapshot',
        'recorded_at',
        'recorded_by',
        'voided_by',
        'voided_at',
        'void_reason',
        'replaces_receipt_id',
    ];

    /** @return BelongsTo<DocumentSeries, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(DocumentSeries::class, 'document_series_id');
    }

    /** «Recibí de» en el ingreso; el beneficiario en el egreso. */
    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    /** @return BelongsTo<User, $this> */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** Quien lo anulo. */
    /** @return BelongsTo<User, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** El comprobante al que este reemplaza. */
    /** @return BelongsTo<self, $this> */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_receipt_id');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', ReceiptStatus::Issued);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'receipt_type' => ReceiptType::class,
            'status' => ReceiptStatus::class,
            'issue_mode' => ReceiptIssueMode::class,
            'prints_talonario_number' => 'boolean',
            'amount' => 'decimal:2',
            'issue_date' => 'date',
            'recorded_at' => 'datetime',
            'voided_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
