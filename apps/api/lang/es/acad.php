<?php

// REQ-ACAD (paso 1.11-b), permisos.md §8. INV-009: ningún literal visible
// vive fuera de aquí. `stage` y `regime` viajan como código técnico
// (RN-ACAD-12) y la SPA traduce su etiqueta. Los mensajes de error de
// api.md §6 llegan con los endpoints.

return [
    'permissions' => [
        'resources' => [
            'estructura_academica' => 'Estructura académica',
        ],
    ],

    'stage' => [
        'infantil' => 'Infantil',
        'primaria' => 'Primaria',
        'eso' => 'ESO',
        'bachillerato' => 'Bachillerato',
        'fp' => 'Formación Profesional',
        'universidad' => 'Universidad',
    ],

    'regime' => [
        'publico' => 'Público',
        'privado' => 'Privado',
        'concertado' => 'Concertado',
    ],
];
