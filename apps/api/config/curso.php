<?php

/*
 * REQ-CURSO/funcional.md `RN-CURSO-86` (1.12b): configuración del módulo `curso`.
 *
 * `rollover.allow_nested_transaction` solo se activa en el entorno de pruebas, nunca en
 * producción: deja que `RepeatableReadTransaction` se ejecute dentro de la transacción
 * que envuelve cada test de `tests/Feature/` (en un punto de guardado, con el
 * aislamiento heredado y sin reintentos). La semántica de `REPEATABLE READ` solo la
 * prueban los tests de `tests/Concurrency/`, que no envuelven en transacción
 * (`CA-CURSO-132`, `-158`). Sin variable de entorno: no se puede activar por
 * descuido en un despliegue.
 */
return [

    'rollover' => [
        'allow_nested_transaction' => false,

        // RN-CURSO-85: minutos desde el último cambio tras los que una copia en
        // `pendiente`, `en_curso` o `revirtiendo` se considera huérfana y se puede
        // cancelar (aceptado por el usuario el 2026-10-21). Mayor que `retry_after`
        // (900 s) y que el `timeout` (600 s) de los trabajos.
        'cancel_after_minutes' => 30,

        // Límite por centro de `POST /academic-year-rollovers` y de `POST …/revert` (cubo propio cada uno): cada solicitud
        // toma `FOR SHARE` sobre el curso destino y encola un trabajo de minutos, así que un bucle de solicitudes
        // ocuparía el único `worker-long`. Cuenta todos los intentos, también los rechazados. Con más workers de la cola
        // `long`, se sube aquí (el límite protege la cola, no la base de datos).
        'rate_limit' => [
            'attempts' => 6,
            'decay_seconds' => 3600,
        ],
    ],

];
