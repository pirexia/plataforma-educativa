# REQ-XXX · <Nombre del módulo> · Funcional

| Campo | Valor |
|-------|-------|
| Código | `REQ-XXX` |
| Prioridad | MUST / SHOULD / COULD |
| Fase | |
| Depende de | Códigos de módulo de `depends_on` del descriptor |
| Esencial | `essential: true` solo si el producto no funciona sin él (`ADR-045`; un esencial no puede depender de uno no esencial) |
| Estado | PROPUESTO / APROBADO / EN CURSO / IMPLEMENTADO / VERIFICADO |

## Alcance
Qué entra y, sobre todo, **qué no entra**.

## Actores y roles implicados

## Flujos principales
Un apartado por flujo, con pasos numerados.

## Reglas de negocio
Numeradas y verificables.

## Casos límite y errores

## Interacción con otros módulos
Eventos de dominio que emite y que consume. Nunca dependencias directas de código.

## Descriptor del módulo (`ADR-045`, `ADR-034 §5`)
Los campos de `moduleDescriptor()`: `code`, `name_key` (`modules.<código>` en los cuatro idiomas), `phase`, **`depends_on`** (códigos, sin ciclos; `platform:sync-registry` aborta el despliegue si no se cumple), **`essential`** (por omisión `false`) y, si hay, `feature_flags`. *`AR-03` comprueba que el `ServiceProvider` existe, se descubre y declara el catálogo; `AR-07b` exige `module-enabled:<código>` en toda ruta de un módulo no esencial.*

## Comportamiento con el módulo desactivado
Qué desaparece de la interfaz y qué responde la API (`RMOD-008`, `RMOD-009`).

## Interfaz: *shell* del frontend (`ADR-053`)
Si el módulo tiene pantallas: sus **rutas** (cada una con `meta.layout` y `meta.permissions`, anyOf; `[]` solo para autoservicio por identidad, en una lista cerrada que verifica un test), sus **entradas de navegación** (sin permisos propios: visibles si y solo si el *guard* dejaría montar la ruta) y sus **bloques del panel de inicio**, las tres listas del `shell.ts` del módulo. *`AR-11` comprueba que el módulo tiene `shell.ts`, `locales/{es,en,de,fr}.json`, y que está registrado en `src/navigation/modules.ts` y `src/i18n/index.ts`.*

## Criterios de aceptación
- *Dado* … *cuando* … *entonces* …
- **Por cada recurso con ámbito restringido, acceso denegado en listado Y en detalle** (`ADR-044 §4.2`): *dado* un usuario con el permiso solo sobre su ámbito, *cuando* pide el listado, *entonces* solo ve lo suyo, y *cuando* pide por `public_id` un recurso fuera de su ámbito, *entonces* recibe la misma respuesta que si no existiera. Ningún test estático lo comprueba (`AR-10` ve consultas, no endpoints): es criterio de aceptación y de revisión.
- **Aislamiento entre tenants** (`INV-001`): con dos centros con datos equivalentes, ningún endpoint del módulo devuelve, modifica ni cuenta datos del otro.

## Preguntas abiertas
Sin resolver por cuenta propia.
