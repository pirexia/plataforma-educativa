---
name: auditoria
description: Reglas de auditoría de INV-003 y de lectura de datos sensibles. Úsala al crear o cambiar un endpoint que cree, modifique, borre o LEA datos de personas o de categoría especial, una exportación, un modelo o una tabla nueva (audit_logs, AuditRecorder, Auditable, RecordsAuditTrail, tablas de solo anexar), aunque el usuario no pida auditoría explícitamente.
---

# Auditoría

`INV-003`: toda creación, modificación y borrado se audita. Además, la **lectura** de datos de categoría especial y de datos delicados de menores o de terceros (CLAUDE.md §8, `RPERM-012`, `RPERM-015`). Sin rastro no hay forma de responder ante un centro ni ante la AEPD de quién vio qué.

## Cómo se audita de verdad

Infraestructura transversal en `apps/api/app/Support/Audit/` (`AuditServiceProvider`, `ADR-035`). No es un módulo de negocio: se usa desde cualquier módulo (`INV-007`).

- **Escritura automática**: el modelo implementa `Auditable` (`auditValuePolicy()`, `auditRecordedAttributes()`, `auditSecretAttributes()`, `auditExcludedEvents()`) y usa `RecordsAuditTrail` + `HasAuditableAttributes`. `auditValuePolicy()` **no tiene valor por defecto a propósito**: un modelo que no declara qué valores se registran no se registra en el *morph map* y rompe el test de arquitectura (`CoreModelsTest`).
- **Escritura explícita**: `AuditRecorder::record($model, $event, $changes, $context)`.
- **Lectura**: `AuditRecorder::recordRead($alias, $id, $publicId, $context)` deja una fila `read` **sin valores**. Ejemplos reales: `Modules/Prl/Application/PrlReadGuard.php` y `Modules/FamUnit/Application/PickupListReader.php`, `PickupPersonService.php`, `PickupPhotoService.php`.
- **Fila antes que datos**: la fila `read` se escribe **antes** de devolver; si falla, no sale ningún dato (`REQ-PRL funcional.md RN-PRL-10`, `D-66` de `REQ-FAM-UNIT`). Va acompañada de un cupo de lecturas por centro y usuario (`429` con `Retry-After`, `config('prl.read_rate_limit')`) para que el rastro no sirva de sondeo.
- No se llama sin centro en curso; en modo plataforma con propósito `Mantenimiento` lanza excepción: el rastro de backoffice es `admin_action_logs`, no `audit_logs` (`ADR-046 §6.5`).

## Qué se registra y qué NUNCA (`ADR-035`)

`audit_logs` es inmutable y no se «suprime»: se evita que entre lo que reidentifica al sujeto y se purga la fila entera al vencer la retención (`ADR-035 §1`).

- `context` de una lectura: finalidad (`purpose`), recuentos y códigos; **nunca** nombres, documentos, teléfonos ni claves de objeto. `recordRead()` lo valida y lanza `InvalidArgumentException` si no cumple (`ADR-035 §5`).
- Secretos: se redactan por patrón global (`config/audit.php`: `*password*`, `*token*`, `*secret*`, `*_key`, `*totp*`, `*recovery_code*`) aunque el modelo no los declare. Un valor mayor que `max_value_length` se redacta como `oversized`, nunca se trunca.
- Identificadores personales (nombre, documento, teléfono, nacimiento): según la política del modelo, no se serializan valores. El `created` es el caso grande: cambian todos los atributos.
- Datos de categoría especial: tabla separada, cifrada, permiso propio y auditoría de lectura; el detalle de la fila no lleva el contenido.
- Un evento auditado por otro camino con su propio `event` puede excluirse con `auditExcludedEvents()` solo con la justificación de `ADR-040 §4`.

## Tablas de solo anexar

Registros que no deben poder corregirse ni borrarse (movimientos de puerta, reaperturas, mfa_resets): migración con `tenantTableAppendOnly()` → sin `updated_at` ni `deleted_at` y `REVOKE UPDATE, DELETE` del rol de aplicación **y** del de plataforma. Ejemplos: `student_movements` (`Prl`, `CA-PRL-004`, modelo `AppendOnlyModel`), `academic_year_reopenings` y `year_rollover_items` (`Curso`). Un error se corrige con otro registro que lo anula, no con `UPDATE`. La purga por retención usa el rol propietario (`Auth/Infrastructure/Jobs/PurgeLoginAttempts.php`); ver skills `migracion-segura` y `aislamiento-tenant`.

## Errores característicos

- **Auditar el alta y olvidar la lectura**: un `GET` que devuelve el documento completo o teléfonos sin fila `read`. La 2.ª revisión de seguridad de `1.14b-a` (`D-66`, N1) amplió la regla a `POST` con reutilización, `PATCH` y `PUT …/photo`, porque devuelven datos de una persona ya existente.
- **Auditar al empezar y no al fallar**, o escribir la fila después de enviar los datos.
- **Meter el dato en `context`** «para que sea útil». La utilidad es `purpose` + ID público.
- **Olvidar la exportación**: se audita con el detalle de lo exportado (skill `permisos-y-roles`).
- **Registrar el modelo sin política de valores** o con una lista de inclusión que incluye un campo de texto libre.
- **Lectura disparada por la UI sola** (montaje, sondeo): genera ruido y desvirtúa el rastro (`§23.2` UI-2, skill `frontend-spa`).

## Tests

Todo requisito auditado tiene test que referencia su ID: la fila existe con el evento y el actor correctos, **no contiene valores personales** y un fallo de escritura impide la respuesta. En tablas de solo anexar, un test que `UPDATE`/`DELETE` falla en el motor.

## Antes de cerrar

- [ ] ¿Cada alta, cambio y borrado deja fila (`INV-003`)?
- [ ] ¿Cada lectura de dato sensible escribe `read` antes de devolver y está limitada por cupo?
- [ ] ¿`context` solo lleva finalidad, recuentos y códigos?
- [ ] ¿Tabla de registro histórico con `tenantTableAppendOnly()`?
- [ ] ¿Tests de rastro y de ausencia de valores personales?
