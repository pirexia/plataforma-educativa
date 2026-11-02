<?php

/*
 * REQ-FAM-UNIT/api.md §2.5 y §7 (issue #470): configuración del módulo `fam_unit`.
 *
 * `revocation.rate_limit`: límite de las dos únicas acciones con consecuencias legales del módulo, revocar y levantar la
 * revocación de un vínculo (`POST` y `DELETE /guardian-links/{id}/revocation`). Por centro **y** usuario, con un cubo
 * propio cada acción, contando todos los intentos (también los que acaban en 404 o 409)
 * para que no sirvan de sondeo. Las lecturas y las demás escrituras no tienen límite propio; el limitador global de la
 * API es un paso transversal aparte.
 */
return [

    // 1.14-b (RN-FAM-UNIT-76, decisión del usuario del 2026-10-27): mismo patrón para registrar decisiones de imagen y pedir
    // el informe de consentimientos. Por centro y usuario, un cubo por acción, contando todos los intentos.
    'consent' => [
        'rate_limit' => [
            'decision' => ['attempts' => 60, 'decay_seconds' => 3600],
            'export' => ['attempts' => 10, 'decay_seconds' => 3600],
        ],
    ],

    // 1.14b-a (REQ-FAM-UNIT/operacion.md §12.1, `RN-FAM-UNIT-83`, `-93`, `ADR-068 §5.3`): fotografía de recogida y lectura de la
    // lista. Valores por defecto [DERIVADA] revisables; sin variable de entorno nueva.
    //
    // - `photo.max_bytes`: 5 MB. `photo.max_dimension`: 1024 px de lado mayor tras reprocesar. `photo.max_input_pixels`: píxeles
    //   máximos de **entrada** (12 MP, una foto de móvil de 4000 x 3000); se comprueba antes de decodificar, contra las bombas de
    //   descompresión, y acota la memoria de `gd`. `photo.signed_url_ttl_minutes`: caducidad de la URL firmada, 5 minutos
    //   (no `core.signed_url_ttl_minutes`). `photo.orphan_grace_days`: plazo de gracia del barrido de huérfanos, 30
    //   (`OPEN-FAM-UNIT-37`).
    // - `read_rate_limit`: lecturas de la lista y de fotografías por centro y usuario (300 por hora), `RN-FAM-UNIT-93`.
    'pickup' => [
        'photo' => [
            'max_bytes' => 5 * 1024 * 1024,
            'max_dimension' => 1024,
            'max_input_pixels' => 12_000_000,
            'signed_url_ttl_minutes' => 5,
            'orphan_grace_days' => 30,
        ],
        'read_rate_limit' => [
            'attempts' => 300,
            'decay_seconds' => 3600,
        ],
        // RN-FAM-UNIT-92 enmendada (decisión del usuario del 2026-11-02): búsqueda por documento y alta con reutilización.
        'person_rate_limit' => [
            'lookup' => ['attempts' => 60, 'decay_seconds' => 3600],
            'register' => ['attempts' => 60, 'decay_seconds' => 3600],
        ],
    ],

    'revocation' => [
        'rate_limit' => [
            'attempts' => 30,
            'decay_seconds' => 3600,
        ],
    ],

];
