<?php

// REQ-ACAD (paso 1.11-b), permisos.md §8. INV-009: ningún literal visible
// vive fuera de aquí. `stage` y `regime` viajan como código técnico
// (RN-ACAD-12) y la SPA traduce su etiqueta. Los mensajes de error de
// api.md §6 llegan con los endpoints.

return [
    'permissions' => [
        'resources' => [
            'estructura_academica' => 'Structure académique',
        ],
    ],

    'stage' => [
        'infantil' => 'Petite enfance',
        'primaria' => 'Primaire',
        'eso' => 'Collège (ESO)',
        'bachillerato' => 'Lycée (Bachillerato)',
        'fp' => 'Formation professionnelle',
        'universidad' => 'Université',
    ],

    'regime' => [
        'publico' => 'Public',
        'privado' => 'Privé',
        'concertado' => 'Sous contrat',
    ],
];
