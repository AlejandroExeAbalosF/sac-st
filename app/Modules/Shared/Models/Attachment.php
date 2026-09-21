<?php

declare(strict_types=1);

namespace App\Modules\Shared\Models;

use App\Models\User;
use App\Modules\Shared\Enums\AttachmentSource;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Enums\Confidentiality;
use Carbon\CarbonInterface;
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
 */
final class Attachment extends Model
{
    /** Inmutable: no hay `updated_at` que tenga sentido. */
    public const UPDATED_AT = null;

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
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Si conviene mostrarlo en pantalla en lugar de descargarlo. */
    public function isViewableInline(): bool
    {
        return str_starts_with($this->mime_type, 'image/')
            || $this->mime_type === 'application/pdf';
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
        ];
    }
}
