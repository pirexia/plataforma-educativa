/**
 * E2E real en el CI (issue #737, ADR-091, docs/proceso/e2e-real-ci.md): fija lo que el job y sus guiones prometen y que ningún
 * E2E puede comprobar desde dentro: número de tests esperados (CA-E2ECI-01, CA-E2ECI-12), regla de rutas del job `cambios`
 * (CA-E2ECI-02, CA-E2ECI-13), propiedades del YAML (CA-E2ECI-14, CA-E2ECI-15), regla del agregador (CA-E2ECI-04) e
 * instrumentación de tiempos (CA-E2ECI-06). Lo que solo se ve en GitHub Actions lo prueba la ejecución del CI, no este fichero.
 */
import { spawnSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
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

describe('infra/lib/container-runtime.sh: cabecera sobre docker (ADR-091 §4.3)', () => {
  it('ya no dice que docker está sin verificar en el camino del E2E real y remite a ADR-091', () => {
    const cabecera = readFileSync(resolve(raiz, 'infra/lib/container-runtime.sh'), 'utf8')
    expect(cabecera).toContain('ADR-091 §4.3')
    expect(cabecera).toContain('verde en `develop`')
    expect(cabecera).not.toContain('con `docker` el soporte es de mejor esfuerzo y sin verificar.')
  })
})
