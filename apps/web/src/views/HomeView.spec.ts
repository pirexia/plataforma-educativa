import { describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import HomeView from './HomeView.vue'
import { i18n } from '@/i18n'

vi.mock('@/api/client', () => ({
  apiFetch: vi.fn().mockResolvedValue({
    status: 'ok',
    version: '0.1.0',
    timestamp: '2026-08-14T00:00:00Z',
  }),
  ApiError: class ApiError extends Error {},
}))

describe('HomeView', () => {
  it('muestra el estado de la API tras cargar', async () => {
    const wrapper = mount(HomeView, { global: { plugins: [i18n] } })
    await flushPromises()

    expect(wrapper.text()).toContain('ok')
    expect(wrapper.text()).toContain('0.1.0')
  })
})
