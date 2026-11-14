/**
 * `docs/modulos/REQ-CORE/funcional.md §12.11`, `CA-CORE-104`.
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { House, ShieldCheck } from '@lucide/vue'
import { i18n, setLocale } from '@/i18n'
import type { NavigationEntry } from '@/navigation/types'
import AppNavList from './AppNavList.vue'

setLocale('es')

const entries: NavigationEntry[] = [
  { id: 'core.home', route: 'home', labelKey: 'core.nav.home', icon: House, section: 'inicio' },
  {
    id: 'auth.mfaSecurity',
    route: 'mfa-security',
    labelKey: 'auth.mfa.security.title',
    icon: ShieldCheck,
    section: 'cuenta',
  },
]

async function mountAt(path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div/>' } },
      { path: '/cuenta/seguridad', name: 'mfa-security', component: { template: '<div/>' } },
    ],
  })

  await router.push(path)
  await router.isReady()

  return mount(AppNavList, {
    props: { entries },
    global: { plugins: [i18n, router] },
  })
}

describe('AppNavList — CA-CORE-104', () => {
  it('la entrada de la ruta activa lleva aria-current="page" y ninguna otra', async () => {
    const wrapper = await mountAt('/cuenta/seguridad')

    const links = wrapper.findAll('a')
    const current = links.filter((link) => link.attributes('aria-current') === 'page')

    expect(current).toHaveLength(1)
    expect(current[0]!.text()).toContain('Seguridad de la cuenta')
  })

  it('en Inicio, solo la entrada de Inicio lleva aria-current', async () => {
    const wrapper = await mountAt('/')

    const links = wrapper.findAll('a')
    const current = links.filter((link) => link.attributes('aria-current') === 'page')

    expect(current).toHaveLength(1)
    expect(current[0]!.text()).toContain('Inicio')
  })
})
