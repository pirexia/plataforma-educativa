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
 * Paso 1.9d (`funcional.md §14.3`, `§14.7`, `§14.8`): auditoría (`auditoria.leer`,
 * con acceso directo) y roles en solo lectura (`rol.leer`). No existe
 * `core-role-detail` (`OPEN-CORE-36` = A: el detalle llega con `1.5b`). Ninguna
 * de las dos usa `permissions: []`.
 *
 * Paso 1.9e (`funcional.md §14.3`, `§14.3.1`, `§14.9`-`§14.10c`): configuración
 * del centro (`configuracion.leer`), activos de marca (misma ruta de permiso,
 * sin entrada de menú: es una acción de `core-settings`), módulos contratados en
 * solo lectura (`modulo.leer`) y perfil propio. **`core-profile` es la única ruta
 * de `core` con `permissions: []`** (autoservicio por identidad, `permisos.md
 * §5.2`; séptima de la lista cerrada de `RN-CORE-24`, `CA-CORE-264`): `PATCH /me`
 * se autoriza por identidad y un permiso para editar los datos propios crearía
 * una forma de dejar a alguien sin poder corregir su propio teléfono.
 *
 * Sin `dashboardBlocks`: ningún módulo, incluido `core`, aporta un bloque
 * del panel en este paso (`OPEN-CORE-13`).
 */
import {
  Blocks,
  Building2,
  FileUp,
  House,
  KeyRound,
  Mail,
  ScrollText,
  UserRound,
  Users,
} from '@lucide/vue'
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
    {
      path: '/administracion/roles',
      name: 'core-roles',
      component: () => import('./views/RolesView.vue'),
      meta: { layout: 'app', permissions: ['rol.leer'], titleKey: 'core.roles.title' },
    },
    {
      path: '/administracion/auditoria',
      name: 'core-audit',
      component: () => import('./views/AuditView.vue'),
      meta: { layout: 'app', permissions: ['auditoria.leer'], titleKey: 'core.audit.title' },
    },
    {
      path: '/administracion/centro',
      name: 'core-settings',
      component: () => import('./views/SettingsView.vue'),
      meta: {
        layout: 'app',
        permissions: ['configuracion.leer'],
        titleKey: 'core.settings.title',
      },
    },
    // ADR-053 §4.4: sin entrada de menú — acción de `core-settings`, miga de pan bajo «Centro».
    {
      path: '/administracion/centro/marca',
      name: 'core-branding-assets',
      component: () => import('./views/BrandingAssetsView.vue'),
      meta: {
        layout: 'app',
        permissions: ['configuracion.leer'],
        titleKey: 'core.branding.title',
        breadcrumbKey: 'core.branding.title',
        breadcrumbParent: 'core-settings',
      },
    },
    {
      path: '/administracion/modulos',
      name: 'core-modules',
      component: () => import('./views/ModulesView.vue'),
      meta: { layout: 'app', permissions: ['modulo.leer'], titleKey: 'core.modules.title' },
    },
    // Autoservicio por identidad (RN-CORE-88, §14.3.1): la única ruta de `core` con permissions vacío.
    {
      path: '/cuenta/perfil',
      name: 'core-profile',
      component: () => import('./views/ProfileView.vue'),
      meta: { layout: 'app', permissions: [], titleKey: 'core.profile.title' },
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
    {
      id: 'core.roles',
      route: 'core-roles',
      labelKey: 'core.nav.roles',
      icon: KeyRound,
      section: 'administracion',
      shortcut: false,
    },
    {
      id: 'core.audit',
      route: 'core-audit',
      labelKey: 'core.nav.audit',
      icon: ScrollText,
      section: 'administracion',
      shortcut: true,
    },
    {
      id: 'core.settings',
      route: 'core-settings',
      labelKey: 'core.nav.settings',
      icon: Building2,
      section: 'administracion',
      shortcut: false,
    },
    {
      id: 'core.modules',
      route: 'core-modules',
      labelKey: 'core.nav.modules',
      icon: Blocks,
      section: 'administracion',
      shortcut: false,
    },
    {
      id: 'core.profile',
      route: 'core-profile',
      labelKey: 'core.nav.profile',
      icon: UserRound,
      section: 'cuenta',
      shortcut: false,
    },
  ],
  dashboardBlocks: [],
}
