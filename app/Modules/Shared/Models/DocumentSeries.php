<?php

declare(strict_types=1);

namespace App\Modules\Shared\Models;

use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Enums\SeriesOrigin;
use Illuminate\Database\Eloquent\Model;

/**
 * Una serie de numeración.
 *
 * @property int $id
 * @property string $code
 * @property DocumentType $document_type
 * @property string $label
 * @property SeriesOrigin $origin
 * @property string $format_pattern
 * @property int $code_padding
 * @property int $number_padding
 * @property string $reset_rule
 * @property int|null $year
 * @property int $next_number
 * @property bool $is_active
 */
final class DocumentSeries extends Model
{
    /** Laravel pluralizaría «DocumentSeries» a «document_series» igual, pero mejor explícito. */
    protected $table = 'document_series';

    protected $fillable = [
        'code',
        'document_type',
        'label',
        'cash_box_id',
        'origin',
        'format_pattern',
        'code_padding',
        'number_padding',
        'reset_rule',
        'year',
        'next_number',
        'is_active',
        'created_by',
    ];

    /**
     * Arma el número visible: `0010/00000001`.
     *
     * El formato vive en la serie y no en el código para que una serie
     * futura —Aranceles, o una que el área pida distinta— no obligue a
     * tocar PHP.
     */
    public function formatNumber(int $number): string
    {
        return str_replace(
            ['{code}', '{number}'],
            [
                str_pad($this->code, $this->code_padding, '0', STR_PAD_LEFT),
                str_pad((string) $number, $this->number_padding, '0', STR_PAD_LEFT),
            ],
            $this->format_pattern,
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'origin' => SeriesOrigin::class,
            'code_padding' => 'integer',
            'number_padding' => 'integer',
            'year' => 'integer',
            'next_number' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
