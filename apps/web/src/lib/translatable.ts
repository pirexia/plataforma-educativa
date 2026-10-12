/**
 * `docs/adr/ADR-062-contenido-del-centro-traducible.md §5.2`, `§5.3`, `§5.7`.
 * Lógica pura del campo traducible de la SPA: orden de los idiomas, cálculo de
 * la fusión de un `PATCH` y procedencia de un texto resuelto por caída
 * (WCAG 3.1.2). La resolución de `name` sigue siendo del servidor (`RN-ACAD-13`):
 * aquí solo se **identifica** de qué idioma salió, para marcar `lang`.
 */
import type { Locale } from '@/modules/core/types'

/** Vocabulario cerrado y su orden de caída (`ADR-062 §5.3`). */
export const TRANSLATION_LOCALES: readonly Locale[] = ['es-ES', 'en', 'de', 'fr']

export type TranslationValues = Partial<Record<Locale, string>>

/** El idioma por defecto primero y después el resto de los activos, en el orden canónico. */
export function orderedLocales(defaultLocale: Locale, activeLocales: readonly Locale[]): Locale[] {
  const rest = TRANSLATION_LOCALES.filter(
    (locale) => locale !== defaultLocale && activeLocales.includes(locale),
  )

  return [defaultLocale, ...rest]
}

/** Valores recortados y sin vacíos, solo de los idiomas indicados. */
export function cleanTranslations(
  values: TranslationValues,
  locales: readonly Locale[],
): TranslationValues {
  const result: TranslationValues = {}

  for (const locale of locales) {
    const text = (values[locale] ?? '').trim()

    if (text !== '') {
      result[locale] = text
    }
  }

  return result
}

/**
 * Cuerpo de un `PATCH` de `<campo>_translations` (fusión por idioma,
 * `ADR-062 §5.2` punto 2): una clave cambia solo ese idioma; `null` lo quita.
 * Solo se envían los idiomas activos que cambian; los demás (también los de un
 * idioma ya no activo) no se mencionan y el servidor no los toca.
 */
export function translationsPatch(
  original: TranslationValues,
  edited: TranslationValues,
  locales: readonly Locale[],
): Record<string, string | null> {
  const next = cleanTranslations(edited, locales)
  const patch: Record<string, string | null> = {}

  for (const locale of locales) {
    const before = original[locale]
    const after = next[locale]

    if (after !== undefined && after !== before) {
      patch[locale] = after
    } else if (after === undefined && before !== undefined) {
      patch[locale] = null
    }
  }

  return patch
}

/**
 * Idioma del que sale `text` (el `name` resuelto): el primero, en el orden de
 * caída del servidor (idioma de la página → por defecto → `es-ES` → orden
 * canónico), cuya traducción coincide con el texto. `null` si no coincide
 * ninguna (texto desconocido).
 */
export function provenanceLocale(
  text: string,
  translations: TranslationValues,
  pageLocale: Locale,
  defaultLocale: Locale,
): Locale | null {
  const order: Locale[] = [pageLocale, defaultLocale, 'es-ES', ...TRANSLATION_LOCALES]

  for (const locale of order) {
    if (translations[locale] === text) {
      return locale
    }
  }

  return null
}
