<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Modules\Ledger\Data\CashMonthDocumentData;
use App\Modules\Ledger\Data\CashMonthLinkData;
use App\Modules\Ledger\Data\CashMonthPanelData;
use App\Modules\Ledger\Enums\CashBoxAbility;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CashBoxModule;
use App\Modules\Ledger\Support\ChequeCustodyAt;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineMonthlyReport;
use App\Modules\Multas\Models\FinePaymentReceipt;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * La caja de Multas, para las pantallas de caja de Ledger.
 *
 * Se opera con permisos propios (`multas.caja.*`): cerrar Haberes no
 * habilita a cerrar Multas, ni al revés.
 */
final class FineCashBoxModule implements CashBoxModule
{
    /**
     * Las columnas del reverso. El Excel del área no muestra cheques en
     * cartera, así que no hay un formato que copiar: el expediente y la
     * carátula identifican la multa.
     *
     * El librador es **solo el que declaró la apertura**, que lo pide con
     * ese nombre. El cobro en la caja no lo registra, y quien paga no es
     * necesariamente quien libró el cheque —una empresa puede entregar uno
     * de un tercero—: un cheque cobrado, o un reemplazo, sale con el
     * librador en blanco antes que con una identidad que nadie afirmó.
     */
    public const CHEQUE_COLUMNS = [
        ['key' => 'receipt', 'header' => 'RECIBO'],
        ['key' => 'expediente', 'header' => 'EXPTE'],
        ['key' => 'caption', 'header' => 'CARÁTULA'],
        ['key' => 'drawer', 'header' => 'LIBRADOR'],
    ];

    public function __construct(private readonly ChequeCustodyAt $custodia) {}

    public function permission(CashBoxAbility $ability): string
    {
        return match ($ability) {
            CashBoxAbility::View, CashBoxAbility::ViewClosings => 'multas.caja.ver',
            CashBoxAbility::Count => 'multas.caja.arquear',
            CashBoxAbility::ReviewCount => 'multas.caja.revisar-arqueo',
            CashBoxAbility::AdjustDifference => 'multas.caja.ajustar-diferencia',
            CashBoxAbility::Close => 'multas.caja.cerrar',
            CashBoxAbility::Reopen => 'multas.caja.reabrir',
            CashBoxAbility::Export => 'multas.caja.exportar',
            CashBoxAbility::RegenerateSheet => 'multas.caja.regenerar-planilla',
            CashBoxAbility::Open => 'multas.caja.abrir',
        };
    }

    /** Multas no cobra en dólares: un solo libro. */
    public function currencies(): array
    {
        return [Currency::Ars];
    }

    public function chequeColumns(): array
    {
        return self::CHEQUE_COLUMNS;
    }

    /**
     * La cuenta de Multas es compartida con Aranceles y su saldo no se
     * reparte: la columna dice lo que el libro de Multas reconoció.
     */
    public function bankColumnNote(): string
    {
        return 'Lo reconocido en el libro de Multas. La cuenta es compartida con Aranceles: no es su saldo.';
    }

    /** La cuenta es compartida: lo que se muestra es lo que reconoció el libro. */
    public function bankLedgerLabel(): string
    {
        return 'Reconocido en el libro de Multas';
    }

    /**
     * La rendición mensual a la SAF (P02): sus dos notas sobre el cierre
     * vigente del mes, las de versiones anteriores archivadas, y emitirlas
     * cuando el mes está cerrado y todavía no salieron. Más los movimientos
     * del mes, que son la consulta operativa del mismo período.
     */
    public function monthPanel(CarbonImmutable $month, ?PeriodClosing $closing, ?Authorizable $viewer): CashMonthPanelData
    {
        $veMultas = $viewer?->can('multas.ver') ?? false;
        $informes = $closing === null || ! $veMultas
            ? collect()
            : FineMonthlyReport::query()
                ->with('emitter:id,name')
                ->where('period_closing_id', $closing->id)
                ->orderByDesc('closing_version')
                ->orderBy('kind', 'desc')
                ->get();

        $cerrado = $closing?->status === PeriodClosingStatus::Closed;
        $vigentes = $informes->where('closing_version', $closing?->evidence_version);

        $enlaces = [];

        if ($veMultas) {
            $enlaces[] = new CashMonthLinkData('Movimientos del mes', route('multas.movimientos.index', ['mes' => $month->format('Y-m')], absolute: false));
            $enlaces[] = new CashMonthLinkData('Todas las rendiciones', route('multas.rendiciones.index', absolute: false));
        }

        return new CashMonthPanelData(
            title: 'Rendición a la SAF',
            documents: array_values($informes->map(fn (FineMonthlyReport $i): CashMonthDocumentData => new CashMonthDocumentData(
                label: $i->kind === FineMonthlyReport::ON_TIME ? 'En término' : 'Fuera de término',
                detail: $i->rows_count === 1 ? '1 ingreso' : $i->rows_count.' ingresos',
                total: Decimal::scale($i->total),
                version: $i->closing_version,
                current: $i->closing_version === $closing?->evidence_version,
                emittedAt: $i->emitted_at->toIso8601String(),
                emittedBy: $i->emitter?->name,
                viewUrl: route('multas.rendiciones.view', $i, absolute: false),
                downloadUrl: route('multas.rendiciones.pdf', $i, absolute: false),
            ))->all()),
            emptyText: match (true) {
                ! $veMultas => 'La rendición la ve quien puede ver multas.',
                $closing === null => 'Se emite con el mes cerrado.',
                ! $cerrado => 'El mes está reabierto: la rendición se emite al volver a cerrarlo.',
                default => 'Sin rendición emitida para este cierre.',
            },
            emit: $cerrado && $vigentes->isEmpty() && ($viewer?->can('multas.rendicion.emitir') ?? false)
                ? new CashMonthLinkData('Generar rendición', route('multas.rendiciones.store', $closing, absolute: false))
                : null,
            links: $enlaces,
        );
    }

    /**
     * Cada recibo de Multas abre la ficha de la multa que cobró, a quien
     * puede ver multas. Quien solo opera la caja ve el recibo como texto.
     */
    public function rowLinks(array $receiptIds, ?Authorizable $viewer): array
    {
        if ($receiptIds === [] || ! ($viewer?->can('multas.ver') ?? false)) {
            return [];
        }

        $multaPorRecibo = FinePaymentReceipt::query()
            ->join('fine_payments', 'fine_payments.id', '=', 'fine_payment_receipts.fine_payment_id')
            ->whereIn('fine_payment_receipts.receipt_id', $receiptIds)
            ->pluck('fine_payments.fine_id', 'fine_payment_receipts.receipt_id');

        // La ficha cuelga del expediente: se traen las multas con el suyo, de una vez.
        $multas = Fine::query()
            ->with('fineCase')
            ->whereIn('id', $multaPorRecibo->unique()->values()->all())
            ->get()
            ->keyBy('id');

        return $multaPorRecibo
            ->map(fn (mixed $multa): string => route('multas.show', $multas[(int) $multa]->showParameters(), absolute: false))
            ->all();
    }

    /**
     * El inventario de cheques a la fecha del cierre.
     *
     * Cada cheque se identifica por la aplicación de la raíz de su cadena:
     * un reemplazo paga la misma multa que el cheque rechazado, con el mismo
     * recibo. Un cheque de la apertura que todavía no se aplicó sale con lo
     * que declaró la apertura, y aparece igual: está en el cajón.
     */
    public function chequesInCustody(PeriodClosing $closing): array
    {
        $hasta = $closing->period_to;

        return array_values($this->custodia->query($closing)
            ->leftJoin('fine_payment_applications AS aplicacion', function ($join) use ($hasta): void {
                $join->whereRaw('aplicacion.fund_receipt_id = fund_receipt_root(fund_receipts.id)')
                    ->whereRaw('(SELECT event_date FROM financial_events WHERE id = aplicacion.application_event_id) <= ?', [$hasta->toDateString()]);
            })
            ->leftJoin('fine_payment_receipts AS papel', 'papel.fine_payment_id', '=', 'aplicacion.fine_payment_id')
            ->leftJoin('receipts', 'receipts.id', '=', 'papel.receipt_id')
            ->select([
                'fund_receipts.id',
                'fund_receipts.amount',
                'fund_receipts.cheque_number',
                'fund_receipts.cheque_bank',
                'fund_receipts.cheque_issue_date',
                'receipts.talonario_number',
                'receipts.formatted_number',
                'receipts.expediente_number_snapshot',
                'fund_receipts.expediente_number_snapshot AS expediente_declarado',
                'fund_receipts.counterparty_name_snapshot AS librador_declarado',
            ])
            ->selectRaw("papel.snapshot->>'caption' AS caratula")
            ->orderBy('fund_receipts.id')
            ->get()
            ->map(fn (object $fila): array => [
                'receipt' => (string) ($fila->talonario_number ?? $fila->formatted_number ?? ''),
                'expediente' => (string) ($fila->expediente_number_snapshot ?? $fila->expediente_declarado ?? ''),
                'caption' => (string) ($fila->caratula ?? ''),
                'drawer' => (string) ($fila->librador_declarado ?? ''),
                'number' => (string) ($fila->cheque_number ?? ''),
                'bank' => (string) ($fila->cheque_bank ?? ''),
                'date' => $fila->cheque_issue_date === null
                    ? ''
                    : date('d/m/Y', (int) strtotime((string) $fila->cheque_issue_date)),
                'amount' => Decimal::scale((string) $fila->amount),
            ])
            ->all());
    }
}
