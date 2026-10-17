/**
 * `docs/adr/ADR-053-registro-de-navegacion-y-bloques-del-panel.md` §1-§2 y
 * `docs/modulos/REQ-ACAD/funcional.md §10` (paso 1.11-b). Superficie pública de
 * `acad`, junto a `api/index.ts` y `types/index.ts`.
 *
 * Cinco rutas, todas con `meta.permissions` **exactamente**
 * `estructura_academica.leer` (el permiso del *endpoint* que pinta su contenido
 * principal, anyOf, `ADR-053 §3`), nunca un rol. Las acciones (crear, editar,
 * borrar, asignar asignaturas) se gobiernan dentro de cada vista por su permiso
 * y por el estado del curso académico (`RN-CORE-61`, `CA-ACAD-046`). **Ninguna
 * ruta usa `permissions: []`**: la lista cerrada de `RN-CORE-24` no cambia.
 *
 * Todas llevan `meta.academicYearScoped: true`: el *shell* muestra el selector de
 * curso académico (`§10.2`); en las fichas queda fijo en el curso de la entidad.
 * `1.11d-b` (`funcional.md §10.5.1`) añade las cuatro rutas de escalas de
 * calificación con `configuracion_academica.<acción>` (recurso distinto de
 * `estructura_academica`, `RN-ACAD-50`): lista, alta, ficha y edición; solo la
 * lista tiene entrada de menú. El alta y la lista muestran el selector de curso
 * académico; la ficha y la edición lo dejan fijo en el curso de la escala.
 * Con `acad` descontratado, `/me.permissions` deja de incluir sus permisos
 * (inertes, `REQ-PERM §9`): la entrada de menú y las rutas desaparecen
 * (`RMOD-008`). Sin `dashboardBlocks` (`§10.3`).
 */
import { GraduationCap, ListOrdered } from '@lucide/vue'
import type { ModuleShell } from '@/navigation/types'

export const shell: ModuleShell = {
  routes: [
    {
      path: '/academico/estructura',
      name: 'acad-structure',
      component: () => import('./views/StructureView.vue'),
      meta: {
        layout: 'app',
        permissions: ['estructura_academica.leer'],
        titleKey: 'acad.structure.title',
        academicYearScoped: true,
      },
    },
    // ADR-053 §4.4: sin entrada de menú — destino de un enlace del listado.
    {
      path: '/academico/etapas/:publicId',
      name: 'acad-level-detail',
      component: () => import('./views/LevelDetailView.vue'),
      meta: {
        layout: 'app',
        permissions: ['estructura_academica.leer'],
        titleKey: 'acad.level.title',
        breadcrumbKey: 'acad.level.title',
        breadcrumbParent: 'acad-structure',
        academicYearScoped: true,
      },
    },
    {
      path: '/academico/cursos/:publicId',
      name: 'acad-course-detail',
      component: () => import('./views/CourseDetailView.vue'),
      meta: {
        layout: 'app',
        permissions: ['estructura_academica.leer'],
        titleKey: 'acad.course.title',
        breadcrumbKey: 'acad.course.title',
        breadcrumbParent: 'acad-structure',
        academicYearScoped: true,
      },
    },
    {
      path: '/academico/grupos/:publicId',
      name: 'acad-group-detail',
      component: () => import('./views/GroupDetailView.vue'),
      meta: {
        layout: 'app',
        permissions: ['estructura_academica.leer'],
        titleKey: 'acad.group.title',
        breadcrumbKey: 'acad.group.title',
        breadcrumbParent: 'acad-structure',
        academicYearScoped: true,
      },
    },
    {
      path: '/academico/asignaturas/:publicId',
      name: 'acad-subject-detail',
      component: () => import('./views/SubjectDetailView.vue'),
      meta: {
        layout: 'app',
        permissions: ['estructura_academica.leer'],
        titleKey: 'acad.subject.title',
        breadcrumbKey: 'acad.subject.title',
        breadcrumbParent: 'acad-structure',
        academicYearScoped: true,
      },
    },
    {
      path: '/academico/escalas',
      name: 'acad-grading-scales',
      component: () => import('./views/GradingScalesView.vue'),
      meta: {
        layout: 'app',
        permissions: ['configuracion_academica.leer'],
        titleKey: 'acad.scales.title',
        academicYearScoped: true,
      },
    },
    // `/nueva` antes que `/:publicId`: el segmento fijo gana al paramétrico.
    {
      path: '/academico/escalas/nueva',
      name: 'acad-grading-scale-new',
      component: () => import('./views/GradingScaleFormView.vue'),
      meta: {
        layout: 'app',
        permissions: ['configuracion_academica.crear'],
        titleKey: 'acad.form.title.create.scale',
        breadcrumbKey: 'acad.form.title.create.scale',
        breadcrumbParent: 'acad-grading-scales',
        academicYearScoped: true,
      },
    },
    {
      path: '/academico/escalas/:publicId',
      name: 'acad-grading-scale-detail',
      component: () => import('./views/GradingScaleDetailView.vue'),
      meta: {
        layout: 'app',
        permissions: ['configuracion_academica.leer'],
        titleKey: 'acad.scales.detailTitle',
        breadcrumbKey: 'acad.scales.detailTitle',
        breadcrumbParent: 'acad-grading-scales',
        academicYearScoped: true,
      },
    },
    {
      path: '/academico/escalas/:publicId/editar',
      name: 'acad-grading-scale-edit',
      component: () => import('./views/GradingScaleFormView.vue'),
      meta: {
        layout: 'app',
        permissions: ['configuracion_academica.actualizar'],
        titleKey: 'acad.form.title.edit.scale',
        breadcrumbKey: 'acad.form.title.edit.scale',
        breadcrumbParent: 'acad-grading-scales',
        academicYearScoped: true,
      },
    },
  ],
  navigation: [
    {
      id: 'acad.structure',
      route: 'acad-structure',
      labelKey: 'acad.nav.structure',
      icon: GraduationCap,
      section: 'administracion',
      shortcut: false,
    },
    {
      id: 'acad.scales',
      route: 'acad-grading-scales',
      labelKey: 'acad.nav.gradingScales',
      icon: ListOrdered,
      section: 'administracion',
      shortcut: false,
    },
  ],
  dashboardBlocks: [],
}
