<?php

declare(strict_types=1);

namespace App\Modules\Shared\Models;

use App\Models\User;
use App\Modules\Shared\Enums\AttachmentKind;
use App\Modules\Shared\Enums\AttachmentSource;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Enums\Confidentiality;
use App\Modules\Shared\Enums\SharedReasonCode;
use App\Modules\Shared\Policies\AttachmentPolicy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un archivo guardado, inmutable.
 *
 * @property int $id
 * @property AttachmentSubject $subject_type
 * @property int $subject_id
 * @property string $document_type
 * @property string $title
 * @property string $storage_disk
 * @property string $object_key
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property AttachmentSource $source
 * @property string|null $template_version
 * @property Confidentiality $confidentiality
 * @property int|null $created_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface|null $voided_at
 * @property int|null $voided_by
 * @property SharedReasonCode|null $void_reason_code
 * @property string|null $void_reason
 */
#[UsePolicy(AttachmentPolicy::class)]
final class Attachment extends Model
{
    /** Inmutable: no hay `updated_at` que tenga sentido. */
    public const UPDATED_AT = null;

    /**
     * Lo único que se abre en el navegador en lugar de descargarse.
     *
     * Una lista cerrada y no «todo lo que empiece con `image/`»: eso
     * incluía SVG, que es un documento con scripts adentro, y abrirlo en
     * pantalla es ejecutarlo en el origen del sistema.
     */
    public const INLINE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    /**
     * Los adjuntos que se pueden anular: respaldos opcionales que sube una
     * persona, por sujeto y tipo de documento.
     *
     * Una lista cerrada a propósito: un tipo nuevo no queda anulable solo
     * por existir. Lo que no está —el ticket del traslado, el extracto, la
     * planilla de cierre, la rendición, el comprobante de cobro de
     * Aranceles— es la prueba de un hecho. La base tiene la misma lista en
     * `attachment_is_voidable()`, y un test las compara.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const VOIDABLE = [
        ['fine_case', 'fine_case_paper'],
        ['fine_document', 'fine_document_paper'],
        ['fine', 'fine_support'],
        ['fine_payment', 'fine_payment_evidence'],
        ['fine_historical_movement', 'fine_historical_evidence'],
        ['receipt', 'talonario_photo'],
        ['deposit_ticket', 'deposit_ticket'],
        ['legacy_document', 'legacy_income_receipt'],
        ['legacy_document', 'legacy_payment_order'],
        ['legacy_document', 'legacy_expense_receipt'],
        ['disbursement', 'transfer_report'],
    ];

    protected $fillable = [
        'subject_type',
        'subject_id',
        'document_type',
        'title',
        'storage_disk',
        'object_key',
        'original_filename',
        'mime_type',
        'size_bytes',
        'sha256',
        'source',
        'template_version',
        'confidentiality',
        'created_by',
        'voided_at',
        'voided_by',
        'void_reason_code',
        'void_reason',
    ];

    /**
     * Los adjuntos de un sujeto, del más nuevo al más viejo.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForSubject(Builder $query, AttachmentSubject $type, int $id): Builder
    {
        return $query
            ->where('subject_type', $type->value)
            ->where('subject_id', $id)
            ->latest('id');
    }

    /** @return BelongsTo<User, $this> */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * Si el archivo admite que se lo anule: el estado, no el permiso.
     *
     * El permiso lo responde `AttachmentPolicy`, pero `Gate::before` le
     * concede todo al administrador antes de llegar ahí: esta es la regla
     * que también lo frena a él.
     */
    public function canBeVoided(): bool
    {
        return ! $this->isVoided()
            && $this->source !== AttachmentSource::Generated
            && in_array([$this->subject_type->value, $this->document_type], self::VOIDABLE, true);
    }

    /** Un adjunto anulado no se renombra: queda como estaba al anularlo. */
    public function canBeRenamed(): bool
    {
        return ! $this->isVoided();
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Si conviene mostrarlo en pantalla en lugar de descargarlo. */
    public function isViewableInline(): bool
    {
        return in_array($this->mime_type, self::INLINE_MIME_TYPES, true);
    }

    /** Cómo se muestra: foto, hoja de PDF o archivo para descargar. */
    public function kind(): AttachmentKind
    {
        return match (true) {
            ! $this->isViewableInline() => AttachmentKind::Other,
            $this->mime_type === 'application/pdf' => AttachmentKind::Pdf,
            default => AttachmentKind::Image,
        };
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subject_type' => AttachmentSubject::class,
            'source' => AttachmentSource::class,
            'confidentiality' => Confidentiality::class,
            'subject_id' => 'integer',
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
            'voided_at' => 'datetime',
            'void_reason_code' => SharedReasonCode::class,
        ];
    }
}
