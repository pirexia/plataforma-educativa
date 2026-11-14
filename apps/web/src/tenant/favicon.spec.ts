/**
 * `docs/design-system.md` §7.5, §18 (`CA-DS-026`).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

async function freshFavicon() {
  vi.resetModules()

  return import('./favicon')
}

beforeEach(() => {
  document.head.innerHTML = ''
})

describe('applyFavicon — CA-DS-026', () => {
  it('con URL no nula, fija href y retira type; con null, restaura los originales', async () => {
    const link = document.createElement('link')
    link.setAttribute('rel', 'icon')
    link.setAttribute('type', 'image/svg+xml')
    link.setAttribute('href', '/favicon.svg')
    document.head.appendChild(link)

    const { applyFavicon } = await freshFavicon()

    applyFavicon('https://cdn.example.com/centro/favicon.png?sig=abc')

    expect(link.getAttribute('href')).toBe('https://cdn.example.com/centro/favicon.png?sig=abc')
    expect(link.hasAttribute('type')).toBe(false)

    applyFavicon(null)

    expect(link.getAttribute('href')).toBe('/favicon.svg')
    expect(link.getAttribute('type')).toBe('image/svg+xml')
  })

  it('memoriza el original solo en la primera llamada', async () => {
    const link = document.createElement('link')
    link.setAttribute('rel', 'icon')
    link.setAttribute('type', 'image/svg+xml')
    link.setAttribute('href', '/favicon.svg')
    document.head.appendChild(link)

    const { applyFavicon } = await freshFavicon()

    applyFavicon('https://cdn.example.com/a.png')
    applyFavicon('https://cdn.example.com/b.ico')
    applyFavicon(null)

    // El original memorizado la primera vez, no el de la última llamada.
    expect(link.getAttribute('href')).toBe('/favicon.svg')
    expect(link.getAttribute('type')).toBe('image/svg+xml')
  })

  it('sin link[rel~="icon"] en el documento, no lanza', async () => {
    const { applyFavicon } = await freshFavicon()

    expect(() => applyFavicon('https://cdn.example.com/a.png')).not.toThrow()
  })
})
