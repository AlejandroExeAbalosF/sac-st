<?php

declare(strict_types=1);

return [
    // Preparado y probado, pero pendiente de definición del área. No habilitar
    // sin resolver la rectificación de «nunca acreditado» y revisar la interfaz.
    'cheque_bank_followup_enabled' => false,

    // La importación de la planilla histórica (Etapa 9): construida y probada,
    // en pausa hasta retomarla (decisión del usuario, 11/11/2026). Apagada, sus
    // rutas responden 404, el botón no aparece y las Actions de entrada la rechazan.
    'historical_import_enabled' => false,

    /*
     * Lo que la rendición mensual imprime tal como lo escribe el área en sus
     * notas a la SAF (fotos de la rendición de septiembre de 2026). La cuenta
     * ya no está acá: es la de cada fila, citada como dice su cuenta bancaria
     * («Cómo se cita en los documentos»), y queda guardada al emitir.
     */
    'rendicion' => [
        'lugar' => 'Contable',
        'destinatario' => ['Administrador General - SAF', 'Ministerio de Gobierno y Justicia'],
    ],
];
