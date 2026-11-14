/**
 * `CA-070-06` (`ADR-070`, issue #481), `REQ-FAM-UNIT-003`/`-004`/`-005`, `INV-015`: el texto legal es texto plano. Los dos
 * componentes que lo pintan no usan HTML crudo, conservan los saltos de línea con `whitespace-pre-line` y muestran
 * literalmente un `body` con marcado (sin elemento `b`). Patrón de `RolloverNoRawHtml.spec.ts`.
 */
import { readFileSync } from 'node:fs'
import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { i18n, setLocale } from '@/i18n'
import TermsTextDialog from '../components/TermsTextDialog.vue'
import type { TermsVersion } from '../types'

const FILES = ['views/TermsWallView.vue', 'components/TermsTextDialog.vue']
const MARKUP_BODY = 'Línea 1\nLínea 2 <b>x</b>'

describe('CA-070-06 ADR-070: el texto legal se pinta siempre como texto', () => {
  for (const file of FILES) {
    it(`${file} no usa v-html, innerHTML, outerHTML ni insertAdjacentHTML y el cuerpo usa whitespace-pre-line`, () => {
      const source = readFileSync(`src/modules/fam-unit/${file}`, 'utf8')

      expect(source).not.toMatch(/v-html|\.innerHTML|\.outerHTML|insertAdjacentHTML/)
      expect(source).toContain('whitespace-pre-line')
      expect(source).not.toContain('whitespace-pre-wrap')
    })
  }

  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('TermsTextDialog muestra un body con <b>x</b> literalmente, sin crear ningún elemento b', async () => {
    setLocale('es')
    const version = { title: 'Título', body: MARKUP_BODY } as unknown as TermsVersion

    mount(TermsTextDialog, {
      props: { open: true, version },
      global: { plugins: [i18n] },
      attachTo: document.body,
    })
    await Promise.resolve()

    const region = document.body.querySelector('[role="region"]')
    expect(region).not.toBeNull()
    expect(region?.textContent).toContain('<b>x</b>')
    expect(region?.querySelector('b')).toBeNull()
    expect(region?.className).toContain('whitespace-pre-line')
  })
})
