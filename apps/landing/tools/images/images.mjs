#!/usr/bin/env node
// Genera las variantes AVIF y WebP de la landing y regenera src/img/manifest.json (ADR-074 §4.3).
// Es la ÚNICA pieza que importa `sharp` (RNF-MANT-007): la interfaz propia es este script y su
// protocolo (README.md de la landing). build.mjs y check.mjs no la importan.
//
// Uso: node images.mjs [--masters <directorio>] [--out <directorio>]
//   Lee <masters>/<id>.png (por defecto tools/images/masters/, ignorado por Git) y escribe en
//   <out> (por defecto src/img/) <id>-<ancho>.avif y <id>-<ancho>.webp en 640, 1024 y 1600 px,
//   sin metadatos, y el manifiesto. Los PNG maestros nunca se commitean.
import { existsSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { IMG_WIDTHS, MAX_IMAGES, MAX_IMG_FILE_BYTES } from '../../scripts/lib.mjs'

const HERE = dirname(fileURLToPath(import.meta.url))
const FORMATS = ['avif', 'webp']
const ID_RE = /^[a-z0-9][a-z0-9-]*$/

// Codificador por defecto: sharp, cargado de forma diferida para que el resto del módulo (y sus
// tests con un codificador sintético) funcione sin tenerla instalada.
//   size(file)                 -> { width, height }
//   encode(file, format, width) -> { data: Buffer, width, height }
export function createSharpEncoder() {
  let sharpPromise
  const load = () => (sharpPromise ??= import('sharp').then((m) => m.default))
  return {
    async size(file) {
      const sharp = await load()
      const { width, height } = await sharp(file).metadata()
      return { width, height }
    },
    async encode(file, format, width) {
      const sharp = await load()
      // Sin withMetadata(): sharp descarta EXIF, ICC y XMP.
      const pipeline = sharp(file).resize({ width, withoutEnlargement: true })
      const out =
        format === 'avif'
          ? pipeline.avif({ quality: 50, effort: 4 })
          : pipeline.webp({ quality: 78, effort: 4 })
      const { data, info } = await out.toBuffer({ resolveWithObject: true })
      return { data, width: info.width, height: info.height }
    },
  }
}

function readManifest(file) {
  if (!existsSync(file)) return { images: {} }
  const manifest = JSON.parse(readFileSync(file, 'utf8'))
  return { images: manifest?.images ?? {} }
}

export async function optimize({
  mastersDir,
  outDir,
  widths = IMG_WIDTHS,
  encoder = createSharpEncoder(),
}) {
  if (!existsSync(mastersDir)) throw new Error(`No existe el directorio de maestros: ${mastersDir}`)
  const masters = readdirSync(mastersDir)
    .filter((f) => f.toLowerCase().endsWith('.png'))
    .sort()
  if (masters.length === 0) throw new Error(`Sin PNG maestros en ${mastersDir}`)
  mkdirSync(outDir, { recursive: true })

  const manifestFile = join(outDir, 'manifest.json')
  const manifest = readManifest(manifestFile)
  const written = []

  for (const name of masters) {
    const id = name.slice(0, -'.png'.length)
    if (!ID_RE.test(id)) throw new Error(`Nombre de maestro no válido "${name}" (a-z, 0-9 y guiones)`)
    const master = join(mastersDir, name)
    const { width: masterWidth } = await encoder.size(master)
    if (masterWidth < Math.max(...widths)) {
      throw new Error(`${name}: ${masterWidth} px de ancho; hacen falta al menos ${Math.max(...widths)}`)
    }
    // Se codifica todo antes de escribir nada de esta imagen: si falla o se pasa de peso, no queda a medias.
    const encoded = []
    for (const format of FORMATS) {
      for (const width of widths) {
        const { data, height } = await encoder.encode(master, format, width)
        const file = `${id}-${width}.${format}`
        if (data.length > MAX_IMG_FILE_BYTES) {
          throw new Error(`${file}: ${data.length} bytes superan el tope de ${MAX_IMG_FILE_BYTES}`)
        }
        encoded.push({ file, format, width, height, bytes: data.length, data })
      }
    }
    for (const old of readdirSync(outDir)) {
      if (old.startsWith(`${id}-`) && /\.(avif|webp)$/.test(old)) rmSync(join(outDir, old))
    }
    for (const v of encoded) writeFileSync(join(outDir, v.file), v.data)
    manifest.images[id] = {
      alt: `img.${id}.alt`,
      variants: encoded.map(({ data, ...v }) => v),
    }
    written.push(id)
  }

  const ids = Object.keys(manifest.images).sort()
  if (ids.length > MAX_IMAGES) {
    throw new Error(`${ids.length} imágenes en el manifiesto (máximo ${MAX_IMAGES})`)
  }
  const sorted = { images: Object.fromEntries(ids.map((id) => [id, manifest.images[id]])) }
  writeFileSync(manifestFile, JSON.stringify(sorted, null, 2) + '\n')
  return { written, manifestFile }
}

const isMain = process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href
if (isMain) {
  const arg = (name, fallback) => {
    const i = process.argv.indexOf(name)
    return i > -1 ? resolve(process.argv[i + 1] ?? '') : fallback
  }
  try {
    const { written } = await optimize({
      mastersDir: arg('--masters', join(HERE, 'masters')),
      outDir: arg('--out', join(HERE, '..', '..', 'src', 'img')),
    })
    console.log(`Imágenes generadas: ${written.join(', ')}`)
  } catch (e) {
    console.error(`ERROR: ${e.message}`)
    process.exit(1)
  }
}
