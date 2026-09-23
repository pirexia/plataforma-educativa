# ADR-053 · Registro de navegación y bloques del panel de inicio en la SPA

**Estado**: **ACEPTADA** (2026-09-23). El usuario decidió el 2026-09-23 que esta convención se fija por ADR antes de implementar `1.8` (`OPEN-CORE-16`); ratificó el contenido de §1-§6, redactado por `architect`, sin cambios el mismo día.
**Fecha**: 2026-09-23
**Resuelve**: `OPEN-CORE-16` (`docs/modulos/REQ-CORE/funcional.md §12.14`)
**Se apoya en**: `ADR-038 §6` (tipos de error, `module-disabled` distinto de `forbidden`), `ADR-045` (módulos esenciales `core`/`auth` no descontratables), `ADR-052` (patrón de *composable* singleton sin Pinia), `INV-002`, `INV-007`, `INV-009`, `RMOD-008`, `RMOD-009`, `RPERM-011`
**No toca**: la API (ni un *endpoint*, ni un campo de `GET /me`), el modelo de permisos, `ADR-046` (el *backoffice* es otra SPA y no está sujeto a este ADR)
**Afecta a**: paso `1.8` (entrada obligatoria), y a todo paso posterior que añada pantallas o bloques de panel a `apps/web`

---

## Contexto

`1.8` introduce el *shell* de la SPA con un registro de navegación (`funcional.md §12.5`) y un «punto de extensión» de bloques del panel (`§12.4`). Los 53 módulos del producto copiarán esta forma: es a `apps/web` lo que `ADR-038` fue a la API. La especificación deja abiertas cuatro cosas: la forma de una entrada, dónde vive y cómo se ensambla, el contrato de los bloques del panel, y si `GET /me` se recarga ante `403 module-disabled`.

### Estado verificado el 2026-09-23 (rama `feature/REQ-CORE-008-layout-navegacion-dashboards`, `da8ccdb`)

- **Las rutas están centralizadas**: `src/router/index.ts` declara las 17 rutas a mano e importa directamente vistas internas de `src/modules/auth/views/*`. Ninguna ruta lleva `meta`.
- `src/i18n/index.ts` ensambla los catálogos con una **lista explícita** de importaciones (`@/modules/<m>/locales/*.json`), sin descubrimiento automático. Es el patrón que cita `§12.5`.
- Los módulos exponen hoy `api/index.ts`, `types/index.ts` y `locales/`. No existe ningún fichero de módulo que declare rutas o navegación.
- `@lucide/vue` ya es dependencia. Pinia no está instalado.
- `§12.5` y `§12.3.2` declaran **dos veces** los permisos de una misma pantalla: `permissions` en la entrada de navegación y `meta.permissions` en la ruta. Nada impide que diverjan.
- `§12.5` dice que cada módulo futuro «añade su» sección de menú.

Los dos últimos puntos son los que obligan a ajustar la forma, no solo a ratificarla.

---

## Decisión

### 1. Cada módulo declara su *shell* en un único fichero: `src/modules/<modulo>/shell.ts`

Exporta un objeto `ModuleShell` con tres listas, cualquiera de ellas vacía:

```ts
export const shell: ModuleShell = {
  routes: [...],          // RouteRecordRaw con meta obligatoria (§3)
  navigation: [...],      // NavigationEntry (§4)
  dashboardBlocks: [...], // DashboardBlock (§5)
}
```

- Los tipos (`ModuleShell`, `NavigationEntry`, `DashboardBlock`, `SectionId`, y la ampliación de `RouteMeta`) viven en **`src/navigation/types.ts`**, de nivel de aplicación. Los módulos importan de ahí **solo tipos**; `src/navigation` no importa nada de ningún módulo salvo su `shell.ts` (y lo que ya permite `CA-CORE-152`).
- `shell.ts` pasa a formar parte de la **superficie pública** del módulo, junto a `api/index.ts`, `types/index.ts` y `locales/`. Un módulo nunca importa el `shell.ts` de otro (`INV-007`).
- Vistas y bloques se referencian **siempre** con `() => import(...)`: el manifiesto es metadatos y no arrastra el código del módulo al *chunk* principal. Los iconos se importan como componente (`import { Shield } from '@lucide/vue'`), no como cadena: se eliminan por *tree-shaking* y un nombre mal escrito falla en compilación.

**Las rutas se mudan al módulo.** Una entrada de navegación nombra una ruta; si la ruta vive en `src/router/index.ts` y la entrada en el módulo, una misma pantalla se declara en dos sitios con dos dueños, y con 53 módulos `router/index.ts` acabaría con varios cientos de rutas importando vistas internas de todos ellos. `1.8` ya reescribe todas las rutas (tienen que ganar `meta.layout` y `meta.permissions`), así que el traslado de las de `auth` a `src/modules/auth/shell.ts` es mecánico y cae en el mismo cambio. En `src/router/index.ts` quedan solo las rutas de aplicación (`home`, *catch-all*, centro no encontrado).

### 2. Ensamblado: una lista explícita y ordenada, no descubrimiento automático

Un único fichero, **`src/navigation/modules.ts`**, importa el `shell` de cada módulo y los expone en un *array* ordenado:

```ts
export const moduleShells: readonly ModuleShell[] = [authShell, coreShell /* , … */]
```

El *router* concatena sus rutas de aplicación con `moduleShells.flatMap(s => s.routes)`; el registro de navegación y el panel hacen lo mismo con `navigation` y `dashboardBlocks`. **El orden de esta lista es el orden del producto** (§4.3, §5.3). Añadir un módulo al *shell* es añadir una línea aquí, igual que hoy se añade una a `src/i18n/index.ts`.

Se ratifica, por tanto, el patrón de `src/i18n/index.ts`, con una diferencia: el catálogo de traducciones **no** se mueve al manifiesto. Funciona, se inicializa antes que el *router* y fusionarlo no aporta nada que justifique tocarlo en `1.8`.

Las comprobaciones de coherencia son **tests de Vitest sobre el registro ensamblado**, no validación en tiempo de ejecución:

1. `id` de entradas y bloques únicos en todo el registro, con prefijo del módulo (`auth.sessions`, `core.users`).
2. Nombres de ruta únicos entre todos los módulos.
3. Toda entrada apunta a una ruta registrada con `meta.layout === 'app'` (`RN-CORE-25`, `CA-CORE-103`).
4. Toda `section` existe en el catálogo de §4.2.
5. Toda ruta `app` o `bare` declara `meta.permissions` **explícitamente** (un `[]` escrito, nunca un campo ausente), y las que lo tienen vacío están en una lista cerrada escrita en el propio test (`RN-CORE-24`).

### 3. Los permisos de una pantalla se declaran una sola vez: en la ruta

`RouteMeta` se amplía con:

| Campo | Tipo | Obligatorio |
|-------|------|-------------|
| `layout` | `'public' \| 'app' \| 'bare'` | En **todas** las rutas |
| `permissions` | `readonly string[]`, semántica **anyOf** | En toda ruta `app` y `bare` |

La especificación de `1.8` puede añadir otros campos de presentación a `meta` (título, miga de pan); este ADR no los fija.

**La entrada de navegación no lleva `permissions`.** Su visibilidad se **deriva** de la ruta a la que apunta: una entrada es visible si y solo si el *guard* dejaría montar esa ruta. Así el menú no puede enseñar un enlace que el *guard* rechaza, ni ocultar uno que dejaría pasar, y no hace falta un test que compruebe que dos listas coinciden.

**Solo anyOf.** Una lista vacía significa «cualquier usuario autenticado» y solo se admite en autoservicio por identidad (`RN-CORE-24`). No hay `allOf`, ni negación, ni condición por rol (`RN-CORE-23`). El motivo: la visibilidad es comodidad y la decisión es del servidor (`INV-002`); una pantalla que necesite dos permisos a la vez se muestra con el que le da sentido, y el servidor responde `403` en la acción concreta que falte. Añadir `allOf` más adelante es aditivo si aparece un caso real.

### 4. Forma de una entrada de navegación

#### 4.1 Campos

| Campo | Tipo | Significado |
|-------|------|-------------|
| `id` | `string` | `<modulo>.<nombre>`, estable, único en el registro |
| `route` | `string` | Nombre de ruta (nunca una URL) de una ruta `app` |
| `labelKey` | `string` | Clave de traducción, en el espacio de nombres del módulo (`INV-009`) |
| `icon` | `Component` | Componente de `@lucide/vue`, decorativo (`aria-hidden`) |
| `section` | `SectionId` | Sección del catálogo de §4.2 |
| `shortcut` | `boolean`, opcional, `false` por defecto | Aparece en «Accesos directos» del panel |

Frente a `§12.5`, desaparece `permissions` (§3) y `section` cambia de naturaleza (§4.2). El resto se ratifica tal cual.

#### 4.2 Las secciones son un catálogo de aplicación, no una por módulo

**`src/navigation/sections.ts`** declara las secciones en orden, con su `id` y su `labelKey` (espacio de nombres de aplicación). `SectionId` es la unión de esos `id`, así que una sección inexistente falla en compilación. En `1.8` son tres: `inicio`, `cuenta`, `administracion`.

Se **rechaza** que cada módulo añada la suya, como dice hoy `§12.5`. Con 53 módulos serían 53 cabeceras de menú, casi todas con una o dos entradas; y cómo se agrupan las pantallas para el usuario (docencia, secretaría, economía…) es una decisión de arquitectura de información **transversal a los módulos**, no de ninguno de ellos. Varios módulos comparten sección; un módulo puede aportar entradas a varias.

Añadir una sección es editar `sections.ts`, y se hace en el paso del primer módulo que la necesite, con su justificación en la especificación de ese paso. **Este ADR no fija la taxonomía futura**: no hay módulos académicos de los que deducirla, e inventarla ahora sería rellenar huecos con suposiciones.

Una sección sin ninguna entrada visible para el usuario no se pinta.

#### 4.3 Orden

Secciones: el orden de `sections.ts`. Entradas dentro de una sección: por la posición de su módulo en `moduleShells` y, dentro del módulo, por orden de declaración. Determinista, sin números mágicos, y visible leyendo dos ficheros.

No hay campo `order`/`weight`. Pesos numéricos repartidos entre 53 módulos obligan a coordinar números entre ficheros que nadie ve juntos. Si hiciera falta intercalar entradas de módulos distintos en una sección, se añade un campo opcional: es aditivo.

#### 4.4 Dos niveles, sin sub-entradas

Sección → entrada. Sin jerarquía dentro de una entrada. Las pantallas secundarias (alta, edición, detalle) no son entradas: son rutas que cuelgan de la miga de pan, como ya hace `§12.5` con `sso-administration-new`/`-edit`. Un tercer nivel de menú en móvil (`RUX-RESP-003`) es mala navegación y ningún requisito lo pide. Si un módulo llegara a necesitarlo, un campo opcional `parent` es aditivo.

### 5. Contrato de los bloques del panel de inicio

#### 5.1 Forma

| Campo | Tipo | Significado |
|-------|------|-------------|
| `id` | `string` | `<modulo>.<nombre>`, único en el registro |
| `titleKey` | `string` | Clave de traducción del título del bloque; el panel pinta el encabezado, no el bloque |
| `permissions` | `readonly string[]`, anyOf, **no vacía** | Permisos que hacen visible el bloque |
| `component` | `() => Promise<Component>` | Componente del bloque, cargado bajo demanda |

La lista vacía no se admite en bloques de módulo: los bloques por identidad (bienvenida, estado de la cuenta, accesos directos) son del *shell*. Si un módulo futuro necesita un bloque por identidad, se amplía la lista cerrada de la comprobación 5 de §2 con justificación, igual que en navegación.

No hay campos de tamaño, prioridad ni configuración: `OPEN-CORE-13` difiere la configuración del panel, y la forma que tenga cuando llegue no se adivina ahora. Se añadirán como campos opcionales.

#### 5.2 Obligaciones del componente de un bloque

1. **No se monta si no está permitido.** El panel evalúa `permissions` contra `/me` antes de montarlo; un bloque no permitido no carga su código ni hace ninguna petición (`RN-CORE-33`).
2. **Pide sus propios datos** a través del `api/` de su propio módulo, y solo a *endpoints* que sus `permissions` cubren. No recibe *props* del panel.
3. **Pinta sus estados con los componentes de aplicación** de `§12.6` (carga, vacío, error). Un bloque sin nada que mostrar pinta su estado vacío; no desaparece, para que el panel no salte al cargar.
4. **Su fallo queda contenido.** El panel envuelve cada bloque de forma que una excepción de montaje o renderizado pinte el estado de error **en su tarjeta** y no afecte a los demás bloques ni al *shell*.

#### 5.3 Orden

Primero los tres bloques del *shell* en el orden de `§12.4` (bienvenida, estado de la cuenta, accesos directos); después los bloques de módulo por la posición de su módulo en `moduleShells` y, dentro del módulo, por orden de declaración. Es la misma regla que la navegación, y la sustituirá la configuración de `OPEN-CORE-13` cuando exista.

### 6. `403 module-disabled` también recarga la sesión

Se simplifica la regla de `§12.3.4`: **cualquier `403` que no sea `mfa-enrollment-required` recarga `GET /me`**, con la misma deduplicación (una sola recarga para varias respuestas concurrentes). `module-disabled` entra.

Motivos:

- Un `module-disabled` es la prueba de que los permisos de ese módulo ya son inertes en el servidor (`REQ-PERM/api.md §7.2`), y por tanto de que el `/me` en memoria está desfasado. Es el mismo caso que el `403` genérico, solo que más seguro.
- `RMOD-008` pide «sin enlaces muertos». Sin recarga, el menú sigue ofreciendo durante toda la sesión entradas de un módulo que responde `403` a todo.
- Cuesta una petición deduplicada, y **no hay bucle**: `/me` nunca responde `module-disabled` (`core` y `auth` son esenciales, `ADR-045`), la recarga no vuelve a montar la vista actual ni relanza sus peticiones, y los bloques del panel cuyo permiso desaparece se desmontan en vez de reintentar.
- Una regla con una excepción es más fácil de mantener que una con dos.

Una restricción, para no perder el mensaje de `RMOD-009`: si la recarga la disparó un `module-disabled` y la ruta actual deja de estar permitida, la vista **conserva el estado «módulo no disponible»** hasta la siguiente navegación, en vez de pasar a «sin acceso». Es la información más precisa de las dos.

Si el servidor todavía devuelve el permiso tras la recarga (la caché de disponibilidad de módulos aún no se ha invalidado, `ADR-045 §8.3`), la entrada sigue visible hasta la siguiente recarga. Se acepta: no se añade sondeo, y la siguiente respuesta `module-disabled` vuelve a recargar sin que haya bucle.

---

## Motivo

| Criterio | **Manifiesto por módulo + lista explícita** (elegida) | Rutas centrales, navegación en el módulo (`§12.5` tal cual) | Descubrimiento con `import.meta.glob` | Menú servido por la API |
|---|---|---|---|---|
| Coste en solitario | Bajo: tres ficheros nuevos de aplicación, un `shell.ts` en `auth`, traslado mecánico de rutas | El más bajo hoy | Bajo | Alto: *endpoint*, contrato OpenAPI, tests de servidor, y el cliente sigue necesitando las rutas |
| Mantenimiento a 3 años | Una pantalla se declara en un solo sitio; `router/index.ts` no crece | `router/index.ts` con cientos de rutas e importaciones internas de todos los módulos; permisos duplicados | Un fichero mal nombrado desaparece del menú sin error; orden implícito | Dos fuentes de verdad de rutas (servidor y cliente) |
| Invariantes | `INV-007` más limpio que hoy: el *router* deja de importar vistas internas | El *router* sigue importando internos de cada módulo | Igual que la elegida | `INV-002` igual; añade superficie de API sin requisito |
| Reversibilidad | Alta: volver a centralizar es mover código | — | Alta | Baja una vez publicado el contrato |

**Menú servido por la API**: la visibilidad ya viene resuelta del servidor en forma de permisos efectivos; lo que añadiría un *endpoint* de menú es un catálogo de rutas que solo existen en el cliente. Complejidad sin beneficio.

**Descubrimiento automático**: ahorra una línea por módulo a cambio de que un error de nombre sea silencioso y de que el orden del producto dependa del orden de los ficheros en disco. Una línea por módulo, 53 como máximo, no es un problema que merezca resolverse.

**Permisos solo en la ruta** (§3) y **secciones de aplicación** (§4.2) corrigen dos defectos concretos de `§12.5` que, copiados 53 veces, serían caros de deshacer: la doble declaración que puede divergir, y un menú con una cabecera por módulo.

---

## Consecuencias

**Positivas**

- Añadir un módulo al *shell* es un `shell.ts` y una línea en `src/navigation/modules.ts`, y los errores de coherencia fallan en CI.
- El menú no puede contradecir al *guard*: los dos leen los mismos `meta.permissions`.
- `RMOD-008` se cumple dentro de la sesión, no solo al recargar la página.

**Negativas y riesgos aceptados**

- `1.8` crece un poco: `src/navigation/{types,modules,sections}.ts`, `src/modules/auth/shell.ts` con las rutas trasladadas, y los tests de §2.
- El orden de `moduleShells` pasa a tener significado de producto; reordenarlo cambia el menú. Queda escrito en el propio fichero.
- Un bloque de panel mal escrito puede hacer peticiones lentas; el contrato de §5.2 las contiene visualmente pero no las limita. Se revisa en el paso de cada módulo.
- Tras una descontratación, el menú puede tardar una recarga en corregirse si la caché de módulos del servidor va por detrás (§6).

**Cambios obligatorios en `docs/modulos/REQ-CORE/funcional.md §12`** (los hace quien mantiene esa especificación, no este ADR):

- `§12.5`: retirar `permissions` de la tabla de campos de la entrada y trasladar esa columna de la tabla de entradas de `1.8` a `meta.permissions` de las rutas; cambiar «cada módulo futuro añade la suya» por el catálogo de §4.2; sustituir la última frase («ver `OPEN-CORE-16`») por la referencia a este ADR.
- `§12.4`: sustituir «el contrato exacto es parte de la decisión de `OPEN-CORE-16`» por la referencia a §5.
- `§12.3.4`, tabla de `§12.6`, `§12.9` (fila «Módulo descontratado con la sesión abierta») y `CA-CORE-098` (segunda mitad): `module-disabled` **sí** recarga `/me`, con la restricción de §6.
- `RN-CORE-24` y `CA-CORE-103`: la lista cerrada de permisos vacíos se aplica a `meta.permissions` de las rutas, no a las entradas.
- `CA-CORE-152`: añadir `shell.ts` a la superficie pública admitida.
- `§12.14`: marcar `OPEN-CORE-16` como resuelta por este ADR.

---

## Alternativas descartadas

- **`§12.5` tal cual** (permisos en la entrada y en la ruta, rutas centralizadas, una sección por módulo): ver Motivo.
- **Descubrimiento con `import.meta.glob`**: ver Motivo.
- **Registro en tiempo de ejecución** (cada módulo llama a `registerNavigation()` al importarse): depende del orden de efectos secundarios de las importaciones y obliga a limpiar un estado global entre tests.
- **Menú servido por la API**: ver Motivo.
- **Pinia para el registro**: el registro es estático; lo único dinámico es la sesión, y ya tiene su singleton (`§12.3.4`, mismo patrón que `ADR-052`).
- **Pesos numéricos de orden, sub-entradas, `allOf` y campos de configuración de bloques**: sin caso real hoy; todos se pueden añadir después como campos opcionales.
- **Mantener la excepción de `module-disabled` en la recarga de `/me`**: deja enlaces muertos durante toda la sesión (`RMOD-008`) para ahorrar una petición deduplicada.

---

## Hallazgos fuera del ámbito de este ADR

No se corrigen aquí (`architect` solo escribe en `docs/adr/` y en el índice de la sección 18):

1. La contradicción de `§12.5` con §4.2 de este ADR (una sección por módulo) y la doble declaración de permisos de `§12.5`/`§12.3.2`: se listan arriba como cambios obligatorios de la especificación.
2. El historial de versiones de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` necesita una fila por el alta de este ADR en el índice; queda fuera de la sección 18.
