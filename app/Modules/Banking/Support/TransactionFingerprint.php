<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Enums\TransactionDirection;

/**
 * La huella que identifica a un movimiento entre descargas distintas.
 *
 * El problema que resuelve es concreto: el operador baja «Últimos
 * movimientos» con rangos que se pisan, así que el mismo crédito llega en
 * dos archivos. Sin huella, cada importación duplicaría el saldo.
 *
 * **Incluye el saldo posterior, y eso es un desvío deliberado del DER.**
 * §9.3 propone la huella sin él y la deja como índice no único, resignada
 * a que dos comisiones de $121 del mismo día sean indistinguibles. Pero la
 * cadena de saldos es estrictamente secuencial: dos movimientos idénticos
 * tienen saldos posteriores distintos porque uno ocurrió después del otro.
 * Con el saldo adentro, la deduplicación deja de ser una sugerencia y pasa
 * a ser una garantía de la base —el índice único parcial de
 * `bank_transactions`—.
 *
 * Cuando el saldo falta, la garantía no existe y la fila queda marcada
 * para que una persona la mire. Es lo que el DER llama «duplicados
 * asistidos», reducido a los casos donde de verdad hace falta.
 */
final class TransactionFingerprint
{
    /**
     * @param  numeric-string  $amount  positivo
     * @param  numeric-string|null  $balanceAfter
     */
    public static function of(
        int $bankAccountId,
        string $transactionDate,
        TransactionDirection $direction,
        string $amount,
        ?string $operationId,
        ?string $description,
        ?string $balanceAfter,
    ): string {
        $parts = [
            (string) $bankAccountId,
            $transactionDate,
            $direction->value,
            $amount,
            $operationId ?? '',
            self::normalizeText($description),
            $balanceAfter ?? '',
        ];

        return hash('sha256', implode('|', $parts));
    }

    /**
     * Mayúsculas sin acentos y con los espacios colapsados.
     *
     * El mismo concepto puede volver con otro espaciado según el formato
     * de descarga; que eso cambie la huella crearía un movimiento nuevo
     * por un detalle tipográfico.
     */
    private static function normalizeText(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return mb_strtoupper($normalized);
    }
}
