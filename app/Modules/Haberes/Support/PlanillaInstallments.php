<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Data\AllInstallmentRowData;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\Receipt;
use App\Support\Database\Like;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** Una sola selección de cuotas para la pantalla, el PDF y el Excel. */
final class PlanillaInstallments
{
    public function __construct(
        private readonly InstallmentStages $stages,
        private readonly InstallmentFunding $funding,
    ) {}

    /** @return LengthAwarePaginator<int, AllInstallmentRowData> */
    public function page(PlanillaFilters $filters, int $number): LengthAwarePaginator
    {
        if (! $filters->needsDerivedFilter()) {
            $page = $this->query($filters)->orderBy('id')->paginate(50, ['*'], 'page', $number)->withQueryString();
            $rows = $this->forMany($page->getCollection());

            return $page->through(fn (BeneficiaryInstallment $installment): AllInstallmentRowData => $rows[(int) $installment->id]);
        }

        $visible = [];
        $total = 0;
        $offset = ($number - 1) * 50;

        foreach ($this->rows($filters) as $row) {
            if ($total >= $offset && count($visible) < 50) {
                $visible[] = $row;
            }

            $total++;
        }

        return new LengthAwarePaginator($visible, $total, 50, $number, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    }

    public function count(PlanillaFilters $filters): int
    {
        if (! $filters->needsDerivedFilter()) {
            return $this->query($filters)->count();
        }

        $count = 0;

        foreach ($this->rows($filters) as $row) {
            $count++;
        }

        return $count;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    public function matchingIds(PlanillaFilters $filters, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $result = [];
        $installments = $this->query($filters)->whereIn('id', $ids)->get();

        foreach ($this->forMany($installments) as $id => $row) {
            if ($filters->accepts($row)) {
                $result[$id] = true;
            }
        }

        return $result;
    }

    /** @return Generator<int, AllInstallmentRowData> */
    public function rows(PlanillaFilters $filters): Generator
    {
        $afterId = 0;

        while (true) {
            $batch = $this->query($filters)
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit(250)
                ->get();

            if ($batch->isEmpty()) {
                return;
            }

            $afterId = (int) $batch->last()->id;

            foreach ($this->forMany($batch) as $row) {
                if ($filters->accepts($row)) {
                    yield $row;
                }
            }
        }
    }

    /** @return Builder<BeneficiaryInstallment> */
    private function query(PlanillaFilters $filters): Builder
    {
        $query = BeneficiaryInstallment::query()->with([
            'haber.beneficiary',
            'haber.expediente',
            'managementLabel',
        ]);

        if ($filters->expediente !== '') {
            $term = Like::contains($filters->expediente);
            $query->whereHas('haber.expediente', fn (Builder $case) => $case
                ->where(fn (Builder $numbers) => $numbers
                    ->where('display_number', 'ilike', $term)
                    ->orWhere('canonical_number', 'ilike', $term)));
        }

        if ($filters->beneficiario !== '') {
            $name = Like::contains(Expediente::normalizar($filters->beneficiario));
            $digits = preg_replace('/\D+/', '', $filters->beneficiario) ?? '';
            $query->whereHas('haber.beneficiary', function (Builder $person) use ($name, $digits): void {
                $person->where('search_name', 'like', $name);

                if ($digits !== '') {
                    $person->orWhere('document', 'like', Like::contains($digits));
                }
            });
        }

        if ($filters->empleador !== '') {
            $name = Like::contains(Expediente::normalizar($filters->empleador));
            $query->whereHas('haber.expediente.employer', fn (Builder $person) => $person
                ->where('search_name', 'like', $name));
        }

        if ($filters->medioPrevisto !== '') {
            $query->where('expected_medium', $filters->medioPrevisto);
        }

        if ($filters->retenido) {
            $query->where('workflow_status', InstallmentWorkflowStatus::Active)
                ->whereHas('managementLabel', fn (Builder $label) => $label->where('blocks_payment', true));
        }

        foreach ([
            ['due_date', '>=', $filters->vencimientoDesde],
            ['due_date', '<=', $filters->vencimientoHasta],
            ['expected_amount', '>=', $filters->importeMin],
            ['expected_amount', '<=', $filters->importeMax],
        ] as [$column, $operator, $value]) {
            if ($value !== '') {
                $query->where($column, $operator, $value);
            }
        }

        if ($filters->pagoDesde !== '' || $filters->pagoHasta !== '') {
            $query->where(function (Builder $paid) use ($filters): void {
                $paid->whereExists(function (QueryBuilder $payment) use ($filters): void {
                    $payment->selectRaw('1')
                        ->from('disbursements')
                        ->whereColumn('disbursements.beneficiary_installment_id', 'beneficiary_installments.id')
                        ->where('disbursements.status', DisbursementStatus::Confirmed->value);

                    if ($filters->pagoDesde !== '') {
                        $payment->whereDate('disbursements.payment_date', '>=', $filters->pagoDesde);
                    }

                    if ($filters->pagoHasta !== '') {
                        $payment->whereDate('disbursements.payment_date', '<=', $filters->pagoHasta);
                    }
                })->orWhereExists(function (QueryBuilder $legacy) use ($filters): void {
                    $legacy->selectRaw('1')
                        ->from('legacy_settlements')
                        ->whereColumn('legacy_settlements.beneficiary_installment_id', 'beneficiary_installments.id')
                        ->whereNull('legacy_settlements.voided_at');

                    if ($filters->pagoDesde !== '') {
                        $legacy->whereDate('legacy_settlements.paid_on', '>=', $filters->pagoDesde);
                    }

                    if ($filters->pagoHasta !== '') {
                        $legacy->whereDate('legacy_settlements.paid_on', '<=', $filters->pagoHasta);
                    }
                });
            });
        }

        return $query;
    }

    /**
     * @param  Collection<int, BeneficiaryInstallment>  $installments
     * @return array<int, AllInstallmentRowData>
     */
    private function forMany(Collection $installments): array
    {
        if ($installments->isEmpty()) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = $installments->pluck('id')->map(intval(...))->values()->all();
        $stages = $this->stages->forMany($installments);
        $funded = $this->funding->allocatedForMany($ids);
        $mediums = $this->funding->mediumForMany($ids);
        $payments = Disbursement::query()
            ->whereIn('beneficiary_installment_id', $ids)
            ->where('status', DisbursementStatus::Confirmed)
            ->get()
            ->keyBy('beneficiary_installment_id');
        $receipts = Receipt::query()
            ->whereIn('beneficiary_installment_id', $ids)
            ->where('receipt_type', ReceiptType::Expense)
            ->where('status', ReceiptStatus::Issued)
            ->pluck('beneficiary_installment_id')
            ->flip();

        $rows = [];

        foreach ($installments as $installment) {
            $id = (int) $installment->id;
            $rows[$id] = AllInstallmentRowData::fromModel(
                $installment,
                $stages[$id],
                $funded[$id],
                $mediums[$id],
                $payments->get($id)?->method,
                $receipts->has($id),
            );
        }

        return $rows;
    }
}
