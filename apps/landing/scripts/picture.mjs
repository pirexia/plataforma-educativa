// Lectura de src/img/manifest.json y marcado <picture> (ADR-074 §4.3). Solo módulos integrados:
// build.mjs no importa sharp; lee el manifiesto que escribe tools/images/images.mjs.
import { existsSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { escapeHtml, imgAltKey } from './lib.mjs'

export const IMG_URL_PREFIX = '/img/'
export const DEFAULT_SIZES = '(min-width: 64rem) 60rem, 100vw'
// Variante que va en `src` del <img> de reserva: su ancho y alto son los atributos width/height.
export const FALLBACK_WIDTH = 1024

export function manifestFile(root) {
  return join(root, 'src', 'img', 'manifest.json')
}

// Manifiesto vacío si no hay imágenes todavía.
export function readManifest(root) {
  const file = manifestFile(root)
  if (!existsSync(file)) return { images: {} }
  return JSON.parse(readFileSync(file, 'utf8'))
}

const srcset = (variants) => variants.map((v) => `${IMG_URL_PREFIX}${v.file} ${v.width}w`).join(', ')

// <picture> con <source> AVIF y <img> WebP de reserva. `lcp` marca la imagen LCP de la página
// (sin loading="lazy", con fetchpriority="high"); el resto, loading="lazy". El `alt` sale de la
// locale (`img.<id>.alt`), nunca del manifiesto con texto.
export function pictureHtml(manifest, id, locale, { lcp = false, sizes = DEFAULT_SIZES } = {}) {
  const entry = manifest?.images?.[id]
  if (!entry) throw new Error(`Imagen inexistente en el manifiesto: "${id}"`)
  const alt = locale?.[imgAltKey(id)]
  if (typeof alt !== 'string' || alt.trim() === '') {
    throw new Error(`Falta el texto alternativo "${imgAltKey(id)}" para la imagen "${id}"`)
  }
  const byFormat = (format) =>
    entry.variants.filter((v) => v.format === format).sort((a, b) => a.width - b.width)
  const avif = byFormat('avif')
  const webp = byFormat('webp')
  if (avif.length === 0 || webp.length === 0) {
    throw new Error(`La imagen "${id}" necesita variantes AVIF y WebP`)
  }
  const fallback = webp.find((v) => v.width === FALLBACK_WIDTH) ?? webp[webp.length - 1]
  const loading = lcp ? 'fetchpriority="high"' : 'loading="lazy"'
  const sz = escapeHtml(sizes)
  return (
    `<picture>` +
    `<source type="image/avif" srcset="${srcset(avif)}" sizes="${sz}">` +
    `<img src="${IMG_URL_PREFIX}${fallback.file}" srcset="${srcset(webp)}" sizes="${sz}" ` +
    `width="${fallback.width}" height="${fallback.height}" alt="${escapeHtml(alt)}" ` +
    `${loading} decoding="async">` +
    `</picture>`
  )
}
