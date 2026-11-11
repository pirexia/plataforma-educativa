/**
 * `docs/adr/ADR-053-registro-de-navegacion-y-bloques-del-panel.md` §1-§2 y `docs/modulos/REQ-ALUM/funcional.md §10`
 * (`REQ-ALUM`, paso 1.15-d). Superficie pública de `alum` en la SPA, junto a `api/index.ts`, `types/index.ts` y
 * `locales/`.
 *
 * Pantallas de **personal del centro**, cada una con `meta.permissions` **exactamente** el permiso del *endpoint* que
 * pinta su contenido principal (anyOf, `ADR-053 §3`), nunca un rol; las acciones de cada vista se gobiernan dentro de
 * ella por su propio permiso (`RN-CORE-61`). Ninguna usa `permissions: []`.
 *
 * `/alumnado` y `/alumnado/:publicId` → `alumno.leer`; la lista de clase (`/alumnado/listas`) y las solicitudes de
 * cambio de grupo (`/alumnado/cambios-de-grupo`) → `matricula.leer` (`1.15-d` T2b). Las dos últimas se declaran
 * **antes** de `/:publicId` (el segmento fijo gana al paramétrico).
 *
 * Entrada de menú: «Alumnado», en la sección `administracion` (el catálogo de secciones es cerrado, `ADR-053 §4.2`).
 * Una entrada solo se ve si el usuario puede montar su ruta (`RMOD-008`). Sin bloques de panel (§10, #261).
 *
 * `alum-students` lleva `meta.academicYearScoped: true`: el *shell* muestra el selector de curso académico, que vive
 * solo en la URL (`?academic_year=`, `RN-ACAD-40`). La ficha no (`§10`).
 */
import { ArrowRightLeft, ClipboardList, ContactRound } from '@lucide/vue'
import type { ModuleShell } from '@/navigation/types'

export const shell: ModuleShell = {
  routes: [
    {
      path: '/alumnado',
      name: 'alum-students',
      component: () => import('./views/StudentsView.vue'),
      meta: {
        layout: 'app',
        permissions: ['alumno.leer'],
        titleKey: 'alum.students.title',
        academicYearScoped: true,
      },
    },
    {
      path: '/alumnado/listas',
      name: 'alum-class-list',
      component: () => import('./views/ClassListView.vue'),
      meta: {
        layout: 'app',
        permissions: ['matricula.leer'],
        titleKey: 'alum.classList.title',
        academicYearScoped: true,
      },
    },
    {
      path: '/alumnado/cambios-de-grupo',
      name: 'alum-group-changes',
      component: () => import('./views/GroupChangesView.vue'),
      meta: {
        layout: 'app',
        permissions: ['matricula.leer'],
        titleKey: 'alum.groupChanges.title',
        academicYearScoped: true,
      },
    },
    // ADR-053 §4.4: sin entrada de menú — destino de un enlace del listado.
    {
      path: '/alumnado/:publicId',
      name: 'alum-student-detail',
      component: () => import('./views/StudentDetailView.vue'),
      meta: {
        layout: 'app',
        permissions: ['alumno.leer'],
        titleKey: 'alum.detail.pageTitle',
        breadcrumbKey: 'alum.detail.pageTitle',
        breadcrumbParent: 'alum-students',
      },
    },
  ],
  navigation: [
    {
      id: 'alum.students',
      route: 'alum-students',
      labelKey: 'alum.nav.students',
      icon: ContactRound,
      section: 'administracion',
      shortcut: false,
    },
    {
      id: 'alum.classList',
      route: 'alum-class-list',
      labelKey: 'alum.nav.classList',
      icon: ClipboardList,
      section: 'administracion',
      shortcut: false,
    },
    {
      id: 'alum.groupChanges',
      route: 'alum-group-changes',
      labelKey: 'alum.nav.groupChanges',
      icon: ArrowRightLeft,
      section: 'administracion',
      shortcut: false,
    },
  ],
  dashboardBlocks: [],
}
