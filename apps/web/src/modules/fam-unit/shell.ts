/**
 * `docs/adr/ADR-053-registro-de-navegacion-y-bloques-del-panel.md` §1-§2 y `docs/adr/ADR-067-registro-de-muros-de-sesion.md`
 * §5.11 (`REQ-FAM-UNIT`, paso 1.14-c). Superficie pública de `fam-unit` en la SPA, junto a `api/index.ts`, `types/index.ts`
 * y `locales/`: de momento solo el muro de condiciones de uso (`REQ-FAM-UNIT-003`); las pantallas de personal son de
 * `1.14-d`.
 *
 * `fam-unit-terms-wall` es `bare` con `meta.permissions: []`: la alcanza **cualquier** usuario autenticado con el muro
 * pendiente, un estado en el que no hay permiso real que declarar sin inventarlo (`INV-002`), mismo motivo que
 * `mfa-enrollment-wall` (`src/modules/auth/shell.ts`). Es la octava de la lista cerrada de rutas con `[]`
 * (ampliación L2 de `ADR-067 §6`, aprobada el 2026-10-28; `src/navigation/modules.spec.ts`). Sin entrada de menú ni
 * bloque de panel: el muro no se navega, se llega a él.
 */
import type { ModuleShell } from '@/navigation/types'

export const shell: ModuleShell = {
  routes: [
    {
      path: '/condiciones-de-uso',
      name: 'fam-unit-terms-wall',
      component: () => import('./views/TermsWallView.vue'),
      meta: { layout: 'bare', permissions: [], titleKey: 'famUnit.termsWall.title' },
    },
  ],
  navigation: [],
  dashboardBlocks: [],
  // ADR-067 §5.11: el muro de condiciones. El *guard* lleva aquí al usuario cuando `GET /me` trae `terms-acceptance` en
  // `session_walls` y el cliente HTTP ante `403 terms-acceptance-required`.
  sessionWalls: [
    {
      code: 'terms-acceptance',
      errorType: 'terms-acceptance-required',
      route: 'fam-unit-terms-wall',
    },
  ],
}
