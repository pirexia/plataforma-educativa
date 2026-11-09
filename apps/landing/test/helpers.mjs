import { mkdtempSync, mkdirSync, cpSync, readFileSync, writeFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { build } from '../scripts/build.mjs'

export const PROJECT = join(dirname(fileURLToPath(import.meta.url)), '..')
export const FIXTURES = join(PROJECT, 'test', 'fixtures')

const created = []

// Copia src/ y locales/ del proyecto a un directorio temporal. Con `built`, construye dist/.
export function makeSite({ built = true } = {}) {
  const root = mkdtempSync(join(tmpdir(), 'landing-test-'))
  created.push(root)
  // Sin `src/img`: los tests parten de un sitio sin imágenes aunque la rama lleve las reales (T2b, ADR-074 §4.3).
  const IMG_DIR = join(PROJECT, 'src', 'img')
  cpSync(join(PROJECT, 'src'), join(root, 'src'), { recursive: true, filter: (from) => from !== IMG_DIR })
  cpSync(join(PROJECT, 'locales'), join(root, 'locales'), { recursive: true })
  cpSync(join(PROJECT, '.lighthouserc.json'), join(root, '.lighthouserc.json'))
  if (built) build({ root })
  return root
}

export function cleanup() {
  for (const d of created.splice(0)) rmSync(d, { recursive: true, force: true })
}

export function edit(root, rel, fn) {
  const file = join(root, rel)
  writeFileSync(file, fn(readFileSync(file, 'utf8')))
}

export function editJson(root, rel, fn) {
  const file = join(root, rel)
  const data = JSON.parse(readFileSync(file, 'utf8'))
  fn(data)
  writeFileSync(file, JSON.stringify(data, null, 2))
}

export const fixture = (name) => readFileSync(join(FIXTURES, name), 'utf8')

// Imagen sintética (bytes sin formato real: check.mjs no decodifica) con su manifiesto y su texto
// alternativo en las cuatro locales. Hay que llamarla antes de construir.
export function addImage(root, id = 'shot-a', { bytes = 2000, widths = [640, 1024, 1600], ratio = 0.625 } = {}) {
  const dir = join(root, 'src', 'img')
  mkdirSync(dir, { recursive: true })
  const manifestPath = join(dir, 'manifest.json')
  let manifest = { images: {} }
  try {
    manifest = JSON.parse(readFileSync(manifestPath, 'utf8'))
  } catch {}
  const variants = []
  for (const format of ['avif', 'webp']) {
    for (const width of widths) {
      const file = `${id}-${width}.${format}`
      writeFileSync(join(dir, file), Buffer.alloc(bytes, 7))
      variants.push({ file, format, width, height: Math.round(width * ratio), bytes })
    }
  }
  manifest.images[id] = { alt: `img.${id}.alt`, variants }
  writeFileSync(manifestPath, JSON.stringify(manifest, null, 2))
  for (const l of ['en', 'es-ES', 'de', 'fr']) {
    editJson(root, `locales/${l}.json`, (d) => (d[`img.${id}.alt`] = `Alt ${id} ${l}`))
  }
  return manifest.images[id]
}

// Inserta una imagen del manifiesto en la portada.
export function placeImage(root, token) {
  edit(root, 'src/home.html', (h) => h.replace('<main id="main" tabindex="-1">', `<main id="main" tabindex="-1">\n${token}`))
}
