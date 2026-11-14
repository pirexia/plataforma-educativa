<?php

/*
 * REQ-ALUM/api.md §6 (1.15-b, decisión del usuario del 2026-11-11): configuración del módulo `alum`.
 *
 * `export.rate_limit`: límite de pedir la exportación de matrículas (`POST /enrollments/exports`), con el patrón de
 * `fam_unit.consent.rate_limit` (`ConsentRateLimit`). Por centro y usuario, contando todos los intentos (también los
 * rechazados por validación) para que no sirvan de sondeo. Al agotarse: `429 too-many-requests` con `Retry-After`.
 * Valor por defecto [DERIVADA]: el mismo que el informe de consentimientos de imagen, revisable.
 *
 * `photo` (1.15-c T2b, `RN-ALUM-51`): fotografía del alumno, con los mismos valores que `fam_unit.pickup.photo` (no se
 * lee esa clave: `INV-007`). `max_bytes` 5 MB; `max_dimension` 1024 px de lado mayor tras reprocesar; `max_input_pixels`
 * 12 MP de **entrada** (se comprueba antes de decodificar, contra las bombas de descompresión); `signed_url_ttl_minutes`
 * caducidad de la URL firmada (5 min); `read_rate_limit` lecturas de `GET /students/{id}/photo` por centro y usuario
 * (300 por hora, patrón de `fam_unit.pickup.read_rate_limit`) [DERIVADA], revisables.
 */
return [
    'export' => [
        'rate_limit' => ['attempts' => 10, 'decay_seconds' => 3600],
    ],
    'photo' => [
        'max_bytes' => 5 * 1024 * 1024,
        'max_dimension' => 1024,
        'max_input_pixels' => 12_000_000,
        'signed_url_ttl_minutes' => 5,
        'read_rate_limit' => ['attempts' => 300, 'decay_seconds' => 3600],
    ],
];
