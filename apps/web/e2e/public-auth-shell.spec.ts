import { test, expect, type Page } from '@playwright/test'

/**
 * `docs/modulos/REQ-CORE/capa-visual.md §12.1`: criterios de la pantalla
 * «Panel» que necesitan estilos calculados (`CA-UI-009`, `-012`, `-015` a
 * `-018`, `-020`). API simulada con `page.route`: los de Vitest
 * (`PublicAuthShell.spec.ts`) cubren el marcado.
 */

const BRANDING = {
  name: 'Centro de ejemplo',
  color_primary: '#1D4ED8',
  color_secondary: '#FFFFFF',
  logo_url: null,
  favicon_url: null,
  login_background_url: null as string | null,
  default_locale: 'es-ES',
  active_locales: ['es-ES', 'fr'],
}

const PUBLIC_ROUTES = [
  '/entrar',
  '/entrar/google',
  '/entrar/sso',
  '/activar/token-e2e',
  '/recuperar',
  '/restablecer/token-e2e',
  '/desbloquear/token-e2e',
]

function solidSvg(color: string): string {
  return `<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="64" height="64" fill="${color}"/></svg>`
}

async function mockBackend(page: Page, branding: Partial<typeof BRANDING> = {}): Promise<void> {
  await page.route('**/tenant/branding', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ ...BRANDING, ...branding }),
    }),
  )
  await page.route('**/auth/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }))
  await page.route('**/auth/identity-providers', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: [{ id: 'sso-e2e', display_name: 'Acceso del centro' }] }),
    }),
  )
  await page.route('https://cdn.example.com/**', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'image/svg+xml',
      body: solidSvg(route.request().url().includes('blanco') ? '#ffffff' : '#000000'),
    }),
  )
}

/** Resuelve cualquier color CSS (oklab, oklch, rgb…) a [r, g, b, a] con un lienzo. */
async function resolveColor(page: Page, css: string): Promise<number[]> {
  return page.evaluate((value) => {
    const canvas = document.createElement('canvas')
    canvas.width = canvas.height = 1
    const ctx = canvas.getContext('2d')!
    ctx.clearRect(0, 0, 1, 1)
    ctx.fillStyle = value
    ctx.fillRect(0, 0, 1, 1)
    const [r, g, b, a] = ctx.getImageData(0, 0, 1, 1).data

    return [r!, g!, b!, a! / 255]
  }, css)
}

function luminance([r, g, b]: number[]): number {
  const lin = (c: number) => {
    const v = c / 255
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4
  }

  return 0.2126 * lin(r!) + 0.7152 * lin(g!) + 0.0722 * lin(b!)
}

function contrast(a: number[], b: number[]): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x)

  return (hi! + 0.05) / (lo! + 0.05)
}

function over(top: number[], bottom: number[]): number[] {
  const a = top[3]!

  return [0, 1, 2].map((i) => top[i]! * a + bottom[i]! * (1 - a))
}

const panelOf = (page: Page) => page.locator('main').locator('xpath=..')

test.describe('CA-UI-009: disposición «Panel» en las rutas públicas', () => {
  for (const route of PUBLIC_ROUTES) {
    test(`${route}: dos columnas en escritorio, una columna sin sombra en móvil`, async ({
      page,
    }) => {
      await mockBackend(page)
      await page.setViewportSize({ width: 1440, height: 900 })
      await page.goto(route)

      const aside = page.locator('aside')
      const panel = panelOf(page)
      await expect(aside).toBeVisible()
      await expect(panel).toBeVisible()

      const asideBox = (await aside.boundingBox())!
      const panelBox = (await panel.boundingBox())!
      expect(asideBox.x).toBeLessThan(panelBox.x)
      expect(panelBox.x + panelBox.width).toBeCloseTo(1440, 0)
      expect(panelBox.width).toBeGreaterThanOrEqual(360)
      expect(panelBox.width).toBeLessThanOrEqual(460)
      expect(panelBox.width).toBeCloseTo(432, 0)
      expect(panelBox.height).toBeGreaterThanOrEqual(900)

      for (const [width, expected] of [
        [1920, 460],
        [1024, 360],
      ] as const) {
        await page.setViewportSize({ width, height: 900 })
        expect((await panel.boundingBox())!.width).toBeCloseTo(expected, 0)
      }

      for (const width of [767, 320]) {
        await page.setViewportSize({ width, height: 800 })
        const a = (await aside.boundingBox())!
        const p = (await panel.boundingBox())!
        expect(p.y).toBeGreaterThanOrEqual(a.y + a.height - 1)
        expect(p.width).toBeCloseTo(width, 0)
        expect(a.height).toBeGreaterThanOrEqual(180)
        expect(await panel.evaluate((el) => getComputedStyle(el).boxShadow)).toBe('none')
        expect(
          await page.evaluate(
            () => document.documentElement.scrollWidth <= document.documentElement.clientWidth,
          ),
        ).toBe(true)
      }
    })
  }
})

test.describe('CA-UI-012: texto de bienvenida sobre imagen blanca y negra', () => {
  for (const [name, backdrop] of [
    ['blanco', [255, 255, 255, 1]],
    ['negro', [0, 0, 0, 1]],
  ] as const) {
    for (const width of [1440, 320]) {
      test(`imagen ${name}, ${width} px`, async ({ page }) => {
        await mockBackend(page, { login_background_url: `https://cdn.example.com/${name}.svg` })
        await page.setViewportSize({ width, height: 900 })
        await page.goto('/entrar')

        const title = page.getByTestId('auth-welcome-title')
        await expect(title).toBeVisible()
        const plate = title.locator('xpath=..')

        const plateBox = (await plate.boundingBox())!
        const lines = await page.evaluate(() => {
          const rects: { top: number; bottom: number; left: number; right: number }[] = []
          for (const id of ['auth-welcome-title', 'auth-welcome-text']) {
            const el = document.querySelector(`[data-testid="${id}"]`)
            if (!el || getComputedStyle(el).display === 'none') continue
            const range = document.createRange()
            range.selectNodeContents(el)
            for (const r of range.getClientRects()) {
              rects.push({ top: r.top, bottom: r.bottom, left: r.left, right: r.right })
            }
          }

          return rects
        })
        expect(lines.length).toBeGreaterThan(0)
        for (const line of lines) {
          expect(line.top).toBeGreaterThanOrEqual(plateBox.y - 0.5)
          expect(line.bottom).toBeLessThanOrEqual(plateBox.y + plateBox.height + 0.5)
          expect(line.left).toBeGreaterThanOrEqual(plateBox.x - 0.5)
          expect(line.right).toBeLessThanOrEqual(plateBox.x + plateBox.width + 0.5)
        }

        const tokens = await page.evaluate(() => {
          const probe = document.createElement('div')
          probe.style.backgroundColor = 'var(--scrim-auth)'
          probe.style.color = 'var(--on-scrim)'
          document.body.append(probe)
          const cs = getComputedStyle(probe)
          const out = { scrim: cs.backgroundColor, onScrim: cs.color }
          probe.remove()

          return out
        })
        const plateBg = await plate.evaluate((el) => getComputedStyle(el).backgroundColor)
        const textColor = await title.evaluate((el) => getComputedStyle(el).color)

        const scrim = await resolveColor(page, tokens.scrim)
        expect(await resolveColor(page, plateBg)).toEqual(scrim)
        expect(scrim[3]).toBeCloseTo(0.64, 1)
        const text = await resolveColor(page, textColor)
        expect(text).toEqual(await resolveColor(page, tokens.onScrim))
        expect(text[3]).toBe(1)

        const composed = over(scrim, [...backdrop])
        expect(contrast(text, composed)).toBeGreaterThanOrEqual(4.5)
      })
    }
  }
})

test.describe('CA-UI-015: foco por teclado en /entrar', () => {
  for (const width of [1440, 320]) {
    test(`orden y indicador de foco a ${width} px`, async ({ page }) => {
      await mockBackend(page)
      await page.setViewportSize({ width, height: 900 })
      await page.goto('/entrar')
      await expect(page.locator('#login-email')).toBeVisible()
      await expect(page.getByRole('button', { name: 'Français' })).toBeVisible()
      await expect(page.getByRole('button', { name: 'Acceso del centro' })).toBeVisible()

      const ring = await resolveColor(
        page,
        await page.evaluate(() => {
          const probe = document.createElement('div')
          probe.style.borderColor = 'var(--ring)'
          probe.style.borderStyle = 'solid'
          document.body.append(probe)
          const color = getComputedStyle(probe).borderTopColor
          probe.remove()

          return color
        }),
      )

      const seen: string[] = []
      for (let i = 0; i < 12; i += 1) {
        await page.keyboard.press('Tab')
        // Los controles animan el borde y el anillo (`transition-all`): se mide ya asentado.
        await page.waitForFunction(() => document.getAnimations().length === 0)
        const info = await page.evaluate(() => {
          const el = document.activeElement as HTMLElement | null
          if (!el || el === document.body) return null
          const cs = getComputedStyle(el)
          const box = el.getBoundingClientRect()
          const cx = box.x + box.width / 2
          const cy = box.y + box.height / 2
          const top = document.elementFromPoint(cx, cy)

          return {
            key: el.id || el.getAttribute('lang') || el.textContent?.trim() || el.tagName,
            border: cs.borderTopColor,
            shadow: cs.boxShadow,
            outlineStyle: cs.outlineStyle,
            outlineWidth: parseFloat(cs.outlineWidth),
            outline: cs.outlineColor,
            covered: !(top === el || el.contains(top)),
            left: box.left,
            right: box.right,
            top: box.top,
            bottom: box.bottom,
          }
        })
        if (!info) continue
        seen.push(info.key)

        const borderRing =
          (await resolveColor(page, info.border)).join() === ring.join() && info.shadow !== 'none'
        const outlineRing =
          info.outlineStyle !== 'none' &&
          info.outlineWidth > 0 &&
          (await resolveColor(page, info.outline)).join() === ring.join()
        expect(borderRing || outlineRing, `indicador de foco de ${info.key}`).toBe(true)

        expect(info.covered, `${info.key} tapado`).toBe(false)
        expect(info.left).toBeGreaterThanOrEqual(0)
        expect(info.right).toBeLessThanOrEqual(width)
      }

      const index = (key: string) => seen.indexOf(key)
      const order = [
        'login-email',
        'login-password',
        'Entrar',
        'Acceso del centro',
        '¿Has olvidado tu contraseña?',
        'es',
        'fr',
      ].map(index)
      expect(
        order.every((i) => i >= 0),
        `recorrido: ${seen.join(' > ')}`,
      ).toBe(true)
      expect([...order].sort((a, b) => a - b)).toEqual(order)
    })
  }
})

test.describe('CA-UI-016: sombra del panel', () => {
  test('dos capas con desplazamiento horizontal negativo a 1440 px', async ({ page }) => {
    await mockBackend(page)
    await page.setViewportSize({ width: 1440, height: 900 })
    await page.goto('/entrar')

    const shadow = await panelOf(page).evaluate((el) => getComputedStyle(el).boxShadow)
    const expected = await page.evaluate(() => {
      const probe = document.createElement('div')
      probe.style.boxShadow = 'var(--shadow-auth-panel)'
      document.body.append(probe)
      const value = getComputedStyle(probe).boxShadow
      probe.remove()

      return value
    })

    expect(shadow).toBe(expected)
    expect(shadow).toContain('-2px 0px 6px')
    expect(shadow).toContain('-12px 0px 40px')
  })
})

test.describe('CA-UI-017: tamaño de los botones de idioma', () => {
  test('al menos 24x24 px con puntero fino', async ({ page }) => {
    await mockBackend(page)
    await page.setViewportSize({ width: 1440, height: 900 })
    await page.goto('/entrar')

    const buttons = page.locator('footer nav button')
    await expect(buttons).toHaveCount(2)
    for (const box of await Promise.all([0, 1].map((i) => buttons.nth(i).boundingBox()))) {
      expect(box!.width).toBeGreaterThanOrEqual(24)
      expect(box!.height).toBeGreaterThanOrEqual(24)
    }
  })

  test.describe('puntero grueso', () => {
    test.use({ hasTouch: true, isMobile: true, viewport: { width: 800, height: 900 } })

    test('al menos 44x44 px', async ({ page }) => {
      await mockBackend(page)
      await page.goto('/entrar')

      const buttons = page.locator('footer nav button')
      await expect(buttons).toHaveCount(2)
      for (const box of await Promise.all([0, 1].map((i) => buttons.nth(i).boundingBox()))) {
        expect(box!.width).toBeGreaterThanOrEqual(44)
        expect(box!.height).toBeGreaterThanOrEqual(44)
      }
    })
  })
})

test.describe('CA-UI-018: sin animación con reducedMotion', () => {
  test('ningún elemento del shell supera 0.01 ms de transición o animación', async ({ page }) => {
    await mockBackend(page)
    await page.emulateMedia({ reducedMotion: 'reduce' })
    await page.goto('/entrar')
    await expect(page.locator('aside')).toBeVisible()

    const offenders = await page.evaluate(() => {
      const toSeconds = (value: string) =>
        value.split(',').map((part) => {
          const v = part.trim()

          return v.endsWith('ms') ? parseFloat(v) / 1000 : parseFloat(v)
        })
      const bad: string[] = []
      for (const el of document.querySelectorAll('#app *')) {
        const cs = getComputedStyle(el)
        const max = Math.max(
          ...toSeconds(cs.transitionDuration),
          ...toSeconds(cs.animationDuration),
        )
        if (max > 0.00001 + 1e-9) bad.push(`${el.tagName}.${el.className}: ${max}s`)
      }

      return bad
    })

    expect(offenders).toEqual([])
  })
})

test.describe('CA-UI-020: modo oscuro', () => {
  test('el panel usa el --background oscuro; velo, texto y sombra no cambian', async ({ page }) => {
    await mockBackend(page, { login_background_url: 'https://cdn.example.com/negro.svg' })
    await page.setViewportSize({ width: 1440, height: 900 })

    const read = async () => {
      await page.goto('/entrar')
      await expect(page.getByTestId('auth-welcome-title')).toBeVisible()

      return page.evaluate(() => {
        const title = document.querySelector('[data-testid="auth-welcome-title"]')!
        const plate = title.parentElement!
        const panel = document.querySelector('main')!.parentElement!
        const probe = document.createElement('div')
        probe.className = 'bg-background'
        document.body.append(probe)
        const background = getComputedStyle(probe).backgroundColor
        probe.remove()

        return {
          panelBackground: getComputedStyle(panel).backgroundColor,
          background,
          scrim: getComputedStyle(plate).backgroundColor,
          text: getComputedStyle(title).color,
          shadow: getComputedStyle(panel).boxShadow,
        }
      })
    }

    await page.emulateMedia({ colorScheme: 'light' })
    const light = await read()
    await page.emulateMedia({ colorScheme: 'dark' })
    const dark = await read()

    expect(dark.panelBackground).toBe(dark.background)
    expect(dark.panelBackground).not.toBe(light.panelBackground)
    expect(dark.scrim).toBe(light.scrim)
    expect(dark.text).toBe(light.text)
    expect(dark.shadow).toBe(light.shadow)
  })
})
