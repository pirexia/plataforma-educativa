/**
 * `docs/modulos/REQ-PERM/funcional.md §20.17.8`, `CA-PERM-130` (`RN-PERM-27`,
 * `INV-009`): las nueve acciones, los seis ámbitos, los dos efectos, las dos
 * decisiones y los cuatro motivos de inercia tienen etiqueta en `es`, `en`, `de`
 * y `fr`; un valor desconocido de cualquiera de ellos se pinta en crudo.
 */
import { beforeEach, describe, expect, it } from 'vitest'
import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import { i18n, setLocale } from '@/i18n'

import {
  ACTIONS,
  DECISIONS,
  EFFECTS,
  INERT_REASONS,
  SCOPES,
  compareActions,
  usePermissionVocabulary,
} from './permissionVocabulary'
import es from './locales/es.json'
import en from './locales/en.json'
import de from './locales/de.json'
import fr from './locales/fr.json'

type Tree = { [key: string]: string | Tree }

const LOCALES = { es, en, de, fr } as unknown as Record<string, Tree>

/** `useT` exige un contexto de componente: se monta uno mínimo que expone el vocabulario. */
function vocabulary(locale: 'es' | 'en' | 'de' | 'fr'): ReturnType<typeof usePermissionVocabulary> {
  setLocale(locale)

  let vocab!: ReturnType<typeof usePermissionVocabulary>

  mount(
    defineComponent({
      setup() {
        vocab = usePermissionVocabulary()

        return () => null
      },
    }),
    { global: { plugins: [i18n] } },
  )

  return vocab
}

beforeEach(() => {
  setLocale('es')
})

describe('CA-PERM-130 (RN-PERM-27, INV-009): vocabulario cerrado en los cuatro idiomas', () => {
  const groups: [string, readonly string[]][] = [
    ['actions', ACTIONS],
    ['scopes', SCOPES],
    ['effects', EFFECTS],
    ['decisions', DECISIONS],
    ['inertReasons', INERT_REASONS],
  ]

  it('son exactamente nueve acciones, seis ámbitos, dos efectos, dos decisiones y cuatro motivos', () => {
    expect(ACTIONS).toHaveLength(9)
    expect(SCOPES).toHaveLength(6)
    expect(EFFECTS).toHaveLength(2)
    expect(DECISIONS).toHaveLength(2)
    expect(INERT_REASONS).toHaveLength(4)
  })

  it.each(['es', 'en', 'de', 'fr'])('%s tiene etiqueta no vacía para cada valor', (locale) => {
    const vocab = (LOCALES[locale]!.core as Tree).permissions as Tree

    for (const [group, values] of groups) {
      const node = (vocab.vocabulary as Tree)[group] as Tree

      for (const value of values) {
        expect(typeof node[value], `${locale} ${group}.${value}`).toBe('string')
        expect((node[value] as string).trim(), `${locale} ${group}.${value}`).not.toBe('')
      }

      expect(Object.keys(node).sort(), `${locale} ${group}`).toEqual([...values].sort())
    }
  })

  it('las etiquetas se pintan traducidas en cada idioma', () => {
    expect(vocabulary('es').action('actualizar')).toBe('Actualizar')
    expect(vocabulary('en').action('actualizar')).toBe('Update')
    expect(vocabulary('de').scope('unidad_familiar')).toBe('Familieneinheit')
    expect(vocabulary('fr').decision('denegado')).toBe('Refusé')
    expect(vocabulary('en').effect('deny')).toBe('Deny')
    expect(vocabulary('es').inertReason('inerte_datos_especiales')).toContain('categoría especial')
  })

  it('un valor desconocido de cualquier vocabulario se pinta en crudo (ADR-038 §7.3)', () => {
    const vocab = vocabulary('es')

    expect(vocab.action('archivar')).toBe('archivar')
    expect(vocab.scope('region')).toBe('region')
    expect(vocab.effect('audit')).toBe('audit')
    expect(vocab.decision('pendiente')).toBe('pendiente')
    expect(vocab.inertReason('inerte_futuro')).toBe('inerte_futuro')
  })

  it('el recurso es resource_label tal cual; sin él, el código en crudo (RN-PERM-28)', () => {
    const vocab = vocabulary('es')

    expect(vocab.resource('usuario', 'Usuarios')).toBe('Usuarios')
    expect(vocab.resource('usuario')).toBe('usuario')
    expect(vocab.resource('usuario', '')).toBe('usuario')
  })

  it('el orden de dominio de las acciones es el de RPERM-003, con las desconocidas al final', () => {
    expect([...ACTIONS].reverse().sort(compareActions)).toEqual([...ACTIONS])
    expect(['archivar', 'leer', 'crear'].sort(compareActions)).toEqual([
      'crear',
      'leer',
      'archivar',
    ])
  })
})
