/**
 * Revisión de seguridad B4 de `1.12b` (`REQ-CURSO-002`): las dos pantallas de la apertura del curso muestran
 * mensajes y códigos que vienen del servidor; ninguna debe usar `v-html` ni `innerHTML` (XSS por mensaje).
 */
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

describe('revisión de seguridad B4 (1.12b): sin HTML crudo en las pantallas del rollover', () => {
  for (const file of ['RolloverWizardView.vue', 'RolloverDetailView.vue']) {
    it(`${file} no usa v-html ni innerHTML ni outerHTML`, () => {
      const source = readFileSync(`src/modules/curso/views/${file}`, 'utf8')

      expect(source).not.toMatch(/v-html|\.innerHTML|\.outerHTML|insertAdjacentHTML/)
    })
  }
})
