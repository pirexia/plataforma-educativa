#!/usr/bin/env node
// Comprobaciones propias de ADR-072 §4.2 y §4.5. Solo módulos integrados.
// Uso: node scripts/check.mjs [--root <directorio>] [--publish]
//   --publish: además falla con marcadores pendientes ([TITULAR]...) y con claves
//              pendientes de traducción (`_pending_translation`).
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, dirname, resolve, sep, basename } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import {
  LANGS,
  DEFAULT_LANG,
  BASE_LANG,
  LEGAL_LANG,
  LEGAL_PREFIX,
  ORIGIN,
  PENDING_KEY,
  MARKER_RE,
  KEY_PATTERN,
  INNER_MARKER_RE,
  PENDING_DATA_RE,
  MAX_IMG_FILE_BYTES,
  MAX_IMG_TOTAL_BYTES,
  MAX_IMAGES,
  readLocales,
  tokenize,
  pagePath,
  escapeHtml,
  langNameKey,
  imgAltKey,
} from './lib.mjs'
import { manifestFile, IMG_URL_PREFIX } from './picture.mjs'
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

// Reglas de ADR-074 §4.2 sobre la propia lista de idiomas.
function checkLangs(langs, report) {
  const slugs = new Map()
  const codes = new Set()
  for (const l of langs) {
    if (typeof l.code !== 'string' || l.code === '') report.error('LANGS: idioma sin código')
    if (codes.has(l.code)) report.error(`LANGS: código duplicado "${l.code}"`)
    codes.add(l.code)
    if (typeof l.slug !== 'string' || !/^(?:[a-z][a-z0-9-]*)?$/.test(l.slug)) {
      report.error(`LANGS: el slug de "${l.code}" no es válido ("${l.slug}")`)
    } else if (slugs.has(l.slug)) {
      report.error(`LANGS: "${l.code}" y "${slugs.get(l.slug)}" comparten el slug "${l.slug}"`)
    } else slugs.set(l.slug, l.code)
    if (l.dir !== 'ltr' && l.dir !== 'rtl') {
      report.error(`LANGS: dir de "${l.code}" debe ser ltr o rtl ("${l.dir}")`)
    }
  }
  for (const [role, code] of [
    ['DEFAULT_LANG', DEFAULT_LANG],
    ['BASE_LANG', BASE_LANG],
    ['LEGAL_LANG', LEGAL_LANG],
  ]) {
    if (!codes.has(code)) report.error(`LANGS: ${role} ("${code}") no está en la lista`)
  }
  if (langs.find((l) => l.code === DEFAULT_LANG)?.slug !== '') {
    report.error(`LANGS: ${DEFAULT_LANG} (DEFAULT_LANG) debe tener slug vacío (sirve la raíz)`)
  }
}

// Cada portada y la página legal de cada idioma deben estar en .lighthouserc.json (ADR-074 §4.2).
function checkLighthouse(root, langs, report) {
  const file = join(root, '.lighthouserc.json')
  if (!existsSync(file)) return report.error('.lighthouserc.json: no existe')
  let urls
  try {
    urls = JSON.parse(readFileSync(file, 'utf8')).ci.collect.url
  } catch {
    return report.error('.lighthouserc.json: no se puede leer ci.collect.url')
  }
  if (!Array.isArray(urls)) return report.error('.lighthouserc.json: ci.collect.url no es una lista')
  const toUrl = (path) => `http://localhost${path}index.html`
  const wanted = [
    ...langs.map((l) => [`portada de ${l.code}`, toUrl(pagePath(l.code, 'home', langs))]),
    ['página legal', toUrl(pagePath(DEFAULT_LANG, 'legal', langs))],
  ]
  for (const [what, url] of wanted) {
    if (!urls.includes(url)) report.error(`.lighthouserc.json: falta la URL de la ${what} (${url})`)
  }
}

function checkLocales(root, publish, report, langs) {
  const locales = readLocales(root, langs)
  const codes = langs.map((l) => l.code)
  let ok = true
  for (const lang of codes) {
    if (!locales[lang]) {
      report.error(`locales/${lang}.json: no existe`)
      ok = false
    }
  }
  if (!ok || !locales[BASE_LANG] || !locales[LEGAL_LANG]) return null

  // `en` y `es-ES` son idiomas de redacción: no admiten traducción pendiente (ADR-074 §4.1).
  for (const lang of new Set([BASE_LANG, LEGAL_LANG])) {
    if (PENDING_KEY in locales[lang]) {
      report.error(
        `locales/${lang}.json: "${PENDING_KEY}" solo se permite en los otros idiomas`,
      )
    }
  }
  for (const lang of codes.filter((l) => l !== BASE_LANG && l !== LEGAL_LANG)) {
    if (locales[lang][PENDING_KEY] === true) {
      const msg = `locales/${lang}.json: traducción pendiente (${PENDING_KEY})`
      publish ? report.error(msg) : report.warn(msg)
    } else if (PENDING_KEY in locales[lang]) {
      report.error(`locales/${lang}.json: "${PENDING_KEY}" solo puede valer true (o no existir)`)
    }
  }

  const base = new Set(keysOf(locales[BASE_LANG]))
  for (const lang of codes.filter((l) => l !== BASE_LANG)) {
    const keys = new Set(keysOf(locales[lang]))
    for (const k of base)
      if (!keys.has(k)) report.error(`locales/${lang}.json: falta la clave "${k}"`)
    for (const k of keys)
      if (!base.has(k)) report.error(`locales/${lang}.json: sobra la clave "${k}"`)
  }

  // El nombre propio de cada idioma, en todas las locales y con el mismo valor (ADR-074 §4.2).
  for (const code of codes) {
    const key = langNameKey(code)
    if (!(key in locales[BASE_LANG])) {
      report.error(`locales/${BASE_LANG}.json: falta la clave "${key}"`)
      continue
    }
    for (const lang of codes) {
      // La ausencia en los demás idiomas ya la cubre la paridad contra BASE_LANG.
      if (key in locales[lang] && locales[lang][key] !== locales[BASE_LANG][key]) {
        report.error(`locales/${lang}.json: "${key}" debe valer lo mismo que en ${BASE_LANG}`)
      }
    }
  }

  const pendingData = []
  for (const lang of codes) {
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
      } else if (lang === LEGAL_LANG && HAS_PENDING_DATA.test(v)) {
        pendingData.push(k)
      }
    }
  }

  if (pendingData.length > 0) {
    report.warn(
      `locales/${LEGAL_LANG}.json: ${pendingData.length} clave(s) con marcador pendiente (bloquean --publish)`,
    )
  }

  for (const k of base) {
    const ref = JSON.stringify((String(locales[BASE_LANG][k]).match(INNER_MARKER_RE) ?? []).sort())
    for (const lang of codes.filter((l) => l !== BASE_LANG)) {
      const v = locales[lang][k]
      if (typeof v !== 'string') continue
      const mine = JSON.stringify((v.match(INNER_MARKER_RE) ?? []).sort())
      if (mine !== ref)
        report.error(`locales/${lang}.json: los marcadores de "${k}" difieren de ${BASE_LANG}`)
    }
  }
  return locales
}

export function checkTemplate(name, html, locales, publish, report, langs = LANGS) {
  const tokens = tokenize(html)
  checkCarousels(name, tokens, report)
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
    for (const { code: lang } of langs) {
      if (locales && locales[lang] && !(m[1] in locales[lang])) {
        report.error(`${name}: la clave "${m[1]}" no existe en ${lang}`)
      }
    }
  }
  if (publish && HAS_PENDING_DATA.test(html))
    report.error(`${name}: marcador pendiente en la plantilla`)
}

function checkSource(root, locales, publish, report, langs) {
  const srcDir = join(root, 'src')
  if (!existsSync(srcDir)) {
    report.error('src/: no existe')
    return
  }
  const cssFile = join(srcDir, 'styles.css')
  if (existsSync(cssFile)) {
    const css = readFileSync(cssFile, 'utf8')
    checkReducedMotion(css, 'src/styles.css', report)
    checkScrollEffects(css, 'src/styles.css', report)
  }
  else report.error('src/styles.css: no existe')
  const used = new Set()
  for (const file of walk(srcDir).filter((f) => f.endsWith('.html'))) {
    const html = readFileSync(file, 'utf8')
    checkTemplate(relative(root, file), html, locales, publish, report, langs)
    for (const m of html.matchAll(MARKER_RE)) used.add(m[1])
  }
  if (locales) {
    // Las claves lang.name.* (selector) e img.*.alt (imágenes del manifiesto) se usan sin marcador.
    const implicit = (k) => k.startsWith('lang.name.') || /^img\..+\.alt$/.test(k)
    for (const k of keysOf(locales[BASE_LANG])) {
      if (!used.has(k) && !implicit(k)) report.warn(`locales: la clave "${k}" no se usa en ninguna plantilla`)
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

// El primer segmento de la URL es el slug, no el código (ADR-074 §4.1).
function expectedLang(url, langs) {
  const first = url.split('/')[1]
  return langs.find((l) => l.slug !== '' && l.slug === first)?.code ?? DEFAULT_LANG
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

function checkDist(root, publish, report, langs, locales) {
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
  const manifest = loadManifest(root, report)
  const manifestIndex = indexManifest(manifest)

  for (const file of htmlFiles) {
    const rel = relative(distDir, file)
    const html = readFileSync(file, 'utf8')
    const tokens = tokenize(html)
    const alternates = {}
    let canonical = null
    let lang = null
    let h1 = 0
    const imgs = []
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
      if (t.name === 'img') imgs.push(t.attrs)
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
    checkPageImages(rel, imgs, manifestIndex, report)
    checkCarousels(`dist/${rel}`, tokens, report)
    if (!lang) report.error(`dist/${rel}: <html> sin lang`)

    const url = pageUrlOf(distDir, file)
    if (url) {
      info.set(url, { rel, tokens, alternates, canonical, lang })
      if (langs.some((l) => pagePath(l.code, 'legal', langs) === url)) {
        checkLegalText(rel, html, locales, report)
      }
      const want = expectedLang(url, langs)
      if (lang && lang !== want) report.error(`dist/${rel}: lang="${lang}", se esperaba "${want}"`)
      if (canonical !== ORIGIN + url)
        report.error(`dist/${rel}: canonical debe ser ${ORIGIN + url}`)
      for (const { code: l } of langs) {
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
      if (target.alternates[expectedLang(url, langs)] !== ORIGIN + url) {
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
      if ((t.name === 'img' || t.name === 'source') && 'srcset' in t.attrs) {
        checkSrcset(rel, pageUrl, t, distDir, report)
      }
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

  // CSS: sin @import, sin url() externa ni data: (la CSP la bloquea) y toda url() local existe.
  for (const file of files.filter((f) => f.endsWith('.css'))) {
    const css = readFileSync(file, 'utf8')
    const name = `dist/${relative(distDir, file)}`
    if (/@import/i.test(css)) report.error(`${name}: @import no permitido`)
    for (const m of css.matchAll(/url\(\s*['"]?([^'")]+)/gi)) {
      const ref = m[1].trim()
      if (/^data:/i.test(ref)) {
        report.error(`${name}: url() con data: no permitida (CSP)`)
      } else if (isScheme(ref)) {
        report.error(`${name}: url() externa ${ref}`)
      } else if (!ref.startsWith('#')) {
        const target = resolveLocal(distDir, '/' + relative(distDir, file).split(sep).join('/'), ref)
        if (!target || !existsSync(target) || !statSync(target).isFile()) {
          report.error(`${name}: url() a un fichero inexistente ${ref}`)
        }
      }
    }
    if (name === 'dist/styles.css') {
      checkReducedMotion(css, name, report)
      checkScrollEffects(css, name, report)
    }
  }
  checkImageFiles(root, distDir, files, locales, manifest, report)

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

// ---------- Imágenes (ADR-074 §4.3 y §4.6) ----------

// Resuelve una URL local (relativa a la página o absoluta del sitio) a un fichero de dist/.
function resolveLocal(distDir, pageUrl, value) {
  let url
  try {
    url = new URL(value, ORIGIN + pageUrl)
  } catch {
    return null
  }
  if (url.origin !== ORIGIN) return null
  let path = decodeURIComponent(url.pathname)
  if (path.endsWith('/')) path += 'index.html'
  return join(distDir, path)
}

// Cada URL de un srcset (de <img> y de <source>) es del mismo origen y existe en dist/.
function checkSrcset(rel, pageUrl, tag, distDir, report) {
  const candidates = tag.attrs.srcset
    .split(',')
    .map((c) => c.trim().split(/\s+/)[0])
    .filter((u) => u !== '')
  if (candidates.length === 0) report.error(`dist/${rel}: <${tag.name}> con srcset vacío`)
  for (const value of candidates) {
    if (isScheme(value)) continue // ya notificado como recurso externo
    const target = resolveLocal(distDir, pageUrl, value)
    if (!target || !existsSync(target) || !statSync(target).isFile()) {
      report.error(`dist/${rel}: srcset de <${tag.name}> apunta a un fichero inexistente "${value}"`)
    }
  }
}

function loadManifest(root, report) {
  const file = manifestFile(root)
  if (!existsSync(file)) return null
  let manifest
  try {
    manifest = JSON.parse(readFileSync(file, 'utf8'))
  } catch {
    report.error('src/img/manifest.json: no es JSON válido')
    return null
  }
  const images = manifest?.images
  if (images === null || typeof images !== 'object' || Array.isArray(images)) {
    report.error('src/img/manifest.json: debe tener un objeto "images"')
    return null
  }
  return manifest
}

// file -> { width, height, bytes } de todas las variantes del manifiesto.
function indexManifest(manifest) {
  const index = new Map()
  for (const entry of Object.values(manifest?.images ?? {})) {
    for (const v of Array.isArray(entry?.variants) ? entry.variants : []) {
      if (typeof v?.file === 'string') index.set(v.file, v)
    }
  }
  return index
}

// Reglas 1 y 2 de ADR-074 §4.6, por página.
function checkPageImages(rel, imgs, manifestIndex, report) {
  let eager = 0
  for (const a of imgs) {
    const src = a.src ?? ''
    if (!('alt' in a)) report.error(`dist/${rel}: <img src="${src}"> sin alt`)
    for (const dim of ['width', 'height']) {
      if (!/^[1-9][0-9]*$/.test(a[dim] ?? '')) {
        report.error(`dist/${rel}: <img src="${src}"> sin ${dim} válido`)
      }
    }
    if (src.startsWith(IMG_URL_PREFIX)) {
      const variant = manifestIndex.get(src.slice(IMG_URL_PREFIX.length))
      if (!variant) {
        report.error(`dist/${rel}: <img src="${src}"> no está en src/img/manifest.json`)
      } else if (String(variant.width) !== a.width || String(variant.height) !== a.height) {
        report.error(
          `dist/${rel}: <img src="${src}"> width/height (${a.width}x${a.height}) no coinciden con el manifiesto (${variant.width}x${variant.height})`,
        )
      }
    }
    if (a.loading !== 'lazy') {
      eager++
      if (a.fetchpriority !== 'high') {
        report.error(`dist/${rel}: <img src="${src}"> sin loading="lazy" debe llevar fetchpriority="high"`)
      }
    }
  }
  if (eager > 1) {
    report.error(`dist/${rel}: ${eager} imágenes sin loading="lazy" (como mucho una, la LCP)`)
  }
}

// Regla 5: topes de peso y coherencia de bytes con el manifiesto; el texto alternativo sale de locales.
function checkImageFiles(root, distDir, distFiles, locales, manifest, report) {
  const srcImg = join(root, 'src', 'img')
  const present = existsSync(srcImg) ? walk(srcImg) : []
  const assets = present.filter((f) => basename(f) !== 'manifest.json')
  if (present.length > 0 && !manifest) {
    if (!existsSync(manifestFile(root))) report.error('src/img/: falta manifest.json')
    return
  }
  let total = 0
  for (const f of assets) {
    const name = relative(srcImg, f).split(sep).join('/')
    const bytes = statSync(f).size
    total += bytes
    if (bytes > MAX_IMG_FILE_BYTES) {
      report.error(`src/img/${name}: ${bytes} bytes superan el tope de ${MAX_IMG_FILE_BYTES}`)
    }
    if (/\.(png|jpe?g|gif)$/i.test(name)) {
      report.error(`src/img/${name}: solo AVIF, WebP o SVG (los PNG maestros no entran en Git)`)
    }
  }
  if (total > MAX_IMG_TOTAL_BYTES) {
    report.error(`src/img/: ${total} bytes superan el tope total de ${MAX_IMG_TOTAL_BYTES}`)
  }
  for (const f of distFiles) {
    if (/\.(png|jpe?g|gif)$/i.test(f) && relative(distDir, f).split(sep)[0] === 'img') {
      report.error(`dist/${relative(distDir, f)}: no se publican PNG ni JPEG (ADR-074 §4.3)`)
    }
  }
  if (!manifest) return

  const ids = Object.keys(manifest.images)
  if (ids.length > MAX_IMAGES) {
    report.error(`src/img/manifest.json: ${ids.length} imágenes (máximo ${MAX_IMAGES})`)
  }
  const listed = new Set()
  for (const id of ids) {
    const entry = manifest.images[id]
    if (!/^[a-z0-9][a-z0-9-]*$/.test(id)) report.error(`src/img/manifest.json: id no válido "${id}"`)
    if (!Array.isArray(entry?.variants) || entry.variants.length === 0) {
      report.error(`src/img/manifest.json: "${id}" sin variantes`)
      continue
    }
    if (locales) {
      for (const lang of Object.keys(locales)) {
        if (locales[lang] && !(imgAltKey(id) in locales[lang])) {
          report.error(`locales/${lang}.json: falta el texto alternativo "${imgAltKey(id)}"`)
        }
      }
    }
    for (const v of entry.variants) {
      listed.add(v.file)
      const file = join(srcImg, String(v.file))
      if (!existsSync(file)) {
        report.error(`src/img/manifest.json: ${v.file} no existe en src/img/`)
        continue
      }
      if (statSync(file).size !== v.bytes) {
        report.error(`src/img/manifest.json: bytes de ${v.file} (${v.bytes}) no coinciden con el fichero (${statSync(file).size})`)
      }
      if (!Number.isInteger(v.width) || !Number.isInteger(v.height) || v.width < 1 || v.height < 1) {
        report.error(`src/img/manifest.json: ${v.file} sin width/height enteros`)
      }
      if (!existsSync(join(distDir, 'img', String(v.file)))) {
        report.error(`dist/img/${v.file}: no existe (vuelva a ejecutar la construcción)`)
      }
    }
  }
  for (const f of assets) {
    const name = relative(srcImg, f).split(sep).join('/')
    if (/\.(avif|webp)$/i.test(name) && !listed.has(name)) {
      report.error(`src/img/${name}: no figura en manifest.json`)
    }
  }
}


// ---------- Carrusel y efectos de scroll (ADR-074 §4.5) ----------

// Carrusel sin JavaScript: contenedor enfocable con role="region" y nombre; sin aria-roledescription
// (promete el patrón completo de la APG, que sin JS no se cumple).
function checkCarousels(name, tokens, report) {
  for (const t of tokens) {
    if (t.type !== 'open') continue
    if ('aria-roledescription' in t.attrs) {
      report.error(`${name}: aria-roledescription no permitido (el carrusel no cumple el patrón completo de la APG)`)
    }
    if (!(t.attrs.class ?? '').split(/\s+/).includes('carousel')) continue
    if (t.attrs.tabindex !== '0') report.error(`${name}: el carrusel debe llevar tabindex="0"`)
    if (t.attrs.role !== 'region') report.error(`${name}: el carrusel debe llevar role="region"`)
    if (!('aria-label' in t.attrs) || t.attrs['aria-label'].trim() === '') {
      report.error(`${name}: el carrusel debe llevar aria-label`)
    }
  }
}

// Árbol mínimo de bloques CSS: { prelude, body (declaraciones propias), children }.
function parseCss(css) {
  const src = css.replace(/\/\*[\s\S]*?\*\//g, '')
  let i = 0
  function block() {
    const node = { prelude: '', body: '', children: [] }
    let buf = ''
    while (i < src.length) {
      const c = src[i++]
      if (c === '{') {
        const child = block()
        child.prelude = buf.trim()
        node.children.push(child)
        buf = ''
      } else if (c === '}') {
        node.body += buf
        return node
      } else buf += c
    }
    node.body += buf
    return node
  }
  return block()
}

const SCROLL_TIMELINE = /animation-timeline\s*:\s*(?!none\b)|animation\s*:[^;]*\b(?:view|scroll)\(/i
const ALLOWED_KEYFRAME_PROPS = new Set(['transform', 'translate', 'scale', 'rotate', 'animation-timing-function'])

function checkScrollEffects(css, name, report) {
  if (/::scroll-button|::scroll-marker/i.test(css)) {
    report.error(`${name}: ::scroll-button y ::scroll-marker no se usan en esta versión (ADR-074 §4.5)`)
  }
  const root = parseCss(css)
  const keyframes = new Map()
  const linked = []
  ;(function walkCss(node, ctx) {
    for (const c of node.children) {
      if (/^@keyframes\s/i.test(c.prelude)) {
        keyframes.set(c.prelude.replace(/^@keyframes\s+/i, '').trim(), c)
      } else if (c.prelude.startsWith('@')) {
        walkCss(c, [...ctx, c.prelude])
      } else if (SCROLL_TIMELINE.test(c.body)) {
        linked.push({ rule: c, ctx })
      }
    }
  })(root, [])
  for (const { rule, ctx } of linked) {
    if (!ctx.some((p) => /^@supports[^{]*animation-timeline/i.test(p))) {
      report.error(`${name}: "${rule.prelude}" usa animation-timeline fuera de @supports (animation-timeline: ...)`)
    }
    if (!ctx.some((p) => /^@media[^{]*prefers-reduced-motion\s*:\s*no-preference/i.test(p))) {
      report.error(`${name}: "${rule.prelude}" usa animation-timeline fuera de @media (prefers-reduced-motion: no-preference)`)
    }
    const names = [...rule.body.matchAll(/animation(?:-name)?\s*:\s*([^;]+)/gi)].flatMap((m) => m[1].split(/[\s,]+/))
    for (const n of names) {
      const kf = keyframes.get(n)
      if (!kf) continue
      const props = new Set()
      for (const step of kf.children) {
        for (const d of step.body.split(';')) {
          const prop = d.split(':')[0].trim().toLowerCase()
          if (prop) props.add(prop)
        }
      }
      for (const p of props) {
        if (!ALLOWED_KEYFRAME_PROPS.has(p)) {
          report.error(`${name}: @keyframes ${n} (ligada al scroll) anima "${p}": solo se anima transform (ADR-074 §4.5)`)
        }
      }
    }
  }
}

// Regla 7: bloque prefers-reduced-motion: reduce (ADR-074 §4.5).
function checkReducedMotion(css, name, report) {
  const block = css.match(/@media\s*\(\s*prefers-reduced-motion\s*:\s*reduce\s*\)\s*\{([\s\S]*)/i)
  if (!block) return report.error(`${name}: falta el bloque @media (prefers-reduced-motion: reduce)`)
  if (!/animation\s*:\s*none\s*!important/i.test(block[1])) {
    report.error(`${name}: prefers-reduced-motion: reduce sin "animation: none !important"`)
  }
  if (!/scroll-behavior\s*:\s*auto/i.test(block[1])) {
    report.error(`${name}: prefers-reduced-motion: reduce sin "scroll-behavior: auto"`)
  }
}

// El texto legal sale siempre de LEGAL_LANG, en un bloque con su lang (ADR-074 §4.1).
function checkLegalText(rel, html, locales, report) {
  if (!locales?.[LEGAL_LANG]) return
  if (!html.includes(`<div lang="${LEGAL_LANG}">`)) {
    report.error(`dist/${rel}: el texto legal debe ir en un bloque lang="${LEGAL_LANG}"`)
  }
  for (const [key, value] of Object.entries(locales[LEGAL_LANG])) {
    if (key.startsWith(LEGAL_PREFIX) && typeof value === 'string' && !html.includes(escapeHtml(value))) {
      report.error(`dist/${rel}: "${key}" no es el texto de ${LEGAL_LANG} (el texto legal solo sale de ${LEGAL_LANG})`)
    }
  }
}

// ---------- API ----------

export function runChecks({ root, publish = false, skipDist = false, langs = LANGS }) {
  const errors = []
  const warnings = []
  const report = { error: (m) => errors.push(m), warn: (m) => warnings.push(m) }
  checkLangs(langs, report)
  checkLighthouse(root, langs, report)
  const locales = checkLocales(root, publish, report, langs)
  checkSource(root, locales, publish, report, langs)
  if (!skipDist) checkDist(root, publish, report, langs, locales)
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
