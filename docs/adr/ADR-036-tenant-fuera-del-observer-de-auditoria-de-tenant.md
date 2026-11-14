# ADR-036 · `Tenant` queda fuera del *observer* de auditoría de tenant

**Estado**: ACEPTADA (2026-08-18, implementada en el paso 0.9 — auditoría e i18n)
**Fecha**: 2026-08-18
**Sustituye**: la fila `Tenant` de la tabla de `ADR-035 §8` ("Declaración de los modelos del núcleo"). El resto de `ADR-035` queda intacto y no se reabre.
**Se apoya en**: `ADR-033 §7` (reserva de `admin_action_logs`), `ADR-034 §3` (`audit_logs` es tabla de tenant)
**Afecta a**: paso **0.9**, hallazgo de severidad Alta de la revisión independiente de `security-reviewer` sobre `feature/REQ-0.9-auditoria-i18n`

---

## Contexto

`ADR-035 §8` fija una tabla de "los siete modelos del núcleo" que el paso 0.9 declara auditables "sin más decisiones", e incluye:

```
| `Tenant` | `Full` | — | Datos del centro, persona jurídica |
```

La implementación de 0.9 no declaró `Tenant` como `Auditable` ni lo registró en el *morph map*. La revisión independiente de `security-reviewer` sobre esa rama lo detectó como hallazgo de severidad **Alta**: es una desviación de una decisión de ADR ya cerrada, sin ADR que la sustituya, incumpliendo `CLAUDE.md §11`. Este documento es esa sustitución.

El motivo técnico de la desviación, verificado por `security-reviewer` como correcto, es este: `Tenant` (`App\Support\Tenancy\Tenant`) es un modelo que vive en la conexión `pgsql_platform`, sin `tenant_id` propio — es la entidad que *define* qué es un tenant, no una entidad que pertenece a uno. `AuditRecorder::record()` (0.9.c) exige contexto de tenant activo (`TenantContext::hasTenant()`) antes de escribir, porque `audit_logs` es una tabla de tenant particionada lógicamente por `tenant_id` (`ADR-034 §3`) con RLS forzada. El ciclo de vida de `Tenant` —alta, suspensión, cambio de `slug`, baja— ocurre precisamente en los momentos en que no hay, o no debe haber, un tenant activo resuelto. Forzar `Tenant` a través del mismo mecanismo que `Person`/`User` exigiría inventarle un `tenant_id` que no le corresponde, o debilitar la guarda de `AuditRecorder` que `ADR-035`/0.9 acaban de blindar (ver `ADR-036` hermano de esta decisión, el hallazgo Media sobre `isPlatformMode()`).

`ADR-035 §8` se equivocó al meter `Tenant` en la misma fila que `Person`/`User`/`AcademicYear` sin la misma salvedad que sí aplicó, en esa misma tabla, a `Permission`/`Module` ("Catálogos de referencia, sin `tenant_id`; los cambia `platform:sync-registry` y su registro es de plataforma"). `Tenant` necesitaba exactamente esa salvedad y no la tuvo.

**Por qué no se resuelve implementando auditoría de `Tenant` ahora**: `ADR-033 §7` ya reservó el sitio correcto para esto — `admin_action_logs`, tabla de auditoría de plataforma, independiente e inmutable, "no mezclada con la auditoría de los tenants" (`REQ-BO-007`). Esa tabla la crea el paso **1.6** (`REQ-BO`: backoffice de superadmin), no 0.9. Construir un mecanismo de auditoría de plataforma ad-hoc dentro de 0.9 para cubrir un único modelo sería adelantar trabajo de 1.6 sin el resto del contexto de esa fase (quién es el actor de plataforma, qué acciones se audita, el propio diseño de `admin_action_logs`), y contradeciría el criterio de `ADR-033`/`ADR-034` de "reversible antes que óptimo": mejor no auditar todavía que auditar con un mecanismo provisional que haya que deshacer en 1.6.

## Decisión

**`Tenant` no es auditable en 0.9.** No implementa `Auditable`, no está en el *morph map* de `audit_logs`, y `AuditServiceProvider` no lo registra. La fila `Tenant` de `ADR-035 §8` queda sustituida por:

| Modelo | Política | Nota |
|--------|----------|------|
| `Tenant` | *(ninguna, 0.9)* | Vive en la conexión de plataforma, sin `tenant_id`. Su ciclo de vida (alta, suspensión, renombrado, baja) se audita en `admin_action_logs` (`ADR-033 §7`), creada en el paso **1.6**. Hasta entonces, **sin auditoría** — es una laguna conocida y asumida, no un mecanismo provisional. |

El resto de la tabla de `§8` (`Person`, `User`, `AcademicYear`, `Role`, `ModuleSubscription`, `AuditLog`, `Permission`/`Module`) no cambia.

`apps/api/tests/Feature/Core/CoreModelsTest.php` afirmaba en un comentario que "el conjunto `Full` es exactamente el declarado en `ADR-035 §8`" y comprobaba un conjunto que ya excluía `Tenant` — una contradicción entre el comentario y lo que el propio ADR decía en ese momento. Se corrige el comentario para que declare correctamente el conjunto vigente tras este ADR, en vez de remitir a una tabla que ya no describe el comportamiento real.

## Motivo

**Porque una laguna documentada y con fecha de cierre es preferible a un mecanismo provisional.** `Tenant` es la entidad raíz de todo el aislamiento multi-tenant (`ADR-033`); no tener su ciclo de vida auditado durante la fase 0 es una limitación real, no cosmética — pero construir ahora una versión reducida de `admin_action_logs` solo para esta entidad duplicaría trabajo cuando 1.6 diseñe la tabla completa (actor de plataforma, qué eventos se cubren, retención propia por `REQ-BO-007`), y el mecanismo ad-hoc de 0.9 tendría que migrarse o descartarse entonces.

**Porque `audit_logs` es, por diseño de `ADR-034 §3`, una tabla de tenant.** Meter una fila sin `tenant_id` real (usando un valor centinela, o el propio `id` del tenant como si fuera su propio sujeto) rompería la invariante que sostiene RLS sobre esa tabla y crearía el primer caso especial de una tabla que hasta ahora no tiene ninguno.

**Porque `ADR-035` construyó una guarda explícita (`AuditRecorder`, hallazgo Media resuelto en la misma revisión) que ahora falla en cerrado ante justo esta situación** — un intento de auditar sin contexto de tenant claro se rechaza con una excepción, no se silencia ni se escribe con un valor incorrecto. Dejar `Tenant` fuera es coherente con esa misma guarda, no una excepción a ella.

## Consecuencias

**A favor**

- El comentario falso de `CoreModelsTest.php` se corrige; el test vuelve a describir con precisión lo que comprueba.
- `ADR-035` queda con una referencia clara a este ADR en vez de una tabla que se contradice con la implementación.
- No se construye infraestructura de auditoría de plataforma a medias, evitando el retrabajo previsible en 1.6.

**En contra, y se asume**

- **El ciclo de vida de `Tenant` no tiene auditoría en ningún sitio hasta el paso 1.6.** Una suspensión, reactivación o cambio de `slug` de un centro no deja rastro hoy. Es la limitación central de este ADR; se documenta explícitamente en vez de dejarla como vacío silencioso, con [issue #27](https://github.com/pirexia/plataforma-educativa/issues/27) de seguimiento referenciando este ADR y el paso 1.6.
- **`ADR-035 §8` queda con una fila incorrecta si se lee sin este ADR al lado.** Es el precio de la regla de inmutabilidad de ADR (`docs/adr/README.md`): no se edita, se sustituye. Cualquiera que lea `ADR-035 §8` sobre `Tenant` debe encontrar la referencia a este documento — se añade una nota en el índice de la sección 18 de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`, siguiendo el mismo patrón que el cierre de `OPEN-12`.

**Reversibilidad**: alta. Cuando 1.6 diseñe `admin_action_logs`, esta decisión se sustituye por otra que declare cómo `Tenant` se audita ahí — no hay dato escrito bajo la decisión anterior que limpiar, porque la decisión anterior nunca llegó a implementarse.

## Alternativas descartadas y por qué

- **Auditar `Tenant` en `audit_logs` usando su propio `id` como `tenant_id`**: rompe la invariante de que `tenant_id` identifica el tenant *propietario* de la fila, no el sujeto auditado cuando ambos coinciden por casualidad. Cualquier consulta futura que filtre `audit_logs` por tenant activo (el caso de uso normal de `REQ-CORE-005`) mezclaría sin querer los eventos de ciclo de vida del propio tenant con los de sus datos de negocio.
- **Crear ya una versión mínima de `admin_action_logs` solo para `Tenant`**: adelanta diseño de 1.6 sin el resto de su contexto (qué otras acciones de plataforma se auditan, quién es el actor, retención propia). Contradice el criterio de "reversible antes que óptimo" de `ADR-033`/`ADR-034`: preferible no auditar todavía que migrar un mecanismo provisional dentro de dos pasos.
- **Dejarlo sin ADR, solo con el comentario en `docs/modulos/REQ-CORE/datos.md`**: es lo que hizo la implementación original de 0.9, y es precisamente lo que la revisión independiente marcó como incumplimiento de `CLAUDE.md §11`. Una nota en la documentación de módulo no sustituye una decisión de ADR ya cerrada.

## Preguntas abiertas

Ninguna que bloquee 0.9. Queda anotado para el paso **1.6**: diseñar `admin_action_logs` y cubrir en ella el ciclo de vida de `Tenant` (alta, suspensión, reactivación, cambio de `slug`, baja), con su propio actor de plataforma y su propia retención (`REQ-BO-007`). Seguimiento: [issue #27](https://github.com/pirexia/plataforma-educativa/issues/27).
