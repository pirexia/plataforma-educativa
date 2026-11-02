# REQ-PRL · Puerta y entrega de menores · Operación

> **Origen del contenido.** Traslado **sin cambios de contenido** de lo marcado `prl` en `docs/modulos/REQ-FAM-UNIT/` (especificación del paso `1.14b`, aprobada el 2026-10-29), tal como exige `ADR-068 §5.1`. La numeración de IDs (`RN-PRL-*`, `CA-PRL-*`, `OPEN-*`, secciones `§21.x`/`§8.x`) se conserva tal cual para que las referencias cruzadas existentes sigan siendo válidas. Las referencias a `§N` sin más indican la sección de `REQ-FAM-UNIT/funcional.md`, `datos.md`, `api.md`, `permisos.md` u `operacion.md` del fichero homónimo de ese módulo.

## 12.1 Configuración

El texto del protocolo ante persona no autorizada es **configuración del centro** (`settings` del módulo `prl`, `modulo.actualizar`, `ADR-045`), no del despliegue (`OPEN-FAM-UNIT-40`).

## 12.4 Despliegue: orden, siembra y reversión

Véase `REQ-FAM-UNIT/operacion.md §12.4` (orden común: migrar, `sync-registry`, contratar `prl`, siembra, publicar el texto `autorizacion_recogida`). Reversión: `student_movements` y `pickup_alerts` son evidencia; sus `down()` fallan si hay filas.

## 12.5 Métricas, alertas y diagnóstico

Señales de `prl`: alertas `persona_no_autorizada` por día y errores `422 person_not_authorized` (posible intento de recogida indebida o lista desactualizada). Véase `REQ-FAM-UNIT/operacion.md §12.5`.
