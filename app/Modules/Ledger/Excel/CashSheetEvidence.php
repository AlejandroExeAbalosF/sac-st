<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Excel;

use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Models\CashCountLine;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Shared\Models\CashBox;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Evidencia de cada cierre efectivo, independiente de las versiones del Excel.
 *
 * `schema 2` congela también **el formato del reverso**: qué columnas
 * identifican cada cheque y qué representa la columna de banco. Cada caja
 * declara las suyas, y una planilla rehecha dentro de un año no puede
 * preguntarle al módulo de hoy cómo eran. La de `schema 1` es toda de
 * Haberes, de cuando era la única caja, y se lee con sus columnas de
 * siempre.
 *
 * @phpstan-type ReceiptRow array{number: string, cash: numeric-string, cheques: numeric-string, bank: numeric-string, eventId?: int|null}
 * @phpstan-type ChequeColumn array{key: string, header: string}
 * @phpstan-type Evidence array{schema: int, closing: array<string, mixed>, cashBox: array<string, mixed>, title: string, sheetName: string, reverseSheetName: string, income: list<ReceiptRow>, expense: list<ReceiptRow>, cheques: list<array<string, string>>, bankDepositsLabel: string, count: array<string, mixed>|null, lines: list<array<string, mixed>>, dailyEvidenceIds: list<int>, chequeColumns?: list<ChequeColumn>, bankColumnNote?: string|null}
 */
final class CashSheetEvidence
{
    public function capture(CashSheet $sheet): void
    {
        $dailyIds = [];
        if ($sheet->closing->period_type === PeriodType::Monthly) {
            $days = PeriodClosing::query()
                ->where('cash_box_id', $sheet->closing->cash_box_id)
                ->where('currency', $sheet->closing->currency)
                ->where('period_type', PeriodType::Daily)
                ->where('status', PeriodClosingStatus::Closed)
                ->whereBetween('period_from', [$sheet->closing->period_from, $sheet->closing->period_to])
                ->orderBy('period_from')->get();
            foreach ($days as $day) {
                $dailyIds[] = $this->row($day)->id;
            }
        }

        DB::table('period_closing_evidence')->insert([
            'period_closing_id' => $sheet->closing->id,
            'version' => $sheet->closing->evidence_version,
            'payload' => json_encode([
                'schema' => 2,
                'closing' => $sheet->closing->getAttributes(),
                'cashBox' => $sheet->closing->cashBox->getAttributes(),
                'title' => $sheet->title,
                'sheetName' => $sheet->sheetName,
                'reverseSheetName' => $sheet->reverseSheetName,
                'income' => $sheet->income,
                'expense' => $sheet->expense,
                'cheques' => $sheet->cheques,
                'bankDepositsLabel' => $sheet->bankDepositsLabel,
                'chequeColumns' => $sheet->chequeColumns,
                'bankColumnNote' => $sheet->bankColumnNote,
                'count' => $sheet->count?->getAttributes(),
                'lines' => $sheet->count?->lines->map(fn (CashCountLine $line): array => $line->getAttributes())->all() ?? [],
                'dailyEvidenceIds' => $dailyIds,
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    public function read(PeriodClosing $closing): CashSheet
    {
        return $this->hydrate($this->decode($this->row($closing)->payload));
    }

    /** @return list<CashSheet> */
    public function workbook(PeriodClosing $closing): array
    {
        $payload = $this->decode($this->row($closing)->payload);
        $sheets = [];
        foreach ($payload['dailyEvidenceIds'] as $id) {
            $json = DB::table('period_closing_evidence')->where('id', $id)->value('payload');
            if (! is_string($json)) {
                throw ValidationException::withMessages(['status' => 'Falta la evidencia de un día del cierre mensual.']);
            }
            $sheets[] = $this->hydrate($this->decode($json));
        }

        return [...$sheets, $this->hydrate($payload)];
    }

    /** @return object{id: int, payload: string} */
    private function row(PeriodClosing $closing): object
    {
        /** @var object{id: int, payload: string}|null $row */
        $row = DB::table('period_closing_evidence')
            ->where('period_closing_id', $closing->id)
            ->where('version', $closing->evidence_version)->first(['id', 'payload']);
        if ($row === null) {
            throw ValidationException::withMessages([
                'status' => sprintf('El cierre del %s en %s no tiene detalle histórico congelado. Conservá su archivo original; para emitir una nueva versión hay que reabrir, verificar y volver a cerrar ese período.', $closing->period_from->format('d/m/Y'), $closing->currency->value),
            ]);
        }

        return $row;
    }

    /** @return Evidence */
    private function decode(string $json): array
    {
        /** @var Evidence $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! in_array($data['schema'], [1, 2], true)) {
            throw ValidationException::withMessages(['status' => 'La versión del detalle del cierre no es compatible.']);
        }

        return $data;
    }

    /** @param Evidence $data */
    private function hydrate(array $data): CashSheet
    {
        $closing = (new PeriodClosing)->setRawAttributes($data['closing'], true);
        $closing->setRelation('cashBox', (new CashBox)->setRawAttributes($data['cashBox'], true));
        $count = $data['count'] === null ? null : (new CashCount)->setRawAttributes($data['count'], true);
        $count?->setRelation('lines', CashCountLine::hydrate($data['lines']));

        return new CashSheet(
            closing: $closing, title: $data['title'], sheetName: $data['sheetName'],
            reverseSheetName: $data['reverseSheetName'], income: $data['income'], expense: $data['expense'],
            count: $count, cheques: $data['cheques'], bankDepositsLabel: $data['bankDepositsLabel'],
            chequeColumns: $data['chequeColumns'] ?? CashSheet::HABERES_CHEQUE_COLUMNS,
            bankColumnNote: $data['bankColumnNote'] ?? null,
        );
    }
}
