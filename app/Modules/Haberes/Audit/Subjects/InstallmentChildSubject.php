<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Audit\Subjects;

use App\Models\User;
use App\Modules\Haberes\Audit\InstallmentLinks;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Un sujeto que se mira en la tarjeta de su cuota.
 *
 * La orden de pago, el egreso, la asignación y el recibo se describen igual:
 * su propio nombre, el contexto de la cuota y el enlace al haber. Lo único
 * que cambia es qué columnas nombran a cada uno, y eso llega por parámetro.
 */
final class InstallmentChildSubject extends BaseAuditSubjectResolver
{
    /**
     * @param  class-string<Model>  $model  Con columna `beneficiary_installment_id`.
     * @param  list<string>  $columns  Las que `$name` necesita, además del id y la cuota.
     * @param  Closure(Model): string  $name
     * @param  array<string, string>  $fields
     * @param  array<string, array<array-key, string>>  $values
     * @param  list<string>  $money
     */
    public function __construct(
        private readonly InstallmentLinks $links,
        private readonly string $type,
        private readonly string $label,
        private readonly string $model,
        private readonly array $columns,
        private readonly Closure $name,
        private readonly array $fields,
        private readonly array $values = [],
        private readonly array $money = [],
    ) {}

    public function subjectType(): string
    {
        return $this->type;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function fields(): array
    {
        return $this->fields;
    }

    public function valueLabels(): array
    {
        return $this->values;
    }

    public function moneyFields(): array
    {
        return $this->money;
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $filas = $this->model::query()
            ->whereIn('id', $ids)
            ->get(['id', 'beneficiary_installment_id', ...$this->columns]);

        $cuotas = $this->links->load(array_values(array_filter(array_map(
            fn (Model $fila): int => (int) $fila->getAttribute('beneficiary_installment_id'),
            $filas->all(),
        ))));

        $descripciones = [];

        foreach ($filas as $fila) {
            $cuota = $cuotas[(int) $fila->getAttribute('beneficiary_installment_id')] ?? null;

            $descripciones[(int) $fila->getKey()] = new AuditSubjectDescription(
                ($this->name)($fila).($cuota === null ? '' : ' · '.$this->links->context($cuota)),
                $this->links->url($cuota, $viewer),
            );
        }

        return $descripciones;
    }
}
