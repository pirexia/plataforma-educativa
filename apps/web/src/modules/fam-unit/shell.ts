/**
 * `docs/adr/ADR-053-registro-de-navegacion-y-bloques-del-panel.md` §1-§2, `docs/adr/ADR-067-registro-de-muros-de-sesion.md`
 * §5.11 y `docs/modulos/REQ-FAM-UNIT/funcional.md §10` (`REQ-FAM-UNIT`, pasos 1.14-c y 1.14-d). Superficie pública de
 * `fam-unit` en la SPA, junto a `api/index.ts`, `types/index.ts` y `locales/`.
 *
 * Pantallas de **personal del centro** (1.14-d), cada una con `meta.permissions` **exactamente** el permiso del *endpoint*
 * que pinta su contenido principal (anyOf, `ADR-053 §3`), nunca un rol; las acciones de cada vista se gobiernan dentro
 * de ella por su propio permiso (`RN-CORE-61`). Ninguna de las cuatro usa `permissions: []`:
 *
 * - `/familias` y `/familias/:publicId` → `familia.leer`; `/familias/consentimientos` → `consentimiento_imagen.leer`
 *   (declarada antes que `/:publicId`: el segmento fijo gana al paramétrico);
 * - `/configuracion/condiciones-de-uso` → `texto_legal.leer`.
 *
 * Entradas de menú (§10): «Familias» y «Condiciones de uso», ambas en la sección `administracion` (el catálogo de
 * secciones es cerrado, `ADR-053 §4.2`: no hay sección «configuración», `D-43`). Una entrada solo se ve si el usuario puede
 * montar su ruta (`RMOD-008`); con el módulo descontratado, `/me.permissions` deja de traer sus permisos y desaparecen.
 * Sin bloques de panel (§10).
 *
 * `fam-unit-terms-wall` es `bare` con `meta.permissions: []`: la alcanza **cualquier** usuario autenticado con el muro
 * pendiente, un estado en el que no hay permiso real que declarar sin inventarlo (`INV-002`), mismo motivo que
 * `mfa-enrollment-wall` (`src/modules/auth/shell.ts`). Es la octava de la lista cerrada de rutas con `[]`
 * (ampliación L2 de `ADR-067 §6`, aprobada el 2026-10-28; `src/navigation/modules.spec.ts`); `1.14-d` **no** la amplía.
 * Sin entrada de menú ni bloque de panel: el muro no se navega, se llega a él.
 */
import { FileText, UsersRound } from '@lucide/vue'
import type { ModuleShell } from '@/navigation/types'

export const shell: ModuleShell = {
  routes: [
    {
      path: '/familias',
      name: 'fam-unit-list',
      component: () => import('./views/FamilyUnitsView.vue'),
      meta: { layout: 'app', permissions: ['familia.leer'], titleKey: 'famUnit.units.title' },
    },
    // Antes que `/familias/:publicId`: el segmento fijo gana al paramétrico.
    {
      path: '/familias/consentimientos',
      name: 'fam-unit-consents',
      component: () => import('./views/ConsentsView.vue'),
      meta: {
        layout: 'app',
        permissions: ['consentimiento_imagen.leer'],
        titleKey: 'famUnit.consents.title',
        breadcrumbKey: 'famUnit.consents.title',
        breadcrumbParent: 'fam-unit-list',
      },
    },
    // ADR-053 §4.4: sin entrada de menú — destino de un enlace del listado.
    {
      path: '/familias/:publicId',
      name: 'fam-unit-detail',
      component: () => import('./views/FamilyUnitDetailView.vue'),
      meta: {
        layout: 'app',
        permissions: ['familia.leer'],
        titleKey: 'famUnit.detail.pageTitle',
        breadcrumbKey: 'famUnit.detail.pageTitle',
        breadcrumbParent: 'fam-unit-list',
      },
    },
    {
      path: '/configuracion/condiciones-de-uso',
      name: 'fam-unit-terms',
      component: () => import('./views/TermsVersionsView.vue'),
      meta: { layout: 'app', permissions: ['texto_legal.leer'], titleKey: 'famUnit.terms.title' },
    },
    {
      path: '/condiciones-de-uso',
      name: 'fam-unit-terms-wall',
      component: () => import('./views/TermsWallView.vue'),
      meta: { layout: 'bare', permissions: [], titleKey: 'famUnit.termsWall.title' },
    },
  ],
  navigation: [
    {
      id: 'fam-unit.units',
      route: 'fam-unit-list',
      labelKey: 'famUnit.nav.units',
      icon: UsersRound,
      section: 'administracion',
      shortcut: false,
    },
    {
      id: 'fam-unit.terms',
      route: 'fam-unit-terms',
      labelKey: 'famUnit.nav.terms',
      icon: FileText,
      section: 'administracion',
      shortcut: false,
    },
  ],
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
