/**
 * `docs/adr/ADR-053-registro-de-navegacion-y-bloques-del-panel.md` §1-§2.
 * Superficie pública de `core`, junto a `api/index.ts` y `types/index.ts`.
 *
 * La ruta `home` (`/`) sigue registrada en `src/router/index.ts`
 * (`funcional.md §12.5`, `ADR-053 §1`: "en router/index.ts quedan solo home,
 * catch-all, centro no encontrado"). Este `shell.ts` aporta la **entrada de
 * navegación** «Inicio», que apunta a esa ruta por nombre — una entrada no
 * tiene que vivir en el mismo fichero que declara su ruta, solo nombrarla
 * (`CA-CORE-103`: toda entrada apunta a un nombre de ruta registrado).
 *
 * Paso 1.9b (`funcional.md §14.3`, `RN-CORE-60`): las pantallas de usuarios e
 * invitaciones se registran aquí, no en `src/router/index.ts`.
 * `meta.permissions` de cada ruta es **exactamente** el permiso del
 * *endpoint* que la vista necesita para pintar su contenido principal
 * (anyOf, `ADR-053 §3`), nunca un rol ni la unión de los permisos de sus
 * acciones secundarias; estas se gobiernan por `RN-CORE-61` dentro de cada
 * vista. La entrada de menú no lleva permisos: se derivan de la ruta.
 * Ninguna ruta de 1.9b usa `permissions: []` (la lista cerrada de
 * `RN-CORE-24` no cambia en este sub-paso, `CA-CORE-208`).
 *
 * Paso 1.9c (`funcional.md §14.3`, `§14.6`): importación de usuarios. Ambas
 * rutas exigen `usuario.importar`, el permiso de los *endpoints* de
 * `/user-imports`; ninguna usa `permissions: []`.
 *
 * Sin `dashboardBlocks`: ningún módulo, incluido `core`, aporta un bloque
 * del panel en este paso (`OPEN-CORE-13`).
 */
import { FileUp, House, Mail, Users } from '@lucide/vue'
import type { ModuleShell } from '@/navigation/types'

export const shell: ModuleShell = {
  routes: [
    {
      path: '/administracion/usuarios',
      name: 'core-users',
      component: () => import('./views/UsersListView.vue'),
      meta: { layout: 'app', permissions: ['usuario.leer'], titleKey: 'core.users.title' },
    },
    // ADR-053 §4.4: sin entrada de menú — destino de una acción de `core-users`.
    {
      path: '/administracion/usuarios/nuevo',
      name: 'core-user-new',
      component: () => import('./views/UserFormView.vue'),
      meta: {
        layout: 'app',
        permissions: ['usuario.crear'],
        titleKey: 'core.users.form.titleNew',
        breadcrumbKey: 'core.users.form.titleNew',
        breadcrumbParent: 'core-users',
      },
    },
    {
      path: '/administracion/usuarios/:publicId',
      name: 'core-user-detail',
      component: () => import('./views/UserDetailView.vue'),
      meta: {
        layout: 'app',
        permissions: ['usuario.leer'],
        titleKey: 'core.users.detail.title',
        breadcrumbKey: 'core.users.detail.title',
        breadcrumbParent: 'core-users',
      },
    },
    {
      path: '/administracion/usuarios/:publicId/editar',
      name: 'core-user-edit',
      component: () => import('./views/UserFormView.vue'),
      meta: {
        layout: 'app',
        permissions: ['usuario.actualizar'],
        titleKey: 'core.users.form.titleEdit',
        breadcrumbKey: 'core.users.form.titleEdit',
        breadcrumbParent: 'core-users',
      },
    },
    {
      path: '/administracion/invitaciones',
      name: 'core-invitations',
      component: () => import('./views/InvitationsView.vue'),
      meta: {
        layout: 'app',
        permissions: ['invitacion.leer'],
        titleKey: 'core.invitations.title',
      },
    },
    {
      path: '/administracion/importaciones',
      name: 'core-user-imports',
      component: () => import('./views/UserImportsView.vue'),
      meta: {
        layout: 'app',
        permissions: ['usuario.importar'],
        titleKey: 'core.userImports.title',
      },
    },
    // ADR-053 §4.4: sin entrada de menú — destino de una acción de `core-user-imports`.
    {
      path: '/administracion/importaciones/:publicId',
      name: 'core-user-import-detail',
      component: () => import('./views/UserImportDetailView.vue'),
      meta: {
        layout: 'app',
        permissions: ['usuario.importar'],
        titleKey: 'core.userImports.detail.pageTitle',
        breadcrumbKey: 'core.userImports.detail.pageTitle',
        breadcrumbParent: 'core-user-imports',
      },
    },
  ],
  navigation: [
    {
      id: 'core.home',
      route: 'home',
      labelKey: 'core.nav.home',
      icon: House,
      section: 'inicio',
      shortcut: false,
    },
    {
      id: 'core.users',
      route: 'core-users',
      labelKey: 'core.nav.users',
      icon: Users,
      section: 'administracion',
      shortcut: true,
    },
    {
      id: 'core.invitations',
      route: 'core-invitations',
      labelKey: 'core.nav.invitations',
      icon: Mail,
      section: 'administracion',
      shortcut: false,
    },
    {
      id: 'core.userImports',
      route: 'core-user-imports',
      labelKey: 'core.nav.userImports',
      icon: FileUp,
      section: 'administracion',
      shortcut: false,
    },
  ],
  dashboardBlocks: [],
}
