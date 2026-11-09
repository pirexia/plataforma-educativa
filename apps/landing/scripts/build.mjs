#!/usr/bin/env node
// Construye dist/ a partir de src/ y locales/ (ADR-072 §4.1). Solo módulos integrados.
// Uso: node scripts/build.mjs [--root <directorio>]
import {
  mkdirSync,
  readFileSync,
  writeFileSync,
  rmSync,
  existsSync,
  cpSync,
  statSync,
} from 'node:fs'
import { join, dirname, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import {
  LANGS,
  DEFAULT_LANG,
  ORIGIN,
  LEGAL_PREFIX,
  KEY_PATTERN,
  PAGES,
  readLocales,
  langPath,
  pagePath,
  escapeHtml,
} from './lib.mjs'
import { SECURITY_HEADERS, REDIRECT_HOSTS } from './headers.mjs'

const TOKEN_RE = new RegExp(`\\{\\{(?:(t):(${KEY_PATTERN})|(v):([^}]+))\\}\\}`, 'g')
const INCLUDE_RE = /\{\{include:([A-Za-z0-9_-]+)\}\}/g

function readTemplate(root, name) {
  const file = join(root, 'src', `${name}.html`)
  if (!existsSync(file)) throw new Error(`Plantilla inexistente: src/${name}.html`)
  return readFileSync(file, 'utf8')
}

function expandIncludes(root, html, depth = 0) {
  if (depth > 5) throw new Error('Includes anidados en exceso (¿ciclo?)')
  return html.replace(INCLUDE_RE, (_, name) => {
    const part = readFileSync(join(root, 'src', 'partials', `${name}.html`), 'utf8')
    return expandIncludes(root, part, depth + 1)
  })
}

function text(locales, lang, key, where) {
  // Textos legales: siempre es-ES (OPEN-072-05).
  const source = key.startsWith(LEGAL_PREFIX) ? DEFAULT_LANG : lang
  const value = locales[source]?.[key]
  if (typeof value !== 'string') {
    throw new Error(`Clave de idioma inexistente "${key}" (${source}) en ${where}`)
  }
  return value
}

export function render(root, locales, template, lang, vars) {
  const html = expandIncludes(root, readTemplate(root, template))
  const out = html.replace(TOKEN_RE, (_, tk, tkey, vk, vkey) => {
    const kind = tk ?? vk
    const key = tkey ?? vkey
    if (kind === 't') return escapeHtml(text(locales, lang, key, template))
    if (!(key in vars)) throw new Error(`Variable inexistente "${key}" en ${template}`)
    return vars[key]
  })
  const left = out.match(/\{\{[^}]*\}\}/)
  if (left) throw new Error(`Marcador inválido ${left[0]} en ${template}`)
  return out
}

function pageVars(lang, page) {
  const vars = {
    lang,
    home: langPath(lang),
    'legal-href': pagePath(lang, 'legal'),
    canonical: ORIGIN + pagePath(lang, page),
    'alt.default': ORIGIN + pagePath(DEFAULT_LANG, page),
    'legal-lang': DEFAULT_LANG,
  }
  for (const l of LANGS) {
    vars[`alt.${l}`] = ORIGIN + pagePath(l, page)
    vars[`href.${l}`] = pagePath(l, page)
    vars[`cur.${l}`] = l === lang ? ' aria-current="page"' : ''
  }
  return vars
}

function write(dist, rel, content) {
  const file = join(dist, rel)
  mkdirSync(dirname(file), { recursive: true })
  writeFileSync(file, content)
}

function sitemap() {
  const urls = []
  for (const page of PAGES) {
    for (const lang of LANGS) {
      const links = LANGS.map(
        (l) =>
          `    <xhtml:link rel="alternate" hreflang="${l}" href="${ORIGIN + pagePath(l, page)}"/>`,
      )
      links.push(
        `    <xhtml:link rel="alternate" hreflang="x-default" href="${ORIGIN + pagePath(DEFAULT_LANG, page)}"/>`,
      )
      urls.push(
        `  <url>\n    <loc>${ORIGIN + pagePath(lang, page)}</loc>\n${links.join('\n')}\n  </url>`,
      )
    }
  }
  return `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
${urls.join('\n')}
</urlset>
`
}

function headersFile() {
  const lines = Object.entries(SECURITY_HEADERS).map(([k, v]) => `  ${k}: ${v}`)
  return `/*\n${lines.join('\n')}\n`
}

function redirectsFile() {
  const lines = [
    '# ADR-072 §4.3: todo nombre que no sea https://adagiox.com -> 301 a la misma ruta.',
    '# Los dominios .es/.eu/.net también se redirigen por regla de zona en el proveedor (T2 lo verifica).',
  ]
  for (const host of REDIRECT_HOSTS) {
    lines.push(`https://${host}/* ${ORIGIN}/:splat 301!`)
    lines.push(`http://${host}/* ${ORIGIN}/:splat 301!`)
  }
  return lines.join('\n') + '\n'
}

export function build({ root }) {
  if (!root || !existsSync(root) || !statSync(root).isDirectory()) {
    throw new Error(`--root no existe o no es un directorio: ${root}`)
  }
  const locales = readLocales(root)
  for (const lang of LANGS) {
    if (!locales[lang]) throw new Error(`Falta locales/${lang}.json`)
  }
  const dist = join(root, 'dist')
  rmSync(dist, { recursive: true, force: true })
  mkdirSync(dist, { recursive: true })

  for (const lang of LANGS) {
    for (const page of PAGES) {
      const html = render(root, locales, page, lang, pageVars(lang, page))
      write(dist, join(pagePath(lang, page), 'index.html'), html)
    }
  }
  write(
    dist,
    '404.html',
    render(root, locales, '404', DEFAULT_LANG, pageVars(DEFAULT_LANG, 'home')),
  )
  cpSync(join(root, 'src', 'styles.css'), join(dist, 'styles.css'))
  write(dist, 'sitemap.xml', sitemap())
  write(dist, 'robots.txt', `User-agent: *\nAllow: /\n\nSitemap: ${ORIGIN}/sitemap.xml\n`)
  write(dist, '_headers', headersFile())
  write(dist, '_redirects', redirectsFile())
  return dist
}

const isMain = process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href
if (isMain) {
  const i = process.argv.indexOf('--root')
  const root =
    i > -1
      ? resolve(process.argv[i + 1] ?? '')
      : resolve(dirname(fileURLToPath(import.meta.url)), '..')
  try {
    const dist = build({ root })
    console.log(`Construido: ${dist}`)
  } catch (e) {
    console.error(`ERROR de construcción: ${e.message}`)
    process.exit(1)
  }
}
