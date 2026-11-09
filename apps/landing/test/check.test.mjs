// ADR-072 §4.2 y §4.5: cada comprobación falla con un caso malo y pasa con uno bueno.
import { test, after } from 'node:test'
import assert from 'node:assert/strict'
import { rmSync, writeFileSync, mkdirSync } from 'node:fs'
import { join } from 'node:path'
import { makeSite, cleanup, edit, editJson, fixture } from './helpers.mjs'
import { build } from '../scripts/build.mjs'
import { checkTemplate, runChecks } from '../scripts/check.mjs'
import { readLocales } from '../scripts/lib.mjs'
import { PROJECT } from './helpers.mjs'

after(cleanup)

const has = (res, re) => res.errors.some((e) => re.test(e))
const run = (root, opts = {}) => runChecks({ root, ...opts })

function tpl(name, publish = false) {
  const errors = []
  const warnings = []
  checkTemplate(name, fixture(name), readLocales(PROJECT), publish, {
    error: (m) => errors.push(m),
    warn: (m) => warnings.push(m),
  })
  return errors
}

// ---- Línea base ----

test('el sitio del proyecto, construido, pasa todas las comprobaciones (sin --publish)', () => {
  const res = run(makeSite())
  assert.deepEqual(res.errors, [])
})

// ---- INV-009 en plantillas (ADR-072 §4.2, puntos 1 y 2) ----

test('plantilla buena: sin errores', () => {
  assert.deepEqual(tpl('template-good.html'), [])
})
test('texto visible fuera de un marcador: error', () => {
  assert.ok(tpl('template-bad-text.html').some((e) => /literal visible/.test(e)))
})
test('alt sin marcador: error', () => {
  assert.ok(tpl('template-bad-alt.html').some((e) => /"alt"/.test(e)))
})
test('aria-label sin marcador: error', () => {
  assert.ok(tpl('template-bad-aria.html').some((e) => /"aria-label"/.test(e)))
})
test('meta description sin marcador: error', () => {
  assert.ok(tpl('template-bad-meta.html').some((e) => /description/.test(e)))
})
test('<script> en una plantilla: error', () => {
  assert.ok(tpl('template-bad-script.html').some((e) => /<script>/.test(e)))
})
test('clave de plantilla inexistente en los idiomas: error', () => {
  assert.ok(tpl('template-bad-key.html').some((e) => /no\.existe\.esta\.clave/.test(e)))
})
test('un literal real en la plantilla del proyecto hace fallar check', () => {
  const root = makeSite()
  edit(root, 'src/home.html', (h) => h.replace('<main id="main"', '<p>Hola</p><main id="main"'))
  assert.ok(has(run(root, { skipDist: true }), /literal visible/))
})

// ---- Locales (ADR-072 §4.2, puntos 3-5) ----

test('falta una clave en de: error de paridad', () => {
  const root = makeSite()
  editJson(root, 'locales/de.json', (d) => delete d['home.f3.text'])
  assert.ok(has(run(root, { skipDist: true }), /de\.json: falta la clave "home\.f3\.text"/))
})
test('sobra una clave en fr: error de paridad', () => {
  const root = makeSite()
  editJson(root, 'locales/fr.json', (d) => (d['clave.extra'] = 'x'))
  assert.ok(has(run(root, { skipDist: true }), /fr\.json: sobra la clave "clave\.extra"/))
})
test('clave vacía: error', () => {
  const root = makeSite()
  editJson(root, 'locales/en.json', (d) => (d['home.f3.text'] = '  '))
  assert.ok(has(run(root, { skipDist: true }), /en\.json: clave "home\.f3\.text" vacía/))
})
test('valor con "<": error', () => {
  const root = makeSite()
  editJson(root, 'locales/es-ES.json', (d) => (d['home.f3.text'] = 'Hola <b>mundo</b>'))
  assert.ok(has(run(root, { skipDist: true }), /contiene "<"/))
})
test('marcadores internos distintos entre idiomas: error', () => {
  const root = makeSite()
  editJson(root, 'locales/es-ES.json', (d) => (d['home.f3.text'] = 'Hola {nombre}'))
  editJson(root, 'locales/en.json', (d) => (d['home.f3.text'] = 'Hello {name}'))
  assert.ok(has(run(root, { skipDist: true }), /marcadores de "home\.f3\.text" difieren/))
})
test('marcadores internos iguales entre idiomas: correcto', () => {
  const root = makeSite()
  for (const l of ['es-ES', 'en', 'de', 'fr']) {
    editJson(root, `locales/${l}.json`, (d) => (d['home.f3.text'] = `Hola {nombre} ${l}`))
  }
  assert.deepEqual(run(root, { skipDist: true }).errors, [])
})
test('falta un fichero de idioma: error', () => {
  const root = makeSite()
  rmSync(join(root, 'locales', 'de.json'))
  assert.ok(has(run(root, { skipDist: true }), /de\.json: no existe/))
})
test('_pending_translation en es-ES: error', () => {
  const root = makeSite()
  editJson(root, 'locales/es-ES.json', (d) => (d._pending_translation = true))
  assert.ok(has(run(root, { skipDist: true }), /solo se permite en los otros idiomas/))
})

// ---- Modo --publish ----

test('claves pendientes de traducción: aviso en desarrollo, error en --publish', () => {
  const root = makeSite()
  const dev = run(root, { skipDist: true })
  assert.ok(dev.warnings.some((w) => /en\.json: traducción pendiente/.test(w)))
  assert.ok(!has(dev, /traducción pendiente/))
  assert.ok(has(run(root, { skipDist: true, publish: true }), /en\.json: traducción pendiente/))
})
test('marcadores [TITULAR]-style: aviso en desarrollo, error en --publish', () => {
  const root = makeSite()
  const dev = run(root, { skipDist: true })
  assert.ok(!has(dev, /marcador pendiente/))
  const pub = run(root, { skipDist: true, publish: true })
  assert.ok(
    has(pub, /es-ES\.json: "legal\.body\.id\.holder\.value" tiene marcador pendiente \[TITULAR\]/),
  )
})
test('--publish pasa con traducciones completas y sin marcadores', () => {
  const root = makeSite({ built: false })
  const fill = {
    '[TITULAR]': 'Persona',
    '[NIF]': '1',
    '[DOMICILIO]': 'Calle',
    '[CORREO]': 'a@example.com',
    '[PROVEEDOR]': 'P',
    '[GARANTIA]': 'G',
    '[PLAZO]': '30 días',
  }
  for (const l of ['es-ES', 'en', 'de', 'fr']) {
    editJson(root, `locales/${l}.json`, (d) => {
      delete d._pending_translation
      for (const k of Object.keys(d))
        for (const [m, v] of Object.entries(fill)) d[k] = String(d[k]).replaceAll(m, v)
    })
  }
  build({ root })
  assert.deepEqual(run(root, { publish: true }).errors, [])
})

// ---- dist/ (ADR-072 §4.5) ----

function brokenDist(rel, fn) {
  const root = makeSite()
  edit(root, join('dist', rel), fn)
  return run(root)
}

test('<script> en dist: error', () => {
  assert.ok(
    has(
      brokenDist('index.html', (h) => h.replace('</body>', '<script>1</script></body>')),
      /<script> no permitido/,
    ),
  )
})
test('<form> en dist: error', () => {
  assert.ok(
    has(
      brokenDist('index.html', (h) => h.replace('</main>', '<form></form></main>')),
      /<form> no permitido/,
    ),
  )
})
test('manejador de evento en dist: error', () => {
  assert.ok(
    has(
      brokenDist('index.html', (h) => h.replace('<main', '<main onclick="x()"')),
      /manejador de evento/,
    ),
  )
})
test('atributo style en dist: error (CSP)', () => {
  assert.ok(
    has(
      brokenDist('index.html', (h) => h.replace('<main', '<main style="color:red"')),
      /atributo style/,
    ),
  )
})
test('imagen de terceros en dist: error', () => {
  assert.ok(
    has(
      brokenDist('index.html', (h) =>
        h.replace('</main>', '<img src="https://cdn.example.com/a.png" alt="x"></main>'),
      ),
      /recurso externo/,
    ),
  )
})
test('hoja de estilos de terceros en dist: error', () => {
  assert.ok(
    has(
      brokenDist('index.html', (h) =>
        h.replace(
          '<link rel="stylesheet" href="/styles.css">',
          '<link rel="stylesheet" href="https://fonts.googleapis.com/css">',
        ),
      ),
      /externo/,
    ),
  )
})
test('@import y url() externos en el CSS: error', () => {
  const r1 = brokenDist('styles.css', (c) => '@import "https://x.example/a.css";\n' + c)
  assert.ok(has(r1, /@import/))
  const r2 = brokenDist('styles.css', (c) => c + '\nbody{background:url(https://x.example/a.png)}')
  assert.ok(has(r2, /url\(\) externa/))
})
test('enlace interno roto: error', () => {
  assert.ok(
    has(
      brokenDist('index.html', (h) => h.replace('</main>', '<a href="/no-existe/">x</a></main>')),
      /enlace roto "\/no-existe\/"/,
    ),
  )
})
test('ancla rota: error; ancla válida: correcto', () => {
  assert.ok(
    has(
      brokenDist('index.html', (h) => h.replace('</main>', '<a href="#nada">x</a></main>')),
      /ancla rota/,
    ),
  )
  assert.deepEqual(run(makeSite()).errors, [])
})
test('lang equivocado: error', () => {
  assert.ok(
    has(
      brokenDist('de/index.html', (h) => h.replace('<html lang="de">', '<html lang="en">')),
      /lang="en", se esperaba "de"/,
    ),
  )
})
test('canonical equivocado: error', () => {
  assert.ok(
    has(
      brokenDist('en/index.html', (h) =>
        h.replace('href="https://adagiox.com/en/">', 'href="https://adagiox.com/">'),
      ),
      /canonical/,
    ),
  )
})
test('hreflang ausente: error', () => {
  assert.ok(
    has(
      brokenDist('fr/index.html', (h) =>
        h.replace(/<link rel="alternate" hreflang="de"[^>]*>\n/, ''),
      ),
      /falta hreflang="de"/,
    ),
  )
})
test('hreflang no recíproco: error', () => {
  const root = makeSite()
  edit(root, 'dist/de/index.html', (h) =>
    h.replace(
      'hreflang="en" href="https://adagiox.com/en/"',
      'hreflang="en" href="https://adagiox.com/fr/"',
    ),
  )
  assert.ok(has(run(root), /no es recíproco/))
})
test('x-default distinto de es-ES: error', () => {
  assert.ok(
    has(
      brokenDist('index.html', (h) =>
        h.replace(
          'hreflang="x-default" href="https://adagiox.com/"',
          'hreflang="x-default" href="https://adagiox.com/en/"',
        ),
      ),
      /x-default debe coincidir/,
    ),
  )
})
test('dos <h1>: error', () => {
  assert.ok(
    has(
      brokenDist('index.html', (h) => h.replace('</main>', '<h1>x</h1></main>')),
      /exactamente un <h1>/,
    ),
  )
})
test('_headers con CSP alterada: error', () => {
  assert.ok(
    has(
      brokenDist('_headers', (h) => h.replace("default-src 'none'", "default-src 'self'")),
      /Content-Security-Policy no tiene el valor exacto/,
    ),
  )
})
test('_headers sin una cabecera: error', () => {
  assert.ok(
    has(
      brokenDist('_headers', (h) => h.replace(/ {2}X-Frame-Options: DENY\n/, '')),
      /falta X-Frame-Options/,
    ),
  )
})
test('HSTS con includeSubDomains: error', () => {
  assert.ok(
    has(
      brokenDist('_headers', (h) => h.replace('max-age=86400', 'max-age=86400; includeSubDomains')),
      /Strict-Transport-Security no tiene el valor exacto/,
    ),
  )
})
test('sitemap sin una página: error', () => {
  assert.ok(
    has(
      brokenDist('sitemap.xml', (s) =>
        s.replace(
          /<url>\s*<loc>https:\/\/adagiox\.com\/de\/<\/loc>/,
          '<url><loc>https://adagiox.com/zz/</loc>',
        ),
      ),
      /sitemap\.xml/,
    ),
  )
})
test('dist inexistente: error', () => {
  const root = makeSite({ built: false })
  assert.ok(has(run(root), /dist\/: no existe/))
})
test('marcador sin resolver en dist: error', () => {
  assert.ok(
    has(
      brokenDist('index.html', (h) => h.replace('</main>', '<p>{{t:x}}</p></main>')),
      /marcador sin resolver/,
    ),
  )
})
