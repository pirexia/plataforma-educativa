// Utilidades comunes de build.mjs y check.mjs. Solo módulos integrados de Node (ADR-072 §4.1).
import { readFileSync, existsSync } from 'node:fs'
import { join } from 'node:path'

// ADR-074 §4.1-4.2: LANGS es la única lista de idiomas. `code` es el código BCP 47 (ADR-021: va en
// `lang` y `hreflang`); `slug` es el segmento de URL ('' = raíz); `dir` es la dirección del texto.
export const LANGS = [
  { code: 'en', slug: '', dir: 'ltr' },
  { code: 'es-ES', slug: 'es', dir: 'ltr' },
  { code: 'de', slug: 'de', dir: 'ltr' },
  { code: 'fr', slug: 'fr', dir: 'ltr' },
]
export const LANG_CODES = LANGS.map((l) => l.code)
// Los tres papeles que antes hacía DEFAULT_LANG (ADR-074 §4.1):
//   DEFAULT_LANG: idioma de `/`, de `x-default` y de la 404.
//   BASE_LANG: base de la paridad de claves. `en` y `es-ES` son idiomas de redacción.
//   LEGAL_LANG: idioma único de las claves `legal.body.*`.
export const DEFAULT_LANG = 'en'
export const BASE_LANG = 'en'
export const LEGAL_LANG = 'es-ES'
export const ORIGIN = 'https://adagiox.com'
export const PENDING_KEY = '_pending_translation'
// Los textos legales no se traducen automáticamente (CLAUDE.md §2.9, OPEN-072-05): se
// renderizan siempre desde es-ES, con lang="es-ES", en las cuatro rutas.
export const LEGAL_PREFIX = 'legal.body.'
export const PAGES = ['home', 'legal']
export const KEY_PATTERN = '[A-Za-z0-9_.-]+'
export const MARKER_RE = new RegExp(`\\{\\{t:(${KEY_PATTERN})\\}\\}`, 'g')
// Marcadores internos de un valor de idioma: {nombre}.
export const INNER_MARKER_RE = /\{[A-Za-z0-9_]+\}/g
// Marcadores pendientes de datos del titular: [TITULAR], [NIF]...
export const PENDING_DATA_RE = /\[[A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ0-9_-]{2,}\]|\b(?:TODO|TBD)\b/g

export function localeFile(root, lang) {
  return join(root, 'locales', `${lang}.json`)
}

export function readLocales(root, langs = LANGS) {
  const out = {}
  for (const { code: lang } of langs) {
    const file = localeFile(root, lang)
    if (!existsSync(file)) {
      out[lang] = null
      continue
    }
    out[lang] = JSON.parse(readFileSync(file, 'utf8'))
  }
  return out
}

export function slugOf(lang, langs = LANGS) {
  const entry = langs.find((l) => l.code === lang)
  if (!entry) throw new Error(`Idioma desconocido: ${lang}`)
  return entry.slug
}

export function langPath(lang, langs = LANGS) {
  const slug = slugOf(lang, langs)
  return slug === '' ? '/' : `/${slug}/`
}

export function pagePath(lang, page, langs = LANGS) {
  return page === 'legal' ? `${langPath(lang, langs)}legal/` : langPath(lang, langs)
}

// Clave de locales del nombre propio de un idioma (autónimo), igual en todas las locales.
export const langNameKey = (code) => `lang.name.${code}`
// Clave de locales del texto alternativo de una imagen del manifiesto.
export const imgAltKey = (id) => `img.${id}.alt`

// Límites de peso de ADR-074 §4.3 y §4.6 (KiB = 1024 bytes).
export const MAX_IMG_FILE_BYTES = 120 * 1024
export const MAX_IMG_TOTAL_BYTES = Math.round(2.5 * 1024 * 1024)
export const IMG_WIDTHS = [640, 1024, 1600]
export const MAX_IMAGES = 8

const VOID = new Set([
  'meta',
  'link',
  'img',
  'br',
  'hr',
  'input',
  'source',
  'area',
  'base',
  'col',
  'wbr',
])

export function isVoid(name) {
  return VOID.has(name)
}

export function parseAttrs(str) {
  const attrs = {}
  const re = /([^\s"'<>/=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g
  let m
  while ((m = re.exec(str)) !== null) {
    attrs[m[1].toLowerCase()] = m[2] ?? m[3] ?? m[4] ?? ''
  }
  return attrs
}

// Tokenizador mínimo de HTML: suficiente para plantillas propias y para el HTML generado.
export function tokenize(html) {
  const tokens = []
  const re =
    /<!--[\s\S]*?-->|<![^>]*>|<\/([a-zA-Z][\w-]*)\s*>|<([a-zA-Z][\w-]*)((?:[^>"']|"[^"]*"|'[^']*')*)>|([^<]+|<)/g
  let m
  while ((m = re.exec(html)) !== null) {
    const raw = m[0]
    if (raw.startsWith('<!--')) tokens.push({ type: 'comment', raw })
    else if (raw.startsWith('<!')) tokens.push({ type: 'doctype', raw })
    else if (m[1]) tokens.push({ type: 'close', name: m[1].toLowerCase(), raw })
    else if (m[2]) {
      const attrStr = m[3].replace(/\/$/, '')
      tokens.push({ type: 'open', name: m[2].toLowerCase(), attrs: parseAttrs(attrStr), raw })
    } else tokens.push({ type: 'text', raw })
  }
  return tokens
}

export function escapeHtml(s) {
  return s
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;')
}
