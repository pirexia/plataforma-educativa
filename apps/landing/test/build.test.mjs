// ADR-072 §4.1, §4.2, §4.3, §4.4: la construcción genera el sitio esperado.
import { test, after } from 'node:test'
import assert from 'node:assert/strict'
import { existsSync, readFileSync, writeFileSync, mkdtempSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { makeSite, cleanup, editJson, edit, PROJECT } from './helpers.mjs'
import { build } from '../scripts/build.mjs'
import { SECURITY_HEADERS } from '../scripts/headers.mjs'

after(cleanup)

const read = (root, rel) => readFileSync(join(root, 'dist', rel), 'utf8')

test('genera una portada y una página legal por idioma, y los ficheros del sitio', () => {
  const root = makeSite()
  for (const rel of [
    'index.html',
    'en/index.html',
    'de/index.html',
    'fr/index.html',
    'legal/index.html',
    'en/legal/index.html',
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
  const en = read(root, 'en/index.html')
  assert.match(en, /<html lang="en">/)
  assert.match(en, /<link rel="canonical" href="https:\/\/adagiox\.com\/en\/">/)
  for (const [hl, href] of [
    ['es-ES', 'https://adagiox.com/'],
    ['en', 'https://adagiox.com/en/'],
    ['de', 'https://adagiox.com/de/'],
    ['fr', 'https://adagiox.com/fr/'],
    ['x-default', 'https://adagiox.com/'],
  ]) {
    assert.ok(en.includes(`hreflang="${hl}" href="${href}"`), `falta hreflang ${hl}`)
  }
  assert.match(read(root, 'index.html'), /<html lang="es-ES">/)
})

test('el selector de idioma son enlaces con lang y hreflang, sin JavaScript', () => {
  const root = makeSite()
  const html = read(root, 'de/index.html')
  assert.match(html, /<a href="\/fr\/" lang="fr" hreflang="fr">Français<\/a>/)
  assert.match(html, /<a href="\/de\/" lang="de" hreflang="de" aria-current="page">Deutsch<\/a>/)
  assert.doesNotMatch(html, /<script/i)
})

test('las páginas legales de en/de/fr usan el texto es-ES con lang="es-ES" (OPEN-072-05)', () => {
  const root = makeSite()
  editJson(root, 'locales/en.json', (d) => {
    d['legal.body.id.title'] = 'TRADUCIDO AUTOMÁTICAMENTE'
  })
  build({ root })
  const html = read(root, 'en/legal/index.html')
  assert.match(html, /<html lang="en">/)
  assert.match(html, /<div lang="es-ES">/)
  assert.ok(html.includes('Identificación del titular'))
  assert.ok(!html.includes('TRADUCIDO AUTOMÁTICAMENTE'))
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

test('_headers lleva la CSP sin script-src y todas las cabeceras del ADR', () => {
  const root = makeSite()
  const h = read(root, '_headers')
  for (const [name, value] of Object.entries(SECURITY_HEADERS)) {
    assert.ok(h.includes(`  ${name}: ${value}\n`), `falta ${name}`)
  }
  assert.ok(!h.includes('script-src'))
  assert.ok(!/includeSubDomains|preload/.test(h))
})

test('_redirects redirige www y los otros dominios con 301 al canónico', () => {
  const root = makeSite()
  const r = read(root, '_redirects')
  assert.match(r, /^https:\/\/www\.adagiox\.com\/\* https:\/\/adagiox\.com\/:splat 301!$/m)
  assert.match(r, /^https:\/\/adagiox\.net\/\* https:\/\/adagiox\.com\/:splat 301!$/m)
})

test('los valores de idioma se escapan al generar el HTML', () => {
  const root = makeSite({ built: false })
  editJson(root, 'locales/es-ES.json', (d) => {
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
  assert.throws(() => build({ root }), /Clave de idioma inexistente/)
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
