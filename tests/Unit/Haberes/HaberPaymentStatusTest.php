<?php

declare(strict_types=1);

use App\Modules\Haberes\Enums\HaberPaymentStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus as Cuota;

/*
|--------------------------------------------------------------------------
| En qué va el pago de un haber
|--------------------------------------------------------------------------
|
| Se deriva de las cuotas y se compara por importe, no por cantidad: la
| cantidad prevista puede faltar, y entonces «1 de 1 pagadas» daría por
| completo un haber al que todavía le faltan cuotas por cargar.
|
*/

/**
 * @param  list<array{0: Cuota, 1: numeric-string}>  $cuotas
 */
function situacion(string $reconocido, array $cuotas): HaberPaymentStatus
{
    /** @var numeric-string $reconocido */
    return HaberPaymentStatus::fromInstallments(
        $reconocido,
        array_map(fn (array $cuota): array => ['status' => $cuota[0], 'amount' => $cuota[1]], $cuotas),
    );
}

test('sin cuotas cargadas, el plan está incompleto', function (): void {
    expect(situacion('400000.00', []))->toBe(HaberPaymentStatus::IncompletePlan);
});

test('con el plan cubierto y nada pagado, está sin pagos', function (): void {
    expect(situacion('400000.00', [[Cuota::Active, '200000.00'], [Cuota::Active, '200000.00']]))
        ->toBe(HaberPaymentStatus::Unpaid);
});

test('si las cuotas no cubren el importe, el plan está incompleto', function (): void {
    expect(situacion('400000.00', [[Cuota::Active, '100000.00']]))
        ->toBe(HaberPaymentStatus::IncompletePlan);
});

test('una cuota pagada sin cubrir el importe es pago parcial', function (): void {
    expect(situacion('400000.00', [[Cuota::Paid, '200000.00'], [Cuota::Active, '200000.00']]))
        ->toBe(HaberPaymentStatus::PartiallyPaid);
});

test('todas las cuotas cargadas pagadas no alcanzan si falta plan', function (): void {
    // El caso que «1 de 1 pagadas» daría por completo.
    expect(situacion('400000.00', [[Cuota::Paid, '100000.00']]))
        ->toBe(HaberPaymentStatus::PartiallyPaid);
});

test('lo pagado que cubre el importe es pago completado', function (): void {
    expect(situacion('400000.00', [[Cuota::Paid, '400000.00']]))
        ->toBe(HaberPaymentStatus::FullyPaid);
});

test('lo pagado fuera del circuito cuenta como pagado', function (): void {
    expect(situacion('400000.00', [[Cuota::LegacySettled, '150000.00'], [Cuota::Paid, '250000.00']]))
        ->toBe(HaberPaymentStatus::FullyPaid);
});

test('una cuota anulada no cuenta para el plan ni para lo pagado', function (): void {
    expect(situacion('400000.00', [[Cuota::Cancelled, '400000.00']]))
        ->toBe(HaberPaymentStatus::IncompletePlan)
        ->and(situacion('400000.00', [[Cuota::Cancelled, '100000.00'], [Cuota::Paid, '400000.00']]))
        ->toBe(HaberPaymentStatus::FullyPaid);
});

test('una cuota bloqueada o suspendida sigue siendo plan sin pagar', function (): void {
    expect(situacion('400000.00', [[Cuota::Blocked, '200000.00'], [Cuota::Suspended, '200000.00']]))
        ->toBe(HaberPaymentStatus::Unpaid);
});

test('compara centavos sin redondear', function (): void {
    expect(situacion('5790.06', [[Cuota::Paid, '2895.03'], [Cuota::Paid, '2895.02']]))
        ->toBe(HaberPaymentStatus::PartiallyPaid)
        ->and(situacion('5790.06', [[Cuota::Paid, '2895.03'], [Cuota::Paid, '2895.03']]))
        ->toBe(HaberPaymentStatus::FullyPaid);
});
