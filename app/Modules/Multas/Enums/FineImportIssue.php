<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Lo que el importador le encuentra a una fila de la planilla.
 *
 * Los que **bloquean** no dejan consolidar la fila hasta que alguien decida
 * qué hacer, con una de las acciones que admite cada uno. La base repite la
 * misma lista (`fine_import_action_allowed`). Los que no bloquean quedan
 * para completar después, en la multa.
 */
#[TypeScript]
enum FineImportIssue: string
{
    // Bloquean.
    case MissingAmount = 'missing_amount';
    case DuplicateManual = 'duplicate_manual';
    case Changed = 'changed';
    case PossibleMatch = 'possible_match';
    case DuplicateInFile = 'duplicate_in_file';
    case TotalMismatch = 'total_mismatch';
    case TotalMissing = 'total_missing';
    case BalanceMismatch = 'balance_mismatch';
    case BalanceMissing = 'balance_missing';
    case FormulaWithoutResult = 'formula_without_result';
    case PaymentExceedsAmount = 'payment_exceeds_amount';
    case NegativePayment = 'negative_payment';
    case NoteInPayment = 'note_in_payment';
    case PlanColumn = 'plan_column';
    case RefundColumn = 'refund_column';
    case UnknownColumn = 'unknown_column';

    // No bloquean.
    case Unchanged = 'unchanged';
    case CaseIncomplete = 'case_incomplete';
    case ResolutionIncomplete = 'resolution_incomplete';
    case ActIncomplete = 'act_incomplete';
    case MissingDate = 'missing_date';
    case Overpaid = 'overpaid';
    case AfterCutoff = 'after_cutoff';

    public function blocking(): bool
    {
        return match ($this) {
            self::Unchanged, self::CaseIncomplete, self::ResolutionIncomplete, self::ActIncomplete,
            self::MissingDate, self::Overpaid, self::AfterCutoff => false,
            default => true,
        };
    }

    /**
     * Lo que se puede hacer con este problema. Excluir la fila siempre se puede.
     *
     * @return list<FineImportAction>
     */
    public function actions(): array
    {
        $propias = match ($this) {
            self::DuplicateManual => [FineImportAction::Link],
            self::Changed => [FineImportAction::AddMonths],
            self::PossibleMatch => [FineImportAction::SameCase, FineImportAction::NewCase],
            self::DuplicateInFile => [FineImportAction::Keep],
            // Un pago mayor que la multa puede ser con intereses o un tipeo: se mira fila por fila.
            self::TotalMismatch, self::TotalMissing, self::BalanceMismatch, self::BalanceMissing,
            self::FormulaWithoutResult, self::PaymentExceedsAmount => [FineImportAction::Accept, FineImportAction::WithoutPayments],
            self::NegativePayment, self::NoteInPayment => [FineImportAction::WithoutPayments],
            self::PlanColumn, self::RefundColumn, self::UnknownColumn => [FineImportAction::IgnoreColumns],
            default => [],
        };

        return $this->blocking() ? [...$propias, FineImportAction::Exclude] : [];
    }

    /** Si se puede resolver para todas las filas que lo tienen a la vez: solo donde la planilla se contradice consigo misma. */
    public function bulk(): bool
    {
        return in_array($this, [
            self::TotalMismatch, self::TotalMissing, self::BalanceMismatch, self::BalanceMissing, self::FormulaWithoutResult,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::MissingAmount => 'Sin importe, carátula o expediente',
            self::DuplicateManual => 'Ya cargada a mano',
            self::Changed => 'Cambió desde la importación anterior',
            self::PossibleMatch => 'Posible coincidencia',
            self::DuplicateInFile => 'Repetida en la planilla',
            self::TotalMismatch => 'El total pagado no coincide con los meses',
            self::TotalMissing => 'Sin total pagado',
            self::BalanceMismatch => 'El saldo no coincide',
            self::BalanceMissing => 'Sin saldo',
            self::FormulaWithoutResult => 'Fórmula sin resultado guardado',
            self::PaymentExceedsAmount => 'Pago mayor que la multa',
            self::NegativePayment => 'Pago negativo',
            self::NoteInPayment => 'Nota en una celda de pago',
            self::PlanColumn => 'Importe en la columna de plan de pagos',
            self::RefundColumn => 'Importe en la columna de restitución',
            self::UnknownColumn => 'Dato en una columna sin identificar',
            self::Unchanged => 'Ya importada, sin cambios',
            self::CaseIncomplete => 'Expediente incompleto',
            self::ResolutionIncomplete => 'Resolución incompleta',
            self::ActIncomplete => 'Acta incompleta',
            self::MissingDate => 'Sin fecha',
            self::Overpaid => 'Pagado de más',
            self::AfterCutoff => 'Pago desde la apertura, pendiente de identificar',
        };
    }
}
