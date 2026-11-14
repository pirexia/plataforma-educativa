---
name: colas-y-workers
description: Reglas de colas, jobs y workers (INV-012). Úsala al escribir o cambiar un Job (ShouldQueue), un listener asíncrono, un comando programado, una exportación, un PDF, un correo o notificación, un onQueue, config/queue.php, worker.container o el perfil worker de compose.yaml, y al desplegar código que afecta a trabajos encolados, aunque el usuario no lo mencione.
---

# Colas y workers

`INV-012`: las tareas pesadas van a la cola, nunca en la petición HTTP (PDF, exportaciones, correos, copias masivas). Un job se ejecuta en otro proceso, más tarde, con código posiblemente distinto y sin petición: nada de lo que daba la petición está disponible.

## La realidad actual (CLAUDE.md §1, fila «Colas»)

- **Driver `database`** (`QUEUE_CONNECTION=database`, tablas `jobs` y `failed_jobs`). Redis se usa como caché, **no** como cola.
- **Horizon NO está instalado** (ni en `composer.json` ni `config/horizon.php`). Adoptarlo es una dependencia nueva sujeta a justificación y a `OPEN-BO-23`. Antes de apoyarte en cualquier pieza, comprueba `composer.json` (`docs/proceso/casos-reales.md#horizon-no-instalado`).
- **Dos procesos**:

| Proceso | Conexión y cola | Parámetros | Definición |
|---------|-----------------|------------|-----------|
| `worker` | `database`, colas `auth-mail,core-mail,bo-mail,default,auth-maintenance,core-maintenance,backoffice-maintenance,core-exports,core-imports` (el orden es la prioridad) | `--tries=3 --backoff=30 --timeout=120`, `retry_after` 180 | `infra/quadlet/worker.container`, servicio `worker` de `compose.yaml` |
| `worker-long` | `database_long`, cola `long` | `--tries=1 --timeout=600`, `retry_after` 900 | `infra/quadlet/worker-long.container`, servicio `worker-long` |

- El `retry_after` es **de la conexión** con la que se lee la cola, no de la cola. Por eso lo que dura minutos (copia y reversión del rollover del curso, `REQ-CURSO 1.12b`, `RN-CURSO-84`) va a `long`: en `worker` se reentregaría a los 3 minutos mientras sigue ejecutándose. Regla: `timeout` del job < `retry_after` de la conexión, con margen.
- **Toda cola con `->onQueue()` debe estar en un worker.** Lo comprueba `apps/api/tests/Feature/Core/QueueWorkerTest.php`; una cola nueva exige tocar `worker.container` **y** `compose.yaml`.
- En desarrollo los workers están en el perfil `worker` (`podman compose --profile worker up -d worker`); no arrancan con el perfil reducido. Si un correo «no sale» en local, comprueba primero que el worker corre.

## Reglas para escribir un job

1. **El tenant va en el payload** (`public readonly int $tenantId`) y se aplica al empezar `handle()`: `$tenantContext->applyToConnection(...)`. Sin petición no hay centro en curso. Si el job usa `pgsql_owner`, la RLS con `FORCE` también le aplica: sin propagar el tenant a esa conexión, el `DELETE` corre sin error y sin borrar nada (`Auth/Infrastructure/Jobs/PurgeLoginAttempts.php`). Skill `aislamiento-tenant`.
2. **El idioma del destinatario va en el payload** (`recipientLocale`) y se aplica al enviar (`Mail::to(...)->locale(...)`): el worker no tiene el `Accept-Language` de nadie. Skill `i18n-cuatro-idiomas`.
3. **Pasa IDs y valores simples, no modelos completos ni objetos que cambian de forma.** Es lo que sobrevive a un despliegue.
4. **Idempotente**: la cola entrega al menos una vez. Reejecutar el job no debe duplicar correos, cobros ni filas (condición de estado, clave única, transición condicional como en `failed()` de `RunAcademicYearRollover`). Si no puede serlo, `tries = 1` y salida explícita: nunca `queue:retry` ciego sobre `failed_jobs`.
5. **Define `failed()`** cuando el estado de negocio queda a medias, para llevarlo a un estado terminal visible.
6. **Secretos en el payload**: `ShouldBeEncrypted`. Un token de activación en `jobs` o `failed_jobs` queda en claro (issues #73 y #75; `SendInvitationEmail`, `tests/Feature/Core/QueuedMailEncryptionTest.php`).
7. **Exportaciones**: permiso, auditoría con detalle, cola `core-exports` y enlace caducable (`ExportJobLifecycle`, `FamUnit/Infrastructure/Jobs/GenerateImageConsentsExport.php`). Skills `permisos-y-roles`, `auditoria`.
8. **Entorno mínimo**: `worker-long` no recibe `DB_OWNER_*` ni `DB_PLATFORM_*` porque ningún trabajo `long` los usa; un job nuevo que los necesite va a `worker`, o se justifica el cambio de entorno con revisión de seguridad.

## Despliegue (CLAUDE.md §9)

- **Compatibilidad hacia atrás**: los workers deben procesar trabajos encolados por la versión anterior. El payload se serializa por nombre de clase: **no renombres ni muevas una clase de job, ni cambies el constructor, en la misma entrega** que deja de usarse; expand/contract (skill `migracion-segura`).
- **Reiniciar workers tras desplegar**: cargan el código al arrancar y no reconectan tras reiniciar PostgreSQL (skill `depuracion`). `--max-time=3600` hace que terminen solos cada hora y `Restart=always` los relance, pero no sustituye al reinicio tras un despliegue.
- Dependencias entre unidades: `Wants=` + `After=`, nunca `Requires=`/`BindsTo=` (skill `contenedores-y-red`, `ADR-028`).

## Antes de cerrar

- [ ] ¿Va a cola y no en la petición? ¿Cola presente en ambos ficheros de worker y `QueueWorkerTest` en verde?
- [ ] ¿`timeout` < `retry_after`? ¿Si dura minutos, cola `long`?
- [ ] ¿Tenant e idioma en el payload? ¿Idempotente? ¿`failed()`?
- [ ] ¿Secretos cifrados? ¿Sigue entendiendo el worker un payload de la versión anterior?
- [ ] ¿Test del job con tenant, reintento y fallo?
