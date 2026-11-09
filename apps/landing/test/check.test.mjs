// ADR-072 §4.2 y §4.5: cada comprobación falla con un caso malo y pasa con uno bueno.
import { test, after } from 'node:test'
import assert from 'node:assert/strict'
import { rmSync, utimesSync, writeFileSync, readFileSync, mkdirSync } from 'node:fs'
import { join } from 'node:path'
import { makeSite, cleanup, edit, editJson, fixture, addImage, placeImage } from './helpers.mjs'
import { build } from '../scripts/build.mjs'
import { checkTemplate, runChecks } from '../scripts/check.mjs'
import { readLocales, PENDING_DATA_RE, LANGS, MAX_IMG_FILE_BYTES, MAX_IMG_TOTAL_BYTES } from '../scripts/lib.mjs'
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
  const res = run(makeSite({ showcase: true }))
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
  editJson(root, 'locales/de.json', (d) => delete d['home.s.roles.text'])
  assert.ok(has(run(root, { skipDist: true }), /de\.json: falta la clave "home\.s\.roles\.text"/))
})
test('sobra una clave en fr: error de paridad', () => {
  const root = makeSite()
  editJson(root, 'locales/fr.json', (d) => (d['clave.extra'] = 'x'))
  assert.ok(has(run(root, { skipDist: true }), /fr\.json: sobra la clave "clave\.extra"/))
})
test('clave vacía: error', () => {
  const root = makeSite()
  editJson(root, 'locales/en.json', (d) => (d['home.s.roles.text'] = '  '))
  assert.ok(has(run(root, { skipDist: true }), /en\.json: clave "home\.s\.roles\.text" vacía/))
})
test('valor con "<": error', () => {
  const root = makeSite()
  editJson(root, 'locales/es-ES.json', (d) => (d['home.s.roles.text'] = 'Hola <b>mundo</b>'))
  assert.ok(has(run(root, { skipDist: true }), /contiene "<"/))
})
test('marcadores internos distintos entre idiomas: error', () => {
  const root = makeSite()
  editJson(root, 'locales/es-ES.json', (d) => (d['home.s.roles.text'] = 'Hola {nombre}'))
  editJson(root, 'locales/en.json', (d) => (d['home.s.roles.text'] = 'Hello {name}'))
  assert.ok(has(run(root, { skipDist: true }), /marcadores de "home\.s\.roles\.text" difieren/))
})
test('marcadores internos iguales entre idiomas: correcto', () => {
  const root = makeSite()
  for (const l of ['es-ES', 'en', 'de', 'fr']) {
    editJson(root, `locales/${l}.json`, (d) => (d['home.s.roles.text'] = `Hola {nombre} ${l}`))
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
  editJson(root, 'locales/de.json', (d) => (d._pending_translation = true))
  const dev = run(root, { skipDist: true })
  assert.ok(dev.warnings.some((w) => /de\.json: traducción pendiente/.test(w)))
  assert.ok(!has(dev, /traducción pendiente/))
  assert.ok(has(run(root, { skipDist: true, publish: true }), /de\.json: traducción pendiente/))
})
test('_pending_translation en en (idioma de redacción): error también fuera de --publish (ADR-074 §4.1)', () => {
  const root = makeSite()
  editJson(root, 'locales/en.json', (d) => (d._pending_translation = true))
  assert.ok(has(run(root, { skipDist: true }), /en\.json: "_pending_translation" solo se permite en los otros idiomas/))
})
test('marcadores [TITULAR]-style: aviso en desarrollo, error en --publish', () => {
  const root = makeSite()
  // Los textos reales ya no tienen marcadores (ADR-073): se reintroduce uno en la copia.
  editJson(root, 'locales/es-ES.json', (d) => (d['legal.body.id.holder.value'] = '[TITULAR]'))
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
      brokenDist('de/index.html', (h) => h.replace('<html lang="de" dir="ltr">', '<html lang="en" dir="ltr">')),
      /lang="en", se esperaba "de"/,
    ),
  )
})
test('canonical equivocado: error', () => {
  assert.ok(
    has(
      brokenDist('es/index.html', (h) =>
        h.replace('href="https://adagiox.com/es/">', 'href="https://adagiox.com/">'),
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
      'hreflang="es-ES" href="https://adagiox.com/es/"',
      'hreflang="es-ES" href="https://adagiox.com/fr/"',
    ),
  )
  assert.ok(has(run(root), /no es recíproco/))
})
test('x-default distinto del idioma por defecto (en): error', () => {
  assert.ok(
    has(
      brokenDist('index.html', (h) =>
        h.replace(
          'hreflang="x-default" href="https://adagiox.com/"',
          'hreflang="x-default" href="https://adagiox.com/es/"',
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

// ---- --publish escanea dist/ ----

function filledSite() {
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
      for (const k of Object.keys(d)) {
        for (const [m, v] of Object.entries(fill)) d[k] = String(d[k]).replaceAll(m, v)
      }
    })
  }
  build({ root })
  return root
}

test('--publish: marcador pendiente en el HTML generado: error', () => {
  const root = filledSite()
  assert.deepEqual(run(root, { publish: true }).errors, [])
  edit(root, 'dist/index.html', (h) => h.replace('</main>', '<p>[IBAN2]</p></main>'))
  assert.ok(
    has(run(root, { publish: true }), /dist\/index\.html: marcador pendiente \[IBAN2\]/),
  )
  assert.deepEqual(run(root).errors, [])
})
test('--publish: _pending_translation en el HTML generado: error', () => {
  const root = filledSite()
  edit(root, 'dist/index.html', (h) => h.replace('</main>', '<!-- _pending_translation --></main>'))
  assert.ok(has(run(root, { publish: true }), /contiene _pending_translation/))
})
test('--publish: dist/ anterior a src/ o locales/: error; sin --publish no', () => {
  const root = filledSite()
  const future = new Date(Date.now() + 60_000)
  utimesSync(join(root, 'locales', 'en.json'), future, future)
  assert.ok(has(run(root, { publish: true }), /dist\/: es anterior a src\/ o locales\//))
  assert.ok(!has(run(root), /es anterior/))
})
test('--publish: sin dist/ falla', () => {
  const root = makeSite({ built: false })
  assert.ok(has(run(root, { publish: true }), /dist\/: no existe/))
})

// ---- Enlaces externos ----

const extLink = (rel) => `<a href="https://example.org/x"${rel ? ` rel="${rel}"` : ''}>x</a>`
test('enlace externo sin rel noopener noreferrer: error', () => {
  assert.ok(
    has(
      brokenDist('index.html', (h) => h.replace('</main>', extLink() + '</main>')),
      /sin rel="noopener noreferrer"/,
    ),
  )
  assert.ok(
    has(
      brokenDist('index.html', (h) => h.replace('</main>', extLink('noopener') + '</main>')),
      /sin rel/,
    ),
  )
})
test('enlace externo con rel noopener noreferrer: correcto', () => {
  const res = brokenDist('index.html', (h) =>
    h.replace('</main>', extLink('noopener noreferrer') + '</main>'),
  )
  assert.deepEqual(res.errors, [])
})
test('enlaces mailto y al propio origen no exigen rel', () => {
  const res = brokenDist('index.html', (h) =>
    h.replace(
      '</main>',
      '<a href="mailto:a@example.com">x</a><a href="https://adagiox.com/es/">y</a></main>',
    ),
  )
  assert.deepEqual(res.errors, [])
})

// ---- Detección de marcadores pendientes ----

const matches = (s) => s.match(PENDING_DATA_RE) ?? []
test('PENDING_DATA_RE detecta marcadores con dígitos, guion, TODO y TBD', () => {
  for (const s of [
    '[TITULAR]',
    '[IBAN2]',
    '[N-A]',
    '[DATO_1]',
    'TODO',
    'pendiente TBD',
    '[ÑANDÚ]',
  ]) {
    assert.ok(matches(s).length > 0, s)
  }
})
test('PENDING_DATA_RE no da falsos positivos con los textos actuales', () => {
  const locales = readLocales(PROJECT)
  const allowed = /^\[(TITULAR|NIF|DOMICILIO|CORREO|PROVEEDOR|GARANTIA|PLAZO)\]$/
  for (const [lang, loc] of Object.entries(locales)) {
    for (const [k, v] of Object.entries(loc)) {
      if (typeof v !== 'string') continue
      for (const m of matches(v)) {
        assert.ok(k.startsWith('legal.body.') && allowed.test(m), `${lang}:${k} -> ${m}`)
      }
    }
  }
  for (const s of ['[a]', '[Hola]', 'Todo bien', '[1]', 'array[0]'])
    assert.deepEqual(matches(s), [], s)
})

// ---- Claves raras ----

test('una clave rara en {{t:...}} falla en check y en build', () => {
  const root = makeSite({ built: false })
  edit(root, 'src/home.html', (h) => h.replace('{{t:home.hero.title}}', '{{t:home hero/title}}'))
  assert.ok(
    has(run(root, { skipDist: true }), /marcador de clave inválido \{\{t:home hero\/title\}\}/),
  )
  assert.throws(() => build({ root }), /Marcador inválido/)
})

// ---- Idiomas (ADR-074 §4.1 y §4.2) ----

const IT = { code: 'it', slug: 'it', dir: 'ltr' }
const WITH_IT = [...LANGS, IT]

// Añade el idioma "it" siguiendo el procedimiento del README: locale, autónimo y URL de Lighthouse.
function addItalian(root, { locale = true, names = true, lighthouse = true, built = true } = {}) {
  if (names) {
    for (const l of ['en', 'es-ES', 'de', 'fr']) {
      editJson(root, `locales/${l}.json`, (d) => (d['lang.name.it'] = 'Italiano'))
    }
  }
  if (locale) {
    const en = JSON.parse(readFileSync(join(root, 'locales', 'en.json'), 'utf8'))
    writeFileSync(
      join(root, 'locales', 'it.json'),
      JSON.stringify({ ...en, 'lang.name.it': 'Italiano', _pending_translation: true }),
    )
  }
  if (lighthouse) {
    editJson(root, '.lighthouserc.json', (d) =>
      d.ci.collect.url.push('http://localhost/it/index.html'),
    )
  }
  if (built) build({ root, langs: WITH_IT })
}

test('añadir un idioma siguiendo los cuatro pasos del README: correcto (aviso de traducción pendiente)', () => {
  const root = makeSite({ built: false })
  addItalian(root)
  const res = run(root, { langs: WITH_IT })
  assert.deepEqual(res.errors, [])
  assert.ok(res.warnings.some((w) => /it\.json: traducción pendiente/.test(w)))
  assert.ok(has(run(root, { langs: WITH_IT, publish: true }), /it\.json: traducción pendiente/))
})
test('idioma añadido a LANGS sin locales/xx.json: error', () => {
  const root = makeSite()
  assert.ok(has(run(root, { langs: WITH_IT, skipDist: true }), /locales\/it\.json: no existe/))
})
test('idioma añadido sin su autónimo en las locales: error', () => {
  const root = makeSite({ built: false })
  addItalian(root, { names: false, built: false })
  const res = run(root, { langs: WITH_IT, skipDist: true })
  assert.ok(has(res, /en\.json: falta la clave "lang\.name\.it"/))
})
test('autónimo con valores distintos entre locales: error', () => {
  const root = makeSite({ built: false })
  addItalian(root)
  editJson(root, 'locales/fr.json', (d) => (d['lang.name.it'] = 'Italien'))
  assert.ok(has(run(root, { langs: WITH_IT, skipDist: true }), /fr\.json: "lang\.name\.it" debe valer lo mismo/))
})
test('idioma añadido sin su portada en .lighthouserc.json: error', () => {
  const root = makeSite({ built: false })
  addItalian(root, { lighthouse: false })
  assert.ok(
    has(run(root, { langs: WITH_IT, skipDist: true }), /\.lighthouserc\.json: falta la URL de la portada de it/),
  )
})
test('.lighthouserc.json sin la portada de un idioma o sin la página legal: error', () => {
  const root = makeSite()
  editJson(root, '.lighthouserc.json', (d) => {
    d.ci.collect.url = d.ci.collect.url.filter((u) => !u.includes('/es/') && !u.includes('/legal/'))
  })
  const res = run(root, { skipDist: true })
  assert.ok(has(res, /falta la URL de la portada de es-ES \(http:\/\/localhost\/es\/index\.html\)/))
  assert.ok(has(res, /falta la URL de la página legal/))
})
test('dos idiomas con el mismo slug, dir inválido o raíz mal asignada: error', () => {
  const root = makeSite()
  const dup = [...LANGS, { code: 'it', slug: 'de', dir: 'ltr' }]
  assert.ok(has(run(root, { langs: dup, skipDist: true }), /comparten el slug "de"/))
  const dir = [...LANGS, { code: 'it', slug: 'it', dir: 'up' }]
  assert.ok(has(run(root, { langs: dir, skipDist: true }), /dir de "it" debe ser ltr o rtl/))
  const root2 = LANGS.map((l) => (l.code === 'en' ? { ...l, slug: 'en' } : l))
  assert.ok(has(run(root, { langs: root2, skipDist: true }), /debe tener slug vacío/))
  const rtl = [...LANGS, { code: 'ar', slug: 'ar', dir: 'rtl' }]
  assert.ok(!has(run(root, { langs: rtl, skipDist: true }), /dir de "ar"|slug/))
})
test('el primer segmento de la URL se compara por slug: /es/ debe ser lang="es-ES"', () => {
  assert.ok(
    has(
      brokenDist('es/index.html', (h) => h.replace('<html lang="es-ES"', '<html lang="es"')),
      /lang="es", se esperaba "es-ES"/,
    ),
  )
})
test('texto legal de otro idioma en una página legal: error (LEGAL_LANG, ADR-074 §4.1)', () => {
  const root = makeSite()
  assert.deepEqual(run(root).errors, [])
  edit(root, 'dist/fr/legal/index.html', (h) =>
    h.replace('Identificación del titular (art. 10 LSSI-CE)', 'Identification du titulaire'),
  )
  assert.ok(has(run(root), /dist\/fr\/legal\/index\.html: "legal\.body\.id\.title" no es el texto de es-ES/))
})
test('texto legal sin el bloque lang="es-ES": error', () => {
  assert.ok(
    has(
      brokenDist('de/legal/index.html', (h) => h.replace('<div lang="es-ES">', '<div>')),
      /bloque lang="es-ES"/,
    ),
  )
})
test('un en.json con legal.body.* distinto no cambia la salida: check pasa (build lee LEGAL_LANG)', () => {
  const root = makeSite({ built: false })
  editJson(root, 'locales/en.json', (d) => (d['legal.body.id.title'] = 'Legal notice (translated)'))
  build({ root })
  assert.deepEqual(run(root).errors, [])
})

// ---- Imágenes (ADR-074 §4.3 y §4.6) ----

function imgSite(token = '{{img:shot-a:lcp}}', extra = () => {}) {
  const root = makeSite({ built: false })
  addImage(root)
  extra(root)
  placeImage(root, token)
  build({ root })
  return root
}
const imgDist = (fn, token) => {
  const root = imgSite(token)
  fn(root)
  return run(root)
}

test('imágenes del manifiesto bien formadas: sin errores', () => {
  assert.deepEqual(run(imgSite()).errors, [])
  assert.deepEqual(run(imgSite('{{img:shot-a}}')).errors, [])
})
test('regla 1: <img> sin alt: error', () => {
  const res = imgDist((r) =>
    edit(r, 'dist/index.html', (h) => h.replace(/ alt="[^"]*"/, '')),
  )
  assert.ok(has(res, /<img src="\/img\/shot-a-1024\.webp"> sin alt/))
})
test('regla 1: <img> sin height: error', () => {
  const res = imgDist((r) => edit(r, 'dist/index.html', (h) => h.replace(' height="640"', '')))
  assert.ok(has(res, /sin height válido/))
})
test('regla 1: width/height distintos del manifiesto: error', () => {
  const res = imgDist((r) => edit(r, 'dist/index.html', (h) => h.replace('width="1024"', 'width="999"')))
  assert.ok(has(res, /width\/height \(999x640\) no coinciden con el manifiesto \(1024x640\)/))
})
test('regla 1: <img src="/img/..."> que no está en el manifiesto: error', () => {
  const res = imgDist((r) =>
    edit(r, 'dist/index.html', (h) => h.replace('/img/shot-a-1024.webp"', '/img/otra-1024.webp"')),
  )
  assert.ok(has(res, /no está en src\/img\/manifest\.json/))
})
test('regla 2: dos imágenes sin loading="lazy": error', () => {
  const root = makeSite({ built: false })
  addImage(root, 'shot-a')
  addImage(root, 'shot-b')
  placeImage(root, '{{img:shot-a:lcp}}{{img:shot-b:lcp}}')
  build({ root })
  assert.ok(has(run(root), /2 imágenes sin loading="lazy" \(como mucho una, la LCP\)/))
})
test('regla 2: una LCP y otra diferida: correcto', () => {
  const root = makeSite({ built: false })
  addImage(root, 'shot-a')
  addImage(root, 'shot-b')
  placeImage(root, '{{img:shot-a:lcp}}{{img:shot-b}}')
  build({ root })
  assert.deepEqual(run(root).errors, [])
})
test('regla 2: la imagen sin lazy no lleva fetchpriority="high": error', () => {
  const res = imgDist((r) => edit(r, 'dist/index.html', (h) => h.replace(' fetchpriority="high"', '')))
  assert.ok(has(res, /sin loading="lazy" debe llevar fetchpriority="high"/))
})
test('regla 3: una URL de srcset que no existe en dist: error (img y source)', () => {
  const imgRes = imgDist((r) => rmSync(join(r, 'dist', 'img', 'shot-a-640.webp')))
  assert.ok(has(imgRes, /srcset de <img> apunta a un fichero inexistente "\/img\/shot-a-640\.webp"/))
  const srcRes = imgDist((r) => rmSync(join(r, 'dist', 'img', 'shot-a-1600.avif')))
  assert.ok(has(srcRes, /srcset de <source> apunta a un fichero inexistente "\/img\/shot-a-1600\.avif"/))
})
test('regla 3: srcset de otro origen: error', () => {
  const res = imgDist((r) =>
    edit(r, 'dist/index.html', (h) =>
      h.replace('<source type="image/avif" srcset="', '<source type="image/avif" srcset="https://cdn.example.com/a.avif 640w, '),
    ),
  )
  assert.ok(has(res, /recurso externo en source\[srcset\]/))
})
test('regla 4: url() con data: en el CSS: error; url() local existente: correcto', () => {
  const bad = imgDist((r) =>
    edit(r, 'dist/styles.css', (c) => c + '\nbody{background:url("data:image/svg+xml,%3Csvg%3E")}'),
  )
  assert.ok(has(bad, /url\(\) con data: no permitida/))
  const good = imgDist((r) =>
    edit(r, 'dist/styles.css', (c) => c + '\nbody{background:url(/img/shot-a-640.webp)}'),
  )
  assert.deepEqual(good.errors, [])
})
test('regla 4: url() local que no existe en dist: error', () => {
  const res = imgDist((r) =>
    edit(r, 'dist/styles.css', (c) => c + "\nbody{background:url('/img/no-existe.svg')}"),
  )
  assert.ok(has(res, /url\(\) a un fichero inexistente \/img\/no-existe\.svg/))
})
test('regla 5: fichero de imagen por encima de 120 KB: error', () => {
  const root = makeSite({ built: false })
  addImage(root, 'shot-a', { bytes: MAX_IMG_FILE_BYTES + 1 })
  placeImage(root, '{{img:shot-a:lcp}}')
  build({ root })
  assert.ok(has(run(root), /shot-a-640\.avif: 122881 bytes superan el tope de 122880/))
  const ok = makeSite({ built: false })
  addImage(ok, 'shot-a', { bytes: MAX_IMG_FILE_BYTES })
  placeImage(ok, '{{img:shot-a:lcp}}')
  build({ root: ok })
  assert.deepEqual(run(ok).errors, [])
})
test('regla 5: src/img por encima de 2,5 MB en total: error', () => {
  const root = makeSite({ built: false })
  for (const id of ['a', 'b', 'c', 'd', 'e']) addImage(root, `shot-${id}`, { bytes: 100 * 1024 })
  build({ root })
  const res = run(root)
  assert.ok(MAX_IMG_TOTAL_BYTES < 5 * 6 * 100 * 1024)
  assert.ok(has(res, /src\/img\/: \d+ bytes superan el tope total de 2621440/))
  assert.ok(!has(res, /superan el tope de 122880/))
})
test('regla 5: bytes del manifiesto distintos del fichero, fichero sin listar o variante ausente: error', () => {
  const root = imgSite()
  editJson(root, 'src/img/manifest.json', (d) => (d.images['shot-a'].variants[0].bytes = 1))
  assert.ok(has(run(root), /bytes de shot-a-640\.avif \(1\) no coinciden con el fichero \(2000\)/))
  const root2 = imgSite()
  writeFileSync(join(root2, 'src', 'img', 'huerfana-640.webp'), 'x')
  assert.ok(has(run(root2), /huerfana-640\.webp: no figura en manifest\.json/))
  const root3 = imgSite()
  rmSync(join(root3, 'src', 'img', 'shot-a-640.webp'))
  assert.ok(has(run(root3), /shot-a-640\.webp no existe en src\/img\//))
})
test('regla 5: más de 8 imágenes, PNG en src/img o imágenes sin manifiesto: error', () => {
  const root = makeSite({ built: false })
  for (let i = 0; i < 9; i++) addImage(root, `shot-${i}`, { bytes: 10 })
  build({ root })
  assert.ok(has(run(root), /9 imágenes \(máximo 8\)/))
  const png = imgSite()
  writeFileSync(join(png, 'src', 'img', 'maestra.png'), 'x')
  assert.ok(has(run(png), /maestra\.png: solo AVIF, WebP o SVG/))
  const none = imgSite()
  rmSync(join(none, 'src', 'img', 'manifest.json'))
  assert.ok(has(run(none), /src\/img\/: falta manifest\.json/))
})
test('regla 5: un PNG publicado en dist/img: error', () => {
  const root = imgSite()
  mkdirSync(join(root, 'dist', 'img'), { recursive: true })
  writeFileSync(join(root, 'dist', 'img', 'x.png'), 'x')
  assert.ok(has(run(root), /dist\/img\/x\.png: no se publican PNG/))
})
test('el manifiesto exige el alt de cada imagen en todas las locales', () => {
  const root = imgSite()
  editJson(root, 'locales/de.json', (d) => delete d['img.shot-a.alt'])
  assert.ok(has(run(root), /de\.json: falta el texto alternativo "img\.shot-a\.alt"/))
})
test('manifiesto que no es JSON o sin "images": error', () => {
  const root = imgSite()
  writeFileSync(join(root, 'src', 'img', 'manifest.json'), '{no')
  assert.ok(has(run(root), /manifest\.json: no es JSON válido/))
  writeFileSync(join(root, 'src', 'img', 'manifest.json'), '{}')
  assert.ok(has(run(root), /debe tener un objeto "images"/))
})

// ---- CSP en el HTML (ADR-074 §4.6, regla 6) ----

test('regla 6: <style> en dist: error; <style> en una plantilla: error', () => {
  assert.ok(
    has(
      brokenDist('index.html', (h) => h.replace('</head>', '<style>body{}</style></head>')),
      /<style> no permitido/,
    ),
  )
  const root = makeSite()
  edit(root, 'src/home.html', (h) => h.replace('</head>', '<style>body{}</style></head>'))
  assert.ok(has(run(root, { skipDist: true }), /etiqueta <style> no permitida/))
})
test('regla 6: style en un <img> generado: error', () => {
  const res = imgDist((r) =>
    edit(r, 'dist/index.html', (h) => h.replace('<img ', '<img style="width:1px" ')),
  )
  assert.ok(has(res, /atributo style no permitido/))
})

// ---- prefers-reduced-motion (ADR-074 §4.5, regla 7) ----

test('regla 7: styles.css con el bloque prefers-reduced-motion: correcto; sin él, o incompleto: error', () => {
  assert.deepEqual(run(makeSite()).errors, [])
  const strip = (c) => c.slice(0, c.indexOf('@media (prefers-reduced-motion: reduce)'))
  const root = makeSite()
  edit(root, 'dist/styles.css', strip)
  edit(root, 'src/styles.css', strip)
  const res = run(root)
  assert.ok(has(res, /dist\/styles\.css: falta el bloque @media \(prefers-reduced-motion: reduce\)/))
  assert.ok(has(res, /src\/styles\.css: falta el bloque/))
  const half = makeSite()
  edit(half, 'src/styles.css', (c) => c.replace(/animation: none !important;/, ''))
  assert.ok(has(run(half, { skipDist: true }), /sin "animation: none !important"/))
  const half2 = makeSite()
  edit(half2, 'src/styles.css', (c) => c.replace(/scroll-behavior: auto !important;/, ''))
  assert.ok(has(run(half2, { skipDist: true }), /sin "scroll-behavior: auto"/))
})

// ---- Carrusel y efectos de scroll (ADR-074 §4.5) ----

const GOOD_FX = `
@media (prefers-reduced-motion: no-preference) {
  @supports (animation-timeline: view()) {
    @keyframes rise { from { transform: translateY(2rem); } to { transform: none; } }
    .card { animation: rise linear both; animation-timeline: view(); }
  }
}
`

function fxSite(extraCss) {
  const root = makeSite()
  edit(root, 'src/styles.css', (c) => c + extraCss)
  return root
}

test('§4.5 carrusel: el de la portada real tiene tabindex, role="region" y aria-label (bueno)', () => {
  const res = run(makeSite({ showcase: true }))
  assert.ok(!has(res, /carrusel/))
})
test('§4.5 carrusel: sin tabindex, sin role o sin aria-label en el contenedor: error', () => {
  for (const [from, re] of [
    [' tabindex="0"', /tabindex="0"/],
    [' role="region"', /role="region"/],
    [' aria-label="{{t:home.carousel.label}}"', /aria-label/],
  ]) {
    const root = makeSite({ showcase: true, built: false })
    edit(root, 'src/home.html', (h) => h.replace(from, ''))
    assert.ok(has(run(root, { skipDist: true }), re), from)
  }
})
test('§4.5 carrusel: aria-roledescription: error', () => {
  const root = makeSite({ showcase: true, built: false })
  edit(root, 'src/home.html', (h) => h.replace('role="region"', 'role="region" aria-roledescription="carousel"'))
  assert.ok(has(run(root, { skipDist: true }), /aria-roledescription no permitido/))
})
test('§4.5 carrusel: el resultado construido sin tabindex falla también en dist', () => {
  const root = makeSite({ showcase: true })
  edit(root, 'dist/index.html', (h) => h.replace(/ tabindex="0">(\s*<ul)/, '>$1'))
  assert.ok(has(run(root), /dist\/index\.html: el carrusel debe llevar tabindex="0"/))
})
test('§4.5 efectos: animation-timeline dentro de @supports y no-preference solo con transform: correcto', () => {
  assert.deepEqual(run(fxSite(GOOD_FX)).errors, [])
})
test('§4.5 efectos: animation-timeline fuera de @supports: error', () => {
  const root = fxSite(`
@media (prefers-reduced-motion: no-preference) {
  @keyframes rise { from { transform: none; } to { transform: none; } }
  .card { animation: rise linear both; animation-timeline: view(); }
}`)
  assert.ok(has(run(root), /usa animation-timeline fuera de @supports/))
})
test('§4.5 efectos: animation-timeline sin @media (prefers-reduced-motion: no-preference): error', () => {
  const root = fxSite(`
@supports (animation-timeline: view()) {
  @keyframes rise { from { transform: none; } to { transform: none; } }
  .card { animation: rise linear both; animation-timeline: view(); }
}`)
  assert.ok(has(run(root), /fuera de @media \(prefers-reduced-motion: no-preference\)/))
})
test('§4.5 efectos: keyframes ligados al scroll con opacity, filter o color: error', () => {
  for (const prop of ['opacity: 0', 'filter: blur(2px)', 'color: red']) {
    const root = fxSite(GOOD_FX.replace('from { transform: translateY(2rem); }', `from { transform: translateY(2rem); ${prop}; }`))
    assert.ok(has(run(root), new RegExp(`anima "${prop.split(':')[0]}": solo se anima transform`)), prop)
  }
})
test('§4.5 efectos: la animación con opacity que NO va ligada al scroll no se rechaza', () => {
  const root = fxSite('@keyframes fade { from { opacity: 0; } to { opacity: 1; } }\n.x { animation: fade 1s; }')
  assert.ok(!has(run(root), /solo se anima transform/))
})
test('§4.5 ::scroll-button y ::scroll-marker: error', () => {
  assert.ok(has(run(fxSite('.carousel::scroll-marker { content: ""; }')), /::scroll-button y ::scroll-marker/))
})
