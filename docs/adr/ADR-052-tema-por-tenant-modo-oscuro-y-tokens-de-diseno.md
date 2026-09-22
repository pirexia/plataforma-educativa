# ADR-052 · Tema por tenant, modo oscuro y tokens de diseño en la SPA

**Estado**: **PROPUESTA** (2026-09-22). Pendiente de ratificación del usuario. Las decisiones de §1-§6 son de `architect` y no dependen de ninguna preferencia de producto; las cuatro preguntas del apartado «Preguntas abiertas» sí lo son, y **ninguna bloquea** la especificación de `1.7`: cada una tiene una respuesta por defecto que es la que el producto ya hace o la que los requisitos permiten sin inventar nada.
**Fecha**: 2026-09-22
**Se apoya en**: `ADR-023` (Tailwind + shadcn-vue + TanStack Table como *design system* único), `ADR-025` (sesión por cookie), `ADR-046` (backoffice como SPA propia), `RNF-MANT-007`, `RNF-COMP-001`
**No toca**: `ADR-023` (la librería no cambia), el modelo de datos de `REQ-CORE` (ni una columna nueva), la validación de contraste de servidor (`RN-CORE-15`, `CA-CORE-003`), el contrato de `GET /api/v1/tenant/branding` (ni un campo nuevo)
**Afecta a**: paso `1.7` (entrada obligatoria de `spec-writer`), `1.8` (el *layout* autenticado consume este mecanismo), la futura SPA del backoffice (`ADR-046`) y la web publicitaria `0.11b`
**Requisitos**: `RUX-002`, `RUX-004`, `RUX-005`, `RUX-BRAND-002`, `RUX-BRAND-003`, `RUX-BRAND-006`, `RNF-UX-002`, `RNF-UX-004`, `RNF-COMP-001`

---

## Contexto

`1.7` abre el *design system*: tokens, tema por tenant, componentes base, validación de contraste y modo oscuro. `ADR-023` fijó la librería y nada más. Antes de especificar hay que decidir tres cosas que, si se dejan a la implementación, se deciden de forma distinta en cada módulo: cómo llega el tema del centro a toda la SPA, cómo convive con el modo oscuro, y qué forma tienen los tokens.

### Estado verificado el 2026-09-22 (rama `feature/1.7-design-system`, `3668929`)

Contrastado contra el código, no contra el inventario previo, que tiene **dos imprecisiones** que cambian la forma de la decisión:

1. **`PublicAuthShell.vue` no escribe en `document.documentElement`.** Liga `--primary`/`--primary-foreground` como `:style` sobre el `<div>` de la tarjeta de login. El tema del centro, por tanto, **solo existe dentro de esa tarjeta**: fuera de ella (el fondo de la pantalla pública) y en toda la SPA autenticada rigen los neutros de `style.css`.
2. **`src/components/ui/` no está vacío**: hay ocho componentes ya vendorizados (`badge`, `button`, `input`, `label`, `radio-group`, `select`, `table`, `textarea`). Varios usan `text-primary` y `border-primary` **sobre el fondo de la página**, no sobre una superficie `bg-primary` (§3.3 explica por qué eso importa).

El resto se confirma:

- `tenant_settings.color_primary`/`color_secondary`, hex `^#[0-9A-Fa-f]{6}$`, ambos opcionales.
- **`RN-CORE-15` valida el par entre sí**: `ContrastRatioCalculator` compara `color_primary` **contra** `color_secondary` con umbral 4,5:1. Es decir, en el producto real `color_secondary` **no es un segundo color de marca: es el color de texto sobre el primario**. `PublicAuthShell` ya lo usa así (`--primary-foreground: color_secondary`).
- `GET /tenant/branding` es público, tenant resuelto por *host*, con regla escrita de «ni un campo más» (`REQ-CORE/api.md §2`). `GET /tenant/settings` exige `configuracion.leer.todos`: **un usuario normal del centro no puede leerlo**.
- Tailwind v4 con `@theme inline` en `style.css`, paleta `:root` y `.dark`, `@custom-variant dark (&:is(.dark *))`. **Nada activa `.dark`**: ni conmutador ni lectura de `prefers-color-scheme`.
- El *favicon* del centro (`RUX-BRAND-003`) **no se aplica en ningún sitio**: `index.html` sirve `/favicon.svg` fijo y `favicon_url` no se consume.
- `@vueuse/core` ya es dependencia (`useColorMode`/`usePreferredDark` disponibles). **Pinia no está instalado.**
- La SPA del backoffice (`ADR-046`, opción A: «SPA propia») aún no existe; será el **segundo consumidor** del *design system*. La web publicitaria (`0.11b`) será el tercero y solo de paleta y tipografía.

### El problema que decide la forma de todo lo demás

**Ningún color puede tener contraste AA como texto a la vez sobre los dos fondos de `style.css`.** Contra el blanco de `:root` y el `oklch(0.145 0 0)` de `.dark`, el mejor caso posible —una luminancia media exacta— se queda en ≈ 4,4:1 contra ambos; cualquier otro color es peor en uno de los dos. `RN-CORE-15` garantiza el contraste **del par** primario/texto-sobre-primario, que es independiente del fondo de la página — pero **no garantiza nada** cuando el primario se usa **como texto, borde o anillo de foco sobre el fondo**, que es exactamente lo que hacen hoy `button` (variante `link`) y `radio-group`. Con el modo oscuro, un azul corporativo típico como `#1D4ED8` queda en ≈ 3:1 sobre el fondo oscuro de `style.css`: incumple `RNF-UX-002` sin que nadie haya elegido mal nada.

Este es el motivo de que la pregunta «¿el tenant tiene paleta clara y oscura?» no sea de gusto: o el centro introduce dos paletas (modelo de datos nuevo), o el producto deriva la variante que falta, o se restringe el uso del color de marca.

---

## Decisión

### 1. Un solo mecanismo de tema, en dos capas que no se conocen

**Capa A · *Design system*** (sin noción de tenant). Expone una única operación: aplicar una paleta de marca `{ primary, primaryForeground } | null` al documento. Escribe **solo** variables de entrada `--brand-*` en `document.documentElement` mediante CSSOM (`style.setProperty`/`removeProperty`) y calcula en esa misma operación las variantes derivadas de §3.3. Con `null`, retira las variables y rigen los neutros. No importa nada de `@/modules/*`, ni del cliente HTTP, ni del *router*.

**Capa B · Contexto del centro** (sin noción de CSS). Un *composable* singleton de ámbito de aplicación que:

- Lanza **una** petición a `GET /tenant/branding` en el arranque (`main.ts`), **antes de montar** la SPA y sin esperarla indefinidamente (la especificación fija el tiempo máximo; pasado este, se monta con los neutros y la marca se aplica al llegar).
- Entrega los colores a la capa A, el *favicon* al `<link rel="icon">` (`RUX-BRAND-003`, hoy sin implementar) y el resto de datos (nombre, logo, fondo, idiomas) a quien los pinte.
- Guarda **solo los dos colores** en `localStorage` (son públicos: los sirve un endpoint anónimo) para aplicarlos de forma síncrona en la siguiente carga y evitar el destello de tema neutro. **Nunca** guarda las URL firmadas, que caducan (`REQ-CORE/api.md §2`: «no se cachean en cliente más allá de su vencimiento»).
- Expone `refresh()`, que llaman la pantalla de configuración tras un `PATCH /tenant/settings` o un cambio de activo, y la recuperación ante caducidad de una URL firmada en una sesión larga.
- Es el **único** llamador de `getTenantBranding()` en la SPA. `usePublicAuthScreen` deja de pedirlo por su cuenta y lo lee de aquí (sigue resolviendo idioma y CSRF, que son suyos).

**Pública y autenticada no son mecanismos distintos.** La razón para separarlos sería que antes del *login* no hay tenant; es falsa en este producto: el tenant se resuelve **por *host*** (`RUX-DOM-001`/`002`), así que la pantalla de login ya sabe de qué centro es. El único caso sin tenant es el `404` de *host* desconocido, que se pinta con los neutros. Y hay una razón fuerte para **no** usar `GET /tenant/settings` en la parte autenticada: exige un permiso de administración que docentes, familias y alumnado no tienen.

**No va en un *guard* de *router*.** El tema no depende de la ruta; un *guard* lo reevaluaría en cada navegación y acoplaría el *design system* al *router*.

`PublicAuthShell.vue` deja de ligar variables en su tarjeta: el tema pasa a ser global y la tarjeta hereda. Conserva logo y fondo, que lee del contexto de la capa B.

### 2. Modo oscuro: ortogonal a la marca, preferencia local, tres estados

- **Tres estados**: `system` (por defecto, sigue `prefers-color-scheme`), `light`, `dark`. La clase `.dark` va en `<html>`, junto con `color-scheme` para que controles nativos y barras de desplazamiento acompañen.
- **Persistencia en `localStorage` del navegador**, sin dato personal (una de tres cadenas). Funciona igual antes y después del *login*, que es donde más se nota. **No se guarda en servidor** en `1.7`: ningún requisito pide sincronizarlo entre dispositivos («Preguntas abiertas», P2).
- Se implementa sobre `useColorMode` de `@vueuse/core` (ya dependencia) **envuelto** en un *composable* propio (`RNF-MANT-007`); ningún componente importa `@vueuse/core` para esto.
- **Ortogonal a la marca**: el centro **no** define paleta oscura y **no** puede forzar ni desactivar el modo oscuro («Preguntas abiertas», P3). El par de marca (`--brand-primary`/`--brand-primary-foreground`) **se usa idéntico en ambos modos**, porque su contraste es interno al par. Lo que cambia con el modo son los neutros (fondo, texto, bordes) y la variante derivada de §3.3.
- **Destello inicial**: con `system`, ninguno, porque la hoja de estilos (bloqueante en `<head>`) resuelve fondo y `color-scheme` por *media query*. Con preferencia explícita contraria al sistema puede haber un destello de un fotograma hasta que corre `main.ts`. Se acepta; eliminarlo exige un `<script>` en línea en `index.html`, que solo es admisible con *hash* en la CSP (`SECURITY.md`, `OPEN-11`) y no compensa en `1.7`.

### 3. Tokens: variables CSS como única fuente, en tres niveles

#### 3.1 Niveles

| Nivel | Qué es | Quién lo escribe |
|-------|--------|------------------|
| **Entrada de marca** `--brand-*` | Colores del centro y sus derivadas | **Solo** la capa A, en tiempo de ejecución. Ninguna hoja de estilos ni componente los define |
| **Semántico** | Nombres de shadcn-vue (`--primary`, `--background`…) más los añadidos de §3.2, con valor en `:root` y en `.dark` | La hoja de tokens. Los semánticos de marca son `var(--brand-…, <neutro>)`, con neutro de respaldo |
| **Utilidad** | Mapeo a Tailwind en `@theme inline` | La hoja de tokens |

Así el tema del centro nunca pisa un semántico directamente, y el modo oscuro decide en CSS —no en JavaScript— qué variante derivada usa cada semántico. La tabla exacta `--brand-*` → semántico la fija la especificación, con dos restricciones de este ADR: **`color_secondary` alimenta `--primary-foreground`**, nunca `--secondary` (que en shadcn-vue es una superficie neutra, no un color de marca); y **la marca solo toca los semánticos de «primario»** (`--primary`, `--primary-foreground`, `--sidebar-primary`, `--sidebar-primary-foreground`, la variante de §3.3 y `--ring`). Ningún otro semántico depende del centro.

#### 3.2 Qué se añade y qué no

- **Se añaden**: estados `success`/`warning`/`info` con su `-foreground` (shadcn-vue solo trae `destructive`, y `RUX-006` exige estados diseñados), la variante de §3.3, y duraciones de animación como token para poder anularlas en bloque (`RUX-005`).
- **No se redefinen** espaciado, sombras ni escala tipográfica: la escala de Tailwind v4 ya es un sistema de tokens (`--spacing`, `--shadow-*`, `--text-*`) documentado y versionado por su proyecto. Se redefine un token solo cuando hay una divergencia real, no para tener una tabla propia.
- **Tipografía**: pila del sistema, sin fuente web. Evita una dependencia, una petición a terceros (con su problema de protección de datos si fuera un CDN) y peso. Si la identidad de marca de `0.11c` elige una tipografía, cambia un token. `components.json` declara `geist-sans` y no se usa: es una incoherencia menor a corregir en `1.7`.
- **`prefers-reduced-motion`**: regla global que anula duraciones y animaciones; los componentes no lo resuelven uno a uno.
- **Ubicación**: los tokens salen de `style.css` a un fichero propio de tokens importado por `style.css`, separado del mapeo a Tailwind. No es cosmético: es la pieza que se extraerá cuando exista el segundo consumidor (§5).

#### 3.3 Uso del color de marca sobre el fondo de la página

Se introduce una **variante derivada con contraste garantizado**, `--primary-on-background` en nivel semántico (nombre fijado aquí para que no haya deriva), con **un valor para modo claro y otro para oscuro**, ambos calculados por la capa A a partir de `color_primary`:

- Se conserva tono y croma en OKLCH y se ajusta **solo la luminosidad** hasta alcanzar **4,5:1** contra el fondo del modo correspondiente, calculado con la fórmula de WCAG, no con un umbral aproximado.
- Es una **función pura** con test unitario sobre un barrido de colores (incluidos amarillos claros, azules oscuros y grises), en TypeScript y sin dependencia nueva. Duplica la fórmula de contraste de `ContrastRatioCalculator`, y se acepta: aquí es cálculo de presentación, no validación de negocio (`INV-010` sigue en servidor).
- Con esta variante, `--ring` pasa a cumplir 3:1 de contraste no textual por construcción.

Y una **regla de uso**, exigible por test y no por revisión:

- `bg-primary` va siempre con `text-primary-foreground`.
- **Prohibidos** `text-primary`, `border-primary`, `outline-primary` y `ring-primary` en los componentes propios: usan la variante `*-primary-on-background`. Los ocho componentes ya vendorizados se adaptan en `1.7` (son código del repositorio, no de la dependencia).
- **Prohibido** cualquier color literal (hex, `rgb()`, `oklch()`, clases arbitrarias de Tailwind `[#…]`) fuera de la hoja de tokens.

Un test de Vitest recorre `src/` y falla ante cualquiera de las tres. Otro test calcula el contraste de **todos los pares semánticos estáticos** (neutros y estados) en ambos modos, para que un cambio de token que rompa AA falle en CI y no en una auditoría.

### 4. Validación de contraste: el servidor sigue siendo la autoridad

`RN-CORE-15` no cambia. El cliente puede reutilizar la función de §3.3 para **previsualizar** el contraste en la futura pantalla de configuración de marca, pero su resultado nunca decide nada: el `422` del servidor es la única validación. No se añade validación de servidor contra los fondos, porque la derivación de §3.3 hace innecesario restringir más los colores que un centro puede elegir.

### 5. Frontera del *design system* dentro de `apps/web`

El *design system* —hoja de tokens, `src/components/ui/`, capa A y *composable* de modo de color— vive en `apps/web` y **no importa** de `@/modules/*`, `@/api`, el *router* ni `vue-i18n` (un componente base no tiene literales propios: los recibe por *props* o *slots*, lo que además cumple `INV-009` por construcción). El test de §3.3 comprueba también esta frontera.

**No se extrae hoy a un paquete compartido** (`packages/ui`, *workspaces*): hay un solo consumidor. La frontera anterior hace que extraerlo sea mover ficheros, no desenredarlos. La extracción se decide con **ADR propio al abrir la SPA del backoffice** (`ADR-046`), que es cuando aparece el segundo consumidor real. Ese ADR decide también si el backoffice usa los neutros sin marca (lo previsible: no hay tenant) o un acento propio de plataforma.

### 6. Documentación

El *design system* **no es un *bounded context*** y no lleva carpeta `docs/modulos/REQ-*`: no tiene datos, API ni permisos, y tres de los cinco ficheros de la plantilla quedarían vacíos. Se documenta en **`docs/design-system.md`**, con el mismo criterio que ya tiene `docs/i18n.md` para otra pieza transversal de *frontend*: niveles de tokens, tabla de semánticos con su valor en cada modo, reglas de uso de §3.3, catálogo de componentes base y cómo añadir uno. La especificación de `1.7` usa los ID `RUX-*`/`RNF-UX-*` como trazabilidad; el prefijo de sus criterios de aceptación lo propone `spec-writer`.

---

## Motivo

**§1, una sola fuente y dos capas.** La duplicación que hay que evitar no está en el código de hoy (una sola pantalla), está en el de mañana: sin mecanismo general, `1.8` escribiría el suyo en el *layout* y cada pantalla con marca haría su propia llamada. La partición en dos capas no es por pureza: la capa A es exactamente lo que necesita la SPA del backoffice, que no tiene tenant, y la capa B es exactamente lo que no debe viajar a ella.

**§2 y §3.3, derivar y no pedir una segunda paleta.** Evaluadas las tres salidas del problema de contraste:

| Criterio | **Derivar la variante** (elegida) | **Paleta oscura por centro** | **Restringir la marca a superficies** |
|---|---|---|---|
| Coste en solitario | Bajo: una función pura con test, sin tocar servidor | Alto: dos columnas, migración, validación de dos pares más, API, pantalla de configuración y documentación en `REQ-CORE` | Mínimo |
| Mantenimiento a 3 años | Una función estable | Cada centro mantiene dos paletas; se configura mal y se olvida | Nulo, pero cada componente nuevo tiene que justificar por qué no usa la marca |
| Invariantes | `RNF-UX-002` garantizado por construcción | Depende de que cada centro elija bien; la validación la protege, pero con más superficie | Garantizado |
| Carga para el centro | Ninguna | Duplica la configuración de marca | Ninguna |
| Reversibilidad | Alta: añadir paleta oscura explícita más tarde es *expand* puro y la variante derivada sería su valor por defecto | Baja: una vez hay datos, retirarla es *contract* | Alta |

La tercera se descarta porque deja los enlaces, los radios marcados y el foco en gris, y la marca de un centro es precisamente lo que `RUX-BRAND-002` pide ver. La segunda añade complejidad al modelo de datos para resolver algo que una función de treinta líneas resuelve sin que el centro haga nada; si un centro llega a pedirla, entra como ampliación sin rehacer nada.

**Preferencia local y no en servidor.** Guardarla en servidor es una columna, un endpoint, su auditoría (`INV-003`) y su documentación para un beneficio —sincronía entre dispositivos— que ningún requisito pide. Además la preferencia hace falta **antes del *login***, donde el servidor no sabe quién es el usuario. Añadirla después es aditivo.

**§3, variables CSS y no un paquete de tokens.** Una capa de tokens versionados (Style Dictionary o equivalente) resuelve el problema de generar tokens para plataformas distintas (iOS, Android, web). Aquí hay un solo formato de salida, CSS, y los tres consumidores previstos (SPA, backoffice, web publicitaria) lo leen de forma nativa. Sería una dependencia nueva y un paso de *build* sin beneficio proporcional.

**§4.** Endurecer `RN-CORE-15` contra los fondos restringiría colores de marca que son perfectamente usables como superficie, y seguiría sin resolver el modo oscuro (ningún color alcanza 4,5:1 contra los dos fondos, §Contexto).

---

## Consecuencias

**Positivas**

- Toda la SPA, pública y autenticada, recibe el tema del centro con una petición por carga y sin destello en visitas repetidas.
- `RNF-UX-004` (modo oscuro) y `RNF-UX-002` (contraste) se cumplen **a la vez** para cualquier par de marca que el servidor acepte, sin tocar el modelo de datos ni el endpoint público.
- `RUX-BRAND-003` (*favicon*), hoy sin aplicar, queda cubierto por el mismo mecanismo.
- Las reglas de §3.3 fallan en CI; no dependen de que un revisor las recuerde.

**Negativas y riesgos aceptados**

- **La variante derivada no es el color exacto del centro** cuando se usa como texto o borde: es el mismo tono, más claro u oscuro. Un centro con una guía de marca estricta puede notarlo. Es el precio de no pedirle una segunda paleta.
- **Un primario muy oscuro en modo oscuro** (o muy claro en modo claro) produce un botón cuyo borde apenas se distingue del fondo. El texto del botón sí cumple AA y es lo que lo identifica, así que no incumple 1.4.11; pero es un riesgo visual que la especificación puede mitigar con un borde neutro en esa variante.
- **Duplicación de la fórmula de contraste** en PHP y TypeScript. Acotada a una función con test en cada lado.
- **Destello de un fotograma** con preferencia explícita contraria al sistema (§2).
- **La preferencia de modo no viaja entre dispositivos** ni entre centros con distinto dominio (orígenes distintos). Es coherente con el resto: la sesión tampoco lo hace.
- El inventario de `1.7` crece: adaptar los ocho componentes vendorizados, dos tests de arquitectura en Vitest y la migración de `PublicAuthShell`/`usePublicAuthScreen`.

**Requisitos afectados**: ninguno se reescribe. `RUX-BRAND-006` se interpreta como hoy (contraste del par), y §3.3 lo complementa en cliente sin cambiar su letra.

---

## Alternativas descartadas

- **Mecanismo distinto para pantallas públicas y autenticadas.** Solo tendría sentido si antes del *login* no hubiera tenant, y lo hay (resolución por *host*).
- **Cargar el tema desde `GET /tenant/settings` en la parte autenticada.** Exige `configuracion.leer.todos`; la mayoría de usuarios recibiría `403`.
- **Añadir a `GET /tenant/branding` las variantes ya calculadas por el servidor.** Evitaría duplicar la fórmula, pero rompe la regla escrita de «ni un campo más» en un endpoint anónimo y enumerable, por algo que el cliente calcula sin coste.
- **Inyectar el tema con un `<style>` generado.** Choca con una CSP estricta sin `'unsafe-inline'` en `style-src`; la escritura por CSSOM no.
- **Pinia para el contexto del centro.** Dependencia nueva para un único dato de solo lectura que un *composable* singleton resuelve igual.
- **Relative color syntax de CSS (`oklch(from var(--brand-primary) …)`) para la variante derivada.** Soportado por los navegadores de `RNF-COMP-001`, pero solo permite fijar la luminosidad por umbral aproximado, no garantizar 4,5:1 contra un fondo concreto, y no se puede probar en Vitest (jsdom no calcula estilos).
- **Paleta oscura configurable por centro** y **restringir la marca a superficies**: ver la tabla de Motivo.
- **Paquete de tokens y extracción a `packages/ui` ahora**: sin segundo consumidor; ver §5.

---

## Hallazgos fuera del ámbito de este ADR

No se corrigen aquí (`architect` solo escribe en `docs/adr/` y en el índice de la sección 18); se reportan para que quien corresponda decida:

1. **Ejemplos de `REQ-CORE/api.md` que el servidor rechazaría.** El par `#1D4ED8`/`#475569` (en el `PATCH` y en las respuestas de `GET /tenant/settings` y `/tenant/branding`) tiene un contraste de ≈ 1,1:1; con `RN-CORE-15` ese `PATCH` devuelve `422`. Contradicción documentación-código, severidad Media mínima (`CLAUDE.md §6.6`), y síntoma del punto siguiente.
2. **Semántica de `color_secondary`.** `RUX-BRAND-002` habla de «paleta primaria y secundaria», que se lee naturalmente como dos colores de marca; la implementación lo trata como color de texto sobre el primario. Este ADR adopta la implementación («Preguntas abiertas», P1).
3. **`CLAUDE.md §6`** enumera la estructura de documentación obligatoria y no incluye ni `docs/i18n.md` (que existe) ni `docs/design-system.md` (que propone §6).
4. **`components.json`** declara `font: geist-sans`, que no se usa (§3.2).

---

## Preguntas abiertas para el usuario

Ninguna bloquea `1.7`: entre paréntesis, lo que se hace si no hay respuesta.

- **P1 · ¿`color_secondary` es «texto sobre el primario» o «segundo color de marca»?** (Texto sobre el primario, que es lo que el servidor valida y la pantalla de login ya usa.) Si es un segundo color de marca, es un cambio de `REQ-CORE` —la validación dejaría de comparar un color contra el otro y el texto sobre el primario se derivaría— y requiere su propio ADR; `1.7` solo cambiaría el mapeo de §3.1.
- **P2 · ¿La preferencia de modo oscuro debe sincronizarse entre dispositivos del mismo usuario?** (No: solo local. Ningún requisito lo pide.)
- **P3 · ¿Un centro debe poder forzar o desactivar el modo oscuro?** (No. `RNF-UX-004` pide soporte y no menciona control del centro; añadirlo sería inventar un requisito.)
- **P4 · Tipografía.** (Pila del sistema hasta que `0.11c` fije identidad de marca.)
