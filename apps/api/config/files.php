<?php

/*
 * ADR-068 §5.3, REQ-FAM-UNIT/operacion.md §12.1 (1.14b-a): configuración de la primitiva de ficheros privados
 * `App\Support\Files`. ADR-076 §4: `FILES_SIGNED_URL_MAX_TTL_MINUTES` (tope de caducidad de las URL firmadas).
 *
 * `disk`: disco privado donde `PrivateObjectStore` escribe y firma. `null` ⇒ `filesystems.default` (S3; MinIO en
 * desarrollo). Clave compartida con cualquier fichero privado futuro, no propia de un módulo.
 */
return [

    'disk' => null,

    // ADR-076 §4: tope global de caducidad de URL firmadas, en minutos. Manda sobre cualquier TTL por caso de uso.
    'signed_url_max_ttl_minutes' => (int) env('FILES_SIGNED_URL_MAX_TTL_MINUTES', 15),

];
