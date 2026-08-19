<?php

// Rutas de REQ-CORE (paso 1.1), documentadas en
// apps/api/openapi/paths/core.yaml. Se registran aquí, no en
// routes/api-v1.php directamente, para que el módulo sea autocontenido
// (INV-007). Requerido desde routes/api-v1.php, ya dentro del grupo
// prefix('v1')->middleware(['resolve-tenant', 'resolve-locale']).
//
// Se va ampliando subpaso a subpaso conforme se implementan los
// controladores (ver docs/modulos/REQ-CORE/api.md).
