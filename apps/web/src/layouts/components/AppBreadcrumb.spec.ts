/**
 * `docs/modulos/REQ-CORE/funcional.md §12.11`, `CA-CORE-088`.
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n, setLocale } from '@/i18n'
import AppBreadcrumb from './AppBreadcrumb.vue'

setLocale('es')

async function mountAt(path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div/>' } },
      {
        path: '/administracion/sso',
        name: 'sso-administration',
        component: { template: '<div/>' },
      },
      {
        path: '/administracion/sso/:publicId',
        name: 'sso-administration-edit',
        component: { template: '<div/>' },
        meta: {
          layout: 'app',
          permissions: ['proveedor_identidad.leer'],
          breadcrumbKey: 'auth.ssoAdmin.form.titleEdit',
          breadcrumbParent: 'sso-administration',
        },
      },
      {
        path: '/cuenta/sesiones',
        name: 'sessions',
        component: { template: '<div/>' },
      },
      // Padre sin entrada de menú, con su propia clave de título (`REQ-PERM §20.3`).
      {
        path: '/administracion/roles/:publicId',
        name: 'core-role-detail',
        component: { template: '<div/>' },
        meta: { layout: 'app', permissions: ['rol.leer'], titleKey: 'core.roles.detail.title' },
      },
      {
        path: '/administracion/roles/:publicId/permisos',
        name: 'core-role-permissions',
        component: { template: '<div/>' },
        meta: {
          layout: 'app',
          permissions: ['rol.actualizar'],
          titleKey: 'core.roles.editor.pageTitle',
          breadcrumbKey: 'core.roles.editor.pageTitle',
          breadcrumbParent: 'core-role-detail',
        },
      },
    ],
  })

  await router.push(path)
  await router.isReady()

  return mount(AppBreadcrumb, {
    global: {
      plugins: [i18n, router],
      stubs: { RouterLink: { template: '<a><slot /></a>' } },
    },
  })
}

describe('AppBreadcrumb — CA-CORE-088', () => {
  it('en la edición de un proveedor SSO: Inicio › SSO › (edición)', async () => {
    const wrapper = await mountAt('/administracion/sso/01J-PROV')

    const nav = wrapper.get('nav')
    expect(nav.attributes('aria-label')).toBeTruthy()

    const items = wrapper.findAll('li')
    expect(items).toHaveLength(3)
    expect(items[0]!.text()).toContain('Inicio')
    expect(items[1]!.text()).toContain('SSO')
    expect(items[2]!.text()).toContain('Editar proveedor de identidad')

    // El último elemento no es un enlace y lleva aria-current="page"; los
    // anteriores sí son enlaces.
    expect(items[2]!.find('a').exists()).toBe(false)
    expect(items[2]!.get('[aria-current="page"]')).toBeTruthy()
    expect(items[0]!.find('a').exists()).toBe(true)
    expect(items[1]!.find('a').exists()).toBe(true)
  })

  it('un padre sin entrada de menú se nombra con la clave de su ruta, no con su nombre técnico (REQ-PERM §20.3)', async () => {
    const wrapper = await mountAt('/administracion/roles/01J-ROL/permisos')

    const items = wrapper.findAll('li')

    expect(items).toHaveLength(3)
    expect(items[1]!.text()).toContain('Ficha del rol')
    expect(items[1]!.text()).not.toContain('core-role-detail')
    expect(items[2]!.text()).toContain('Concesiones del rol')
  })

  it('en Inicio, un único elemento actual sin enlace', async () => {
    const wrapper = await mountAt('/')

    const items = wrapper.findAll('li')
    expect(items).toHaveLength(1)
    expect(items[0]!.find('a').exists()).toBe(false)
  })

  it('en una entrada de primer nivel (Sesiones abiertas): Inicio › Sesiones abiertas', async () => {
    const wrapper = await mountAt('/cuenta/sesiones')

    const items = wrapper.findAll('li')
    expect(items).toHaveLength(2)
    expect(items[0]!.text()).toContain('Inicio')
    expect(items[1]!.text()).toContain('Sesiones abiertas')
  })
})
