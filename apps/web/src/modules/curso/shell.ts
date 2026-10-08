/**
 * `docs/adr/ADR-053-registro-de-navegacion-y-bloques-del-panel.md` §1-§2 y
 * `docs/modulos/REQ-CURSO/funcional.md §10` (paso 1.10). Superficie pública de
 * `curso`, junto a `api/index.ts` y `types/index.ts`.
 *
 * Cuatro rutas, todas con `meta.permissions` **exactamente** el permiso del
 * *endpoint* que la vista necesita para pintar su contenido principal (anyOf,
 * `ADR-053 §3`), nunca un rol. Las acciones de la ficha (activar, cerrar)
 * se gobiernan dentro de la vista por `RN-CORE-61`. **Ninguna ruta usa
 * `permissions: []`**: la lista cerrada de `RN-CORE-24` no cambia.
 *
 * El selector de curso (`REQ-CURSO-001` punto 3) está especificado, no
 * construido en 1.10 (`OPEN-CURSO-17`: se construye en 1.11 con su primer
 * consumidor, en `src/academic-year/`). Sin `dashboardBlocks` (`OPEN-CORE-13`).
 */
import { CalendarDays } from '@lucide/vue'
import type { ModuleShell } from '@/navigation/types'

export const shell: ModuleShell = {
  routes: [
    {
      path: '/administracion/cursos',
      name: 'curso-academic-years',
      component: () => import('./views/AcademicYearsListView.vue'),
      meta: {
        layout: 'app',
        permissions: ['curso_academico.leer'],
        titleKey: 'curso.academicYears.title',
      },
    },
    // ADR-053 §4.4: sin entrada de menú — destino de una acción del listado.
    {
      path: '/administracion/cursos/nuevo',
      name: 'curso-academic-year-new',
      component: () => import('./views/AcademicYearFormView.vue'),
      meta: {
        layout: 'app',
        permissions: ['curso_academico.crear'],
        titleKey: 'curso.form.titleNew',
        breadcrumbKey: 'curso.form.titleNew',
        breadcrumbParent: 'curso-academic-years',
      },
    },
    {
      path: '/administracion/cursos/:publicId',
      name: 'curso-academic-year-detail',
      component: () => import('./views/AcademicYearDetailView.vue'),
      meta: {
        layout: 'app',
        permissions: ['curso_academico.leer'],
        titleKey: 'curso.detail.title',
        breadcrumbKey: 'curso.detail.title',
        breadcrumbParent: 'curso-academic-years',
      },
    },
    {
      path: '/administracion/cursos/:publicId/editar',
      name: 'curso-academic-year-edit',
      component: () => import('./views/AcademicYearFormView.vue'),
      meta: {
        layout: 'app',
        permissions: ['curso_academico.actualizar'],
        titleKey: 'curso.form.titleEdit',
        breadcrumbKey: 'curso.form.titleEdit',
        breadcrumbParent: 'curso-academic-year-detail',
      },
    },
  ],
  navigation: [
    {
      id: 'curso.academicYears',
      route: 'curso-academic-years',
      labelKey: 'curso.nav.academicYears',
      icon: CalendarDays,
      section: 'administracion',
      shortcut: false,
    },
  ],
  dashboardBlocks: [],
}
