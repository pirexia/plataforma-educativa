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
