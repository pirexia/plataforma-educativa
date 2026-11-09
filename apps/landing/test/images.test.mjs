// ADR-074 §4.3: pipeline de imágenes aislado (tools/images). Los tests con codificador sintético
// no necesitan sharp; los de sharp real se omiten si tools/images no tiene `npm ci` hecho (CI no lo instala).
import { test, after } from 'node:test'
import assert from 'node:assert/strict'
import { existsSync, mkdtempSync, mkdirSync, readFileSync, readdirSync, writeFileSync, statSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { tmpdir } from 'node:os'
import { join, relative } from 'node:path'
import { deflateSync } from 'node:zlib'
import { PROJECT, makeSite, cleanup, editJson } from './helpers.mjs'
import { build } from '../scripts/build.mjs'
import { runChecks } from '../scripts/check.mjs'
import { optimize } from '../tools/images/images.mjs'
import { MAX_IMG_FILE_BYTES } from '../scripts/lib.mjs'

after(cleanup)

const TOOLS = join(PROJECT, 'tools', 'images')
const HAS_SHARP = existsSync(join(TOOLS, 'node_modules', 'sharp'))
const temp = () => mkdtempSync(join(tmpdir(), 'landing-img-'))

// PNG RGB sintético (degradado) escrito a mano: sin binarios en el repositorio.
function crc32(buf) {
  let c
  let crc = 0xffffffff
  for (let n = 0; n < buf.length; n++) {
    c = (crc ^ buf[n]) & 0xff
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
    crc = (crc >>> 8) ^ c
  }
  return (crc ^ 0xffffffff) >>> 0
}
function chunk(type, data) {
  const len = Buffer.alloc(4)
  len.writeUInt32BE(data.length)
  const body = Buffer.concat([Buffer.from(type), data])
  const crc = Buffer.alloc(4)
  crc.writeUInt32BE(crc32(body))
  return Buffer.concat([len, body, crc])
}
export function syntheticPng(width, height) {
  const row = width * 3 + 1
  const raw = Buffer.alloc(row * height)
  for (let y = 0; y < height; y++) {
    raw[y * row] = 0
    for (let x = 0; x < width; x++) {
      const o = y * row + 1 + x * 3
      raw[o] = Math.round((x / width) * 255)
      raw[o + 1] = Math.round((y / height) * 255)
      raw[o + 2] = 128
    }
  }
  const ihdr = Buffer.alloc(13)
  ihdr.writeUInt32BE(width, 0)
  ihdr.writeUInt32BE(height, 4)
  ihdr[8] = 8
  ihdr[9] = 2
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr),
    chunk('IDAT', deflateSync(raw)),
    chunk('IEND', Buffer.alloc(0)),
  ])
}

// Codificador sintético: tamaño fijo del maestro y bytes deterministas por variante.
function fakeEncoder({ masterWidth = 2880, masterHeight = 1800, bytes = 1000 } = {}) {
  return {
    size: async () => ({ width: masterWidth, height: masterHeight }),
    encode: async (_file, format, width) => ({
      data: Buffer.alloc(bytes + width, format === 'avif' ? 1 : 2),
      width,
      height: Math.round((width * masterHeight) / masterWidth),
    }),
  }
}

function masters(ids) {
  const dir = temp()
  for (const id of ids) writeFileSync(join(dir, `${id}.png`), 'no se decodifica con el codificador sintético')
  return dir
}

test('optimize: seis variantes por imagen (AVIF y WebP en 640, 1024 y 1600) y manifiesto', async () => {
  const out = temp()
  const res = await optimize({ mastersDir: masters(['schedule']), outDir: out, encoder: fakeEncoder() })
  assert.deepEqual(res.written, ['schedule'])
  assert.deepEqual(
    readdirSync(out).sort(),
    [
      'manifest.json',
      ...[640, 1024, 1600].flatMap((w) => [`schedule-${w}.avif`, `schedule-${w}.webp`]),
    ].sort(),
  )
  const manifest = JSON.parse(readFileSync(join(out, 'manifest.json'), 'utf8'))
  const entry = manifest.images.schedule
  assert.equal(entry.alt, 'img.schedule.alt')
  assert.equal(entry.variants.length, 6)
  const v = entry.variants.find((x) => x.file === 'schedule-1024.webp')
  assert.deepEqual(v, { file: 'schedule-1024.webp', format: 'webp', width: 1024, height: 640, bytes: 2024 })
  for (const x of entry.variants) assert.equal(statSync(join(out, x.file)).size, x.bytes)
})

test('optimize: conserva las demás imágenes del manifiesto y sustituye las variantes de la regenerada', async () => {
  const out = temp()
  await optimize({ mastersDir: masters(['a', 'b']), outDir: out, encoder: fakeEncoder() })
  writeFileSync(join(out, 'a-9999.webp'), 'obsoleta')
  await optimize({ mastersDir: masters(['a']), outDir: out, encoder: fakeEncoder({ bytes: 5000 }) })
  const manifest = JSON.parse(readFileSync(join(out, 'manifest.json'), 'utf8'))
  assert.deepEqual(Object.keys(manifest.images), ['a', 'b'])
  assert.equal(manifest.images.a.variants[0].bytes, 5640)
  assert.equal(manifest.images.b.variants[0].bytes, 1640)
  assert.ok(!existsSync(join(out, 'a-9999.webp')))
})

test('optimize: rechaza maestros estrechos, nombres no válidos, ficheros por encima del tope y más de 8 imágenes', async () => {
  await assert.rejects(
    optimize({ mastersDir: masters(['a']), outDir: temp(), encoder: fakeEncoder({ masterWidth: 1200 }) }),
    /hacen falta al menos 1600/,
  )
  await assert.rejects(
    optimize({ mastersDir: masters(['Mala Imagen']), outDir: temp(), encoder: fakeEncoder() }),
    /Nombre de maestro no válido/,
  )
  const out = temp()
  await assert.rejects(
    optimize({ mastersDir: masters(['a']), outDir: out, encoder: fakeEncoder({ bytes: MAX_IMG_FILE_BYTES }) }),
    /superan el tope/,
  )
  assert.deepEqual(readdirSync(out), [], 'si se pasa de peso no se escribe nada')
  const many = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i']
  await assert.rejects(
    optimize({ mastersDir: masters(many), outDir: temp(), encoder: fakeEncoder() }),
    /máximo 8/,
  )
  await assert.rejects(optimize({ mastersDir: temp(), outDir: temp(), encoder: fakeEncoder() }), /Sin PNG maestros/)
  await assert.rejects(
    optimize({ mastersDir: join(tmpdir(), 'landing-no-existe-xyz'), outDir: temp(), encoder: fakeEncoder() }),
    /No existe el directorio/,
  )
})

test('sharp solo se importa en tools/images/images.mjs y solo vive en tools/images/package.json (RNF-MANT-007)', () => {
  const files = []
  const walk = (dir) => {
    for (const n of readdirSync(dir)) {
      if (n === 'node_modules' || n === 'dist' || n === '.wrangler') continue
      const p = join(dir, n)
      if (statSync(p).isDirectory()) walk(p)
      else if (/\.(mjs|js|html)$/.test(n)) files.push(p)
    }
  }
  walk(PROJECT)
  const importers = files
    .filter((f) => /from\s+['"]sharp['"]|import\(\s*['"]sharp['"]\s*\)|require\(\s*['"]sharp['"]\s*\)/.test(readFileSync(f, 'utf8')))
    .map((f) => relative(PROJECT, f))
  assert.deepEqual(importers, [join('tools', 'images', 'images.mjs')])
  const landing = JSON.parse(readFileSync(join(PROJECT, 'package.json'), 'utf8'))
  assert.ok(!('sharp' in (landing.dependencies ?? {})) && !('sharp' in (landing.devDependencies ?? {})))
})

test('tools/images: sharp con versión exacta (sin ^ ni ~) y package-lock.json', () => {
  const pkg = JSON.parse(readFileSync(join(TOOLS, 'package.json'), 'utf8'))
  assert.match(pkg.dependencies.sharp, /^\d+\.\d+\.\d+$/)
  assert.equal(pkg.private, true)
  assert.ok(existsSync(join(TOOLS, 'package-lock.json')))
  assert.equal(JSON.parse(readFileSync(join(TOOLS, 'package-lock.json'), 'utf8')).packages['node_modules/sharp'].version, pkg.dependencies.sharp)
})

test('los PNG maestros y node_modules de tools/images están fuera de Git', () => {
  const ignored = (rel) => {
    try {
      execFileSync('git', ['check-ignore', '-q', rel], { cwd: PROJECT })
      return true
    } catch {
      return false
    }
  }
  assert.ok(ignored('tools/images/masters/schedule.png'))
  assert.ok(ignored('tools/images/node_modules/sharp/package.json'))
  assert.ok(!ignored('tools/images/images.mjs'))
  assert.ok(!ignored('src/img/manifest.json'))
})

test('sharp real: genera AVIF y WebP válidos, sin metadatos, y check.mjs acepta el resultado', { skip: !HAS_SHARP && 'tools/images sin npm ci (sharp no instalada)' }, async () => {
  const dir = temp()
  writeFileSync(join(dir, 'demo-shot.png'), syntheticPng(1700, 1000))
  const root = makeSite({ built: false })
  const out = join(root, 'src', 'img')
  await optimize({ mastersDir: dir, outDir: out })
  const manifest = JSON.parse(readFileSync(join(out, 'manifest.json'), 'utf8'))
  const variants = manifest.images['demo-shot'].variants
  assert.equal(variants.length, 6)
  for (const v of variants) {
    const buf = readFileSync(join(out, v.file))
    assert.equal(buf.length, v.bytes)
    assert.ok(v.bytes <= MAX_IMG_FILE_BYTES, `${v.file} pesa ${v.bytes}`)
    assert.equal(v.height, Math.round((v.width * 1000) / 1700))
    if (v.format === 'avif') assert.equal(buf.subarray(4, 12).toString(), 'ftypavif')
    else {
      assert.equal(buf.subarray(0, 4).toString(), 'RIFF')
      assert.equal(buf.subarray(8, 12).toString(), 'WEBP')
    }
    assert.ok(!buf.includes('Exif'), `${v.file} lleva EXIF`)
  }
  assert.deepEqual(variants.map((v) => v.width), [640, 1024, 1600, 640, 1024, 1600])
  for (const l of ['en', 'es-ES', 'de', 'fr']) {
    editJson(root, `locales/${l}.json`, (d) => (d['img.demo-shot.alt'] = `Pantalla de ejemplo (${l})`))
  }
  const home = join(root, 'src', 'home.html')
  writeFileSync(home, readFileSync(home, 'utf8').replace('<main id="main" tabindex="-1">', '<main id="main" tabindex="-1">\n{{img:demo-shot:lcp}}'))
  build({ root })
  assert.deepEqual(runChecks({ root }).errors, [])
  assert.ok(existsSync(join(root, 'dist', 'img', 'demo-shot-1600.avif')))
})
