/**
 * `docs/adr/ADR-053-registro-de-navegacion-y-bloques-del-panel.md` §1.
 * Superficie pública de `auth`, junto a `api/index.ts`, `types/index.ts` y
 * `locales/`. Contiene **todas** las rutas del módulo, trasladadas desde
 * `src/router/index.ts` en el paso 1.8 — traslado mecánico: 1.8 ya
 * reescribe todas las rutas para añadirles `meta.layout`/`meta.permissions`
 * (`funcional.md §12.5`, `ADR-053 §1`).
 *
 * `meta.permissions` de cada ruta `app`/`bare` (`ADR-053 §3`, `RN-CORE-24`):
 * - `[]` («cualquier usuario autenticado») en `password-change`, `sessions`
 *   y `mfa-security`: autoservicio por identidad, lista cerrada de
 *   `funcional.md §12.5`/`RN-CORE-24`.
 * - `mfa-administration`: unión de los permisos que consumen sus cuatro
 *   áreas, copiados literalmente de `REQ-AUTH/permisos.md §D.6.3` (no
 *   deducidos): `mfa.leer`, `rol.actualizar`, `mfa.eliminar`,
 *   `exencion_mfa.crear`, `exencion_mfa.leer`, `exencion_mfa.eliminar`.
 * - `sso-administration` y sus dos sub-rutas: `proveedor_identidad.leer`
 *   (`funcional.md §12.5`).
 * - `mfa-enrollment-wall`: **`[]`**. Esta ruta es, por diseño, alcanzable
 *   por **cualquier** usuario autenticado sujeto a la obligación de MFA
 *   (`funcional.md §12.3.5`): el *guard* la alcanza cuando `GET /me`
 *   responde `403 mfa-enrollment-required`, un estado en el que **no
 *   existe** ningún `permissions` con el que comparar — no hay forma de
 *   exigir un permiso real sin inventar uno ficticio, que sería peor
 *   (`INV-002`: la autorización nunca se basa en un código inventado). La
 *   prosa de `RN-CORE-24`/`CA-CORE-103` (corregida por el issue #260, ya
 *   cerrado) enumera **seis** rutas con `[]`: las cuatro de autoservicio
 *   de arriba, esta y el *catch-all* de `src/router/index.ts`. El test de
 *   coherencia (`src/navigation/modules.spec.ts`) verifica esas seis.
 */
import { Building2, KeyRound, Laptop, ShieldAlert, ShieldCheck } from '@lucide/vue'
import type { ModuleShell } from '@/navigation/types'

export const shell: ModuleShell = {
  routes: [
    // -- Públicas (funcional.md §1.6, §C.11, api.md §2/§C.2-§C.3, §E.9) ----
    {
      path: '/entrar',
      name: 'login',
      component: () => import('./views/LoginView.vue'),
      meta: { layout: 'public' },
    },
    // REQ-AUTH-002 (1.4), funcional.md §E.9, api.md §E.4.2.
    {
      path: '/entrar/google',
      name: 'oauth-google-callback',
      component: () => import('./views/GoogleCallbackResultView.vue'),
      meta: { layout: 'public' },
    },
    // REQ-AUTH-004 (1.4b), funcional.md §F.9, api.md §F.7.
    {
      path: '/entrar/sso',
      name: 'oauth-sso-callback',
      component: () => import('./views/SsoCallbackResultView.vue'),
      meta: { layout: 'public' },
    },
    {
      path: '/activar/:token',
      name: 'invitation-redemption',
      component: () => import('./views/InvitationRedemptionView.vue'),
      meta: { layout: 'public' },
    },
    {
      path: '/recuperar',
      name: 'password-reset-request',
      component: () => import('./views/PasswordResetRequestView.vue'),
      meta: { layout: 'public' },
    },
    {
      path: '/restablecer/:token',
      name: 'password-reset',
      component: () => import('./views/PasswordResetView.vue'),
      meta: { layout: 'public' },
    },
    {
      path: '/desbloquear/:token',
      name: 'account-unlock',
      component: () => import('./views/AccountUnlockView.vue'),
      meta: { layout: 'public' },
    },

    // -- Con sesión, autoservicio por identidad (RN-CORE-24) --------------
    {
      path: '/cuenta/contrasena',
      name: 'password-change',
      component: () => import('./views/PasswordChangeView.vue'),
      meta: { layout: 'app', permissions: [], titleKey: 'auth.passwordChange.title' },
    },
    {
      path: '/cuenta/sesiones',
      name: 'sessions',
      component: () => import('./views/SessionsView.vue'),
      meta: { layout: 'app', permissions: [], titleKey: 'auth.sessions.title' },
    },
    {
      path: '/cuenta/seguridad',
      name: 'mfa-security',
      component: () => import('./views/AccountSecurityView.vue'),
      meta: { layout: 'app', permissions: [], titleKey: 'auth.mfa.security.title' },
    },
    // funcional.md §12.3.5: régimen `bare` — sin navegación, sin más
    // opción de menú de usuario que cerrar sesión. Ver la nota de cabecera
    // sobre `permissions: []`.
    {
      path: '/cuenta/seguridad/obligatorio',
      name: 'mfa-enrollment-wall',
      component: () => import('./views/MfaEnrollmentWallView.vue'),
      meta: { layout: 'bare', permissions: [], titleKey: 'auth.mfa.wall.title' },
    },

    // -- Con sesión y permiso (verificado por el servidor, INV-002) -------
    {
      path: '/administracion/mfa',
      name: 'mfa-administration',
      component: () => import('./views/AdminMfaView.vue'),
      meta: {
        layout: 'app',
        permissions: [
          'mfa.leer',
          'rol.actualizar',
          'mfa.eliminar',
          'exencion_mfa.crear',
          'exencion_mfa.leer',
          'exencion_mfa.eliminar',
        ],
        titleKey: 'auth.mfaAdmin.title',
      },
    },
    {
      path: '/administracion/sso',
      name: 'sso-administration',
      component: () => import('./views/AdminSsoView.vue'),
      meta: {
        layout: 'app',
        permissions: ['proveedor_identidad.leer'],
        titleKey: 'auth.ssoAdmin.title',
      },
    },
    // ADR-053 §4.4: sin sub-entrada de menú — destino de una acción dentro
    // de `sso-administration`, en la miga de pan bajo «SSO».
    {
      path: '/administracion/sso/nuevo',
      name: 'sso-administration-new',
      component: () => import('./views/AdminSsoProviderView.vue'),
      meta: {
        layout: 'app',
        permissions: ['proveedor_identidad.leer'],
        titleKey: 'auth.ssoAdmin.form.titleNew',
        breadcrumbKey: 'auth.ssoAdmin.form.titleNew',
        breadcrumbParent: 'sso-administration',
      },
    },
    {
      path: '/administracion/sso/:publicId',
      name: 'sso-administration-edit',
      component: () => import('./views/AdminSsoProviderView.vue'),
      meta: {
        layout: 'app',
        permissions: ['proveedor_identidad.leer'],
        titleKey: 'auth.ssoAdmin.form.titleEdit',
        breadcrumbKey: 'auth.ssoAdmin.form.titleEdit',
        breadcrumbParent: 'sso-administration',
      },
    },
  ],
  navigation: [
    {
      id: 'auth.password',
      route: 'password-change',
      labelKey: 'auth.nav.passwordChange',
      icon: KeyRound,
      section: 'cuenta',
      shortcut: false,
    },
    {
      id: 'auth.sessions',
      route: 'sessions',
      labelKey: 'auth.nav.sessions',
      icon: Laptop,
      section: 'cuenta',
      shortcut: false,
    },
    {
      id: 'auth.mfaSecurity',
      route: 'mfa-security',
      labelKey: 'auth.mfa.security.title',
      icon: ShieldCheck,
      section: 'cuenta',
      shortcut: true,
    },
    {
      id: 'auth.mfaAdministration',
      route: 'mfa-administration',
      labelKey: 'auth.mfaAdmin.title',
      icon: ShieldAlert,
      section: 'administracion',
      shortcut: true,
    },
    {
      id: 'auth.ssoAdministration',
      route: 'sso-administration',
      labelKey: 'auth.nav.ssoAdministration',
      icon: Building2,
      section: 'administracion',
      shortcut: true,
    },
  ],
  dashboardBlocks: [],
}
