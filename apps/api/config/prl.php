<?php

/*
 * REQ-PRL/operacion.md §12.1 (1.14b-b, `RN-PRL-10` enmendada, decisión del usuario del 2026-11-02): configuración del módulo `prl`.
 *
 * `read_rate_limit`: las lecturas de `prl` que devuelven datos de personas —el teléfono del tutor principal y del contacto de
 * emergencia de una alerta abierta (`POST /pickup-alerts`, `GET` y `PATCH /pickup-alerts/{id}` mientras está abierta) y el
 * nombre de quien recogió en `GET /student-movements`— consumen un cupo por centro y usuario, 300 por hora [DERIVADA, mismo
 * valor que `fam_unit.pickup.read_rate_limit`], contando todos los intentos; superado, `429` con `Retry-After`. Cubo propio de
 * `prl`, distinto del de la lista de puerta de `fam_unit`. Sin variable de entorno nueva.
 */
return [
    'read_rate_limit' => ['attempts' => 300, 'decay_seconds' => 3600],
];
