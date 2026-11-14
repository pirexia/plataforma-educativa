import { describe, it, expect } from 'vitest'
import { findLiterals } from './i18n-literals.mjs'

describe('findLiterals (INV-009)', () => {
  it('no encuentra nada en una plantilla que solo usa t()', () => {
    const source = `
      <script setup lang="ts">
      import { useI18n } from 'vue-i18n'
      const { t } = useI18n()
      </script>
      <template>
        <div>
          <h1>{{ t('home.title') }}</h1>
          <button>{{ t('home.retry') }}</button>
        </div>
      </template>
    `

    expect(findLiterals(source)).toEqual([])
  })

  it('detecta un literal de texto en el cuerpo de la plantilla', () => {
    const source = `
      <template>
        <h1>Texto sin traducir</h1>
      </template>
    `

    const findings = findLiterals(source)

    expect(findings).toHaveLength(1)
    expect(findings[0]).toContain('Texto sin traducir')
  })

  it('detecta un literal en un atributo de contenido (placeholder)', () => {
    const source = `
      <template>
        <input placeholder="Escribe aquí" />
      </template>
    `

    const findings = findLiterals(source)

    expect(findings).toHaveLength(1)
    expect(findings[0]).toContain('placeholder')
    expect(findings[0]).toContain('Escribe aquí')
  })

  it('ignora texto sin letras (separadores, símbolos, espacios)', () => {
    const source = `
      <template>
        <span>·</span>
        <span> / </span>
        <span>{{ 42 }}</span>
      </template>
    `

    expect(findLiterals(source)).toEqual([])
  })

  it('ignora un fichero .vue sin bloque template', () => {
    const source = `
      <script setup lang="ts">
      export default {}
      </script>
    `

    expect(findLiterals(source)).toEqual([])
  })

  it('no confunde un atributo dinámico (:placeholder) con un literal', () => {
    const source = `
      <script setup lang="ts">
      const label = 'algo'
      </script>
      <template>
        <input :placeholder="label" />
      </template>
    `

    expect(findLiterals(source)).toEqual([])
  })
})
