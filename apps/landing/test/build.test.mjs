// ADR-072 §4.1, §4.2, §4.3, §4.4: la construcción genera el sitio esperado.
import { test, after } from 'node:test'
import assert from 'node:assert/strict'
import { existsSync, readFileSync, writeFileSync, mkdtempSync, readdirSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { makeSite, cleanup, editJson, edit, addImage, placeImage, PROJECT } from './helpers.mjs'
import { build } from '../scripts/build.mjs'
import { LANGS, langPath } from '../scripts/lib.mjs'
import { SECURITY_HEADERS } from '../scripts/headers.mjs'

after(cleanup)

const read = (root, rel) => readFileSync(join(root, 'dist', rel), 'utf8')

test('genera una portada y una página legal por idioma, y los ficheros del sitio', () => {
  const root = makeSite()
  for (const rel of [
    'index.html',
    'es/index.html',
    'de/index.html',
    'fr/index.html',
    'legal/index.html',
    'es/legal/index.html',
    'de/legal/index.html',
    'fr/legal/index.html',
    '404.html',
    'styles.css',
    'sitemap.xml',
    'robots.txt',
    '_headers',
    '_redirects',
  ]) {
    assert.ok(existsSync(join(root, 'dist', rel)), `falta dist/${rel}`)
  }
})

test('cada página lleva su lang, canonical a sí misma y los cinco hreflang', () => {
  const root = makeSite()
  const es = read(root, 'es/index.html')
  assert.match(es, /<html lang="es-ES" dir="ltr">/)
  assert.match(es, /<link rel="canonical" href="https:\/\/adagiox\.com\/es\/">/)
  for (const [hl, href] of [
    ['es-ES', 'https://adagiox.com/es/'],
    ['en', 'https://adagiox.com/'],
    ['de', 'https://adagiox.com/de/'],
    ['fr', 'https://adagiox.com/fr/'],
    ['x-default', 'https://adagiox.com/'],
  ]) {
    assert.ok(es.includes(`hreflang="${hl}" href="${href}"`), `falta hreflang ${hl}`)
  }
  assert.match(read(root, 'index.html'), /<html lang="en" dir="ltr">/)
})

test('el selector de idioma son enlaces con lang y hreflang, sin JavaScript', () => {
  const root = makeSite()
  const html = read(root, 'de/index.html')
  assert.match(html, /<a href="\/fr\/" lang="fr" hreflang="fr">Français<\/a>/)
  assert.match(html, /<a href="\/" lang="en" hreflang="en">English<\/a>/)
  assert.match(html, /<a href="\/de\/" lang="de" hreflang="de" aria-current="page">Deutsch<\/a>/)
  assert.doesNotMatch(html, /<script/i)
})

test('el texto legal sale siempre de LEGAL_LANG (es-ES) con lang="es-ES" en las cuatro rutas (OPEN-072-05, ADR-074 §4.1)', () => {
  const root = makeSite({ built: false })
  for (const l of ['en', 'de', 'fr']) {
    editJson(root, `locales/${l}.json`, (d) => {
      d['legal.body.id.title'] = 'TRADUCIDO AUTOMÁTICAMENTE'
    })
  }
  build({ root })
  for (const [rel, lang] of [
    ['legal/index.html', 'en'],
    ['es/legal/index.html', 'es-ES'],
    ['de/legal/index.html', 'de'],
    ['fr/legal/index.html', 'fr'],
  ]) {
    const html = read(root, rel)
    assert.ok(html.includes(`<html lang="${lang}" dir="ltr">`), rel)
    assert.match(html, /<div lang="es-ES">/)
    assert.ok(html.includes('Identificación del titular'), rel)
    assert.ok(!html.includes('TRADUCIDO AUTOMÁTICAMENTE'), rel)
  }
})

test('las rutas salen del slug, no del código: / es inglés y /es/ es es-ES (ADR-074 §4.1)', () => {
  const root = makeSite()
  assert.match(read(root, 'index.html'), /<html lang="en" dir="ltr">/)
  assert.match(read(root, 'es/index.html'), /<html lang="es-ES" dir="ltr">/)
  assert.ok(!existsSync(join(root, 'dist', 'en')), 'no hay /en/')
  assert.ok(!existsSync(join(root, 'dist', 'es-ES')), 'no hay /es-ES/')
})

test('un idioma añadido a LANGS aparece en el selector, en hreflang y en el sitemap sin tocar plantillas (ADR-074 §4.2)', () => {
  const root = makeSite({ built: false })
  const langs = [...LANGS, { code: 'it', slug: 'it', dir: 'ltr' }]
  const en = JSON.parse(readFileSync(join(root, 'locales', 'en.json'), 'utf8'))
  for (const l of ['en', 'es-ES', 'de', 'fr']) {
    editJson(root, `locales/${l}.json`, (d) => (d['lang.name.it'] = 'Italiano'))
  }
  writeFileSync(
    join(root, 'locales', 'it.json'),
    JSON.stringify({ ...en, 'lang.name.it': 'Italiano', _pending_translation: true }),
  )
  build({ root, langs })
  const html = read(root, 'fr/index.html')
  assert.ok(html.includes('<a href="/it/" lang="it" hreflang="it">Italiano</a>'))
  assert.ok(html.includes('hreflang="it" href="https://adagiox.com/it/"'))
  assert.ok(read(root, 'it/legal/index.html').includes('<html lang="it" dir="ltr">'))
  assert.ok(read(root, 'sitemap.xml').includes('<loc>https://adagiox.com/it/legal/</loc>'))
  assert.ok(read(root, '404.html').includes('<a href="/it/" lang="it" hreflang="it">Italiano</a>'))
})

test('las plantillas no nombran idiomas: selector y alternates salen de LANGS', () => {
  for (const f of ['src/partials/header.html', 'src/partials/head-links.html', 'src/404.html']) {
    const html = readFileSync(join(PROJECT, f), 'utf8')
    assert.doesNotMatch(html, /hreflang="[a-zA-Z-]+"|lang="[a-zA-Z-]+"/, f)
    for (const l of LANGS) assert.ok(!html.includes(`.${l.code}}}`), `${f} nombra ${l.code}`)
  }
})

test('la 404 está en inglés, no marca idioma actual y enlaza las cuatro portadas (ADR-074 §4.1)', () => {
  const root = makeSite()
  const html = read(root, '404.html')
  assert.match(html, /<html lang="en" dir="ltr">/)
  assert.ok(html.includes('Page not found'))
  for (const l of LANGS) {
    assert.ok(html.includes(`<a href="${langPath(l.code)}" lang="${l.code}" hreflang="${l.code}">`), l.code)
  }
  assert.doesNotMatch(html, /aria-current/)
})

test('x-default apunta a / en todas las páginas y en el sitemap', () => {
  const root = makeSite()
  assert.ok(read(root, 'de/index.html').includes('hreflang="x-default" href="https://adagiox.com/"'))
  assert.ok(read(root, 'fr/legal/index.html').includes('hreflang="x-default" href="https://adagiox.com/legal/"'))
})

test('sitemap.xml lista las ocho URLs con sus alternativas', () => {
  const root = makeSite()
  const sm = read(root, 'sitemap.xml')
  assert.equal([...sm.matchAll(/<loc>/g)].length, 8)
  assert.ok(sm.includes('<loc>https://adagiox.com/fr/legal/</loc>'))
  assert.ok(sm.includes('hreflang="x-default" href="https://adagiox.com/legal/"'))
})

test('robots.txt apunta al sitemap canónico', () => {
  const root = makeSite()
  assert.match(read(root, 'robots.txt'), /Sitemap: https:\/\/adagiox\.com\/sitemap\.xml/)
})

test('_headers lleva la CSP con script-src \'self\' (ADR-075) y todas las cabeceras del ADR', () => {
  const root = makeSite()
  const h = read(root, '_headers')
  for (const [name, value] of Object.entries(SECURITY_HEADERS)) {
    assert.ok(h.includes(`  ${name}: ${value}\n`), `falta ${name}`)
  }
  assert.ok(h.includes("script-src 'self';"))
  assert.ok(!/unsafe-inline|unsafe-eval/.test(h))
  assert.ok(!/includeSubDomains|preload/.test(h))
})

test('_redirects no lleva reglas: Workers no admite nombres de host (#663)', () => {
  const root = makeSite()
  const r = read(root, '_redirects')
  const reglas = r.split('\n').filter((l) => l.trim() !== '' && !l.trim().startsWith('#'))
  assert.deepEqual(reglas, [])
})

test('los valores de idioma se escapan al generar el HTML', () => {
  const root = makeSite({ built: false })
  editJson(root, 'locales/en.json', (d) => {
    d['home.hero.title'] = 'A & B "citado"'
  })
  build({ root })
  assert.ok(read(root, 'index.html').includes('A &amp; B &quot;citado&quot;'))
})

test('una clave inexistente en una plantilla hace fallar la construcción', () => {
  const root = makeSite({ built: false })
  edit(root, 'src/home.html', (h) => h.replace('{{t:home.hero.title}}', '{{t:no.existe}}'))
  assert.throws(() => build({ root }), /no\.existe/)
})

test('una variable inexistente hace fallar la construcción', () => {
  const root = makeSite({ built: false })
  edit(root, 'src/home.html', (h) => h.replace('{{v:lang}}', '{{v:nope}}'))
  assert.throws(() => build({ root }), /nope/)
})

test('falta un fichero de idioma: la construcción falla', () => {
  const root = makeSite({ built: false })
  writeFileSync(join(root, 'locales', 'fr.json'), '{}')
  assert.throws(() => build({ root }), /Falta el nombre del idioma/)
})

test('--root inexistente o que no es un directorio: falla sin tocar nada', () => {
  const missing = join(tmpdir(), 'landing-no-existe-xyz')
  assert.throws(() => build({ root: missing }), /--root no existe/)
  assert.ok(!existsSync(missing))
  const dir = mkdtempSync(join(tmpdir(), 'landing-file-'))
  const file = join(dir, 'f.txt')
  writeFileSync(file, 'x')
  assert.throws(() => build({ root: file }), /no es un directorio/)
  assert.equal(readFileSync(file, 'utf8'), 'x')
  assert.throws(() => build({ root: undefined }), /--root/)
})

test('CLI: --root inexistente sale con código 1', () => {
  const script = join(PROJECT, 'scripts', 'build.mjs')
  assert.throws(
    () => execFileSync('node', [script, '--root', '/tmp/landing-no-existe-xyz'], { stdio: 'pipe' }),
    (e) => e.status === 1 && /--root no existe/.test(String(e.stderr)),
  )
})

// ---- Imágenes (ADR-074 §4.3) ----

test('src/img/ se copia a dist/img/ sin el manifiesto; el manifiesto no se publica', () => {
  const root = makeSite({ built: false })
  addImage(root)
  build({ root })
  assert.ok(existsSync(join(root, 'dist', 'img', 'shot-a-640.avif')))
  assert.ok(existsSync(join(root, 'dist', 'img', 'shot-a-1600.webp')))
  assert.ok(!existsSync(join(root, 'dist', 'img', 'manifest.json')))
})

test('{{img:id}} emite <picture> con AVIF, WebP, width/height del manifiesto, alt de la locale y lazy', () => {
  const root = makeSite({ built: false })
  addImage(root)
  placeImage(root, '{{img:shot-a}}')
  build({ root })
  const html = read(root, 'es/index.html')
  assert.ok(html.includes('<source type="image/avif" srcset="/img/shot-a-640.avif 640w, /img/shot-a-1024.avif 1024w, /img/shot-a-1600.avif 1600w"'))
  assert.ok(html.includes('<img src="/img/shot-a-1024.webp" srcset="/img/shot-a-640.webp 640w, /img/shot-a-1024.webp 1024w, /img/shot-a-1600.webp 1600w"'))
  assert.ok(html.includes('width="1024" height="640" alt="Alt shot-a es-ES" loading="lazy" decoding="async"'))
  assert.ok(read(root, 'de/index.html').includes('alt="Alt shot-a de"'))
  assert.doesNotMatch(html, /fetchpriority/)
})

test('{{img:id:lcp}}: sin loading="lazy" y con fetchpriority="high"', () => {
  const root = makeSite({ built: false })
  addImage(root)
  placeImage(root, '{{img:shot-a:lcp}}')
  build({ root })
  const html = read(root, 'index.html')
  assert.ok(html.includes('fetchpriority="high" decoding="async"'))
  assert.doesNotMatch(html, /loading="lazy"/)
})

test('el alt de una imagen se escapa', () => {
  const root = makeSite({ built: false })
  addImage(root)
  editJson(root, 'locales/en.json', (d) => (d['img.shot-a.alt'] = 'A "b" & c'))
  placeImage(root, '{{img:shot-a}}')
  build({ root })
  assert.ok(read(root, 'index.html').includes('alt="A &quot;b&quot; &amp; c"'))
})

test('imagen inexistente en el manifiesto o sin alt en la locale: la construcción falla', () => {
  const root = makeSite({ built: false })
  addImage(root)
  placeImage(root, '{{img:no-existe}}')
  assert.throws(() => build({ root }), /no-existe/)
  const root2 = makeSite({ built: false })
  addImage(root2)
  editJson(root2, 'locales/fr.json', (d) => delete d['img.shot-a.alt'])
  placeImage(root2, '{{img:shot-a}}')
  assert.throws(() => build({ root: root2 }), /img\.shot-a\.alt/)
})

// ---- Portada (ADR-074 §4.5, §4.6) ----

test('la portada real: carrusel de 8 diapositivas con figcaption, una sola imagen sin lazy (la primera, con fetchpriority)', () => {
  const root = makeSite({ showcase: true })
  for (const page of ['index.html', 'es/index.html', 'de/index.html', 'fr/index.html']) {
    const html = read(root, page)
    const carousel = html.match(/<div class="carousel"[^>]*>([\s\S]*?)<\/ul>/)
    assert.ok(carousel, page)
    assert.match(carousel[0], /role="region"/)
    assert.match(carousel[0], /tabindex="0"/)
    assert.doesNotMatch(html, /aria-roledescription|autoplay/)
    assert.equal((carousel[1].match(/<li class="slide">/g) ?? []).length, 8)
    assert.equal((carousel[1].match(/<figure>/g) ?? []).length, 8)
    assert.equal((carousel[1].match(/<figcaption>/g) ?? []).length, 8)
    const eager = [...html.matchAll(/<img [^>]*>/g)].filter((m) => !/loading="lazy"/.test(m[0]))
    assert.equal(eager.length, 1, page)
    assert.match(eager[0][0], /fetchpriority="high"/)
    assert.ok(carousel[1].indexOf(eager[0][0]) > -1, 'la imagen LCP es la del carrusel')
    // ADR-075 §2.5: la pantalla de acceso va primera; el resto, en el orden de ADR-074.
    const order = ['sign-in', 'hero-schedule', 'schedule-conflict', 'role-permissions', 'audit-log', 'family-unit', 'pickup-door', 'school-branding']
    const pos = order.map((id) => carousel[1].indexOf(`/img/${id}-`))
    assert.ok(pos.every((p) => p > -1), page)
    assert.deepEqual([...pos].sort((a, b) => a - b), pos, `orden de las diapositivas en ${page}`)
    assert.ok(carousel[1].indexOf(eager[0][0]) < pos[1], 'la imagen sin lazy es la de sign-in')
  }
})

test('la portada real mantiene canonical y hreflang, y el estado "en desarrollo" está en el HTML', () => {
  const root = makeSite({ showcase: true })
  const html = read(root, 'index.html')
  assert.ok(html.includes('<link rel="canonical" href="https://adagiox.com/">'))
  for (const hl of ['en', 'es-ES', 'de', 'fr', 'x-default']) assert.ok(html.includes(`hreflang="${hl}"`), hl)
  assert.match(html, /class="badge"/)
  assert.ok(html.includes('href="mailto:founders@adagiox.com"'))
})

test('ADR-075: la portada de las cuatro lenguas lleva el único script propio con defer y ninguno en línea; la 404 y el aviso legal, ninguno', () => {
  const root = makeSite({ showcase: true })
  for (const page of ['index.html', 'es/index.html', 'de/index.html', 'fr/index.html']) {
    const html = read(root, page)
    const scripts = [...html.matchAll(/<script\b[^>]*>/g)].map((m) => m[0])
    assert.deepEqual(scripts, ['<script src="/js/carousel.js" defer>'], page)
    assert.doesNotMatch(html, /<script(?![^>]*\bsrc=)/i, page)
    for (const k of ['prev', 'next', 'goto', 'pause', 'resume']) {
      assert.match(html, new RegExp(`data-${k}="[^"]+"`), `${page} data-${k}`)
    }
  }
  assert.doesNotMatch(read(root, 'legal/index.html'), /<script/i)
  assert.doesNotMatch(read(root, '404.html'), /<script/i)
  assert.deepEqual(readdirSync(join(root, 'dist', 'js')), ['carousel.js'])
})

test('ADR-075: dist/js/carousel.js es copia exacta de la fuente y cabe en 6 KiB', () => {
  const root = makeSite({ showcase: true })
  const built = readFileSync(join(root, 'dist', 'js', 'carousel.js'))
  assert.ok(built.equals(readFileSync(join(PROJECT, 'src', 'js', 'carousel.js'))))
  assert.ok(built.length <= 6 * 1024, `${built.length} bytes`)
})
