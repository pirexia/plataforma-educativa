// Convierte las líneas `E2E_TIEMPO suite=… fase=… segundos=…` del E2E real en `e2e-tiempos.json` y en una tabla Markdown
// (docs/proceso/e2e-real-ci.md §5.2). Uso: node scripts/e2e-real-tiempos.mjs <registro.log> <salida.json>  (tabla por stdout).
import { readFileSync, writeFileSync } from 'node:fs'
import { pathToFileURL } from 'node:url'

const LINEA = /^E2E_TIEMPO suite=(\S+) fase=(\S+) segundos=(\d+)$/

export function parsear(texto) {
  return texto
    .split('\n')
    .map((linea) => LINEA.exec(linea.trim()))
    .filter((coincidencia) => coincidencia !== null)
    .map(([, suite, fase, segundos]) => ({ suite, fase, segundos: Number(segundos) }))
}

export function tabla(filas) {
  const cuerpo = filas.map((fila) => `| ${fila.suite} | ${fila.fase} | ${fila.segundos} |`)
  return ['| Suite | Fase | Segundos |', '|---|---|---:|', ...cuerpo].join('\n')
}

const [, , rutaRegistro, rutaSalida] = process.argv
if (
  process.argv[1] &&
  import.meta.url === pathToFileURL(process.argv[1]).href &&
  rutaRegistro &&
  rutaSalida
) {
  const filas = parsear(readFileSync(rutaRegistro, 'utf8'))
  writeFileSync(rutaSalida, `${JSON.stringify(filas, null, 2)}\n`)
  console.log(tabla(filas))
}
