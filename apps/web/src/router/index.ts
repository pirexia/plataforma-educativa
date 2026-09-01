import { createRouter, createWebHistory } from 'vue-router'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: () => import('@/views/HomeView.vue'),
    },
    // REQ-AUTH (1.2), funcional.md §1.6: rutas exactas de las seis
    // pantallas. Las cinco primeras son públicas, sin `AppLayout`; la
    // sexta exige sesión (comprobada por la propia vista, no por un
    // guard de router — no hay estado de sesión global hasta 1.8).
    {
      path: '/entrar',
      name: 'login',
      component: () => import('@/modules/auth/views/LoginView.vue'),
    },
    // REQ-AUTH-002 (1.4), funcional.md §E.9, api.md §E.4.2: destino del
    // 302 del callback de Google cuando no redirige directamente a la
    // raíz. Pública, sin AppLayout, misma categoría que /entrar.
    {
      path: '/entrar/google',
      name: 'oauth-google-callback',
      component: () => import('@/modules/auth/views/GoogleCallbackResultView.vue'),
    },
    {
      path: '/activar/:token',
      name: 'invitation-redemption',
      component: () => import('@/modules/auth/views/InvitationRedemptionView.vue'),
    },
    {
      path: '/recuperar',
      name: 'password-reset-request',
      component: () => import('@/modules/auth/views/PasswordResetRequestView.vue'),
    },
    {
      path: '/restablecer/:token',
      name: 'password-reset',
      component: () => import('@/modules/auth/views/PasswordResetView.vue'),
    },
    {
      path: '/desbloquear/:token',
      name: 'account-unlock',
      component: () => import('@/modules/auth/views/AccountUnlockView.vue'),
    },
    {
      path: '/cuenta/contrasena',
      name: 'password-change',
      component: () => import('@/modules/auth/views/PasswordChangeView.vue'),
    },
    // 1.2b, funcional.md §B.11: misma categoría que /cuenta/contrasena —
    // con sesión, sin navegación, sin depender del layout de 1.8 ni del
    // design system de 1.7.
    {
      path: '/cuenta/sesiones',
      name: 'sessions',
      component: () => import('@/modules/auth/views/SessionsView.vue'),
    },
    // REQ-AUTH-003 (1.3), funcional.md §C.11: misma categoría — con
    // sesión, sin `AppLayout`. `mfa-enrollment-wall` es además el destino
    // fijo al que `src/api/client.ts` redirige ante cualquier
    // `403 urn:pge:error:mfa-enrollment-required` (funcional.md §C.4.9).
    {
      path: '/cuenta/seguridad',
      name: 'mfa-security',
      component: () => import('@/modules/auth/views/AccountSecurityView.vue'),
    },
    {
      path: '/cuenta/seguridad/obligatorio',
      name: 'mfa-enrollment-wall',
      component: () => import('@/modules/auth/views/MfaEnrollmentWallView.vue'),
    },
    // 1.3b, pieza 3 (funcional.md §D.1.3/§D.9.1): pantalla mínima de
    // administración de MFA. Con sesión y permiso — pero la ruta no
    // comprueba ningún permiso en el cliente (INV-002, permisos.md
    // §D.6.3): cada área de la vista lo hace contra el servidor.
    // Provisional por diseño: 1.5 la absorbe en su editor de roles.
    {
      path: '/administracion/mfa',
      name: 'mfa-administration',
      component: () => import('@/modules/auth/views/AdminMfaView.vue'),
    },
  ],
})

export default router
