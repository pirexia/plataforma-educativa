<?php

/*
 * REQ-ALUM/api.md §6 (1.15-b, decisión del usuario del 2026-11-11): configuración del módulo `alum`.
 *
 * `export.rate_limit`: límite de pedir la exportación de matrículas (`POST /enrollments/exports`), con el patrón de
 * `fam_unit.consent.rate_limit` (`ConsentRateLimit`). Por centro y usuario, contando todos los intentos (también los
 * rechazados por validación) para que no sirvan de sondeo. Al agotarse: `429 too-many-requests` con `Retry-After`.
 * Valor por defecto [DERIVADA]: el mismo que el informe de consentimientos de imagen, revisable.
 */
return [
    'export' => [
        'rate_limit' => ['attempts' => 10, 'decay_seconds' => 3600],
    ],
];
