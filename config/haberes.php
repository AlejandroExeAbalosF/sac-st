<?php

declare(strict_types=1);

return [
    // Conciliar un comprobante de depósito con varios créditos del extracto
    // que sumen su importe: construido y probado, en pausa (decisión del
    // usuario, 12/11/2026). Apagado, el comprobante se concilia con un solo
    // crédito por su importe entero: la pantalla no ofrece la selección
    // múltiple y `LinkDepositTicket` la rechaza. La tabla
    // `deposit_ticket_additional_transactions` y su trigger quedan como están.
    'split_deposit_match_enabled' => false,
];
