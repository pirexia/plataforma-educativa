/**
 * E2E real en el CI (issue #737, ADR-091, docs/proceso/e2e-real-ci.md): fija lo que el job y sus guiones prometen y que ningún
 * E2E puede comprobar desde dentro: número de tests esperados (CA-E2ECI-01, CA-E2ECI-12), regla de rutas del job `cambios`
 * (CA-E2ECI-02, CA-E2ECI-13), propiedades del YAML (CA-E2ECI-14, CA-E2ECI-15), regla del agregador (CA-E2ECI-04) e
 * instrumentación de tiempos (CA-E2ECI-06). Lo que solo se ve en GitHub Actions lo prueba la ejecución del CI, no este fichero.
 */
import { spawnSync } from 'node:child_process'
import { chmodSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { evaluar } from './e2e-real-verificar.mjs'
import { parsear, tabla } from './e2e-real-tiempos.mjs'

const raiz = resolve(__dirname, '../../..')
const workflow = readFileSync(resolve(raiz, '.github/workflows/ci-e2e-real.yml'), 'utf8')

function rutas(ficheros: string[]): string {
  const result = spawnSync('bash', [resolve(__dirname, 'e2e-real-cambios.sh')], {
    input: ficheros.map((f) => `${f}\0`).join(''),
    encoding: 'utf8',
  })
  expect(result.status).toBe(0)
  return result.stdout.trim()
}

describe('e2e-real-esperados.json (CA-E2ECI-01, CA-E2ECI-12)', () => {
  const esperados = JSON.parse(
    readFileSync(resolve(__dirname, '../e2e/e2e-real-esperados.json'), 'utf8'),
  )

  it('la suite core espera 3 tests reales (CA-PERM-133, CA-CURSO-086, RNF-MANT-007)', () => {
    expect(esperados.core).toBe(3)
  })

  it('toda suite de los tres guiones tiene un número esperado entero >= 1 (CA-E2ECI-09)', () => {
    // Claves de `e2e_playwright`: las del guion de la API (E2E_ONLY), `prl` y las dos invocaciones de `alum`.
    const claves = [
      'core',
      'acad-structure',
      'acad-periods',
      'acad-scales',
      'acad-timetable',
      'curso-rollover',
      'fam-unit',
      'prl',
      'alum-http',
      'alum-browser',
    ]
    for (const clave of claves) {
      expect(Number.isInteger(esperados[clave]) && esperados[clave] >= 1, clave).toBe(true)
    }
  })

  it('las claves de esperados coinciden con las que usan los guiones (ninguna suite sin verificar)', () => {
    const usadas = new Set<string>()
    const api = readFileSync(resolve(__dirname, 'e2e-real-api.sh'), 'utf8')
    for (const m of api.matchAll(/^suite (\S+) /gm)) usadas.add(m[1])
    const prl = readFileSync(resolve(__dirname, 'e2e-real-prl.sh'), 'utf8')
    for (const m of prl.matchAll(/e2e_playwright (\S+) /g)) usadas.add(m[1])
    const alum = readFileSync(resolve(__dirname, 'e2e-real-alum.sh'), 'utf8')
    for (const m of alum.matchAll(/^e2e_playwright (\S+) /gm)) usadas.add(m[1])
    const claves = Object.keys(esperados).filter((k) => !k.startsWith('_'))
    expect([...usadas].sort()).toEqual([...claves].sort())
  })
})

describe('e2e-real-verificar.mjs: un verde exige tests ejecutados (CA-E2ECI-12)', () => {
  const esperados = { core: 3 }

  it('acepta 3 pasados, ninguno saltado', () => {
    const informe = { stats: { expected: 3, skipped: 0, unexpected: 0, flaky: 0 } }
    expect(evaluar(informe, esperados, 'core').problemas).toEqual([])
  })

  it('rechaza si todos quedan saltados (sin E2E_REAL_API)', () => {
    const informe = { stats: { expected: 0, skipped: 3, unexpected: 0, flaky: 0 } }
    const { problemas } = evaluar(informe, esperados, 'core')
    expect(problemas.join('|')).toContain('saltado')
    expect(problemas.join('|')).toContain('se esperaban al menos 3')
  })

  it('rechaza menos pasados de los esperados aunque no haya saltados', () => {
    const informe = { stats: { expected: 2, skipped: 0, unexpected: 0, flaky: 0 } }
    expect(evaluar(informe, esperados, 'core').problemas).toHaveLength(1)
  })

  it('rechaza fallidos e intermitentes (sin reintentos, D5)', () => {
    const informe = { stats: { expected: 3, skipped: 0, unexpected: 1, flaky: 1 } }
    expect(evaluar(informe, esperados, 'core').problemas).toHaveLength(2)
  })

  it('rechaza una suite sin número esperado y un informe sin stats', () => {
    expect(
      evaluar({ stats: { expected: 3, skipped: 0, unexpected: 0, flaky: 0 } }, esperados, 'otra')
        .problemas,
    ).not.toEqual([])
    expect(evaluar({}, esperados, 'core').problemas).not.toEqual([])
  })
})

describe('e2e-real-cambios.sh: regla de rutas del job cambios (CA-E2ECI-02, ADR-091 §2.3)', () => {
  it.each([
    'apps/web/src/main.ts',
    'apps/api/app/Modules/Core/X.php',
    'compose.yaml',
    'infra/containers/api/Containerfile',
    'infra/garage/init.sh',
    'infra/lib/container-runtime.sh',
    '.env.example',
    '.github/workflows/ci-e2e-real.yml',
  ])('%s dispara el E2E real', (fichero) => {
    expect(rutas(['docs/README.md', fichero])).toBe('true')
  })

  it.each([
    'docs/proceso/e2e-real-ci.md',
    'infra/compose/compose.prodlike.yaml',
    'infra/quadlet/api.container',
    'apps/landing/index.html',
    'CHANGELOG.md',
    'memory.md',
    '.github/workflows/ci-web.yml',
  ])('%s no lo dispara', (fichero) => {
    expect(rutas([fichero])).toBe('false')
  })

  it('un diff vacío no dispara y un nombre con espacios se trata entero', () => {
    expect(rutas([])).toBe('false')
    expect(rutas(['docs/apps/web/x y.md'])).toBe('false')
    expect(rutas(['apps/web/x y.md'])).toBe('true')
  })
})

describe('ci-e2e-real.yml (CA-E2ECI-14, CA-E2ECI-15, ADR-091 §2)', () => {
  it('declara timeout de 30 minutos, permisos de solo lectura y runner fijado', () => {
    expect(workflow).toMatch(/^permissions:\n {2}contents: read$/m)
    expect(workflow).toMatch(/timeout-minutes: 30/)
    expect(workflow).toMatch(/runs-on: ubuntu-26\.04/)
    expect(workflow).not.toMatch(/runs-on: ubuntu-latest/)
  })

  it('no usa pull_request_target ni filtro paths: en on:', () => {
    expect(workflow).not.toMatch(/^\s*pull_request_target\s*:/m)
    const bloqueOn = workflow.slice(workflow.indexOf('\non:'), workflow.indexOf('\npermissions:'))
    expect(bloqueOn).not.toMatch(/paths/)
    expect(bloqueOn).toContain('branches: [develop, main, staging]')
    expect(bloqueOn).toMatch(/push:\n {4}branches: \[develop\]/)
  })

  it('define los tres jobs con los nombres que ADR-091 fija', () => {
    expect(workflow).toContain('name: E2E real · detección de cambios')
    expect(workflow).toContain('name: E2E real · core')
    expect(workflow).toContain('name: E2E real (API)')
    expect(workflow).toContain('needs: [cambios, e2e-real]')
    expect(workflow).toMatch(/if: needs\.cambios\.outputs\.ejecutar == 'true'/)
  })

  it('el agregador implementa la regla de ADR-091 §2.4 (CA-E2ECI-04)', () => {
    expect(workflow).toContain(
      '[ "$CAMBIOS" = "success" ] && [ "$EJECUTAR" = "false" ] && [ "$E2E_REAL" = "skipped" ]',
    )
    expect(workflow).toContain(
      '[ "$CAMBIOS" = "success" ] && [ "$EJECUTAR" = "true" ] && [ "$E2E_REAL" = "success" ]',
    )
    expect(workflow).toMatch(/agregador:[\s\S]*?if: always\(\)/)
    const agregador = workflow.slice(workflow.indexOf('  agregador:'))
    expect(agregador).not.toContain('actions/checkout')
  })

  it('el cálculo de diff falla el job si no se puede hacer y push a develop ejecuta siempre', () => {
    expect(workflow).toContain('set -euo pipefail')
    expect(workflow).toContain('[ "$EVENTO" != "pull_request" ]')
  })

  it('levanta solo los servicios nombrados (sin web) con la base «plataforma»', () => {
    expect(workflow).toContain('up -d --build postgres redis api worker garage')
    expect(workflow).not.toMatch(/compose up[^\n]*\bweb\b/)
    expect(workflow).toContain('POSTGRES_DB=plataforma"')
    expect(workflow).not.toContain('plataforma_test')
    expect(workflow).toContain('CONTAINER_RUNTIME: docker')
    expect(workflow).not.toMatch(/CONTAINER_CLI:/)
  })

  it('ejecuta core sin reintentos, con trazas y reporters json y html (CA-E2ECI-05)', () => {
    expect(workflow).toContain('E2E_ONLY: core')
    expect(workflow).toContain('--retries=0 --trace=retain-on-failure --reporter=json,html')
  })

  it('enmascara los secretos efímeros', () => {
    expect(workflow).toContain('::add-mask::')
  })

  it('upload-artifact va fijado por SHA completo y el informe solo sube en fallo con 7 días (CA-E2ECI-15)', () => {
    const usos = [...workflow.matchAll(/uses: actions\/upload-artifact@(\S+)/g)].map((m) => m[1])
    expect(usos).toHaveLength(2)
    for (const ref of usos) expect(ref).toMatch(/^[0-9a-f]{40}$/)
    const informe = workflow.slice(workflow.indexOf('Subir informe y trazas de Playwright'))
    expect(informe).toMatch(/if: failure\(\)/)
    expect(informe).toMatch(/retention-days: 7/)
  })
})

describe('instrumentación de tiempos (CA-E2ECI-06, docs/proceso/e2e-real-ci.md §5.2)', () => {
  it('parsea las líneas E2E_TIEMPO y genera la tabla', () => {
    const filas = parsear(
      'ruido\nE2E_TIEMPO suite=core fase=setup segundos=4\nE2E_TIEMPO suite=core fase=playwright segundos=31\n',
    )
    expect(filas).toEqual([
      { suite: 'core', fase: 'setup', segundos: 4 },
      { suite: 'core', fase: 'playwright', segundos: 31 },
    ])
    expect(tabla(filas)).toContain('| core | playwright | 31 |')
  })

  it('e2e_tiempo_medir emite la línea y conserva el código de salida', () => {
    const result = spawnSync(
      'bash',
      [
        '-c',
        'source "$1"; e2e_tiempo_medir core playwright bash -c "exit 3"; echo "rc=$?"',
        '_',
        resolve(__dirname, 'lib/e2e-tiempo.sh'),
      ],
      { encoding: 'utf8', env: { PATH: process.env.PATH } },
    )
    expect(result.stdout).toMatch(/^E2E_TIEMPO suite=core fase=playwright segundos=\d+$/m)
    expect(result.stdout).toContain('rc=3')
  })

  it('los tres guiones emiten setup, playwright y teardown', () => {
    for (const guion of ['e2e-real-api.sh', 'e2e-real-prl.sh', 'e2e-real-alum.sh']) {
      const texto = readFileSync(resolve(__dirname, guion), 'utf8')
      expect(texto, guion).toContain('lib/e2e-tiempo.sh')
      expect(texto, guion).toMatch(/e2e_tiempo_emitir \S+ setup/)
      expect(texto, guion).toMatch(/e2e_tiempo_(medir|emitir) \S+ playwright/)
      expect(texto, guion).toMatch(/e2e_tiempo_medir \S+ teardown/)
    }
  })
})

describe('informes por suite y sin corte entre suites (CA-E2ECI-09, CA-E2ECI-10, docs/proceso/e2e-real-ci.md §12.1.2 paso 7)', () => {
  const lib = resolve(__dirname, 'lib/e2e-informe.sh')

  // `npx` falso: vuelca las variables que Playwright usaría y sale con el código de FAKE_RC.
  function conNpxFalso(clavesRc: string, extraEnv: Record<string, string>) {
    const dir = mkdtempSync(join(tmpdir(), 'e2e-informe-'))
    const npx = join(dir, 'npx')
    writeFileSync(
      npx,
      '#!/usr/bin/env bash\necho "JSON=${PLAYWRIGHT_JSON_OUTPUT_NAME:-} HTML=${PLAYWRIGHT_HTML_OUTPUT_DIR:-} ARGS=$*"\n[[ "$*" == *"$FAKE_FALLA"* ]] && exit 1\nexit 0\n',
    )
    chmodSync(npx, 0o755)
    const result = spawnSync('bash', ['-c', clavesRc, '_', lib], {
      encoding: 'utf8',
      env: { PATH: `${dir}:${process.env.PATH}`, ...extraEnv },
    })
    rmSync(dir, { recursive: true, force: true })
    return result
  }

  it('con E2E_INFORMES_DIR cada clave escribe su JSON, su informe HTML y sus trazas sin pisar a otra', () => {
    const informes = mkdtempSync(join(tmpdir(), 'e2e-informes-'))
    const result = conNpxFalso(
      'source "$1"; e2e_playwright core a.spec.ts; e2e_playwright prl b.spec.ts; echo rc=$?',
      { E2E_INFORMES_DIR: informes },
    )
    rmSync(informes, { recursive: true, force: true })
    expect(result.stdout).toContain(
      `JSON=${informes}/core.json HTML=${informes}/playwright-report/core`,
    )
    expect(result.stdout).toContain(`--output=${informes}/test-results/core a.spec.ts`)
    expect(result.stdout).toContain(
      `JSON=${informes}/prl.json HTML=${informes}/playwright-report/prl`,
    )
    expect(result.stdout).toContain(`--output=${informes}/test-results/prl b.spec.ts`)
  })

  it('sin E2E_INFORMES_DIR no toca la salida (uso local como antes) y conserva el código de salida', () => {
    const result = conNpxFalso(
      'source "$1"; e2e_playwright core a.spec.ts; echo rc=$?; e2e_playwright core FALLA.spec.ts; echo rc=$?',
      { FAKE_FALLA: 'FALLA' },
    )
    expect(result.stdout).toContain('JSON= HTML= ARGS=playwright test a.spec.ts')
    expect(result.stdout).not.toContain('--output')
    expect(result.stdout).toMatch(/rc=0[\s\S]*rc=1/)
  })

  it('rechaza una clave de suite no válida', () => {
    const result = conNpxFalso('source "$1"; e2e_playwright "../x" a.spec.ts; echo rc=$?', {})
    expect(result.stdout).toContain('rc=2')
  })

  it('e2e-real-api.sh: una suite que falla no corta las siguientes y el guion termina distinto de 0', () => {
    const api = readFileSync(resolve(__dirname, 'e2e-real-api.sh'), 'utf8')
    const funcion = api.match(/^suite\(\) \{[\s\S]*?^\}$/m)?.[0]
    expect(funcion).toBeTruthy()
    // La función real, con las piezas externas sustituidas por dobles; `set -e` activo como en el guion.
    const guion = [
      'set -euo pipefail',
      'only() { return 0; }',
      'prepare_tenant() { [[ "$FAKE_SETUP_ROTO" != "${1:-}" ]]; }',
      'e2e_tiempo_emitir() { :; }',
      'e2e_tiempo_medir() { shift 2; "$@"; }',
      'e2e_playwright() { echo "EJECUTA $1"; [[ "$1" != "$FAKE_PW_ROTO" ]]; }',
      'status=0; fallidas=()',
      funcion,
      'suite a - x.spec.ts',
      'suite b - x.spec.ts',
      'suite c door x.spec.ts',
      'suite d - x.spec.ts',
      'echo "status=$status fallidas=${fallidas[*]}"',
    ].join('\n')
    const ejecutar = (env: Record<string, string>) =>
      spawnSync('bash', ['-c', guion], {
        encoding: 'utf8',
        env: { PATH: process.env.PATH ?? '', ...env },
      })

    const verde = ejecutar({ FAKE_SETUP_ROTO: 'nada', FAKE_PW_ROTO: 'nada' })
    expect(verde.stdout).toContain('status=0 fallidas=')

    const rojo = ejecutar({ FAKE_SETUP_ROTO: 'nada', FAKE_PW_ROTO: 'a' })
    for (const suite of ['a', 'b', 'c', 'd']) expect(rojo.stdout).toContain(`EJECUTA ${suite}`)
    expect(rojo.stdout).toContain('status=1 fallidas=a')

    // Un `setup` roto (modo `door` de la suite c) salta solo esa suite: Playwright no corre con credenciales ajenas.
    const setupRoto = ejecutar({ FAKE_SETUP_ROTO: 'door', FAKE_PW_ROTO: 'nada' })
    expect(setupRoto.stdout).not.toContain('EJECUTA c')
    expect(setupRoto.stdout).toContain('EJECUTA d')
    expect(setupRoto.stdout).toContain('status=1 fallidas=c')
  })

  it('e2e-real-api.sh y e2e-real-alum.sh no cortan entre suites y terminan con el estado acumulado', () => {
    const api = readFileSync(resolve(__dirname, 'e2e-real-api.sh'), 'utf8')
    expect(api).not.toMatch(/^\s*e2e_tiempo_medir \S+ playwright npx /m)
    expect(api).toMatch(/^exit "\$status"$/m)
    const alum = readFileSync(resolve(__dirname, 'e2e-real-alum.sh'), 'utf8')
    expect(alum).not.toMatch(/^npx playwright/m)
    expect(alum).toContain('e2e_playwright alum-http ')
    expect(alum).toContain('e2e_playwright alum-browser ')
    expect(alum).toMatch(/alum-http [^\n]*\|\| status=1/)
    expect(alum).toMatch(/alum-browser [^\n]*\|\| status=1/)
    expect(alum).toMatch(/^exit "\$status"$/m)
  })
})

describe('infra/lib/container-runtime.sh: cabecera sobre docker (ADR-091 §4.3)', () => {
  it('ya no dice que docker está sin verificar en el camino del E2E real y remite a ADR-091', () => {
    const cabecera = readFileSync(resolve(raiz, 'infra/lib/container-runtime.sh'), 'utf8')
    expect(cabecera).toContain('ADR-091 §4.3')
    expect(cabecera).toContain('verde en `develop`')
    expect(cabecera).not.toContain('con `docker` el soporte es de mejor esfuerzo y sin verificar.')
  })
})
