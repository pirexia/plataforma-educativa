// ADR-072 §4.5 y §4.6 (issues #582 y #583): lighthouse, wrangler y deploy.sh, sin red ni Cloudflare.
import { test, after } from 'node:test'
import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { createServer } from 'node:http'
import { mkdtempSync, mkdirSync, cpSync, writeFileSync, readFileSync, rmSync, chmodSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { PROJECT, cleanup } from './helpers.mjs'
import { build } from '../scripts/build.mjs'
import { LANGS, pagePath } from '../scripts/lib.mjs'
import { SECURITY_HEADERS } from '../scripts/headers.mjs'

after(cleanup)
const temps = []
after(() => temps.forEach((d) => rmSync(d, { recursive: true, force: true })))

const json = (rel) => JSON.parse(readFileSync(join(PROJECT, rel), 'utf8'))
// wrangler.jsonc admite comentarios de línea: se quitan las líneas que empiezan por //.
const jsonc = (rel) =>
  JSON.parse(
    readFileSync(join(PROJECT, rel), 'utf8')
      .split('\n')
      .filter((l) => !l.trim().startsWith('//'))
      .join('\n'),
  )

// ---- Lighthouse (ADR-072 §4.5) ----

test('.lighthouserc.json: móvil, la portada de cada idioma de LANGS y una página legal, umbrales del ADR', () => {
  const ci = json('.lighthouserc.json').ci
  assert.equal(ci.collect.staticDistDir, './dist')
  assert.ok(!ci.collect.settings?.preset, 'el perfil móvil es el predeterminado: no se fuerza otro preset')
  assert.ok(!ci.collect.settings?.formFactor || ci.collect.settings.formFactor === 'mobile')
  const urls = ci.collect.url
  // Las URL salen de LANGS (ADR-074 §4.2), no de una lista escrita a mano.
  const expected = [...LANGS.map((l) => pagePath(l.code, 'home')), pagePath(LANGS[0].code, 'legal')].map(
    (p) => `http://localhost${p}index.html`,
  )
  assert.deepEqual([...urls].sort(), expected.sort())
  const a = ci.assert.assertions
  assert.deepEqual(a['categories:accessibility'], ['error', { minScore: 1 }])
  for (const c of ['best-practices', 'seo', 'performance'])
    assert.deepEqual(a[`categories:${c}`], ['error', { minScore: 0.95 }])
  // Aserciones de ADR-074 §4.6 (KB = 1024 bytes).
  assert.deepEqual(a['largest-contentful-paint'], ['error', { maxNumericValue: 2500 }])
  assert.deepEqual(a['cumulative-layout-shift'], ['error', { maxNumericValue: 0.05 }])
  assert.deepEqual(a['resource-summary:image:size'], ['error', { maxNumericValue: 300 * 1024 }])
  assert.deepEqual(a['resource-summary:total:size'], ['error', { maxNumericValue: 450 * 1024 }])
})

test('package.json: dependencias de desarrollo fijadas a versión exacta y script lighthouse', () => {
  const pkg = json('package.json')
  assert.equal(pkg.devDependencies['@lhci/cli'], '0.16.0')
  assert.equal(pkg.devDependencies.wrangler, '4.159.0')
  assert.deepEqual(Object.keys(pkg.devDependencies).sort(), ['@lhci/cli', 'wrangler'])
  assert.equal(pkg.dependencies, undefined)
  assert.equal(pkg.scripts.lighthouse, 'lhci autorun')
})

// ---- wrangler.jsonc (ADR-072 §4.6, §4.7) ----

test('wrangler.jsonc: Worker de solo activos, sin rutas ni dominios, coherente con dist/', () => {
  const w = jsonc('wrangler.jsonc')
  assert.equal(w.name, 'adagiox-landing')
  assert.equal(w.main, undefined)
  assert.equal(w.assets.directory, './dist')
  assert.equal(w.workers_dev, true)
  assert.equal(w.routes, undefined)
  assert.equal(w.route, undefined)
  assert.match(w.compatibility_date, /^\d{4}-\d{2}-\d{2}$/)
  assert.equal(w.assets.html_handling, 'auto-trailing-slash')
  assert.equal(w.assets.not_found_handling, '404-page')
})

// ---- deploy.sh ----

const DEPLOY = join(PROJECT, 'scripts', 'deploy.sh')
const ENV_OK = { CLOUDFLARE_API_TOKEN: 'token-de-prueba', CLOUDFLARE_ACCOUNT_ID: 'cuenta-de-prueba' }

// Proyecto temporal con `npm` y `npx` falsos en el PATH: registran lo invocado y no usan red.
function fakeProject({ publishFails = false } = {}) {
  const root = mkdtempSync(join(tmpdir(), 'landing-deploy-'))
  temps.push(root)
  mkdirSync(join(root, 'scripts'))
  mkdirSync(join(root, 'bin'))
  cpSync(DEPLOY, join(root, 'scripts', 'deploy.sh'))
  chmodSync(join(root, 'scripts', 'deploy.sh'), 0o755)
  const real = mkdtempSync(join(tmpdir(), 'landing-deploy-src-'))
  temps.push(real)
  cpSync(join(PROJECT, 'src'), join(real, 'src'), { recursive: true })
  cpSync(join(PROJECT, 'locales'), join(real, 'locales'), { recursive: true })
  cpSync(join(PROJECT, '.lighthouserc.json'), join(real, '.lighthouserc.json'))
  build({ root: real })
  cpSync(join(real, 'dist'), join(root, 'dist'), { recursive: true })
  const log = join(root, 'calls.log')
  writeFileSync(
    join(root, 'bin', 'npm'),
    `#!/usr/bin/env bash\necho "npm $*" >> "${log}"\n` +
      (publishFails ? `[ "$2" = "check:publish" ] && exit 1\n` : '') +
      'exit 0\n',
  )
  writeFileSync(
    join(root, 'bin', 'npx'),
    `#!/usr/bin/env bash\necho "npx $*" >> "${log}"\n` +
      `echo "Deployed adagiox-landing triggers: https://adagiox-landing.ejemplo.workers.dev"\n`,
  )
  chmodSync(join(root, 'bin', 'npm'), 0o755)
  chmodSync(join(root, 'bin', 'npx'), 0o755)
  return { root, log }
}

function runDeploy(root, args = [], env = ENV_OK) {
  return new Promise((resolve) => {
    const p = spawn('bash', [join(root, 'scripts', 'deploy.sh'), ...args], {
      env: { PATH: `${join(root, 'bin')}:${process.env.PATH}`, HOME: process.env.HOME, ...env },
    })
    let out = ''
    p.stdout.on('data', (d) => (out += d))
    p.stderr.on('data', (d) => (out += d))
    p.on('close', (code) => resolve({ code, out }))
  })
}

const calls = (log) => {
  try {
    return readFileSync(log, 'utf8').trim().split('\n')
  } catch {
    return []
  }
}

for (const missing of ['CLOUDFLARE_API_TOKEN', 'CLOUDFLARE_ACCOUNT_ID']) {
  test(`deploy.sh falla sin ${missing} y no construye ni despliega`, async () => {
    const { root, log } = fakeProject()
    const env = { ...ENV_OK }
    delete env[missing]
    const r = await runDeploy(root, [], env)
    assert.notEqual(r.code, 0)
    assert.match(r.out, new RegExp(missing))
    assert.deepEqual(calls(log), [])
  })
}

test('deploy.sh rechaza argumentos desconocidos', async () => {
  const { root, log } = fakeProject()
  const r = await runDeploy(root, ['--otra-cosa'])
  assert.equal(r.code, 2)
  assert.deepEqual(calls(log), [])
})

test('deploy.sh: si check:publish falla, no se despliega (puerta de publicación, #582)', async () => {
  const { root, log } = fakeProject({ publishFails: true })
  const r = await runDeploy(root)
  assert.notEqual(r.code, 0)
  assert.deepEqual(calls(log), ['npm run build', 'npm run check:publish'])
})

test('deploy.sh con una copia del proyecto con un marcador pendiente: --dry-run se bloquea (#582)', () => {
  // Copia real (scripts, src, locales, package.json) con npm real; los textos del proyecto ya no
  // tienen marcadores (ADR-073), así que se reintroduce uno para probar la puerta de publicación.
  const root = mkdtempSync(join(tmpdir(), 'landing-deploy-marker-'))
  temps.push(root)
  for (const d of ['scripts', 'src', 'locales']) cpSync(join(PROJECT, d), join(root, d), { recursive: true })
  cpSync(join(PROJECT, 'package.json'), join(root, 'package.json'))
  cpSync(join(PROJECT, '.lighthouserc.json'), join(root, '.lighthouserc.json'))
  const es = join(root, 'locales', 'es-ES.json')
  const loc = JSON.parse(readFileSync(es, 'utf8'))
  loc['legal.body.id.holder.value'] = '[TITULAR]'
  writeFileSync(es, JSON.stringify(loc, null, 2) + '\n')
  const r = spawnSync('bash', [join(root, 'scripts', 'deploy.sh'), '--dry-run'], {
    env: { PATH: process.env.PATH, HOME: process.env.HOME, ...ENV_OK },
    encoding: 'utf8',
  })
  assert.notEqual(r.status, 0)
  assert.doesNotMatch(r.stdout + r.stderr, /Total Upload|--dry-run completado/)
})

test('deploy.sh --dry-run: construye, comprueba y simula, sin desplegar ni smoke test', async () => {
  const { root, log } = fakeProject()
  const r = await runDeploy(root, ['--dry-run'])
  assert.equal(r.code, 0, r.out)
  assert.deepEqual(calls(log), ['npm run build', 'npm run check:publish', 'npx wrangler deploy --dry-run'])
})

// Servidor local que responde con las cabeceras de dist/_headers (o con una modificada).
function serve(headers) {
  return new Promise((resolve) => {
    const srv = createServer((req, res) => {
      res.writeHead(200, headers)
      res.end('ok')
    })
    srv.listen(0, '127.0.0.1', () => resolve(srv))
  })
}

test('deploy.sh: smoke test de cabeceras correcto contra una URL con todas las cabeceras (#583)', async () => {
  const { root, log } = fakeProject()
  const srv = await serve(SECURITY_HEADERS)
  try {
    const r = await runDeploy(root, ['--url', `http://127.0.0.1:${srv.address().port}`])
    assert.equal(r.code, 0, r.out)
    assert.match(r.out, /deploy: correcto/)
    assert.deepEqual(calls(log), ['npm run build', 'npm run check:publish', 'npx wrangler deploy'])
  } finally {
    srv.close()
  }
})

test('deploy.sh: el smoke test falla si falta una cabecera o su valor no es el exacto (#583)', async () => {
  for (const mutate of [
    (h) => delete h['Strict-Transport-Security'],
    (h) => (h['Strict-Transport-Security'] = 'max-age=31536000'),
    (h) => delete h['Cross-Origin-Resource-Policy'],
    (h) => (h['X-Frame-Options'] = 'SAMEORIGIN'),
  ]) {
    const { root } = fakeProject()
    const headers = { ...SECURITY_HEADERS }
    mutate(headers)
    const srv = await serve(headers)
    try {
      const r = await runDeploy(root, ['--url', `http://127.0.0.1:${srv.address().port}`])
      assert.notEqual(r.code, 0)
      assert.match(r.out, /smoke test FALLA/)
    } finally {
      srv.close()
    }
  }
})

test('deploy.sh: toma la URL de la salida de wrangler si no se pasa --url', async () => {
  const { root } = fakeProject()
  const r = await runDeploy(root)
  // La URL falsa no resuelve: el fallo debe venir de curl contra esa URL, no de no encontrarla.
  assert.notEqual(r.code, 0)
  assert.doesNotMatch(r.out, /no se pudo determinar la URL/)
  assert.match(r.out, /adagiox-landing\.ejemplo\.workers\.dev/)
})

// ---- ci-landing.yml (ADR-072 §4.5, §4.6) ----

test('ci-landing.yml: paths, jobs, deploy solo en main con interruptor y solo vía deploy.sh', () => {
  const y = readFileSync(join(PROJECT, '..', '..', '.github', 'workflows', 'ci-landing.yml'), 'utf8')
  assert.equal((y.match(/paths: \['apps\/landing\/\*\*', '\.github\/workflows\/ci-landing\.yml'\]/g) ?? []).length, 2)
  for (const j of ['build', 'check', 'lighthouse', 'deploy']) assert.match(y, new RegExp(`^  ${j}:`, 'm'))
  assert.match(y, /needs: \[build, check, lighthouse\]/)
  assert.match(y, /github\.ref == 'refs\/heads\/main'/)
  assert.match(y, /vars\.LANDING_DEPLOY_ENABLED == 'true'/)
  assert.match(y, /working-directory: apps\/landing/)
  assert.match(y, /cache-dependency-path: apps\/landing\/package-lock\.json/)
  assert.match(y, /run: scripts\/deploy\.sh/)
  assert.doesNotMatch(y, /wrangler/)
  assert.match(y, /^permissions:\n  contents: read/m)
})
