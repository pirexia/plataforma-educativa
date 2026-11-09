// Utilidades comunes de build.mjs y check.mjs. Solo módulos integrados de Node (ADR-072 §4.1).
import { readFileSync, existsSync } from 'node:fs'
import { join } from 'node:path'

export const LANGS = ['es-ES', 'en', 'de', 'fr']
export const DEFAULT_LANG = 'es-ES'
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

export function readLocales(root) {
  const out = {}
  for (const lang of LANGS) {
    const file = localeFile(root, lang)
    if (!existsSync(file)) {
      out[lang] = null
      continue
    }
    out[lang] = JSON.parse(readFileSync(file, 'utf8'))
  }
  return out
}

export function langPath(lang) {
  return lang === DEFAULT_LANG ? '/' : `/${lang}/`
}

export function pagePath(lang, page) {
  return page === 'legal' ? `${langPath(lang)}legal/` : langPath(lang)
}

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
