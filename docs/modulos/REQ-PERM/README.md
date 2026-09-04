# REQ-PERM · Núcleo de autorización granular

> **Estado**: especificación del paso **1.5**, redactada el 2026-09-04. **PROPUESTA — pendiente de aprobación del usuario antes de pasar a implementación.**
>
> **Sin preguntas bloqueantes.** Las cinco que lo eran se resolvieron el 2026-09-04 y están aplicadas en los cinco documentos; quedan dos cuestiones vivas y ninguna bloquea. El registro completo, con la decisión de cada una y dónde está aplicada, está en `funcional.md §18` y `§19.1`.
>
> **Entrada obligatoria y vinculante**: `docs/adr/ADR-044-nucleo-de-autorizacion-granular.md` (ACEPTADA, 2026-09-04). Ninguna decisión estructural de ese ADR se reabre aquí. Donde esta especificación necesita algo que el ADR no fija, lo declara como **pregunta abierta** en `funcional.md §18`, no lo decide.

---

## 1. Qué es `REQ-PERM` y qué no es

`REQ-PERM` es el **prefijo de requisito, de rama y de carpeta de documentación** del sistema de autorización de la sección 11 del documento de requisitos (`RPERM-001` a `RPERM-015`), adoptado por `ADR-044 §5` y ya reflejado en la cabecera de la sección 11.

**No es un *bounded context*.** No existe ni existirá `App\Modules\Perm`. Es la decisión de `ADR-044 §4.10` y tiene un motivo verificable: un módulo cuyo único contenido fuera un controlador que necesita el modelo `Role` de `Core` incumpliría `INV-007` en su primer commit.

### Dónde vive el código (`ADR-044 §4.10`)

| Pieza | Ubicación | Por qué ahí |
|-------|-----------|-------------|
| Motor de resolución, objetos de valor, vocabulario de ámbitos | `apps/api/app/Support/Authorization/` | La autorización es **infraestructura de framework**, igual que el aislamiento de tenant. Si viviera en `App\Modules\Core`, el módulo `Auth` tendría que importarla desde otro módulo para autorizar sus endpoints — violación directa de `INV-007` |
| Contratos que implementan los módulos (`ScopeResolver`, ampliación de `DeclaresModuleRegistry`) | `apps/api/app/Support/Authorization/Contracts/` | Un módulo implementa un contrato del núcleo; nunca importa código interno de otro módulo |
| Endpoints de administración de roles, concesiones y permisos efectivos | `apps/api/app/Modules/Core/Http/Controllers/` | `roles`, `permission_role` y `role_user` son recursos de `REQ-CORE`, donde ya están `RolesController`, `PermissionsController` y `UserRolesController`. `PATCH /roles/{public_id}` se **amplía** sobre la misma ruta y el mismo permiso `rol.actualizar` que 1.3 dejó acotado |
| Resolutores de ámbito | El módulo **propietario de la entidad** sobre la que se resuelve | `REQ-ACAD` (1.11) registrará `departamento`/`grupo`/`clase`; `REQ-FAM-UNIT` (1.14), `unidad_familiar`. El núcleo nunca sabe qué es un grupo |

**Si buscas `App\Modules\Perm`, no existe y no es un olvido.**

---

## 2. Estructura de esta carpeta

| Fichero | Contenido |
|---------|-----------|
| `funcional.md` | Alcance, actores, flujos, reglas de negocio (`RN-PERM-NN`), casos límite, criterios de aceptación (`CA-PERM-NNN`) y **preguntas abiertas** |
| `datos.md` | Esquema: el único cambio de esquema del paso (`permission_role.scope` a `NOT NULL` + `CHECK`), migración *expand/contract*, política de auditoría de las tres tablas |
| `api.md` | Endpoints, verbos, payloads, códigos de error y paginación, conforme a `ADR-038` |
| `permisos.md` | Recursos, matriz recurso × acción × ámbito, siembra en roles predefinidos, y qué cambia respecto de `docs/modulos/REQ-CORE/permisos.md` |
| `operacion.md` | Despliegue, orden de migración, `platform:sync-registry`, variables de entorno, tareas programadas |

## 3. Relación con `docs/modulos/REQ-CORE/permisos.md`

Ese documento sigue siendo el catálogo de permisos **del módulo `Core`** y no se sustituye. Lo que 1.5 deja desfasado en él está enumerado en `permisos.md §9` de esta carpeta, con la corrección exacta que hay que aplicar en el cierre del paso (`ADR-044 §8`). En particular su **§5** («por qué todo es `todos`») pierde vigencia como regla de seguridad y **debe reemplazarse**, no borrarse: el motivo por el que se escribió es precisamente lo que este paso cierra.

## 4. Alcance de 1.5 frente a 1.5b

- **1.5 (este documento)**: núcleo y **API**. Todo es consumible por API (`INV-006`).
- **1.5b** (posterior a `1.9`, `ADR-044 §6`): editor de roles, matriz de concesión y pantalla de permisos efectivos, como **interfaz**. Consume únicamente lo que 1.5 expone; no necesita nada de backend adicional.

Entre 1.5 y 1.5b, los roles personalizados se crean **por API y no por pantalla**. Es un coste conocido y aceptado por el usuario el 2026-09-04.
