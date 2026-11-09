#!/usr/bin/env node
// Comprobaciones propias de ADR-072 §4.2 y §4.5. Solo módulos integrados.
// Uso: node scripts/check.mjs [--root <directorio>] [--publish]
//   --publish: además falla con marcadores pendientes ([TITULAR]...) y con claves
//              pendientes de traducción (`_pending_translation`).
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, dirname, resolve, sep } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import {
  LANGS,
  DEFAULT_LANG,
  ORIGIN,
  PENDING_KEY,
  MARKER_RE,
  KEY_PATTERN,
  INNER_MARKER_RE,
  PENDING_DATA_RE,
  readLocales,
  tokenize,
  pagePath,
} from './lib.mjs'
import { SECURITY_HEADERS } from './headers.mjs'

const VISIBLE_ATTRS = ['alt', 'title', 'aria-label', 'aria-description', 'placeholder']
const ANY_MARKER_RE = /\{\{[^}]*\}\}/g
const ONLY_T_MARKER_RE = new RegExp(`^\\{\\{t:${KEY_PATTERN}\\}\\}$`)
const HAS_PENDING_DATA = new RegExp(PENDING_DATA_RE.source)
const FORBIDDEN_TAGS = ['script', 'form', 'iframe', 'object', 'embed', 'style']

function walk(dir) {
  const out = []
  for (const name of readdirSync(dir)) {
    const p = join(dir, name)
    if (statSync(p).isDirectory()) out.push(...walk(p))
    else out.push(p)
  }
  return out
}

const isScheme = (v) => /^(?:[a-z][a-z0-9+.-]*:|\/\/)/i.test(v)

function keysOf(locale) {
  return Object.keys(locale).filter((k) => k !== PENDING_KEY)
}

// ---------- Comprobaciones de fuente: locales y plantillas ----------

function checkLocales(root, publish, report) {
  const locales = readLocales(root)
  let ok = true
  for (const lang of LANGS) {
    if (!locales[lang]) {
      report.error(`locales/${lang}.json: no existe`)
      ok = false
    }
  }
  if (!ok) return null

  if (PENDING_KEY in locales[DEFAULT_LANG]) {
    report.error(
      `locales/${DEFAULT_LANG}.json: "${PENDING_KEY}" solo se permite en los otros idiomas`,
    )
  }
  for (const lang of LANGS.filter((l) => l !== DEFAULT_LANG)) {
    if (locales[lang][PENDING_KEY] === true) {
      const msg = `locales/${lang}.json: traducción pendiente (${PENDING_KEY})`
      publish ? report.error(msg) : report.warn(msg)
    } else if (PENDING_KEY in locales[lang]) {
      report.error(`locales/${lang}.json: "${PENDING_KEY}" solo puede valer true (o no existir)`)
    }
  }

  const base = new Set(keysOf(locales[DEFAULT_LANG]))
  for (const lang of LANGS.filter((l) => l !== DEFAULT_LANG)) {
    const keys = new Set(keysOf(locales[lang]))
    for (const k of base)
      if (!keys.has(k)) report.error(`locales/${lang}.json: falta la clave "${k}"`)
    for (const k of keys)
      if (!base.has(k)) report.error(`locales/${lang}.json: sobra la clave "${k}"`)
  }

  const pendingData = []
  for (const lang of LANGS) {
    for (const k of keysOf(locales[lang])) {
      const v = locales[lang][k]
      if (typeof v !== 'string' || v.trim() === '') {
        report.error(`locales/${lang}.json: clave "${k}" vacía o no es texto`)
        continue
      }
      if (v.includes('<')) report.error(`locales/${lang}.json: "${k}" contiene "<" (texto plano)`)
      if (publish) {
        const pend = v.match(PENDING_DATA_RE)
        if (pend)
          report.error(`locales/${lang}.json: "${k}" tiene marcador pendiente ${pend.join(', ')}`)
      } else if (lang === DEFAULT_LANG && HAS_PENDING_DATA.test(v)) {
        pendingData.push(k)
      }
    }
  }

  if (pendingData.length > 0) {
    report.warn(
      `locales/${DEFAULT_LANG}.json: ${pendingData.length} clave(s) con marcador pendiente (bloquean --publish)`,
    )
  }

  for (const k of base) {
    const ref = JSON.stringify(
      (String(locales[DEFAULT_LANG][k]).match(INNER_MARKER_RE) ?? []).sort(),
    )
    for (const lang of LANGS.filter((l) => l !== DEFAULT_LANG)) {
      const v = locales[lang][k]
      if (typeof v !== 'string') continue
      const mine = JSON.stringify((v.match(INNER_MARKER_RE) ?? []).sort())
      if (mine !== ref)
        report.error(`locales/${lang}.json: los marcadores de "${k}" difieren de es-ES`)
    }
  }
  return locales
}

export function checkTemplate(name, html, locales, publish, report) {
  const tokens = tokenize(html)
  for (const m of html.matchAll(/\{\{t:[^}]*\}\}/g)) {
    if (!new RegExp(`^\\{\\{t:${KEY_PATTERN}\\}\\}$`).test(m[0])) {
      report.error(`${name}: marcador de clave inválido ${m[0]}`)
    }
  }
  for (const t of tokens) {
    if (t.type === 'text') {
      const rest = t.raw.replace(ANY_MARKER_RE, '').trim()
      if (rest !== '')
        report.error(`${name}: literal visible fuera de un marcador: "${rest.slice(0, 60)}"`)
    } else if (t.type === 'open') {
      if (FORBIDDEN_TAGS.includes(t.name))
        report.error(`${name}: etiqueta <${t.name}> no permitida`)
      for (const attr of VISIBLE_ATTRS) {
        if (attr in t.attrs && !ONLY_T_MARKER_RE.test(t.attrs[attr])) {
          report.error(`${name}: atributo "${attr}" visible sin marcador: "${t.attrs[attr]}"`)
        }
      }
      if (t.name === 'meta' && t.attrs.name?.toLowerCase() === 'description') {
        if (!ONLY_T_MARKER_RE.test(t.attrs.content ?? '')) {
          report.error(`${name}: <meta name="description"> sin marcador`)
        }
      }
      if ('style' in t.attrs) report.error(`${name}: atributo style no permitido (CSP)`)
    }
  }
  for (const m of html.matchAll(MARKER_RE)) {
    for (const lang of LANGS) {
      if (locales && locales[lang] && !(m[1] in locales[lang])) {
        report.error(`${name}: la clave "${m[1]}" no existe en ${lang}`)
      }
    }
  }
  if (publish && HAS_PENDING_DATA.test(html))
    report.error(`${name}: marcador pendiente en la plantilla`)
}

function checkSource(root, locales, publish, report) {
  const srcDir = join(root, 'src')
  if (!existsSync(srcDir)) {
    report.error('src/: no existe')
    return
  }
  const used = new Set()
  for (const file of walk(srcDir).filter((f) => f.endsWith('.html'))) {
    const html = readFileSync(file, 'utf8')
    checkTemplate(relative(root, file), html, locales, publish, report)
    for (const m of html.matchAll(MARKER_RE)) used.add(m[1])
  }
  if (locales) {
    for (const k of keysOf(locales[DEFAULT_LANG])) {
      if (!used.has(k)) report.warn(`locales: la clave "${k}" no se usa en ninguna plantilla`)
    }
  }
}

// ---------- Comprobaciones de dist/ ----------

function pageUrlOf(distDir, file) {
  const rel = relative(distDir, file).split(sep).join('/')
  if (rel === 'index.html') return '/'
  if (rel.endsWith('/index.html')) return '/' + rel.slice(0, -'index.html'.length)
  return null
}

function expectedLang(url) {
  const first = url.split('/')[1]
  return LANGS.includes(first) ? first : DEFAULT_LANG
}

function parseHeaders(text) {
  const blocks = {}
  let current = null
  for (const line of text.split('\n')) {
    if (line.trim() === '' || line.trim().startsWith('#')) continue
    if (!/^\s/.test(line)) {
      current = line.trim()
      blocks[current] = {}
    } else if (current) {
      const i = line.indexOf(':')
      if (i > -1) blocks[current][line.slice(0, i).trim()] = line.slice(i + 1).trim()
    }
  }
  return blocks
}

function checkHeaders(distDir, report) {
  const file = join(distDir, '_headers')
  if (!existsSync(file)) return report.error('dist/_headers: no existe')
  const all = parseHeaders(readFileSync(file, 'utf8'))['/*'] ?? {}
  for (const [name, value] of Object.entries(SECURITY_HEADERS)) {
    if (!(name in all)) report.error(`dist/_headers: falta ${name}`)
    else if (all[name] !== value) report.error(`dist/_headers: ${name} no tiene el valor exacto`)
  }
}

function collectIds(html) {
  const ids = new Set()
  for (const t of tokenize(html)) if (t.type === 'open' && t.attrs.id) ids.add(t.attrs.id)
  return ids
}

function newestMtime(paths) {
  return Math.max(0, ...paths.map((p) => statSync(p).mtimeMs))
}

function checkDist(root, publish, report) {
  const distDir = join(root, 'dist')
  if (!existsSync(distDir)) {
    report.error('dist/: no existe (ejecute primero la construcción)')
    return
  }
  const files = walk(distDir)
  if (publish) {
    const sources = ['src', 'locales']
      .filter((d) => existsSync(join(root, d)))
      .flatMap((d) => walk(join(root, d)))
    if (newestMtime(files) < newestMtime(sources)) {
      report.error('dist/: es anterior a src/ o locales/ (vuelva a ejecutar la construcción)')
    }
  }
  const htmlFiles = files.filter((f) => f.endsWith('.html'))
  const info = new Map() // url -> {file, tokens, html, alternates, canonical, lang}
  const idCache = new Map()

  for (const file of htmlFiles) {
    const rel = relative(distDir, file)
    const html = readFileSync(file, 'utf8')
    const tokens = tokenize(html)
    const alternates = {}
    let canonical = null
    let lang = null
    let h1 = 0
    if (/\{\{[^}]*\}\}/.test(html)) report.error(`dist/${rel}: marcador sin resolver`)
    if (publish) {
      const pend = html.match(PENDING_DATA_RE)
      if (pend) report.error(`dist/${rel}: marcador pendiente ${[...new Set(pend)].join(', ')}`)
      if (html.includes(PENDING_KEY)) report.error(`dist/${rel}: contiene ${PENDING_KEY}`)
    }
    for (const t of tokens) {
      if (t.type !== 'open') continue
      if (FORBIDDEN_TAGS.includes(t.name)) report.error(`dist/${rel}: <${t.name}> no permitido`)
      if (t.name === 'h1') h1++
      if (t.name === 'html') lang = t.attrs.lang ?? null
      for (const a of Object.keys(t.attrs)) {
        if (a.startsWith('on')) report.error(`dist/${rel}: manejador de evento "${a}" no permitido`)
        if (a === 'style') report.error(`dist/${rel}: atributo style no permitido (CSP)`)
      }
      for (const a of ['src', 'srcset', 'data', 'poster']) {
        if (a in t.attrs && (isScheme(t.attrs[a]) || /(^|[\s,])(https?:)?\/\//.test(t.attrs[a]))) {
          report.error(`dist/${rel}: recurso externo en ${t.name}[${a}]: ${t.attrs[a]}`)
        }
      }
      if (t.name === 'link') {
        const relAttr = (t.attrs.rel ?? '').toLowerCase()
        const href = t.attrs.href ?? ''
        if (relAttr === 'canonical') canonical = href
        else if (relAttr === 'alternate') alternates[t.attrs.hreflang ?? ''] = href
        else if (isScheme(href))
          report.error(`dist/${rel}: <link rel="${relAttr}"> externo: ${href}`)
      }
    }
    if (h1 !== 1) report.error(`dist/${rel}: debe tener exactamente un <h1> (tiene ${h1})`)
    if (!lang) report.error(`dist/${rel}: <html> sin lang`)

    const url = pageUrlOf(distDir, file)
    if (url) {
      info.set(url, { rel, tokens, alternates, canonical, lang })
      const want = expectedLang(url)
      if (lang && lang !== want) report.error(`dist/${rel}: lang="${lang}", se esperaba "${want}"`)
      if (canonical !== ORIGIN + url)
        report.error(`dist/${rel}: canonical debe ser ${ORIGIN + url}`)
      for (const l of LANGS) {
        if (!(l in alternates)) report.error(`dist/${rel}: falta hreflang="${l}"`)
      }
      if (!('x-default' in alternates)) report.error(`dist/${rel}: falta hreflang="x-default"`)
    }
    idCache.set(file, collectIds(html))
  }

  // hreflang recíprocos
  for (const [url, p] of info) {
    for (const [hl, href] of Object.entries(p.alternates)) {
      if (hl === 'x-default') continue
      if (!href.startsWith(ORIGIN)) continue
      const target = info.get(href.slice(ORIGIN.length))
      if (!target) {
        report.error(`dist/${p.rel}: hreflang="${hl}" apunta a una página inexistente (${href})`)
        continue
      }
      if (target.alternates[expectedLang(url)] !== ORIGIN + url) {
        report.error(`dist/${p.rel}: hreflang="${hl}" no es recíproco con ${href}`)
      }
    }
    if (p.alternates['x-default'] && p.alternates[DEFAULT_LANG] !== p.alternates['x-default']) {
      report.error(`dist/${p.rel}: x-default debe coincidir con hreflang="${DEFAULT_LANG}"`)
    }
  }

  // enlaces internos y anclas
  for (const file of htmlFiles) {
    const rel = relative(distDir, file)
    const pageUrl = pageUrlOf(distDir, file) ?? '/' + rel.split(sep).join('/')
    const tokens = tokenize(readFileSync(file, 'utf8'))
    for (const t of tokens) {
      if (t.type !== 'open') continue
      const attr = t.name === 'a' || t.name === 'link' ? 'href' : t.name === 'img' ? 'src' : null
      if (!attr || !(attr in t.attrs)) continue
      const value = t.attrs[attr]
      if (/^(mailto:|tel:)/i.test(value)) continue
      let url
      try {
        url = new URL(value, ORIGIN + pageUrl)
      } catch {
        report.error(`dist/${rel}: URL no válida "${value}"`)
        continue
      }
      if (t.name === 'a' && /^https?:$/.test(url.protocol) && url.origin !== ORIGIN) {
        const rels = (t.attrs.rel ?? '').toLowerCase().split(/\s+/)
        if (!rels.includes('noopener') || !rels.includes('noreferrer')) {
          report.error(`dist/${rel}: enlace externo "${value}" sin rel="noopener noreferrer"`)
        }
      }
      if (url.origin !== ORIGIN) continue // externos: se revisan a mano (ADR-072 §4.5)
      let path = decodeURIComponent(url.pathname)
      if (path.endsWith('/')) path += 'index.html'
      const target = join(distDir, path)
      if (!existsSync(target) || !statSync(target).isFile()) {
        report.error(`dist/${rel}: enlace roto "${value}"`)
        continue
      }
      if (url.hash.length > 1 && target.endsWith('.html')) {
        if (!idCache.has(target)) idCache.set(target, collectIds(readFileSync(target, 'utf8')))
        if (!idCache.get(target).has(decodeURIComponent(url.hash.slice(1)))) {
          report.error(`dist/${rel}: ancla rota "${value}"`)
        }
      }
    }
  }

  // CSS sin recursos externos
  for (const file of files.filter((f) => f.endsWith('.css'))) {
    const css = readFileSync(file, 'utf8')
    if (/@import/i.test(css)) report.error(`dist/${relative(distDir, file)}: @import no permitido`)
    for (const m of css.matchAll(/url\(\s*['"]?([^'")]+)/gi)) {
      if (isScheme(m[1]) && !m[1].startsWith('data:')) {
        report.error(`dist/${relative(distDir, file)}: url() externa ${m[1]}`)
      }
    }
  }

  for (const name of ['404.html', 'robots.txt', 'sitemap.xml', '_redirects']) {
    if (!existsSync(join(distDir, name))) report.error(`dist/${name}: no existe`)
  }
  const sm = join(distDir, 'sitemap.xml')
  if (existsSync(sm)) {
    const locs = new Set(
      [...readFileSync(sm, 'utf8').matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]),
    )
    for (const url of info.keys()) {
      if (!locs.has(ORIGIN + url)) report.error(`dist/sitemap.xml: falta ${ORIGIN + url}`)
    }
    for (const loc of locs) {
      if (loc.startsWith(ORIGIN) && !info.has(loc.slice(ORIGIN.length))) {
        report.error(`dist/sitemap.xml: ${loc} no existe en dist/`)
      }
    }
  }
  checkHeaders(distDir, report)
}

// ---------- API ----------

export function runChecks({ root, publish = false, skipDist = false }) {
  const errors = []
  const warnings = []
  const report = { error: (m) => errors.push(m), warn: (m) => warnings.push(m) }
  const locales = checkLocales(root, publish, report)
  checkSource(root, locales, publish, report)
  if (!skipDist) checkDist(root, publish, report)
  return { errors, warnings }
}

const isMain = process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href
if (isMain) {
  const i = process.argv.indexOf('--root')
  const root =
    i > -1 ? resolve(process.argv[i + 1]) : resolve(dirname(fileURLToPath(import.meta.url)), '..')
  const publish = process.argv.includes('--publish')
  const { errors, warnings } = runChecks({ root, publish })
  for (const w of warnings) console.warn(`AVISO: ${w}`)
  for (const e of errors) console.error(`ERROR: ${e}`)
  if (errors.length > 0) {
    console.error(`check: ${errors.length} error(es)${publish ? ' (modo --publish)' : ''}`)
    process.exit(1)
  }
  console.log(`check: correcto${publish ? ' (modo --publish)' : ''} (${warnings.length} aviso(s))`)
}
