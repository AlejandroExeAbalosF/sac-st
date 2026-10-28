<?php

declare(strict_types=1);

return [
    // Preparado y probado, pero pendiente de definición del área. No habilitar
    // sin resolver la rectificación de «nunca acreditado» y revisar la interfaz.
    'cheque_bank_followup_enabled' => false,

    /*
     * Lo que la rendición mensual imprime tal como lo escribe el área en sus
     * notas a la SAF (fotos de la rendición de septiembre de 2026). El número
     * de cuenta es el de esas notas —«452680-1»— y no coincide con el del
     * recibo: queda como texto para confirmarlo con el área.
     */
    'rendicion' => [
        'lugar' => 'Contable',
        'destinatario' => ['Administrador General - SAF', 'Ministerio de Gobierno y Justicia'],
        'cuenta' => 'Nº 452680-1 del Bco. Macro',
    ],
];
