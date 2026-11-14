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
/*
 * `lock_timeout_ms` (`1.14b-d1`, `RN-PRL-16`, `OPEN-PRL-04` A): espera máxima de bloqueo (`SET LOCAL lock_timeout`) en la transacción de
 * `POST /student-movements` y `POST /pickup-alerts` [DERIVADA]. Superado, `503 prl.errors.busy` con `Retry-After: 1`. Ante
 * interbloqueo o fallo de serialización se reintenta la transacción completa hasta 3 intentos en total (fijo en código). Sin
 * variable de entorno.
 */
return [
    'lock_timeout_ms' => 3000,
    'read_rate_limit' => ['attempts' => 300, 'decay_seconds' => 3600],
];
