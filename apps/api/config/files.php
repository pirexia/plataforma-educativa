<?php

/*
 * ADR-068 §5.3, REQ-FAM-UNIT/operacion.md §12.1 (1.14b-a): configuración de la primitiva de ficheros privados
 * `App\Support\Files`. Sin variable de entorno nueva.
 *
 * `disk`: disco privado donde `PrivateObjectStore` escribe y firma. `null` ⇒ `filesystems.default` (S3; MinIO en
 * desarrollo). Clave compartida con cualquier fichero privado futuro, no propia de un módulo.
 */
return [

    'disk' => null,

];
