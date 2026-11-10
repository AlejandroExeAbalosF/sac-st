<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Data\AllInstallmentRowData;

/** Criterios compartidos por las tres vistas y sus descargas. */
final readonly class PlanillaFilters
{
    public const STAGES = [
        'unfunded' => 'Sin fondos imputados',
        'partially_funded' => 'Fondos parciales',
        'awaiting_receipt' => 'Falta recibo de ingreso',
        'in_cash_box' => 'Entrega pendiente',
        'deposit_in_transit' => 'Depósito en tránsito',
        'at_bank' => 'Falta Orden de Pago',
        'order_issued' => 'Orden emitida',
        'transfer_reported' => 'Falta débito',
        'debit_observed' => 'Falta informe',
        'ready_to_validate' => 'Falta validación',
        'paid_missing_receipt' => 'Pago confirmado, falta recibo de egreso',
        'paid' => 'Pago confirmado',
        'paid_before_opening' => 'Pagada fuera del circuito',
        'blocked' => 'Bloqueada',
        'suspended' => 'Suspendida',
        'cancelled' => 'Anulada',
    ];

    /** @param list<string> $stages */
    public function __construct(
        public string $expediente = '',
        public string $beneficiario = '',
        public string $empleador = '',
        public array $stages = [],
        public string $medio = '',
        public string $medioPrevisto = '',
        public bool $retenido = false,
        public string $vencimientoDesde = '',
        public string $vencimientoHasta = '',
        public string $pagoDesde = '',
        public string $pagoHasta = '',
        public string $importeMin = '',
        public string $importeMax = '',
    ) {}

    /** @return array<string, string|bool|list<string>> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** Solo los criterios con sentido en la pestaña elegida. */
    public function forQueue(string $queue): self
    {
        if ($queue === 'todas') {
            return $this;
        }

        return new self(
            expediente: $this->expediente,
            beneficiario: $this->beneficiario,
            empleador: $this->empleador,
            stages: $queue === 'transferencias' ? array_values(array_intersect($this->stages, [
                'transfer_reported', 'debit_observed', 'ready_to_validate',
            ])) : [],
            medioPrevisto: $this->medioPrevisto,
            vencimientoDesde: $this->vencimientoDesde,
            vencimientoHasta: $this->vencimientoHasta,
            importeMin: $this->importeMin,
            importeMax: $this->importeMax,
        );
    }

    public function needsDerivedFilter(): bool
    {
        return $this->stages !== [] || $this->medio !== '';
    }

    public function accepts(AllInstallmentRowData $row): bool
    {
        return ($this->stages === [] || in_array($row->stageCode, $this->stages, true))
            && ($this->medio === '' || $row->paymentMethodCode === $this->medio);
    }

    public function summary(): string
    {
        $parts = [];

        foreach ([
            'Expediente' => $this->expediente,
            'Beneficiario' => $this->beneficiario,
            'Empleador' => $this->empleador,
            'Medio de pago' => ['cash' => 'Efectivo', 'cheque' => 'Cheque', 'bank_transfer' => 'Transferencia'][$this->medio] ?? '',
            'Medio de ingreso previsto' => ['cash' => 'Efectivo', 'cheque' => 'Cheque', 'bank' => 'Banco'][$this->medioPrevisto] ?? '',
        ] as $label => $value) {
            if ($value !== '') {
                $parts[] = $label.': '.$value;
            }
        }

        if ($this->stages !== []) {
            $parts[] = 'Situación: '.implode(', ', array_map(
                fn (string $stage): string => self::STAGES[$stage],
                $this->stages,
            ));
        }

        if ($this->retenido) {
            $parts[] = 'Pago retenido';
        }

        foreach ([
            'Vencimiento desde' => $this->vencimientoDesde,
            'Vencimiento hasta' => $this->vencimientoHasta,
            'Pago desde' => $this->pagoDesde,
            'Pago hasta' => $this->pagoHasta,
            'Importe desde' => $this->importeMin,
            'Importe hasta' => $this->importeMax,
        ] as $label => $value) {
            if ($value !== '') {
                $parts[] = $label.': '.$value;
            }
        }

        return $parts === [] ? 'Sin filtros adicionales' : implode(' · ', $parts);
    }
}
