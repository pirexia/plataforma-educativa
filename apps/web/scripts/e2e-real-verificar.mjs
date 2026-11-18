// Comprobación de «un verde exige tests ejecutados» del job `e2e-real` (docs/proceso/e2e-real-ci.md §3 punto 5, ADR-091 §2.4).
// Uso: node scripts/e2e-real-verificar.mjs <informe.json de Playwright> <suite> [ficheros de esperados]
// Sale con 1 si el informe tiene algún test saltado, fallido o intermitente, o menos tests pasados que los esperados de la suite.
import { readFileSync } from 'node:fs'
import { pathToFileURL } from 'node:url'

export function evaluar(informe, esperados, suite) {
  const problemas = []
  const esperado = esperados[suite]
  if (!Number.isInteger(esperado) || esperado < 1) {
    problemas.push(
      `la suite «${suite}» no tiene un número esperado válido en el fichero de esperados`,
    )
  }
  const stats = informe?.stats
  if (!stats || typeof stats !== 'object') {
    problemas.push('el informe JSON no trae `stats`')
    return { problemas, pasados: 0, saltados: 0, esperado }
  }
  const pasados = Number(stats.expected ?? 0)
  const saltados = Number(stats.skipped ?? 0)
  const fallidos = Number(stats.unexpected ?? 0)
  const intermitentes = Number(stats.flaky ?? 0)
  if (saltados > 0)
    problemas.push(`${saltados} test(s) saltado(s): un verde exige tests ejecutados`)
  if (fallidos > 0) problemas.push(`${fallidos} test(s) fallido(s)`)
  if (intermitentes > 0) problemas.push(`${intermitentes} test(s) intermitente(s) (reintentados)`)
  if (Number.isInteger(esperado) && pasados < esperado) {
    problemas.push(`pasaron ${pasados} test(s) y se esperaban al menos ${esperado}`)
  }
  return { problemas, pasados, saltados, esperado }
}

const [, , rutaInforme, suite, rutaEsperados = 'e2e/e2e-real-esperados.json'] = process.argv
if (
  process.argv[1] &&
  import.meta.url === pathToFileURL(process.argv[1]).href &&
  rutaInforme &&
  suite
) {
  let resultado
  try {
    resultado = evaluar(
      JSON.parse(readFileSync(rutaInforme, 'utf8')),
      JSON.parse(readFileSync(rutaEsperados, 'utf8')),
      suite,
    )
  } catch (error) {
    console.error(
      `No se pudo leer el informe o los esperados: ${error instanceof Error ? error.message : error}`,
    )
    process.exit(1)
  }
  const linea = `suite=${suite} pasados=${resultado.pasados} saltados=${resultado.saltados} esperados=${resultado.esperado}`
  if (resultado.problemas.length > 0) {
    console.error(`E2E real INCOMPLETO (${linea})`)
    for (const problema of resultado.problemas) console.error(`- ${problema}`)
    process.exit(1)
  }
  console.log(`E2E real completo (${linea})`)
}
