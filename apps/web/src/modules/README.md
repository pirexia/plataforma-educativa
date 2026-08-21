# src/modules/

Espejo de `apps/api/app/Modules/` (`ARCHITECTURE.md` §3): un directorio por bounded context, con sus vistas, componentes y llamadas a la API propias del módulo. `src/components/ui/` es aparte — son los componentes de shadcn-vue, compartidos por todos los módulos.

## `core/`

Primer módulo real (`REQ-CORE`, paso 1.1). Por decisión explícita de `docs/modulos/REQ-CORE/funcional.md` §1.11, 1.1 es **solo API**: entrega `api/` (cliente tipado sobre `@/api/client`), `types/` (formas de los recursos, coherentes con `apps/api/openapi/{components,paths/core}.yaml`) y `locales/` (literales del módulo en los cuatro idiomas de `ADR-021`, ensamblados en `@/i18n` bajo el espacio de nombres `core`). **Nada en `views/` ni `components/` todavía** — las pantallas llegan con el paso 1.8, cuando existan el *design system* (1.7) y el layout (1.8).
