# ADR-064 · Capa visual de la interfaz: login en disposición «Panel», radio de 2 px y sombra del panel de acceso

**Estado**: **ACEPTADA** (2026-10-16; decisiones tomadas por el usuario sobre la maqueta navegable, versión 5, tras cinco iteraciones; issue [#431](https://github.com/pirexia/plataforma-educativa/issues/431)).
**Fecha**: 2026-10-16
**Paso**: decisión previa al paso `1.15a · Capa visual de la interfaz` (`PLAN-IMPLEMENTACION.md`), que la implementa y especifica lo que este ADR deja abierto (§6).
**Se apoya en**: `RUX-001`, `RUX-BRAND-001` a `-006`, `RNF-UX-002`; `ADR-014` (tenant por subdominio), `ADR-023`, `ADR-052`, `ADR-062`; `docs/design-system.md §4`, `§13` y `§22`; `REQ-SEED-005`.
**Complementa `ADR-052`** sin cambiar ninguna de sus decisiones: `ADR-052` fija el mecanismo de tema, los niveles de tokens y el uso de la marca, pero no el radio, las sombras ni la disposición de la pantalla de acceso.
**Enmienda `docs/design-system.md §22`**, reglas 3 (sombras) y 5 (radios), en lo que se indica en §3 y §4. El resto de `§22` sigue en vigor.

---

## 1 · Contexto

El 2026-10-15/16 el usuario entró en el centro de demostración y valoró la interfaz como «correcta pero poco atractiva»: en blanco y negro, sin imágenes. Hay dos causas, y este ADR solo trata la segunda:

1. **El centro de prueba no tenía marca.** `GET /tenant/branding` devolvía nulos y el sistema cae a neutros por diseño (`ADR-052`). Se configuró en desarrollo (color `#0E6E73` e imagen de acceso original) y la pantalla de acceso cambió sin tocar código.
2. **El sistema de diseño es sobrio por decisión.** La marca solo tiñe el primario (`RN-DS-02`), `§22` pide diseño plano y reserva la sombra a capas flotantes, el radio es `0.625rem` y la pantalla de acceso es una tarjeta centrada sobre fondo neutro o sobre la imagen del centro (`PublicAuthShell.vue`).

La pantalla de acceso es **por centro**: cada centro entra por su subdominio (`ADR-014`) y la SPA resuelve el tenant por *host* antes del *login* (`ADR-052 §1`). Un *host* sin centro no muestra login (`RN-CORE-35`). Por eso la pantalla lleva la identidad del centro, y la de la plataforma solo de forma secundaria.

## 2 · Decisión: pantalla de acceso en disposición «Panel»

1. **Escritorio**: la **imagen del centro ocupa la parte izquierda** de la pantalla y el **panel de acceso, a toda altura, la derecha**. El panel es **estrecho**: entre 360 y 460 px de ancho según la pantalla (referencia de la maqueta: `clamp(360px, 30vw, 460px)`); la imagen ocupa el resto.
2. **Contenido del panel**, de arriba abajo: logo y nombre del centro; título, texto de ayuda y formulario; accesos externos configurados por el centro (Google, SSO); pie con el nombre de la plataforma («Con tecnología de …», marcador hasta `0.11c`) y el selector de idioma. **El nombre de la plataforma no va dentro del formulario.**
3. **Sobre la imagen**, abajo a la izquierda, un **texto de bienvenida** del centro (antetítulo, título y una línea). Como el contraste del texto no puede depender de la imagen, va sobre un **velo degradado** que garantiza 4,5:1 (`RNF-UX-002`). La imagen es decorativa (fondo CSS, sin texto alternativo).
4. **Sin imagen propia del centro**, se usa una **ilustración por defecto de la plataforma**: original, abstracta, **sin personas** (`REQ-SEED-005`) y con licencia propia.
5. **Móvil** (por debajo del *breakpoint* de una columna): la imagen pasa a una **franja superior** con el título de bienvenida y el formulario ocupa el resto, **sin sombra**. Sin desbordamiento horizontal a 320 px (`RUX-RESP`, WCAG 1.4.10).

## 3 · Decisión: sombra del panel de acceso

1. El panel de acceso proyecta una **sombra marcada y direccional hacia la izquierda**, sobre la imagen, para separarlo de ella. Valor de referencia de la maqueta: `-2px 0 6px rgba(0,0,0,.28), -12px 0 40px rgba(0,0,0,.42)`. Una sombra simétrica no se percibe sobre una imagen oscura; por eso es direccional.
2. Se define como **token propio** en la hoja de tokens (`--shadow-auth-panel` o el nombre que fije la especificación), no como valor literal en el componente.
3. **Enmienda de `§22` regla 3**: la sombra sigue reservada a capas flotantes (menús, *popovers*, modales) **con una única excepción nueva, el panel de acceso de §2**. Ninguna otra superficie la usa por analogía; una excepción más exige otro ADR.

## 4 · Decisión: radio de 2 px

1. **`--radius` pasa de `0.625rem` a `2px`.** Afecta a botones, cajas de texto, tarjetas, menús y diálogos, porque todos los radios derivan de él (`--radius-sm/md/lg/xl`).
2. **Enmienda de `§22` regla 5**: «radios coherentes con el tema» se mantiene; cambia el valor base. Las derivadas que con `2px` queden en cero o negativas (`--radius-sm = calc(var(--radius) - 4px)`) se corrigen en la especificación para que ninguna sea negativa.
3. Se descartó el radio 0: con 0 los anillos de foco y los menús desplegables pierden legibilidad visual; 2 px conserva el aspecto recto que se busca.

## 5 · Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| Disposición «Tarjeta» (imagen a sangre y tarjeta flotante con márgenes iguales) | Probada en la maqueta y elegida un momento; el usuario prefirió el panel a toda altura |
| Panel a la izquierda e imagen a la derecha | Probada; el usuario prefirió la imagen a la izquierda y un panel más estrecho |
| Tarjeta translúcida (*glassmorphism*) | Prohibida por `§22` regla 3 y el contraste pasa a depender de lo que hay detrás |
| Cajas rellenas de color claro con texto claro | No llegan a 4,5:1 en texto ni a 3:1 en bordes (WCAG 1.4.3, 1.4.11) |
| Foto de personas como imagen por defecto | `REQ-SEED-005`; y la imagen de un centro la decide el centro, no la plataforma |
| Sombra «sutil» simétrica | No se percibe sobre una imagen oscura |

## 6 · Lo que este ADR deja a la especificación de `1.15a`

1. **Texto de bienvenida personalizable** (`RUX-BRAND-005`): si se añade ya como contenido traducible del centro (`ADR-062`, campo nuevo en `GET /tenant/branding`) o se empieza con un texto genérico traducido. Afecta a la API.
2. **Ilustración por defecto**: diseño final, formato y **encuadre según el tamaño** (en la maqueta, el recorte de una imagen apaisada pierde parte del dibujo).
3. **Valores finales de los tokens** de sombra y radio, en claro y en oscuro, y la corrección de las derivadas de `--radius`.
4. **El resto de la capa visual** que se planteó junto a esto y **no** está decidido: color de marca en el interior de la aplicación (elemento activo del menú, cabecera o barra lateral), acentos, ilustraciones en estados vacíos y página de inicio. Cualquier cambio a `RN-DS-02` exige su propio ADR.
5. Tests: los de arquitectura de `§10` siguen aplicando; el token de sombra nuevo entra en la lista de permitidos de forma explícita.

## 7 · Consecuencias

- `PublicAuthShell.vue` cambia de tarjeta centrada a disposición en dos columnas; las pantallas públicas que lo usan (acceso, recuperación y activación) heredan la disposición.
- El cambio de `--radius` es global y visible en toda la aplicación: se revisa con capturas antes y después.
- `docs/design-system.md §22` recoge las reglas `RN-DS-25` a `RN-DS-29` como **aprobadas y pendientes de implementar** hasta que cierre `1.15a`.
- Referencia visual: maqueta navegable (privada) https://claude.ai/artifact/U5nBzt5e1jWHwRtqUWmwZH, versión 5.
