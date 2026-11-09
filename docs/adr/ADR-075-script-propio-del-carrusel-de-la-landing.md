# ADR-075 · Script propio del carrusel de la landing: avance automático con puntos, flechas y pausa, por mejora progresiva y con CSP `script-src 'self'`

**Estado**: **ACEPTADA** (2026-11-10; decisión del usuario, opción B de la pregunta «¿Cómo hacemos el carrusel con avance automático, puntos y flechas?», con la recomendación de la sesión).
**Fecha**: 2026-11-10
**Origen**: petición del usuario del 2026-11-10: carrusel con desplazamiento automático, selector de puntos y flechas de avance y retroceso, y la pantalla de acceso como primera diapositiva.
**Afecta a**: `apps/landing` (`scripts/headers.mjs`, `scripts/check.mjs`, `scripts/build.mjs`, `src/`, `.lighthouserc.json`, tests). No afecta a `apps/api` ni a `apps/web`.
**Sustituye en parte a**:
- `ADR-072 §4.1` («Sin JavaScript en el sitio publicado») y la directiva CSP de `ADR-072 §4.4` (sin `script-src`).
- `ADR-074 §4.5`, punto «Sin avance automático» y la decisión del usuario del 2026-11-09 («sin JavaScript»), solo para el carrusel de la cabecera. Los efectos de scroll de `ADR-074 §4.5` siguen siendo solo CSS.
**Se apoya en**: `RNF-UX-002` (WCAG 2.2 AA), `RSEC-OWASP-005`/`-006` (cabeceras, CSP estricta), `RNF-MANT-007`.

---

## 1 · Contexto

El usuario aprobó el `ADR-074` con un carrusel sin JavaScript y sin avance automático. Después pidió avance automático, puntos de selección y flechas. Con CSS no se puede animar el desplazamiento de un contenedor con `scroll-snap` (la alternativa, un carril movido con `transform` y casillas ocultas, pierde el gesto de deslizar en móvil y es frágil). Un avance automático exige además, por WCAG 2.2.2 (pausar, detener u ocultar), un control de pausa, y no debe moverse con `prefers-reduced-motion: reduce`.

## 2 · Decisión

1. **Un único script propio**, `/js/carousel.js`, servido del mismo origen, sin dependencias, de pocos KB, sin `eval`, sin `innerHTML` con datos externos y sin código en línea. La CSP pasa a `script-src 'self'`; el resto de directivas no cambia.
2. **Mejora progresiva.** Sin JavaScript el carrusel es el de `ADR-074 §4.5` (desplazamiento con `scroll-snap`, teclado, la siguiente diapositiva asomando) y es completamente utilizable. Con JavaScript el script añade:
   - **Flechas** anterior y siguiente (botones), con la acción `scrollTo` a la diapositiva contigua.
   - **Puntos** de selección (un botón por diapositiva, con `aria-label` y `aria-current="true"` en el activo), sincronizados con el desplazamiento.
   - **Avance automático** cada 6 s, con vuelta a la primera al llegar a la última.
   - **Botón de pausa y reanudación** visible y operable por teclado (WCAG 2.2.2), con `aria-pressed`.
   - Pausa del avance automático al pasar el puntero por encima, al recibir foco dentro del carrusel y cuando la pestaña no está visible.
   - Sin avance automático cuando `prefers-reduced-motion: reduce`; los controles manuales sí aparecen.
3. **Las etiquetas visibles y accesibles** (anterior, siguiente, ir a la diapositiva N, pausar, reanudar) salen de las locales por el mecanismo de plantillas (`{{t:…}}`) como atributos `data-*` del contenedor; el script no contiene literales (`INV-009`).
4. **Controles creados por el script**, no presentes en el HTML sin JavaScript: así los usuarios sin JavaScript no ven botones que no funcionan.
5. **Primera diapositiva: la pantalla de acceso (`sign-in`)**, que pasa a ser la imagen de la LCP (`fetchpriority="high"`, la única sin `loading="lazy"`). El orden restante es el de `ADR-074`.
6. **`check.mjs`**, reglas nuevas con su caso bueno y malo cada una:
   - Se admiten `<script src="/js/…">` del mismo origen; **se prohíben** `<script>` en línea, `src` de otro origen, atributos de manejador de evento (`onclick`…), `javascript:` en URL, `eval`, `new Function` y `document.write` en el fichero del script.
   - La CSP de `_headers` debe ser exactamente la nueva, y `dist/js/` solo puede contener `carousel.js` (y su fuente asociada si la hubiera).
   - El script no puede contener literales visibles (las etiquetas vienen de `data-*`).
   - Peso máximo del script: 6 KiB sin comprimir.
7. **Pruebas.** El comportamiento (avance, pausa, `prefers-reduced-motion`, límites, sincronización de puntos) se escribe como lógica pura con una capa mínima de DOM y se prueba con `node --test` y un DOM de pruebas **sin dependencia nueva** (una maqueta mínima en los tests). Verificación real en navegador con el Chromium de Playwright y Lighthouse (accesibilidad = 1).
8. **Revisión de seguridad obligatoria** (`security-reviewer`) antes de mezclar: cambia la CSP de un sitio público.

## 3 · Consecuencias

- La landing deja de ser «sin JavaScript»: pasa a «sin JavaScript necesario, con un script propio y mínimo de mejora». `script-src 'self'` no admite scripts en línea ni de terceros, de modo que la superficie de ataque añadida es un fichero propio y revisable. Siguen sin cookies, sin analítica y sin recursos de terceros.
- Mantiene el deslizar en móvil y el teclado, y sigue funcionando si el script falla o está bloqueado.
- Coste: un fichero más que mantener, reglas nuevas en `check.mjs` y pruebas de comportamiento.
- Los umbrales de Lighthouse (accesibilidad 1, rendimiento ≥ 0,95, LCP ≤ 2,5 s, CLS ≤ 0,05) no cambian; el script se carga con `defer` y no bloquea el renderizado.
- La imagen `sign-in` como primera impresión se quedará desfasada cuando se implemente el paso `1.15a` (`ADR-064`): hay que regenerarla entonces.

## 4 · Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| Solo CSS con carril y casillas ocultas (opción A) | Pierde el deslizar con el dedo, el avance se detiene al interactuar y es frágil de mantener. |
| Puntos y flechas sin avance automático | Cumple `ADR-074` sin cambios, pero no es lo que pidió el usuario. |
| Librería de carruseles (Swiper, etc.) | Dependencia externa grande y con superficie de ataque para una función de 100 líneas (`CLAUDE.md §1`, `RNF-MANT-007`). |

## 5 · Discrepancia registrada (`CLAUDE.md §0`)

La sesión advirtió antes de ejecutar que el avance automático choca con la decisión de «sin JavaScript» y con la elección deliberada de `ADR-074` de no tener movimiento automático (WCAG 2.2.2), y propuso tres opciones con sus costes. El usuario eligió la opción B, la recomendada. Queda como riesgo aceptado que un carrusel con avance automático puede distraer o incomodar a algunos usuarios; se mitiga con la pausa visible, la pausa al enfocar o pasar el puntero, y la desactivación con movimiento reducido.
