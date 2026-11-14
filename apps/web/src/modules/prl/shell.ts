/**
 * `docs/adr/ADR-053-registro-de-navegacion-y-bloques-del-panel.md` §1-§2 y
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.3` (`REQ-PRL`, paso `1.14b-c2`). Superficie pública de `prl` en la SPA,
 * junto a `api/index.ts`, `types/index.ts` y `locales/`.
 *
 * Cuatro rutas de **personal del centro**, cada una con `meta.permissions` **exactamente** el permiso del *endpoint* que
 * pinta su contenido principal (anyOf, `ADR-053 §3`), nunca un rol; las acciones de cada vista se gobiernan dentro de
 * ella por su propio permiso (`RN-CORE-61`). Ninguna usa `permissions: []`:
 *
 * - `/conserjeria` y `/conserjeria/alumnos/:studentPersonPublicId` → `entrega_menor.leer`;
 * - `/conserjeria/alertas` y `/conserjeria/alertas/:publicId` → `alerta_entrega.leer`.
 *
 * `document.title` y las migas de pan usan títulos fijos («Conserjería», «Lista de recogida», «Alerta»), nunca el
 * nombre del alumno ni de una persona (§23.6). El orden importa: el segmento fijo `/conserjeria/alertas` gana al
 * paramétrico y la lista de puerta cuelga de `/conserjeria/alumnos/…` (no de `/conserjeria/:algo`).
 *
 * Entradas de menú (§23.3): «Conserjería» (`DoorOpen`, con acceso directo, `OPEN-FAM-UNIT-56` A) y «Alertas de entrega»
 * (`ShieldAlert`), ambas en la sección `administracion`. Una entrada solo se ve si el usuario puede montar su ruta
 * (`RMOD-008`); con `prl` descontratado `/me.permissions` deja de traer sus permisos y desaparecen. Sin bloques de panel.
 */
import { DoorOpen, ShieldAlert } from '@lucide/vue'
import type { ModuleShell } from '@/navigation/types'

export const shell: ModuleShell = {
  routes: [
    {
      path: '/conserjeria',
      name: 'prl-door',
      component: () => import('./views/DoorSearchView.vue'),
      meta: { layout: 'app', permissions: ['entrega_menor.leer'], titleKey: 'prl.door.title' },
    },
    // ADR-053 §4.4: sin entrada de menú — destino de un enlace de la búsqueda.
    {
      path: '/conserjeria/alumnos/:studentPersonPublicId',
      name: 'prl-door-student',
      component: () => import('./views/DoorStudentView.vue'),
      meta: {
        layout: 'app',
        permissions: ['entrega_menor.leer'],
        titleKey: 'prl.door.studentTitle',
        breadcrumbKey: 'prl.door.studentTitle',
        breadcrumbParent: 'prl-door',
      },
    },
    {
      path: '/conserjeria/alertas',
      name: 'prl-alerts',
      component: () => import('./views/AlertsView.vue'),
      meta: {
        layout: 'app',
        permissions: ['alerta_entrega.leer'],
        titleKey: 'prl.nav.alerts',
      },
    },
    // ADR-053 §4.4: sin entrada de menú — destino de un enlace del listado.
    {
      path: '/conserjeria/alertas/:publicId',
      name: 'prl-alert-detail',
      component: () => import('./views/AlertDetailView.vue'),
      meta: {
        layout: 'app',
        permissions: ['alerta_entrega.leer'],
        titleKey: 'prl.alert.title',
        breadcrumbKey: 'prl.alert.title',
        breadcrumbParent: 'prl-alerts',
      },
    },
  ],
  navigation: [
    {
      id: 'prl.door',
      route: 'prl-door',
      labelKey: 'prl.nav.door',
      icon: DoorOpen,
      section: 'administracion',
      shortcut: true,
    },
    {
      id: 'prl.alerts',
      route: 'prl-alerts',
      labelKey: 'prl.nav.alerts',
      icon: ShieldAlert,
      section: 'administracion',
      shortcut: false,
    },
  ],
  dashboardBlocks: [],
}
