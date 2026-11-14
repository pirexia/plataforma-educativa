import { createRouter, createWebHistory } from 'vue-router'
import { allModuleRoutes } from '@/navigation/registry'
import { installNavigationGuard } from './guard'

/**
 * `docs/adr/ADR-053-registro-de-navegacion-y-bloques-del-panel.md` §1:
 * aquí quedan solo las rutas de aplicación que no pertenecen a ningún
 * módulo — `home`, el centro no encontrado (`RN-CORE-35`) y el
 * *catch-all* (`CA-CORE-096`). El resto se concatena desde
 * `moduleShells` (`src/navigation/modules.ts`).
 */
const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: () => import('@/views/HomeView.vue'),
      // RN-CORE-24: autoservicio por identidad — lista cerrada.
      meta: { layout: 'app', permissions: [], titleKey: 'core.nav.home' },
    },
    ...allModuleRoutes(),
    // RN-CORE-35: host sin tenant. Sin *shell*, sin `GET /me`.
    {
      path: '/centro-no-encontrado',
      name: 'tenant-not-found',
      component: () => import('@/views/TenantNotFoundView.vue'),
      meta: { layout: 'public' },
    },
    // CA-CORE-096: dentro del *shell* con sesión, en régimen público sin
    // ella — resuelto por el *guard* (`src/router/guard.ts`), no por
    // `meta.layout` a secas. `permissions: []` porque no hay nada que
    // ocultar: el contenido es el mismo para cualquiera.
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFoundView.vue'),
      meta: { layout: 'app', permissions: [], titleKey: 'shell.states.error.notFound.title' },
    },
  ],
  scrollBehavior() {
    return { top: 0 }
  },
})

installNavigationGuard(router)

export default router
