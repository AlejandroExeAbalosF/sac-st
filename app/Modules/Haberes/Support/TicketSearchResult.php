<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

/**
 * El resultado de buscar el movimiento de un ticket.
 *
 * **La parte que más valor agrega es la lista vacía**, porque «no
 * encontré nada» son en realidad dos situaciones que sin esto se verían
 * iguales:
 *
 * - El extracto de esa fecha **todavía no se importó**. No hay nada que
 *   buscar: hay que traer el archivo del banco.
 * - El período está importado y aun así el crédito no aparece. Ahí sí hay
 *   algo que revisar: el depósito no llegó, o el ticket tiene un dato mal.
 *
 * La diferencia entre «esperá» y «revisá» es exactamente lo que hoy la
 * contadora resuelve abriendo el homebanking, y se calcula con los
 * períodos que ya guarda cada importación.
 */
final class TicketSearchResult
{
    /**
     * @param  list<TicketCandidate>  $candidates
     * @param  list<string>  $warnings  incoherencias que no impiden vincular
     */
    public function __construct(
        public readonly array $candidates,
        public readonly bool $periodImported,
        public readonly array $warnings = [],
    ) {}

    public function hasCandidates(): bool
    {
        return $this->candidates !== [];
    }

    /**
     * Qué decirle a quien mira una lista vacía.
     */
    public function emptyReason(): ?string
    {
        if ($this->hasCandidates()) {
            return null;
        }

        return $this->periodImported
            ? 'El período está importado y no aparece ningún crédito por ese importe. '
                .'Revisá los datos del ticket, o esperá a que el banco lo acredite.'
            : 'Todavía no hay extractos importados que cubran esa fecha. '
                .'Importá el período para poder buscarlo.';
    }
}
