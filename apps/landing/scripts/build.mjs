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
import { join, dirname, resolve, basename } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import {
  LANGS,
  DEFAULT_LANG,
  LEGAL_LANG,
  ORIGIN,
  LEGAL_PREFIX,
  KEY_PATTERN,
  PAGES,
  readLocales,
  langPath,
  pagePath,
  escapeHtml,
  langNameKey,
} from './lib.mjs'
import { SECURITY_HEADERS, REDIRECT_HOSTS } from './headers.mjs'
import { readManifest, pictureHtml } from './picture.mjs'

// {{t:clave}} texto de idioma · {{v:variable}} variable de página · {{img:id}} / {{img:id:lcp}}
// imagen del manifiesto (ADR-074 §4.3).
const TOKEN_RE = new RegExp(
  `\\{\\{(?:(t):(${KEY_PATTERN})|(v):([^}]+)|(img):([A-Za-z0-9_-]+)(:lcp)?)\\}\\}`,
  'g',
)
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
  // Textos legales: siempre LEGAL_LANG (OPEN-072-05, ADR-074 §4.1).
  const source = key.startsWith(LEGAL_PREFIX) ? LEGAL_LANG : lang
  const value = locales[source]?.[key]
  if (typeof value !== 'string') {
    throw new Error(`Clave de idioma inexistente "${key}" (${source}) en ${where}`)
  }
  return value
}

export function render(root, locales, template, lang, vars) {
  const html = expandIncludes(root, readTemplate(root, template))
  const out = html.replace(TOKEN_RE, (_, tk, tkey, vk, vkey, ik, iid, ilcp) => {
    if (ik) {
      return pictureHtml(readManifest(root), iid, locales[lang], { lcp: Boolean(ilcp) })
    }
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

// Variables de página. Las plantillas no nombran idiomas: el selector (`lang-items`) y los
// <link rel="alternate"> (`alternates`) salen de recorrer `langs` (ADR-074 §4.2). Con
// `current: false` (la 404) ningún enlace del selector lleva aria-current.
function pageVars(locales, lang, page, langs, { current = true } = {}) {
  const items = langs.map((l) => {
    const name = locales[lang]?.[langNameKey(l.code)]
    if (typeof name !== 'string') {
      throw new Error(`Falta el nombre del idioma "${langNameKey(l.code)}" en ${lang}`)
    }
    const cur = current && l.code === lang ? ' aria-current="page"' : ''
    const href = pagePath(l.code, page, langs)
    return `<li><a href="${href}" lang="${l.code}" hreflang="${l.code}"${cur}>${escapeHtml(name)}</a></li>`
  })
  const alternates = langs.map(
    (l) =>
      `<link rel="alternate" hreflang="${l.code}" href="${ORIGIN + pagePath(l.code, page, langs)}">`,
  )
  alternates.push(
    `<link rel="alternate" hreflang="x-default" href="${ORIGIN + pagePath(DEFAULT_LANG, page, langs)}">`,
  )
  return {
    lang,
    dir: langs.find((l) => l.code === lang).dir,
    home: langPath(lang, langs),
    'legal-href': pagePath(lang, 'legal', langs),
    canonical: ORIGIN + pagePath(lang, page, langs),
    'legal-lang': LEGAL_LANG,
    'lang-items': items.join('\n        '),
    alternates: alternates.join('\n'),
  }
}

function write(dist, rel, content) {
  const file = join(dist, rel)
  mkdirSync(dirname(file), { recursive: true })
  writeFileSync(file, content)
}

function sitemap(langs) {
  const urls = []
  for (const page of PAGES) {
    for (const { code: lang } of langs) {
      const links = langs.map(
        (l) =>
          `    <xhtml:link rel="alternate" hreflang="${l.code}" href="${ORIGIN + pagePath(l.code, page, langs)}"/>`,
      )
      links.push(
        `    <xhtml:link rel="alternate" hreflang="x-default" href="${ORIGIN + pagePath(DEFAULT_LANG, page, langs)}"/>`,
      )
      urls.push(
        `  <url>\n    <loc>${ORIGIN + pagePath(lang, page, langs)}</loc>\n${links.join('\n')}\n  </url>`,
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

export function build({ root, langs = LANGS }) {
  if (!root || !existsSync(root) || !statSync(root).isDirectory()) {
    throw new Error(`--root no existe o no es un directorio: ${root}`)
  }
  const locales = readLocales(root, langs)
  for (const { code: lang } of langs) {
    if (!locales[lang]) throw new Error(`Falta locales/${lang}.json`)
  }
  const dist = join(root, 'dist')
  rmSync(dist, { recursive: true, force: true })
  mkdirSync(dist, { recursive: true })

  for (const { code: lang } of langs) {
    for (const page of PAGES) {
      const html = render(root, locales, page, lang, pageVars(locales, lang, page, langs))
      write(dist, join(pagePath(lang, page, langs), 'index.html'), html)
    }
  }
  // La 404 se sirve en DEFAULT_LANG, con enlaces a todas las portadas y sin idioma actual.
  write(
    dist,
    '404.html',
    render(
      root,
      locales,
      '404',
      DEFAULT_LANG,
      pageVars(locales, DEFAULT_LANG, 'home', langs, { current: false }),
    ),
  )
  cpSync(join(root, 'src', 'styles.css'), join(dist, 'styles.css'))
  // Imágenes ya optimizadas (ADR-074 §4.3); manifest.json es solo fuente de build, no se publica.
  const imgSrc = join(root, 'src', 'img')
  if (existsSync(imgSrc)) {
    cpSync(imgSrc, join(dist, 'img'), {
      recursive: true,
      filter: (src) => basename(src) !== 'manifest.json',
    })
  }
  write(dist, 'sitemap.xml', sitemap(langs))
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
