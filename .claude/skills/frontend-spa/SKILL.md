---
name: frontend-spa
description: Reglas del frontend Vue 3 en apps/web. Úsala ante CUALQUIER cambio bajo apps/web (vistas .vue, componentes, rutas, shell.ts, navigation/modules.ts, locales/*.json, apiFetch, DataTable, tests Vitest, E2E Playwright, accesibilidad, textos visibles), aunque el usuario no pida esta skill ni mencione el frontend explícitamente.
---

# Frontend SPA (`apps/web`)

La SPA es un cliente más de la API (`INV-006`). Vue 3 + TypeScript + Vite, Tailwind, **shadcn-vue** (Reka UI) y **TanStack Table**. No es lugar para reglas de negocio ni de seguridad: la UI orienta, el servidor decide.

## Estructura de un módulo web

Cada directorio de `src/modules/<modulo>/` (referencia: `src/modules/fam-unit`) tiene:

| Pieza | Para qué |
|-------|----------|
| `src/modules/<modulo>/shell.ts` | Rutas, `meta.permissions`, entradas de menú y bloques de panel (`ADR-053`) |
| `locales/{es,en,de,fr}.json` | Textos del módulo, con prefijo propio (`famUnit.*`, `prl.*`) |
| `api/` | Llamadas a la API; es superficie pública (`index.ts`) |
| `types/` | Tipos públicos del módulo |
| `views/`, `components/`, `composables/` | Interior privado, cada vista con su `*.spec.ts` |

`AR-11` (`ADR-056`, `src/modules/architecture.spec.ts`) lo comprueba solo: `shell.ts` y los cuatro `locales` existen, el módulo está registrado en `src/navigation/modules.ts` y en `src/i18n/index.ts`, y **de otro módulo solo se importa `api/`, `types/` o `shell`** (`INV-007`). Un módulo nuevo: skill `modulo-nuevo`.

## Reglas no negociables

1. **shadcn-vue es el único design system.** Prohibido mezclar otra librería de componentes (CLAUDE.md §1). Tablas: `@/data-table` (TanStack Table), no una tabla a mano. Composición visual: `docs/design-system.md §22`; solo tokens semánticos, nunca colores sueltos.
2. **Sesión por cookie `httpOnly` + CSRF** (`ADR-025`). Todas las llamadas pasan por `apiFetch` (`src/api/client.ts`): `credentials: 'include'` y `X-XSRF-TOKEN` en toda escritura. **Prohibido JWT o token en `localStorage`** y `fetch` directo.
3. **`INV-009`: ningún literal visible en el código.** Todo por `vue-i18n`, con claves en los cuatro `locales` del módulo. Comprueba con `npm run lint:i18n` (`apps/web/scripts/check-i18n-literals.mjs`); la paridad de claves la vigila `AR-12`/`TranslationParityTest` (`apps/api/tests/Feature/Architecture`). Incluye `aria-label`, `title`, `placeholder`, `alt` y `document.title`. Textos legales o de privacidad: no se traducen solos (skill `i18n-cuatro-idiomas`).
4. **`INV-002`: la UI oculta, el servidor decide.** Muestra una acción si el permiso está en `/me.permissions` (`usePermissions().can`), nunca por rol. Respeta un `403`/`404` del servidor aunque el permiso aparezca en `/me` (deny de otro rol, ámbito). `meta.permissions` de una ruta es el permiso del *endpoint* que pinta su contenido (`ADR-053 §3`); `permissions: []` solo existe en la lista cerrada de muros (`ADR-067 §6`, `src/navigation/modules.spec.ts`).
5. **Validación en cliente solo como ayuda** (`INV-010`). Los errores se pintan con el `message` traducido que trae el servidor (`ADR-038 §6`); un `429` muestra `Retry-After` y no reintenta.
6. **Datos de personas no se persisten en el navegador.** Nada en `localStorage`, `sessionStorage`, IndexedDB, URL ni `document.title` (`REQ-FAM-UNIT/funcional.md §23.2` UI-3, `§23.6`). Rutas con `public_id`, jamás nombres ni documentos.
7. **Lecturas auditadas bajo demanda.** Un `GET` que escribe una fila `read` no se lanza al montar, por sondeo ni por *prefetch*: solo por acción explícita (`§23.2` UI-2). Ver skill `auditoria`.

## Accesibilidad (WCAG 2.2 AA, `RNF-UX-002`)

Pauta completa y ejemplos: `REQ-FAM-UNIT/funcional.md §23.7`. Lo que más se omite: estado nunca solo por color (texto e icono); iconos decorativos con `aria-hidden="true"`; foco que entra en el diálogo, queda atrapado y vuelve al botón; objetivos ≥ 24×24 px; error junto al campo con `aria-describedby` y `aria-invalid`; mensajes de estado en región *live*; usable a 320 px sin scroll horizontal; `prefers-reduced-motion`.

## Tests

- **Vitest** (`npm test`): un `*.spec.ts` junto a cada vista, componente y `api/`; referencia el ID del requisito (`INV-015`). Incluye el caso de acceso denegado, no solo el feliz.
- **E2E con API simulada** (`npm run test:e2e`, Playwright, `e2e/`): prueba la SPA aislada. **No demuestra** que el contrato con la API real funcione.
- **E2E contra la API real** (`npm run test:e2e:real`, `apps/web/scripts/e2e-real-api.sh`; `e2e-real-prl.sh` para `prl`): crea un centro sintético, lanza Playwright con `E2E_REAL_API=1` y lo retira. Obligatorio con el paso implementado (CLAUDE.md §3); puede recrearse el demo sin pedir permiso. Pide la pila de desarrollo levantada y `demo.plataforma.test` en `/etc/hosts`. Datos solo de `REQ-SEED` (`@example.com`, `CLAUDE.md §11`). Di en el informe qué se probó y qué no (p. ej. S3/MinIO).
- No declares una pantalla terminada sin haber ejecutado `lint`, `lint:i18n`, `vue-tsc` y Vitest.

## Antes de cerrar un cambio en `apps/web`

- [ ] ¿Todo texto visible sale de los cuatro `locales` y pasa `lint:i18n`?
- [ ] ¿Las acciones dependen de permisos de `/me` y no de roles?
- [ ] ¿Solo `apiFetch`, sin token en el navegador ni datos de personas persistidos?
- [ ] ¿Solo componentes shadcn-vue y tokens semánticos?
- [ ] ¿Teclado, foco, contraste y 320 px comprobados?
- [ ] ¿`*.spec.ts` y E2E contra API real (o dicho que no se hizo)?
