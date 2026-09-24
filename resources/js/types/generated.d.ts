declare namespace App {
    namespace Modules {
        namespace Banking {
            namespace Data {
                export type BankAccountData = {
                    id: number;
                    label: string;
                    bankName: string;
                    accountNumber: string | null;
                    cbu: string | null;
                    alias: string | null;
                    currency: string;
                    isActive: boolean;
                    importCount: number;
                    lastKnownBalance: string | null;
                    lastImportedAt: string | null;
                };
                export type BankTransactionListItemData = {
                    id: number;
                    transactionDate: string;
                    amount: string;
                    direction: App.Modules.Banking.Enums.TransactionDirection;
                    operationId: string | null;
                    causalCode: string | null;
                    description: string | null;
                    counterpartyIdentifier: string | null;
                    counterpartyName: string | null;
                    balanceAfter: string | null;
                    reconciliationStatus: App.Modules.Banking.Enums.ReconciliationStatus;
                    ignoredReason: string | null;
                    seenInImports: number;
                };
                export type StatementImportListItemData = {
                    id: number;
                    originalFilename: string;
                    sourceFormat: App.Modules.Banking.Enums.SourceFormat;
                    status: App.Modules.Banking.Enums.ImportStatus;
                    failureReason: string | null;
                    accountLabel: string;
                    periodFrom: string | null;
                    periodTo: string | null;
                    rowsTotal: number;
                    rowsValid: number;
                    rowsRejected: number;
                    rowsNew: number;
                    rowsDuplicate: number;
                    balanceChainOk: boolean | null;
                    continuityWarning: string | null;
                    importedAt: string | null;
                    importedBy: string;
                    closingBalance: string | null;
                    openingBalance: string | null;
                    accountNumberInFile: string | null;
                    currencyInFile: string | null;
                    operatorInFile: string | null;
                    downloadedAt: string | null;
                    fileSha256: string;
                    fileSize: number;
                    attachmentId: number | null;
                    canBeRolledBack: boolean;
                };
                export type StatementPreviewData = {
                    sourceFormat: App.Modules.Banking.Enums.SourceFormat;
                    importable: boolean;
                    problems: string[];
                    continuityWarning: string | null;
                    balanceChainOk: boolean | null;
                    accountNumberInFile: string | null;
                    currencyInFile: string | null;
                    operatorInFile: string | null;
                    downloadedAt: string | null;
                    periodFrom: string | null;
                    periodTo: string | null;
                    openingBalance: string | null;
                    closingBalance: string | null;
                    rowsTotal: number;
                    rowsValid: number;
                    rowsRejected: number;
                    newCount: number;
                    duplicateCount: number;
                    rows: App.Modules.Banking.Data.StatementPreviewRowData[];
                };
                export type StatementPreviewRowData = {
                    rowNumber: number;
                    status: App.Modules.Banking.Enums.ParseStatus;
                    errorMessage: string | null;
                    transactionDate: string | null;
                    amount: string | null;
                    direction: App.Modules.Banking.Enums.TransactionDirection | null;
                    operationId: string | null;
                    causalCode: string | null;
                    description: string | null;
                    counterpartyIdentifier: string | null;
                    balanceAfter: string | null;
                    repeated: boolean | null;
                };
            }
            namespace Enums {
                export type BankAllocationRole =
                    | 'funds_received'
                    | 'payment_confirmation'
                    | 'cash_deposit_confirmation';
                export type CashTransferStatus =
                    'deposited' | 'bank_confirmed' | 'cancelled';
                export type ImportStatus =
                    'uploaded' | 'parsing' | 'completed' | 'failed';
                export type MatchMethod =
                    | 'imported'
                    | 'fingerprint'
                    | 'operation_id'
                    | 'exact'
                    | 'manual';
                export type ParseStatus = 'valid' | 'warning' | 'rejected';
                export type ReconciliationStatus =
                    'pending' | 'partial' | 'reconciled' | 'ignored';
                export type SourceFormat = 'macro_online_csv' | 'macro_excel';
                export type TransactionDirection = 'credit' | 'debit';
            }
        }
        namespace Haberes {
            namespace Data {
                export type CounterPayoutRowData = {
                    installmentId: number;
                    expedienteId: number;
                    haberNumber: number;
                    expedienteNumber: string;
                    beneficiaryName: string;
                    beneficiaryDocument: string | null;
                    installmentLabel: string;
                    employerName: string | null;
                    concept: string | null;
                    amount: string;
                    incomeReceiptNumber: string | null;
                    cashBoxName: string | null;
                };
                export type DepositTicketData = {
                    id: number;
                    expedienteId: number;
                    expedienteNumber: string;
                    employerName: string | null;
                    installmentId: number | null;
                    installmentLabel: string | null;
                    accountLabel: string;
                    depositedAt: string;
                    depositedTime: string | null;
                    amount: string;
                    operationNumber: string | null;
                    depositKind: App.Modules.Haberes.Enums.DepositKind;
                    status: App.Modules.Haberes.Enums.DepositTicketStatus;
                    notes: string | null;
                    bankTransactionId: number | null;
                    matchSignals: Record<string, string> | null;
                    matchedAt: string | null;
                    discardedReason: string | null;
                    attachmentId: number | null;
                    waitingDays: number;
                };
                export type DisbursementSummaryData = {
                    id: number;
                    method: App.Modules.Haberes.Enums.DisbursementMethod;
                    status: App.Modules.Haberes.Enums.DisbursementStatus;
                    amount: string;
                    paymentDate: string | null;
                    deliveredByName: string | null;
                    notes: string | null;
                    reportedAt: string | null;
                    transferReference: string | null;
                    beneficiaryCbu: string | null;
                    debitTransactionId: number | null;
                    debitDate: string | null;
                    debitOperationId: string | null;
                    debitDescription: string | null;
                    validatedByName: string | null;
                    validatedAt: string | null;
                };
                export type ExpedienteExtraData = {
                    declaredTotalAmount: string | null;
                    externalId: string | null;
                    externalReference: string | null;
                    createdByName: string | null;
                    createdAt: string | null;
                };
                export type ExpedienteListItemData = {
                    id: number;
                    displayNumber: string;
                    canonicalNumber: string;
                    subject: string | null;
                    employerName: string;
                    employerRepresentative: string | null;
                    notes: string | null;
                    declaredTotalAmount: string;
                    recognizedTotalAmount: string;
                    fundedTotalAmount: string;
                    status: App.Modules.Haberes.Enums.ExpedienteStatus;
                    receivedDate: string | null;
                    createdAt: string;
                    lastChange: App.Modules.Shared.Data.LastChangeData | null;
                    haberes: App.Modules.Haberes.Data.HaberListItemData[];
                };
                export type FundReceiptDetailData = {
                    id: number;
                    publicId: string;
                    receivedDate: string;
                    medium: App.Modules.Ledger.Enums.PaymentMedium;
                    amount: string;
                    allocated: string;
                    unallocated: string;
                    residualStatus: App.Modules.Ledger.Enums.ResidualStatus;
                    residualNote: string | null;
                    depositorName: string | null;
                    cashBoxName: string | null;
                    receivedByName: string | null;
                    notes: string | null;
                    reversedAt: string | null;
                    reversedByName: string | null;
                    reversalReason: string | null;
                    bankTransactionId: number | null;
                    bankTransactionDescription: string | null;
                    bankAccountLabel: string | null;
                    expedienteId: number | null;
                    expedienteNumber: string | null;
                    employerName: string | null;
                };
                export type FundReceiptListItemData = {
                    id: number;
                    receivedDate: string;
                    medium: App.Modules.Ledger.Enums.PaymentMedium;
                    amount: string;
                    unallocated: string;
                    residualStatus: App.Modules.Ledger.Enums.ResidualStatus;
                    depositorName: string | null;
                    notes: string | null;
                    reversedAt: string | null;
                    expedienteNumber: string | null;
                    expedienteId: number | null;
                };
                export type FundingAllocationListItemData = {
                    id: number;
                    amount: string;
                    kind: App.Modules.Haberes.Enums.AllocationKind;
                    allocatedAt: string;
                    allocatedByName: string | null;
                    installmentId: number;
                    installmentNumber: number;
                    beneficiaryName: string;
                    expedienteNumber: string;
                    expedienteId: number;
                    notes: string | null;
                };
                export type HaberExtraData = {
                    legalDate: string | null;
                    resolutionReference: string | null;
                    paymentTerms: App.Modules.Haberes.Enums.PaymentTerms;
                };
                export type HaberListItemData = {
                    id: number;
                    expedienteId: number;
                    haberNumber: number;
                    beneficiaryName: string;
                    beneficiaryDocument: string;
                    assignedAmount: string;
                    fundedAmount: string;
                    installmentCount: number;
                    paidInstallmentCount: number;
                    status: App.Modules.Haberes.Enums.HaberWorkflowStatus;
                    blockReason: string | null;
                    concept: string | null;
                    notes: string | null;
                    createdAt: string | null;
                    createdByName: string | null;
                    lastChange: App.Modules.Shared.Data.LastChangeData | null;
                    installments: App.Modules.Haberes.Data.InstallmentListItemData[];
                };
                export type HaberRowData = {
                    id: number;
                    expedienteId: number;
                    haberNumber: number;
                    expedienteNumber: string;
                    employerName: string;
                    beneficiaryName: string;
                    beneficiaryDocument: string;
                    concept: string | null;
                    assignedAmount: string;
                    fundedAmount: string;
                    installmentCount: number;
                    loadedInstallmentCount: number;
                    paidInstallmentCount: number;
                    status: App.Modules.Haberes.Enums.HaberWorkflowStatus;
                    blockReason: string | null;
                    receivedDate: string | null;
                    createdAt: string;
                    lastChange: App.Modules.Shared.Data.LastChangeData | null;
                    installments: App.Modules.Haberes.Data.InstallmentListItemData[];
                };
                export type InstallmentAllocationData = {
                    id: number;
                    amount: string;
                    receivedDate: string;
                };
                export type InstallmentCandidateData = {
                    id: number;
                    haberId: number;
                    installmentNumber: number;
                    expectedAmount: string;
                    allocated: string;
                    remaining: string;
                    isFullyFunded: boolean;
                    beneficiaryName: string;
                    beneficiaryDocument: string | null;
                    concept: string | null;
                    description: string | null;
                    expedienteNumber: string;
                    expedienteId: number;
                    fixedMedium: string | null;
                };
                export type InstallmentDisbursementData = {
                    channel: App.Modules.Haberes.Enums.PaymentChannel;
                    applies: boolean;
                    isCounter: boolean;
                    blockedReason: string | null;
                    method: App.Modules.Haberes.Enums.DisbursementMethod | null;
                    amount: string;
                    disbursement: App.Modules.Haberes.Data.DisbursementSummaryData | null;
                    receipt: App.Modules.Haberes.Data.InstallmentReceiptData | null;
                    voidedReceipts: App.Modules.Haberes.Data.InstallmentReceiptData[];
                    hasIncomeReceipt: boolean;
                    orderNumber: string | null;
                    pendingStep: string | null;
                    canPay: boolean;
                    canReportTransfer: boolean;
                    canLinkDebit: boolean;
                    canUnlinkDebit: boolean;
                    canValidate: boolean;
                    needsReceipt: boolean;
                };
                export type InstallmentListItemData = {
                    id: number;
                    number: number;
                    expectedAmount: string;
                    status: App.Modules.Haberes.Enums.InstallmentWorkflowStatus;
                    concept: string | null;
                    managementLabel: string | null;
                    managementLabelId: number | null;
                    blocksPayment: boolean;
                    dueDate: string | null;
                    expectedMedium: App.Modules.Haberes.Enums.ExpectedMedium;
                    effectiveMedium: string | null;
                    notes: string | null;
                    ownConcept: string | null;
                    depositTicket: App.Modules.Haberes.Data.InstallmentTicketData | null;
                    fundedAmount: string;
                    remainingAmount: string;
                    overAllocatedAmount: string;
                    allocations: App.Modules.Haberes.Data.InstallmentAllocationData[];
                    isFullyFunded: boolean;
                    incomeReceipt: App.Modules.Haberes.Data.InstallmentReceiptData | null;
                    voidedReceipts: App.Modules.Haberes.Data.InstallmentReceiptData[];
                    cashTransfer: App.Modules.Haberes.Data.InstallmentTransferData | null;
                    cancelledTransfers: App.Modules.Haberes.Data.InstallmentTransferData[];
                    createdAt: string;
                    updatedAt: string;
                    stage: App.Modules.Haberes.Enums.InstallmentStage | null;
                };
                export type InstallmentOrderStateData = {
                    channel: App.Modules.Haberes.Enums.PaymentChannel;
                    applies: boolean;
                    blockedReason: string | null;
                    canIssue: boolean;
                    missing: App.Modules.Haberes.Data.MissingOrderFieldData[];
                    order: App.Modules.Haberes.Data.PaymentOrderSummaryData | null;
                    deposits: App.Modules.Haberes.Data.OrderDepositRowData[];
                    depositsTotal: string;
                    organismAccountLabel: string | null;
                    incomeReceiptSystemNumber: string | null;
                    incomeReceiptTalonarioNumber: string | null;
                    incomeReceiptPrintsTalonario: boolean;
                    editLocked: boolean;
                    editUnlockReason: string | null;
                };
                export type InstallmentReceiptData = {
                    id: number;
                    formattedNumber: string;
                    talonarioNumber: string | null;
                    printsTalonarioNumber: boolean;
                    signedByName: string | null;
                    signedByTitle: string | null;
                    amount: string;
                    issueDate: string;
                    status: App.Modules.Shared.Enums.ReceiptStatus;
                    issueMode: App.Modules.Shared.Enums.ReceiptIssueMode;
                    issuedByName: string | null;
                    voidReason: string | null;
                    voidedAt: string | null;
                    voidedByName: string | null;
                };
                export type InstallmentTicketData = {
                    id: number;
                    depositedAt: string;
                    depositedTime: string | null;
                    amount: string;
                    operationNumber: string | null;
                    terminal: string | null;
                    depositKind: App.Modules.Haberes.Enums.DepositKind;
                    status: App.Modules.Haberes.Enums.DepositTicketStatus;
                    notes: string | null;
                    bankAccountId: number;
                    accountLabel: string;
                    attachmentId: number | null;
                    bankTransactionId: number | null;
                    fundReceiptId: number | null;
                    editable: boolean;
                    waitingDays: number;
                };
                export type InstallmentTransferData = {
                    id: number;
                    amount: string;
                    depositDate: string;
                    status: App.Modules.Banking.Enums.CashTransferStatus;
                    operationNumber: string | null;
                    bankTransactionId: number | null;
                    creditedDate: string | null;
                    cancelledAt: string | null;
                    cancelledByName: string | null;
                    cancelReason: string | null;
                };
                export type MissingOrderFieldData = {
                    code: string;
                    section: string;
                    label: string;
                    reason: string;
                    required: boolean;
                };
                export type OrderDepositRowData = {
                    operationNumber: string | null;
                    operationDate: string | null;
                    bankAccountNumber: string | null;
                    bankName: string | null;
                    amount: string;
                };
                export type PaseSummaryData = {
                    id: number;
                    destination: string;
                    issueDate: string;
                    status: App.Modules.Haberes.Enums.PaseStatus;
                    notes: string | null;
                };
                export type PaymentOrderContextData = {
                    beneficiaryId: number;
                    beneficiaryName: string;
                    beneficiaryDocument: string | null;
                    beneficiaryAddress: string | null;
                    beneficiaryPhone: string | null;
                    employerId: number | null;
                    employerName: string | null;
                    employerDocument: string | null;
                    employerAddress: string | null;
                    employerPhone: string | null;
                    expedienteNumber: string;
                    expedienteCanonical: string | null;
                    expedienteSubject: string | null;
                    custodyStartDate: string | null;
                    accounts: App.Modules.Haberes.Data.VerifiableAccountData[];
                    defaultPaseDestination: string;
                };
                export type PaymentOrderSummaryData = {
                    id: number;
                    number: number;
                    formattedNumber: string;
                    orderDate: string;
                    status: App.Modules.Haberes.Enums.PaymentOrderStatus;
                    amount: string;
                    beneficiaryCbu: string | null;
                    cbuFolio: string | null;
                    organismAccountLabel: string | null;
                    incomeReceiptNumber: string;
                    notes: string | null;
                    pase: App.Modules.Haberes.Data.PaseSummaryData | null;
                    voidReason: string | null;
                    voidedAt: string | null;
                };
                export type ReceiptPanelData = {
                    receipt: App.Modules.Haberes.Data.InstallmentReceiptData;
                    medium: string;
                    concept: string | null;
                    counterpartyName: string | null;
                    beneficiaryNameOnPaper: string | null;
                    expedienteNumberOnPaper: string | null;
                    subject: App.Modules.Haberes.Data.ReceiptSubjectData | null;
                };
                export type ReceiptSubjectData = {
                    installmentId: number;
                    installmentNumber: number;
                    haberId: number;
                    haberNumber: number;
                    expedienteId: number;
                    expedienteNumber: string;
                    beneficiaryName: string;
                    beneficiaryDocument: string | null;
                    employerName: string;
                    concept: string | null;
                };
                export type TicketCandidateData = {
                    transactionId: number;
                    transactionDate: string;
                    amount: string;
                    operationId: string | null;
                    causalCode: string | null;
                    description: string | null;
                    counterpartyIdentifier: string | null;
                    counterpartyName: string | null;
                    balanceAfter: string | null;
                    dayGap: number;
                    operationMatches: boolean;
                    employerMatches: boolean;
                    signals: Record<string, string>;
                };
                export type UnconfirmedTransferRowData = {
                    disbursementId: number;
                    installmentId: number;
                    expedienteId: number;
                    haberNumber: number;
                    expedienteNumber: string;
                    beneficiaryName: string;
                    installmentLabel: string;
                    amount: string;
                    paymentOrderNumber: string | null;
                    reportedAt: string | null;
                    debitObservedAt: string | null;
                    status: App.Modules.Haberes.Enums.DisbursementStatus;
                    missingStep: string | null;
                    waitingDays: number;
                };
                export type VerifiableAccountData = {
                    id: number;
                    cbu: string;
                    bankName: string | null;
                    accountNumber: string | null;
                    verificationStatus: string;
                    rejectionReason: string | null;
                    checksumValid: boolean;
                    isVirtualWallet: boolean;
                    entityCode: string | null;
                    verifiedAt: string | null;
                    forcedReason: string | null;
                    forcedBypass: string | null;
                };
            }
            namespace Enums {
                export type AllocationKind =
                    'allocation' | 'cash_rounding_surplus' | 'reversal';
                export type DepositKind =
                    'cash_deposit' | 'transfer' | 'cheque_deposit';
                export type DepositTicketStatus =
                    'waiting' | 'matched' | 'discarded';
                export type DisbursementMethod =
                    'cash' | 'cheque' | 'bank_transfer';
                export type DisbursementStatus =
                    | 'pending'
                    | 'report_received'
                    | 'bank_debit_observed'
                    | 'ready_for_validation'
                    | 'confirmed'
                    | 'reversed'
                    | 'failed';
                export type ExpectedMedium = 'cash' | 'cheque' | 'bank';
                export type ExpedienteStatus =
                    'active' | 'suspended' | 'closed' | 'cancelled';
                export type HaberWorkflowStatus =
                    'active' | 'suspended' | 'blocked' | 'cancelled' | 'closed';
                export type InstallmentStage =
                    | 'cancelled'
                    | 'blocked'
                    | 'suspended'
                    | 'unfunded'
                    | 'awaiting_receipt'
                    | 'in_cash_box'
                    | 'deposit_in_transit'
                    | 'at_bank'
                    | 'order_issued'
                    | 'transfer_reported'
                    | 'debit_observed'
                    | 'ready_to_validate'
                    | 'paid';
                export type InstallmentWorkflowStatus =
                    'active' | 'suspended' | 'blocked' | 'cancelled' | 'paid';
                export type PaseStatus =
                    'draft' | 'generated' | 'signed' | 'archived' | 'voided';
                export type PaymentChannel =
                    'counter' | 'transfer' | 'undetermined';
                export type PaymentOrderStatus =
                    | 'draft'
                    | 'reviewed'
                    | 'approved'
                    | 'sent'
                    | 'transfer_reported'
                    | 'bank_debit_observed'
                    | 'ready_for_validation'
                    | 'completed'
                    | 'rejected'
                    | 'voided';
                export type PaymentTerms = 'single' | 'installments';
                export type ReceiptNumberSource = 'system' | 'talonario';
            }
        }
        namespace Ledger {
            namespace Data {
                export type CalendarDayData = {
                    date: string;
                    day: number;
                    inMonth: boolean;
                    isToday: boolean;
                    isWeekend: boolean;
                    state: string;
                    hasMovements: boolean;
                    onlyReversals: boolean;
                    closingCash: string | null;
                    closingId: number | null;
                    countBalanced: boolean | null;
                    partiallyCounted: boolean;
                    hasSheet: boolean;
                };
                export type CalendarMonthData = {
                    month: number;
                    label: string;
                    closed: boolean;
                    closingId: number | null;
                    hasSheet: boolean;
                    daysClosed: number;
                    daysWithMovements: number;
                    closingCash: string | null;
                    isFuture: boolean;
                };
                export type CashBoxStateData = {
                    cashBoxId: number;
                    code: string;
                    name: string;
                    currency: string;
                    cash: string;
                    cheques: string;
                    bank: string;
                    unassigned: string;
                    inTransit: string;
                };
                export type CashCountListItemData = {
                    id: number;
                    countedOn: string;
                    sequence: number;
                    currency: string;
                    expectedAmount: string;
                    countedAmount: string;
                    uncountedAmount: string;
                    differenceAmount: string;
                    status: string;
                    statusLabel: string;
                    explanation: string | null;
                    balanced: boolean;
                    fullyCounted: boolean;
                    editable: boolean;
                    performedById: number | null;
                    performedBy: string | null;
                    reviewedBy: string | null;
                    selfReviewed: boolean;
                    adjusted: boolean;
                    lines: {
                        denomination: string;
                        quantity: number;
                        subtotal: string;
                    }[];
                    carryRecountReason: string | null;
                    carryExpectedAmount: string | null;
                    carryCountedAmount: string | null;
                    carryDifferenceAmount: string | null;
                    carryLines: {
                        denomination: string;
                        quantity: number;
                        subtotal: string;
                    }[];
                };
                export type CashDayStatusData = {
                    balances: App.Modules.Ledger.Data.CashBoxStateData;
                    date: string;
                    needsOpening: boolean;
                    countsToday: number;
                    closingStatus: string | null;
                    href: string;
                };
                export type MovementRowData = {
                    publicId: string;
                    typeLabel: string;
                    type: string;
                    eventDate: string;
                    recordedAt: string;
                    amount: string;
                    description: string | null;
                    cashBoxName: string | null;
                    isReversed: boolean;
                    href: string;
                };
                export type PeriodClosingListItemData = {
                    id: number;
                    periodType: string;
                    periodTypeLabel: string;
                    periodFrom: string;
                    periodTo: string;
                    currency: string;
                    openingCash: string;
                    openingCheques: string;
                    openingBankDeposits: string;
                    receivedCash: string;
                    disbursedCash: string;
                    depositedToBankCash: string;
                    closingCash: string;
                    closingCheques: string;
                    closingBankDeposits: string;
                    totalUnassigned: string;
                    status: string;
                    statusLabel: string;
                    closedBy: string | null;
                    closedAt: string | null;
                    reopenReason: string | null;
                    reopenedBy: string | null;
                    sheetAttachmentId: number | null;
                    sheetTemplateVersion: string | null;
                    sheetHistory: {
                        id: number;
                        issuedAt: string;
                        issuedBy: string | null;
                        templateVersion: string | null;
                        current: boolean;
                    }[];
                    matchesLedger: boolean | null;
                };
            }
            namespace Enums {
                export type CashCountScope = 'day' | 'carry';
                export type CashCountStatus =
                    'draft' | 'reviewed' | 'adjusted' | 'closed';
                export type ChequeStatus =
                    | 'in_custody'
                    | 'delivered'
                    | 'deposited'
                    | 'cleared'
                    | 'rejected';
                export type Currency = 'ARS' | 'USD';
                export type FinancialEventStatus =
                    'draft' | 'posted' | 'reversed';
                export type FinancialEventType =
                    | 'funds_received'
                    | 'funds_allocated'
                    | 'cash_disbursement'
                    | 'cash_deposited_to_bank'
                    | 'bank_disbursement'
                    | 'cash_deposit_credited'
                    | 'cash_adjustment'
                    | 'opening_balance'
                    | 'legacy_disbursement'
                    | 'reversal'
                    | 'authorized_adjustment';
                export type LedgerAccount =
                    | 'CASH_ON_HAND'
                    | 'CHEQUES_IN_CUSTODY'
                    | 'CASH_IN_TRANSIT'
                    | 'BANK_ACCOUNT'
                    | 'UNASSIGNED_FUNDS'
                    | 'BENEFICIARY_FUNDS'
                    | 'LEGACY_FUNDS'
                    | 'CASH_DIFFERENCE';
                export type PaymentMedium = 'cash' | 'cheque' | 'bank';
                export type PeriodClosingStatus =
                    'draft' | 'closed' | 'reopened';
                export type PeriodType = 'daily' | 'monthly';
                export type ResidualStatus = 'open' | 'acknowledged_excess';
            }
        }
        namespace Shared {
            namespace Data {
                export type AccessEventData = {
                    id: number;
                    type: App.Modules.Shared.Enums.LoginEventType;
                    label: string;
                    userId: number | null;
                    userName: string | null;
                    usernameAttempted: string | null;
                    failureReason: string | null;
                    deviceLabel: string | null;
                    ipAddress: string | null;
                    suspicious: boolean;
                    occurredAt: string;
                };
                export type ActiveSessionData = {
                    id: string;
                    userName: string | null;
                    deviceLabel: string | null;
                    ipAddress: string | null;
                    lastActivityAt: string;
                    isCurrent: boolean;
                };
                export type AuditChangeData = {
                    field: string;
                    before: string | null;
                    after: string | null;
                };
                export type CashBoxSummaryData = {
                    code: string;
                    name: string;
                    count: number | null;
                    share: number | null;
                    isCurrentPhase: boolean;
                };
                export type LastChangeData = {
                    at: string;
                    by: string | null;
                };
                export type OperationAuditEventData = {
                    id: number;
                    occurredAt: string;
                    userName: string | null;
                    action: string;
                    label: string;
                    critical: boolean;
                    category: string;
                    subjectType: string;
                    subjectId: number;
                    subjectLabel: string;
                    subjectDescription: string;
                    subjectUrl: string | null;
                    changes: App.Modules.Shared.Data.AuditChangeData[];
                    ipAddress: string | null;
                };
                export type PermissionGroupData = {
                    key: string;
                    label: string;
                    permissions: string[];
                };
                export type QueueSampleData = {
                    label: string;
                    detail: string | null;
                    href: string;
                };
                export type RecentAccessData = {
                    id: number;
                    type: App.Modules.Shared.Enums.LoginEventType;
                    label: string;
                    deviceLabel: string | null;
                    ipAddress: string | null;
                    occurredAt: string;
                };
                export type RoleData = {
                    id: number;
                    name: string;
                    description: string;
                    permissions: string[];
                    editable: boolean;
                    usersCount: number;
                };
                export type UserListItemData = {
                    id: number;
                    name: string;
                    firstName: string;
                    lastName: string;
                    username: string;
                    documentNumber: string;
                    email: string | null;
                    position: string | null;
                    role: string | null;
                    isActive: boolean;
                    mustChangePassword: boolean;
                    lastLoginAt: string | null;
                };
                export type WorkQueueData = {
                    key: string;
                    title: string;
                    description: string;
                    count: number | null;
                    tone: App.Modules.Shared.Enums.QueueTone;
                    pendingModule: string | null;
                    href: string | null;
                    samples: App.Modules.Shared.Data.QueueSampleData[];
                    hasMore: boolean;
                };
            }
            namespace Enums {
                export type AttachmentSource =
                    'generated' | 'uploaded' | 'scanned';
                export type AttachmentSubject =
                    | 'expediente'
                    | 'import'
                    | 'receipt'
                    | 'payment_order'
                    | 'pase'
                    | 'disbursement'
                    | 'cash_transfer'
                    | 'deposit_ticket'
                    | 'period_closing';
                export type AuditSeverity = 'normal' | 'critical';
                export type Confidentiality = 'internal' | 'restricted';
                export type DocumentType =
                    'receipt_income' | 'receipt_expense' | 'payment_order';
                export type LoginEventType =
                    | 'login_success'
                    | 'login_failed'
                    | 'logout'
                    | 'password_reset'
                    | 'password_changed'
                    | 'two_factor_challenged'
                    | 'two_factor_failed'
                    | 'session_revoked'
                    | 'lockout';
                export type LoginFailureReason =
                    | 'invalid_credentials'
                    | 'account_disabled'
                    | 'unknown_username'
                    | 'too_many_attempts'
                    | 'invalid_two_factor_code';
                export type QueueTone =
                    'neutral' | 'action' | 'blocked' | 'done';
                export type ReceiptIssueMode = 'online' | 'offline_talonario';
                export type ReceiptStatus =
                    'issued' | 'voided' | 'spoiled' | 'replaced';
                export type ReceiptType = 'income' | 'expense';
                export type SeriesOrigin = 'system' | 'talonario_loaded';
                export type SystemRole =
                    | 'super-admin'
                    | 'administrador'
                    | 'contador'
                    | 'administrativo'
                    | 'consulta';
            }
        }
    }
}
