# src/modules/

Espejo de `apps/api/app/Modules/` (`ARCHITECTURE.md` §3 y §3.4): un directorio por bounded context, con sus vistas, componentes y llamadas a la API propias del módulo. `src/components/ui/` es aparte — son los componentes de shadcn-vue, compartidos por todos los módulos. Hoy hay dos: `auth` y `core` (el backoffice de plataforma tendrá SPA propia, `ADR-046`, no un directorio aquí).

```
src/modules/<modulo>/
├── shell.ts          # rutas, navegación y bloques del panel del módulo (ADR-053): tres listas, cualquiera vacía
├── api/              # cliente tipado sobre @/api/client; api/index.ts es la superficie pública
├── types/            # formas de los recursos; types/index.ts es la superficie pública
├── locales/          # es.json, en.json, de.json, fr.json
├── views/ components/ composables/   # internos del módulo
```

## Reglas comprobadas por `src/modules/architecture.spec.ts` (`ADR-056`, `AR-11`, `INV-007`, `INV-009`)

Un módulo nuevo queda vigilado sin tocar el test: se enumeran los directorios de `src/modules/`. Para cada módulo:

1. Tiene **`shell.ts`**.
2. Tiene **`locales/{es,en,de,fr}.json`**.
3. Está **registrado** en `src/navigation/modules.ts` (importa su `shell`) y en `src/i18n/index.ts` (importa sus cuatro `locales`). Añadir un módulo es añadir esas líneas; el test falla si se olvida cualquiera.
4. **De otro módulo solo importa su superficie pública**: `@/modules/<otro>/api`, `@/modules/<otro>/types` y `@/modules/<otro>/shell` (con o sin `/index`), por alias o por ruta relativa, incluidos los `import()` dinámicos y las reexportaciones. Un fichero interno como `@/modules/<otro>/api/users` o una vista, no. Los `*.spec.ts` no se escanean.

Otros tests de la web complementan la frontera: `src/navigation/architecture.spec.ts` (`layouts/` y `navigation/` solo ven la superficie pública), `src/design-system/architecture.spec.ts` (el _design system_ no importa `@/modules`), `src/data-table/architecture.spec.ts` y `src/roleLiterals.spec.ts` (sin códigos de rol como literal).

## `core/`

Primer módulo real (`REQ-CORE`) y la referencia de módulo de frontend de `ARCHITECTURE.md §3.4`: `shell.ts`, `api/`, `types/`, `locales/` y las pantallas de `views/` y `components/` que llegaron con los pasos 1.8 y 1.9, sobre el _design system_ (1.7) y el layout (1.8). Sus literales se ensamblan en `@/i18n` bajo el espacio de nombres `core`.

## `auth/`

`REQ-AUTH`: `shell.ts` con todas las rutas del módulo, `api/`, `types/`, `locales/` (espacio de nombres `auth`), `views/`, `components/` y `composables/`. Importa de `core` solo `@/modules/core/api`, que es superficie pública.
