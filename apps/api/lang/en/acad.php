<?php

// REQ-ACAD (paso 1.11-b), permisos.md §8. INV-009: ningún literal visible
// vive fuera de aquí. `stage` y `regime` viajan como código técnico
// (RN-ACAD-12) y la SPA traduce su etiqueta. Los mensajes de error de
// api.md §6 llegan con los endpoints.

return [
    'permissions' => [
        'resources' => [
            'estructura_academica' => 'Academic structure',
        ],
    ],

    'stage' => [
        'infantil' => 'Early childhood',
        'primaria' => 'Primary',
        'eso' => 'Lower secondary (ESO)',
        'bachillerato' => 'Upper secondary (Bachillerato)',
        'fp' => 'Vocational training',
        'universidad' => 'University',
    ],

    'regime' => [
        'publico' => 'Public',
        'privado' => 'Private',
        'concertado' => 'Publicly subsidised',
    ],
];
